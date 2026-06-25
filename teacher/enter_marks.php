<?php
/**
 * teacher/enter_marks.php
 * Steps 1 & 2 of the results workflow: the subject teacher enters marks
 * for their students for a given exam, then submits the batch to the
 * Academic Department for verification. Once submitted, marks are
 * locked from further editing unless the batch is rejected.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['subject_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

$examId = (int) ($_GET['exam_id'] ?? 0);
$classSubjectId = (int) ($_GET['class_subject_id'] ?? 0);

// ---- Save marks (draft, not yet submitted) -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_marks') {
    csrf_verify();
    $examIdPost = (int) ($_POST['exam_id'] ?? 0);
    $classSubjectIdPost = (int) ($_POST['class_subject_id'] ?? 0);

    // Ownership check: this class_subject must belong to this teacher
    $own = $pdo->prepare('SELECT 1 FROM class_subjects WHERE class_subject_id = :cs AND teacher_id = :tid');
    $own->execute(['cs' => $classSubjectIdPost, 'tid' => $teacherId]);
    if (!$own->fetch()) {
        flash_set('error', 'You are not assigned to this class/subject.');
        redirect(app_url('/teacher/enter_marks.php'));
    }

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
            'INSERT INTO exam_marks (exam_id, student_id, class_subject_id, marks_obtained, is_absent, entered_by)
             VALUES (:exam, :sid, :cs, :marks, :absent, :by)
             ON DUPLICATE KEY UPDATE marks_obtained = :marks2, is_absent = :absent2, entered_by = :by2, entered_at = NOW()'
        )->execute([
            'exam' => $examIdPost, 'sid' => $studentId, 'cs' => $classSubjectIdPost,
            'marks' => $score, 'absent' => $isAbsent ? 1 : 0, 'by' => $teacherId,
            'marks2' => $score, 'absent2' => $isAbsent ? 1 : 0, 'by2' => $teacherId,
        ]);
        $savedCount++;
    }

    audit_log('save_marks', 'academics', 'exam_marks', $examIdPost, "Saved {$savedCount} mark(s) as draft");
    flash_set('success', "Saved marks for {$savedCount} student(s). You can keep editing until you submit.");
    redirect(app_url('/teacher/enter_marks.php') . "?exam_id={$examIdPost}&class_subject_id={$classSubjectIdPost}");
}

// ---- Submit to Academic Department (locks editing) ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_marks') {
    csrf_verify();
    $examIdPost = (int) ($_POST['exam_id'] ?? 0);
    $classSubjectIdPost = (int) ($_POST['class_subject_id'] ?? 0);

    $stmt = $pdo->prepare(
        'UPDATE exam_marks SET submitted_at = NOW(), verification_status = "pending"
         WHERE exam_id = :exam AND class_subject_id = :cs AND entered_by = :by AND submitted_at IS NULL'
    );
    $stmt->execute(['exam' => $examIdPost, 'cs' => $classSubjectIdPost, 'by' => $teacherId]);
    $count = $stmt->rowCount();

    $pdo->prepare("UPDATE exams SET status = 'submitted' WHERE exam_id = :id AND status != 'published'")->execute(['id' => $examIdPost]);

    // Get the class ID for this class_subject and notify the class teacher
    $classInfo = $pdo->prepare(
        "SELECT c.class_id, c.class_teacher_id 
         FROM class_subjects cs 
         JOIN classes c ON c.class_id = cs.class_id 
         WHERE cs.class_subject_id = :csid"
    );
    $classInfo->execute(['csid' => $classSubjectIdPost]);
    $classData = $classInfo->fetch();
    
    if ($classData && $classData['class_teacher_id']) {
        $examName = $pdo->prepare("SELECT exam_name FROM exams WHERE exam_id = :eid");
        $examName->execute(['eid' => $examIdPost]);
        $ename = $examName->fetch()['exam_name'] ?? 'Exam';
        notify_user($pdo, (int) $classData['class_teacher_id'], 'Marks Submitted', 
            "Subject teacher has submitted marks for {$ename}. Please compile the results.", 
            'result', app_url('/class_teacher/compile_results.php?exam_id=' . $examIdPost));
    }

    audit_log('submit_marks', 'academics', 'exam_marks', $examIdPost, "Submitted {$count} mark(s) to Class Teacher & Academic Department");
    flash_set('success', "Submitted {$count} mark(s). The class teacher has been notified to compile the results.");
    redirect(app_url('/teacher/enter_marks.php'));
}

// ---- Data for the picker and the grid -----------------------------------
$myClassSubjects = $pdo->prepare(
    "SELECT cs.class_subject_id, sub.subject_name, cl.level_name, c.stream_name, c.class_id
     FROM class_subjects cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE cs.teacher_id = :tid ORDER BY cl.sort_order"
);
$myClassSubjects->execute(['tid' => $teacherId]);
$assignments = $myClassSubjects->fetchAll();

$exams = $pdo->query("SELECT exam_id, exam_name, max_marks, status FROM exams WHERE status NOT IN ('published') ORDER BY created_at DESC")->fetchAll();

$gridStudents = [];
$existingMarks = [];
$isLocked = false;
$selectedClassSubject = null;
$selectedExam = null;

if ($examId > 0 && $classSubjectId > 0) {
    foreach ($assignments as $a) {
        if ((int) $a['class_subject_id'] === $classSubjectId) {
            $selectedClassSubject = $a;
            break;
        }
    }
    foreach ($exams as $ex) {
        if ((int) $ex['exam_id'] === $examId) {
            $selectedExam = $ex;
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
            if ($m['submitted_at'] !== null && $m['verification_status'] !== 'rejected') {
                $isLocked = true;
            }
        }
    }
}

$pageTitle = 'Enter Marks';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Enter Marks</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-5">
        <label class="form-label">Class / Subject</label>
        <select name="class_subject_id" class="form-select" required>
          <option value="">-- Select --</option>
          <?php foreach ($assignments as $a): ?>
            <option value="<?= (int) $a['class_subject_id'] ?>" <?= $classSubjectId === (int) $a['class_subject_id'] ? 'selected' : '' ?>>
              <?= e($a['level_name'] . ' ' . $a['stream_name'] . ' — ' . $a['subject_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Exam</label>
        <select name="exam_id" class="form-select" required>
          <option value="">-- Select --</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= (int) $ex['exam_id'] ?>" <?= $examId === (int) $ex['exam_id'] ? 'selected' : '' ?>><?= e($ex['exam_name']) ?> (max <?= e($ex['max_marks']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 align-self-end"><button class="btn btn-outline-primary w-100">Load</button></div>
    </form>
  </div>
</div>

<?php if ($selectedClassSubject && $selectedExam): ?>
  <?php if ($isLocked): ?>
    <div class="alert alert-info"><i class="fa fa-lock me-2"></i>These marks have been submitted and are awaiting (or have passed) verification. They are locked for editing. If corrections are needed, ask the Academic Department to reject the batch.</div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><?= e($selectedClassSubject['level_name'] . ' ' . $selectedClassSubject['stream_name'] . ' — ' . $selectedClassSubject['subject_name']) ?> &middot; <?= e($selectedExam['exam_name']) ?></span>
      <?php if (!$isLocked): ?>
        <form method="POST" data-confirm="Submit these marks to the Academic Department? You won't be able to edit them afterwards unless rejected.">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="submit_marks">
          <input type="hidden" name="exam_id" value="<?= $examId ?>">
          <input type="hidden" name="class_subject_id" value="<?= $classSubjectId ?>">
          <button class="btn btn-sm btn-gold"><i class="fa fa-paper-plane me-1"></i> Submit for Verification</button>
        </form>
      <?php endif; ?>
    </div>
    <form method="POST">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="save_marks">
      <input type="hidden" name="exam_id" value="<?= $examId ?>">
      <input type="hidden" name="class_subject_id" value="<?= $classSubjectId ?>">
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Admission No.</th><th>Student</th><th style="width:160px;">Marks (out of <?= e($selectedExam['max_marks']) ?>) / "ABS"</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($gridStudents as $s): $existing = $existingMarks[$s['student_id']] ?? null; ?>
              <tr>
                <td><code><?= e($s['admission_no']) ?></code></td>
                <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td>
                  <input type="text" name="marks[<?= (int) $s['student_id'] ?>]" class="form-control form-control-sm"
                         value="<?= $existing ? ($existing['is_absent'] ? 'ABS' : e($existing['marks_obtained'])) : '' ?>"
                         <?= $isLocked ? 'disabled' : '' ?>>
                </td>
                <td>
                  <?php if ($existing): ?>
                    <span class="badge badge-status-<?= $existing['verification_status']==='verified' ? 'verified' : ($existing['verification_status']==='rejected' ? 'rejected' : 'pending') ?>">
                      <?= e(ucfirst($existing['verification_status'])) ?>
                    </span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Not entered</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($gridStudents)): ?><tr><td colspan="4" class="text-center text-muted py-4">No active students found in this class.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$isLocked && !empty($gridStudents)): ?>
        <div class="card-footer bg-white"><button type="submit" class="btn btn-primary">Save Draft</button></div>
      <?php endif; ?>
    </form>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
