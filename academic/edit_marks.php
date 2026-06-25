<?php
/**
 * academic/edit_marks.php
 * Academic Department CRUD on exam marks: view, edit, add, or delete
 * individual student marks across any exam/class/subject combination.
 * This gives the Academic Officer full control over marks before verification.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();

$examId = (int) ($_GET['exam_id'] ?? 0);
$classSubjectId = (int) ($_GET['class_subject_id'] ?? 0);

// ---- Save individual mark edits -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_marks') {
    csrf_verify();
    $examIdPost = (int) ($_POST['exam_id'] ?? 0);
    $classSubjectIdPost = (int) ($_POST['class_subject_id'] ?? 0);

    $examInfo = $pdo->prepare('SELECT max_marks FROM exams WHERE exam_id = :id');
    $examInfo->execute(['id' => $examIdPost]);
    $maxMarks = (float) ($examInfo->fetch()['max_marks'] ?? 100);

    $marksData = $_POST['marks'] ?? []; // [student_id => score or 'ABS']
    $savedCount = 0;
    foreach ($marksData as $studentId => $value) {
        $studentId = (int) $studentId;
        $value = trim((string) $value);
        $isAbsent = (strtoupper($value) === 'ABS' || $value === '');
        $score = $isAbsent ? null : min(max((float) $value, 0), $maxMarks);

        $pdo->prepare(
            'INSERT INTO exam_marks (exam_id, student_id, class_subject_id, marks_obtained, is_absent, entered_by, verification_status)
             VALUES (:exam, :sid, :cs, :marks, :absent, :by, "pending")
             ON DUPLICATE KEY UPDATE marks_obtained = :marks2, is_absent = :absent2, entered_by = :by2, entered_at = NOW(), verification_status = "pending"'
        )->execute([
            'exam' => $examIdPost, 'sid' => $studentId, 'cs' => $classSubjectIdPost,
            'marks' => $score, 'absent' => $isAbsent ? 1 : 0, 'by' => current_user_id(),
            'marks2' => $score, 'absent2' => $isAbsent ? 1 : 0, 'by2' => current_user_id(),
        ]);
        $savedCount++;
    }

    audit_log('academic_edit_marks', 'academics', 'exam_marks', $examIdPost, "Academic edited {$savedCount} mark(s)");
    flash_set('success', "Saved marks for {$savedCount} student(s).");
    redirect(app_url('/academic/edit_marks.php') . "?exam_id={$examIdPost}&class_subject_id={$classSubjectIdPost}");
}

// ---- Delete a single mark -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_mark') {
    csrf_verify();
    $markId = (int) ($_POST['mark_id'] ?? 0);
    $pdo->prepare('DELETE FROM exam_marks WHERE mark_id = :id')->execute(['id' => $markId]);
    audit_log('academic_delete_mark', 'academics', 'exam_marks', $markId, "Academic deleted a mark record");
    flash_set('success', 'Mark record deleted.');
    redirect(app_url('/academic/edit_marks.php') . "?exam_id={$examId}&class_subject_id={$classSubjectId}");
}

// ---- Data for filters -----------------------------------------------------
$exams = $pdo->query(
    "SELECT e.exam_id, e.exam_name, e.max_marks, et.type_name, t.term_name, y.year_name
     FROM exams e
     JOIN exam_types et ON et.exam_type_id = e.exam_type_id
     JOIN terms t ON t.term_id = e.term_id
     JOIN academic_years y ON y.year_id = t.year_id
     ORDER BY e.created_at DESC LIMIT 100"
)->fetchAll();

$classSubjects = $pdo->query(
    "SELECT cs.class_subject_id, sub.subject_name, cl.level_name, c.stream_name, c.class_id
     FROM class_subjects cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     ORDER BY cl.sort_order, sub.subject_name"
)->fetchAll();

// ---- Load grid data -------------------------------------------------------
$gridStudents = [];
$existingMarks = [];
$selectedExam = null;
$selectedClassSubject = null;

if ($examId > 0 && $classSubjectId > 0) {
    foreach ($exams as $ex) {
        if ((int) $ex['exam_id'] === $examId) {
            $selectedExam = $ex;
            break;
        }
    }
    foreach ($classSubjects as $cs) {
        if ((int) $cs['class_subject_id'] === $classSubjectId) {
            $selectedClassSubject = $cs;
            break;
        }
    }

    if ($selectedClassSubject) {
        $studentsStmt = $pdo->prepare(
            "SELECT s.student_id, u.first_name, u.last_name, s.admission_no FROM students s
             JOIN users u ON u.user_id = s.user_id WHERE s.class_id = :cid AND s.status='active' ORDER BY u.first_name"
        );
        $studentsStmt->execute(['cid' => $selectedClassSubject['class_id']]);
        $gridStudents = $studentsStmt->fetchAll();

        $marksStmt = $pdo->prepare('SELECT * FROM exam_marks WHERE exam_id = :exam AND class_subject_id = :cs');
        $marksStmt->execute(['exam' => $examId, 'cs' => $classSubjectId]);
        foreach ($marksStmt->fetchAll() as $m) {
            $existingMarks[$m['student_id']] = $m;
        }
    }
}

$pageTitle = 'Edit Marks (Academic CRUD)';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Edit Exam Marks <span class="badge bg-gold ms-2">Academic CRUD</span></h1>
<p class="text-muted">View, add, edit, or delete individual student marks for any exam/class/subject. Changes reset verification status to <em>pending</em>.</p>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-5">
        <label class="form-label">Exam</label>
        <select name="exam_id" class="form-select" required>
          <option value="">-- Select Exam --</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= (int) $ex['exam_id'] ?>" <?= $examId === (int) $ex['exam_id'] ? 'selected' : '' ?>>
              <?= e($ex['exam_name']) ?> (<?= e($ex['year_name'] . ' - ' . $ex['term_name']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Class / Subject</label>
        <select name="class_subject_id" class="form-select" required>
          <option value="">-- Select --</option>
          <?php foreach ($classSubjects as $cs): ?>
            <option value="<?= (int) $cs['class_subject_id'] ?>" <?= $classSubjectId === (int) $cs['class_subject_id'] ? 'selected' : '' ?>>
              <?= e($cs['level_name'] . ' ' . $cs['stream_name'] . ' — ' . $cs['subject_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 align-self-end"><button class="btn btn-outline-primary w-100">Load</button></div>
    </form>
  </div>
</div>

<?php if ($selectedExam && $selectedClassSubject): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>
        <strong><?= e($selectedExam['exam_name']) ?></strong> &middot;
        <?= e($selectedClassSubject['level_name'] . ' ' . $selectedClassSubject['stream_name'] . ' — ' . $selectedClassSubject['subject_name']) ?>
        &middot; Max: <?= e($selectedExam['max_marks']) ?>
      </span>
      <span class="text-muted small">Editing as Academic Officer &mdash; changes reset to <em>pending</em></span>
    </div>
    <form method="POST">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="save_marks">
      <input type="hidden" name="exam_id" value="<?= $examId ?>">
      <input type="hidden" name="class_subject_id" value="<?= $classSubjectId ?>">
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Admission No.</th>
              <th>Student</th>
              <th style="width:160px;">Marks (out of <?= e($selectedExam['max_marks']) ?>) / "ABS"</th>
              <th>Current Status</th>
              <th style="width:80px;">Delete</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($gridStudents as $s):
              $existing = $existingMarks[$s['student_id']] ?? null;
            ?>
              <tr>
                <td><code><?= e($s['admission_no']) ?></code></td>
                <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td>
                  <input type="text" name="marks[<?= (int) $s['student_id'] ?>]" class="form-control form-control-sm"
                         value="<?= $existing ? ($existing['is_absent'] ? 'ABS' : e($existing['marks_obtained'])) : '' ?>"
                         placeholder="Score or ABS">
                </td>
                <td>
                  <?php if ($existing): ?>
                    <span class="badge badge-status-<?= $existing['verification_status']==='verified' ? 'verified' : ($existing['verification_status']==='rejected' ? 'rejected' : 'pending') ?>">
                      <?= e(ucfirst($existing['verification_status'])) ?>
                    </span>
                    <?php if ($existing['submitted_at']): ?>
                      <span class="badge bg-info ms-1">Submitted</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-secondary">Not entered</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($existing): ?>
                    <form method="POST" data-confirm="Delete this mark record permanently?">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="action" value="delete_mark">
                      <input type="hidden" name="mark_id" value="<?= (int) $existing['mark_id'] ?>">
                      <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($gridStudents)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No active students found in this class.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($gridStudents)): ?>
        <div class="card-footer bg-white">
          <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Save Changes</button>
          <span class="text-muted small ms-3">Saving resets verification to <em>pending</em> for edited records.</span>
        </div>
      <?php endif; ?>
    </form>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>