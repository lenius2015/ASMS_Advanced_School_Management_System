<?php
/**
 * academic/verify_marks.php
 * Step 3 of the results workflow: Academic Department reviews marks
 * that have been compiled by the class teacher, and verifies or rejects them.
 * 
 * Workflow:
 * 1. Subject Teacher submits marks
 * 2. Class Teacher compiles results with remarks
 * 3. Academic Department verifies or rejects
 * 
 * Rejected marks go back to the teacher for correction (verification_status = 'rejected').
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$tab = $_GET['tab'] ?? 'compiled';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_batch') {
    csrf_verify();
    $examId = (int) ($_POST['exam_id'] ?? 0);
    $classSubjectId = (int) ($_POST['class_subject_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $reason = trim($_POST['rejection_reason'] ?? '');

    if (!in_array($decision, ['verified', 'rejected'], true)) {
        flash_set('error', 'Invalid decision.');
        redirect(app_url('/academic/verify_marks.php'));
    }

    $stmt = $pdo->prepare(
        'UPDATE exam_marks SET verification_status = :status, verified_by = :by, verified_at = NOW(), rejection_reason = :reason
         WHERE exam_id = :exam AND class_subject_id = :cs AND submitted_at IS NOT NULL'
    );
    $stmt->execute([
        'status' => $decision, 'by' => current_user_id(), 'reason' => $decision === 'rejected' ? $reason : null,
        'exam' => $examId, 'cs' => $classSubjectId,
    ]);
    $affected = $stmt->rowCount();

    // Update exam status
    if ($decision === 'verified') {
        $pdo->prepare("UPDATE exams SET status = 'verified' WHERE exam_id = :id")->execute(['id' => $examId]);
    }

    audit_log('verify_marks', 'academics', 'exam_marks', null, "{$decision} {$affected} mark(s) for exam #{$examId}, class_subject #{$classSubjectId}");

    // If verified, recompute term_results for the affected students
    if ($decision === 'verified') {
        $examInfo = $pdo->prepare('SELECT term_id, max_marks FROM exams WHERE exam_id = :id');
        $examInfo->execute(['id' => $examId]);
        $exam = $examInfo->fetch();

        $marksStmt = $pdo->prepare(
            'SELECT student_id, marks_obtained FROM exam_marks
             WHERE exam_id = :exam AND class_subject_id = :cs AND verification_status = "verified"'
        );
        $marksStmt->execute(['exam' => $examId, 'cs' => $classSubjectId]);

        foreach ($marksStmt->fetchAll() as $row) {
            if ($row['marks_obtained'] === null) {
                continue;
            }
            $percentage = $exam['max_marks'] > 0 ? ((float) $row['marks_obtained'] / (float) $exam['max_marks']) * 100 : 0;
            $grade = compute_grade($pdo, $percentage);

            $pdo->prepare(
                'INSERT INTO term_results (student_id, class_subject_id, term_id, total_marks, average_marks, grade_letter, gpa)
                 VALUES (:sid, :cs, :term, :total, :avg, :grade, :gpa)
                 ON DUPLICATE KEY UPDATE total_marks = :total2, average_marks = :avg2, grade_letter = :grade2, gpa = :gpa2'
            )->execute([
                'sid' => $row['student_id'], 'cs' => $classSubjectId, 'term' => $exam['term_id'],
                'total' => $row['marks_obtained'], 'avg' => round($percentage, 2),
                'grade' => $grade['grade_letter'] ?? null, 'gpa' => $grade['gpa'] ?? null,
                'total2' => $row['marks_obtained'], 'avg2' => round($percentage, 2),
                'grade2' => $grade['grade_letter'] ?? null, 'gpa2' => $grade['gpa'] ?? null,
            ]);
        }

        // Update report_cards with overall averages
        $rcStmt = $pdo->prepare(
            "UPDATE report_cards rc
             JOIN (SELECT student_id, AVG(average_marks) AS overall_avg FROM term_results WHERE term_id = :term GROUP BY student_id) tr
               ON tr.student_id = rc.student_id
             SET rc.overall_average = tr.overall_avg
             WHERE rc.term_id = :term2"
        );
        $rcStmt->execute(['term' => $exam['term_id'], 'term2' => $exam['term_id']]);

        flash_set('success', "Marks verified, term results and overall averages computed for {$affected} record(s).");
    } else {
        flash_set('success', "Marks rejected and sent back to the class teacher for correction ({$affected} record(s)).");
    }

    redirect(app_url('/academic/verify_marks.php'));
}

// ====== Pending class teacher compilation (subject teacher submitted, not yet compiled) ======
$pendingTeacherGroups = [];
if ($tab === 'pending_teacher') {
    $pendingTeacherGroups = $pdo->query(
        "SELECT em.exam_id, em.class_subject_id, e.exam_name, sub.subject_name, cl.level_name, c.stream_name,
            COUNT(*) AS student_count, MIN(em.submitted_at) AS submitted_at,
            u.first_name AS teacher_fn, u.last_name AS teacher_ln,
            ct.first_name AS ct_fn, ct.last_name AS ct_ln
         FROM exam_marks em
         JOIN exams e ON e.exam_id = em.exam_id
         JOIN class_subjects cs ON cs.class_subject_id = em.class_subject_id
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         JOIN classes c ON c.class_id = cs.class_id
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         LEFT JOIN users u ON u.user_id = cs.teacher_id
         LEFT JOIN users ct ON ct.user_id = c.class_teacher_id
         WHERE em.verification_status = 'pending' AND em.submitted_at IS NOT NULL
         GROUP BY em.exam_id, em.class_subject_id
         ORDER BY submitted_at ASC"
    )->fetchAll();
}

// ====== Pending academic verification (compiled by class teacher) ======
$pendingCompiledGroups = $pdo->query(
    "SELECT em.exam_id, em.class_subject_id, e.exam_name, sub.subject_name, cl.level_name, c.stream_name,
        COUNT(*) AS student_count, MIN(em.submitted_at) AS submitted_at,
        u.first_name AS teacher_fn, u.last_name AS teacher_ln,
        ct.first_name AS ct_fn, ct.last_name AS ct_ln
     FROM exam_marks em
     JOIN exams e ON e.exam_id = em.exam_id
     JOIN class_subjects cs ON cs.class_subject_id = em.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     LEFT JOIN users u ON u.user_id = cs.teacher_id
     LEFT JOIN users ct ON ct.user_id = c.class_teacher_id
     WHERE em.verified_by IS NULL AND em.submitted_at IS NOT NULL
       AND e.status IN ('marks_pending', 'submitted')
       AND em.verification_status = 'pending'
     GROUP BY em.exam_id, em.class_subject_id
     ORDER BY submitted_at ASC"
)->fetchAll();

// Counts for tabs
$pendingCount = count($pendingCompiledGroups);
$teacherPendingCount = count($pendingTeacherGroups);

$pageTitle = 'Verify Marks';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Verify Submitted Marks</h1>
<p class="text-muted">Review marks that have been compiled by class teachers before verification. Verifying a batch automatically calculates each student's grade and updates term results.</p>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'compiled' ? 'active' : '' ?>" href="?tab=compiled">
      <i class="fa fa-check-double me-1"></i>Ready for Verification <span class="badge bg-gold ms-1"><?= $pendingCount ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'pending_teacher' ? 'active' : '' ?>" href="?tab=pending_teacher">
      <i class="fa fa-clock me-1"></i>Awaiting Class Teacher <span class="badge bg-secondary ms-1"><?= $teacherPendingCount ?></span>
    </a>
  </li>
</ul>

<?php if ($tab === 'pending_teacher'): ?>
  <!-- Teacher submitted, awaiting class teacher compilation -->
  <div class="card">
    <div class="card-header"><i class="fa fa-clock text-warning me-2"></i>Awaiting Class Teacher Compilation</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Exam</th><th>Subject</th><th>Class</th><th>Subject Teacher</th><th>Class Teacher</th><th>Students</th><th>Submitted</th></tr></thead>
        <tbody>
          <?php foreach ($pendingTeacherGroups as $g): ?>
            <tr>
              <td><?= e($g['exam_name']) ?></td>
              <td><?= e($g['subject_name']) ?></td>
              <td><?= e($g['level_name'] . ' ' . $g['stream_name']) ?></td>
              <td class="small"><?= e(trim(($g['teacher_fn'] ?? '') . ' ' . ($g['teacher_ln'] ?? '')) ?: 'Unassigned') ?></td>
              <td class="small"><?= e(trim(($g['ct_fn'] ?? '') . ' ' . ($g['ct_ln'] ?? '')) ?: 'No class teacher') ?></td>
              <td><?= (int) $g['student_count'] ?></td>
              <td class="small text-muted"><?= e(date('d M, H:i', strtotime($g['submitted_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($pendingTeacherGroups)): ?><tr><td colspan="7" class="text-center text-muted py-4">All marks have been compiled by class teachers.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <!-- Compiled by class teacher, pending academic verification -->
  <div class="card">
    <div class="card-header"><i class="fa fa-check-double text-success me-2"></i>Compiled by Class Teacher — Pending Verification</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Exam</th><th>Subject</th><th>Class</th><th>Subject Teacher</th><th>Class Teacher</th><th>Students</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($pendingCompiledGroups as $g): ?>
            <tr>
              <td><?= e($g['exam_name']) ?></td>
              <td><?= e($g['subject_name']) ?></td>
              <td><?= e($g['level_name'] . ' ' . $g['stream_name']) ?></td>
              <td class="small"><?= e(trim(($g['teacher_fn'] ?? '') . ' ' . ($g['teacher_ln'] ?? '')) ?: 'Unassigned') ?></td>
              <td class="small"><?= e(trim(($g['ct_fn'] ?? '') . ' ' . ($g['ct_ln'] ?? '')) ?: 'No class teacher') ?></td>
              <td><?= (int) $g['student_count'] ?></td>
              <td class="small text-muted"><?= e(date('d M, H:i', strtotime($g['submitted_at']))) ?></td>
              <td class="text-nowrap">
                <a href="<?= e(app_url('/academic/review_marks_detail.php')) ?>?exam_id=<?= (int) $g['exam_id'] ?>&class_subject_id=<?= (int) $g['class_subject_id'] ?>" class="btn btn-sm btn-outline-secondary">Review</a>
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#verifyModal<?= (int) $g['exam_id'] ?>_<?= (int) $g['class_subject_id'] ?>"><i class="fa fa-check"></i> Decide</button>
              </td>
            </tr>

            <div class="modal fade" id="verifyModal<?= (int) $g['exam_id'] ?>_<?= (int) $g['class_subject_id'] ?>" tabindex="-1">
              <div class="modal-dialog">
                <div class="modal-content">
                  <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="verify_batch">
                    <input type="hidden" name="exam_id" value="<?= (int) $g['exam_id'] ?>">
                    <input type="hidden" name="class_subject_id" value="<?= (int) $g['class_subject_id'] ?>">
                    <div class="modal-header"><h5 class="modal-title">Verify: <?= e($g['exam_name'] . ' — ' . $g['subject_name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                      <p>This will apply to all <?= (int) $g['student_count'] ?> submitted mark(s) for this exam/subject/class.</p>
                      <?php if ($g['ct_fn']): ?>
                        <p class="small text-muted">Compiled by class teacher: <?= e($g['ct_fn'] . ' ' . $g['ct_ln']) ?></p>
                      <?php endif; ?>
                      <div class="mb-2">
                        <label class="form-label">Reason (required if rejecting)</label>
                        <textarea name="rejection_reason" class="form-control" rows="2"></textarea>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="submit" name="decision" value="rejected" class="btn btn-outline-danger">Reject & Send Back</button>
                      <button type="submit" name="decision" value="verified" class="btn btn-success">Verify & Compute Results</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($pendingCompiledGroups)): ?><tr><td colspan="8" class="text-center text-muted py-4">No marks pending verification. All caught up!</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
