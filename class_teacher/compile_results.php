<?php
/**
 * class_teacher/compile_results.php
 * The class teacher compiles results from subject teachers, adds remarks,
 * and submits the compiled results to the Academic Department for publishing.
 *
 * Workflow:
 * 1. Subject teacher submits marks → status becomes 'submitted'
 * 2. Class teacher sees all submitted marks per subject per student
 * 3. Class teacher adds remarks and compiles
 * 4. Academic Department verifies and publishes
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();
$period = get_current_period($pdo);

// Get the teacher's assigned class
$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Compile Results';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher for any class.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ====== Save class teacher remarks & compile ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'compile_results') {
    csrf_verify();
    $examId = (int) ($_POST['exam_id'] ?? 0);
    $remarks = $_POST['remarks'] ?? []; // [student_id => remark]

    if ($examId <= 0) {
        flash_set('error', 'Invalid exam selected.');
        redirect(app_url('/class_teacher/compile_results.php'));
    }

    // Update or insert class teacher remarks into report_cards
    foreach ($remarks as $studentId => $remark) {
        $studentId = (int) $studentId;
        $remark = trim($remark ?? '');
        
        // Check if report_card exists
        $check = $pdo->prepare('SELECT report_card_id FROM report_cards WHERE student_id = :sid AND term_id = :term');
        $check->execute(['sid' => $studentId, 'term' => $period['term_id']]);
        $existing = $check->fetch();

        if ($existing) {
            $pdo->prepare('UPDATE report_cards SET class_teacher_remarks = :remark WHERE report_card_id = :id')
                ->execute(['remark' => $remark ?: null, 'id' => $existing['report_card_id']]);
        } else {
            // Create a draft report card
            $pdo->prepare(
                'INSERT INTO report_cards (student_id, term_id, class_teacher_remarks, status)
                 VALUES (:sid, :term, :remark, :status)'
            )->execute(['sid' => $studentId, 'term' => $period['term_id'], 'remark' => $remark ?: null, 'status' => 'draft']);
        }
    }

    // Update exam status to mark as compiled by class teacher
    $pdo->prepare("UPDATE exams SET status = 'marks_pending' WHERE exam_id = :id AND status = 'submitted'")
        ->execute(['id' => $examId]);

    audit_log('compile_results', 'class_teacher', 'exam_marks', $examId, 'Class teacher compiled results with remarks');
    flash_set('success', 'Results compiled and submitted to Academic Department for verification.');
    redirect(app_url('/class_teacher/compile_results.php'));
}

// ====== Get exams that have submitted marks for this class ======
$exams = $pdo->prepare(
    "SELECT DISTINCT e.exam_id, e.exam_name, e.max_marks, e.status, et.type_name,
            em.submitted_at
     FROM exam_marks em
     JOIN exams e ON e.exam_id = em.exam_id
     JOIN exam_types et ON et.exam_type_id = e.exam_type_id
     JOIN class_subjects cs ON cs.class_subject_id = em.class_subject_id
     WHERE cs.class_id = :cid AND em.submitted_at IS NOT NULL AND e.status IN ('submitted', 'marks_pending', 'verified')
     GROUP BY e.exam_id
     ORDER BY e.created_at DESC"
);
$exams->execute(['cid' => $myClass['class_id']]);
$availableExams = $exams->fetchAll();

$selectedExamId = (int) ($_GET['exam_id'] ?? 0);
$studentsData = [];
$selectedExam = null;

if ($selectedExamId > 0) {
    // Get exam info
    foreach ($availableExams as $ex) {
        if ((int) $ex['exam_id'] === $selectedExamId) {
            $selectedExam = $ex;
            break;
        }
    }

    if ($selectedExam) {
        // Get all students with their marks per subject
        $stmt = $pdo->prepare(
            "SELECT s.student_id, u.first_name, u.last_name, s.admission_no,
                    sub.subject_name, sub.subject_code,
                    em.marks_obtained, em.is_absent, em.verification_status,
                    cs.class_subject_id,
                    rc.class_teacher_remarks, rc.overall_average
             FROM students s
             JOIN users u ON u.user_id = s.user_id
             CROSS JOIN class_subjects cs ON cs.class_id = s.class_id
             JOIN subjects sub ON sub.subject_id = cs.subject_id
             LEFT JOIN exam_marks em ON em.student_id = s.student_id AND em.class_subject_id = cs.class_subject_id AND em.exam_id = :exam
             LEFT JOIN report_cards rc ON rc.student_id = s.student_id AND rc.term_id = :term
             WHERE s.class_id = :cid AND s.status = 'active'
             ORDER BY u.first_name, sub.subject_name"
        );
        $stmt->execute(['exam' => $selectedExamId, 'term' => $period['term_id'], 'cid' => $myClass['class_id']]);
        $rawData = $stmt->fetchAll();

        // Organize by student
        foreach ($rawData as $row) {
            $sid = $row['student_id'];
            if (!isset($studentsData[$sid])) {
                $studentsData[$sid] = [
                    'student_id' => $sid,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'admission_no' => $row['admission_no'],
                    'class_teacher_remarks' => $row['class_teacher_remarks'],
                    'overall_average' => $row['overall_average'],
                    'subjects' => [],
                ];
            }
            $studentsData[$sid]['subjects'][] = [
                'subject_name' => $row['subject_name'],
                'subject_code' => $row['subject_code'],
                'marks_obtained' => $row['marks_obtained'],
                'is_absent' => $row['is_absent'],
                'verification_status' => $row['verification_status'],
            ];
        }
    }
}
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Compile Results: <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>
      <p class="mb-0">Review subject marks, add remarks, and submit to Academic Department</p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/class_teacher/dashboard.php')) ?>" class="btn btn-outline-light"><i class="fa fa-arrow-left me-1"></i> Dashboard</a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-file-alt kpi-icon"></i>
      <div class="kpi-label">Available Exams</div>
      <div class="kpi-value" data-counter="<?= count($availableExams) ?>">0</div>
      <div class="kpi-sub">With submitted marks</div>
    </div>
  </div>
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-users kpi-icon"></i>
      <div class="kpi-label">Students</div>
      <div class="kpi-value" data-counter="<?= count($studentsData) ?>">0</div>
      <div class="kpi-sub">In selected exam</div>
    </div>
  </div>
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Status</div>
      <div class="kpi-value" style="font-size:1rem;">
        <?= $selectedExam ? e(ucfirst(str_replace('_', ' ', $selectedExam['status']))) : '—' ?>
      </div>
      <div class="kpi-sub">Current exam stage</div>
    </div>
  </div>
</div>

<!-- Exam Selector -->
<div class="card mb-4 animate-fade-in animate-delay-1">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-6">
        <label class="form-label">Select Exam to Compile</label>
        <select name="exam_id" class="form-select" onchange="this.form.submit()">
          <option value="0">-- Choose an Exam --</option>
          <?php foreach ($availableExams as $ex): ?>
            <option value="<?= (int) $ex['exam_id'] ?>" <?= $selectedExamId === (int) $ex['exam_id'] ? 'selected' : '' ?>>
              <?= e($ex['exam_name']) ?> (<?= e($ex['type_name']) ?>)
              <?php if ($ex['status'] === 'marks_pending'): ?>— Compiled<?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($selectedExam): ?>
        <div class="col-md-3 align-self-end">
          <span class="badge bg-<?= $selectedExam['status'] === 'verified' ? 'success' : ($selectedExam['status'] === 'marks_pending' ? 'warning' : 'info') ?> p-2">
            Status: <?= e(ucfirst(str_replace('_', ' ', $selectedExam['status']))) ?>
          </span>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($selectedExam && !empty($studentsData)): ?>
  <!-- CRUD Action Bar -->
  <div class="action-bar animate-fade-in animate-delay-1 mb-4">
    <div class="action-left">
      <div class="search-box">
        <i class="fa fa-search search-icon"></i>
        <input type="text" class="form-control form-control-sm" placeholder="Search students..." data-search="#resultsTable" style="width:220px;">
        <i class="fa fa-times search-clear"></i>
      </div>
      <span class="filter-badge"><i class="fa fa-filter"></i> <?= e($selectedExam['exam_name']) ?></span>
    </div>
    <div class="action-right">
      <span class="text-muted small me-2">Add remarks and compile</span>
      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
    </div>
  </div>

  <form method="POST">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="compile_results">
    <input type="hidden" name="exam_id" value="<?= $selectedExamId ?>">

    <div class="card animate-fade-in animate-delay-2">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-file-alt text-gold me-2"></i>Results for <?= e($selectedExam['exam_name']) ?></span>
        <span class="text-muted small">Max marks: <?= e($selectedExam['max_marks']) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0" id="resultsTable">
          <thead>
            <tr>
              <th>Student</th>
              <th>Admission No.</th>
              <th>Subjects / Marks</th>
              <th>Average</th>
              <th style="min-width:200px;">Class Teacher Remarks</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($studentsData as $sd): 
              $totalMarks = 0;
              $subjectCount = 0;
              foreach ($sd['subjects'] as $sub) {
                if ($sub['marks_obtained'] !== null && !$sub['is_absent']) {
                  $totalMarks += (float) $sub['marks_obtained'];
                  $subjectCount++;
                }
              }
              $avg = $subjectCount > 0 ? round($totalMarks / $subjectCount, 1) : null;
            ?>
              <tr>
                <td><strong><?= e($sd['first_name'] . ' ' . $sd['last_name']) ?></strong></td>
                <td><code><?= e($sd['admission_no']) ?></code></td>
                <td>
                  <div class="small" style="max-width:300px;">
                    <?php foreach ($sd['subjects'] as $sub): ?>
                      <div class="d-flex justify-content-between gap-2 mb-1">
                        <span><?= e($sub['subject_name']) ?>:</span>
                        <span class="fw-bold <?= $sub['is_absent'] ? 'text-danger' : '' ?>">
                          <?php if ($sub['is_absent']): ?>
                            ABS
                          <?php elseif ($sub['marks_obtained'] !== null): ?>
                            <?= e($sub['marks_obtained']) ?>
                          <?php else: ?>
                            <span class="text-muted">—</span>
                          <?php endif; ?>
                        </span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </td>
                <td class="fw-bold">
                  <?= $avg !== null ? e($avg) . '%' : '<span class="text-muted">—</span>' ?>
                </td>
                <td>
                  <textarea name="remarks[<?= (int) $sd['student_id'] ?>]" class="form-control form-control-sm" rows="2" 
                    placeholder="e.g. Good progress, needs improvement in..."><?= e($sd['class_teacher_remarks'] ?? '') ?></textarea>
                </td>
                <td>
                  <?php if ($sd['class_teacher_remarks']): ?>
                    <span class="badge bg-success">Reviewed</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Pending</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="text-muted small">Add remarks for each student and submit to Academic Department</span>
        <button type="submit" class="btn btn-gold">
          <i class="fa fa-paper-plane me-1"></i> Compile & Submit to Academic
        </button>
      </div>
    </div>
  </form>
<?php elseif ($selectedExamId > 0): ?>
  <div class="alert alert-warning animate-fade-in">
    <i class="fa fa-exclamation-triangle me-2"></i>No student data found for this exam. Either the exam has no submitted marks or there are no active students in your class.
  </div>
<?php elseif (empty($availableExams)): ?>
  <div class="alert alert-info animate-fade-in">
    <i class="fa fa-info-circle me-2"></i>No exams have submitted marks yet. Once subject teachers submit their marks, they will appear here for compilation.
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>