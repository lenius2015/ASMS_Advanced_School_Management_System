<?php
/**
 * academic/transcripts.php
 * Generate a multi-term academic transcript for a single student,
 * showing subject-by-subject performance across all published terms.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$studentId = (int) ($_GET['student_id'] ?? 0);

$students = $pdo->query(
    "SELECT s.student_id, u.first_name, u.last_name, s.admission_no FROM students s
     JOIN users u ON u.user_id = s.user_id ORDER BY u.first_name"
)->fetchAll();

$transcript = [];
$studentInfo = null;
if ($studentId > 0) {
    $infoStmt = $pdo->prepare(
        "SELECT s.*, u.first_name, u.last_name, cl.level_name, c.stream_name FROM students s
         JOIN users u ON u.user_id = s.user_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE s.student_id = :id"
    );
    $infoStmt->execute(['id' => $studentId]);
    $studentInfo = $infoStmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT t.term_name, y.year_name, sub.subject_name, tr.average_marks, tr.grade_letter, tr.gpa
         FROM term_results tr
         JOIN class_subjects cs ON cs.class_subject_id = tr.class_subject_id
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         JOIN terms t ON t.term_id = tr.term_id
         JOIN academic_years y ON y.year_id = t.year_id
         WHERE tr.student_id = :id
         ORDER BY t.start_date, sub.subject_name"
    );
    $stmt->execute(['id' => $studentId]);
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['year_name'] . ' - ' . $row['term_name'];
        $transcript[$key][] = $row;
    }
}

$pageTitle = 'Transcripts';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Student Transcripts</h1>

<div class="card mb-4 no-print">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-6">
        <select name="student_id" class="form-select" onchange="this.form.submit()">
          <option value="">-- Select Student --</option>
          <?php foreach ($students as $s): ?>
            <option value="<?= (int) $s['student_id'] ?>" <?= $studentId === (int) $s['student_id'] ? 'selected' : '' ?>><?= e($s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['admission_no'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($studentId > 0): ?>
        <div class="col-md-3"><button type="button" class="btn btn-outline-primary w-100" onclick="window.print()"><i class="fa fa-print me-1"></i> Print</button></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($studentInfo): ?>
  <div class="report-card-sheet">
    <div class="text-center mb-4">
      <h2 class="mb-0"><?= e(get_setting($pdo, 'school_name')) ?></h2>
      <p class="text-muted mb-0"><?= e(get_setting($pdo, 'school_motto')) ?></p>
      <h3 class="h5 mt-3">Academic Transcript</h3>
    </div>
    <p class="mb-1"><strong>Student:</strong> <?= e($studentInfo['first_name'] . ' ' . $studentInfo['last_name']) ?></p>
    <p class="mb-1"><strong>Admission No.:</strong> <?= e($studentInfo['admission_no']) ?></p>
    <p class="mb-3"><strong>Current Class:</strong> <?= e($studentInfo['level_name'] ? $studentInfo['level_name'] . ' ' . $studentInfo['stream_name'] : '-') ?></p>

    <?php foreach ($transcript as $termLabel => $rows): ?>
      <h6 class="text-uppercase text-muted mt-4"><?= e($termLabel) ?></h6>
      <table class="table table-sm">
        <thead><tr><th>Subject</th><th>Average %</th><th>Grade</th><th>GPA</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr><td><?= e($r['subject_name']) ?></td><td><?= e($r['average_marks']) ?></td><td><?= e($r['grade_letter']) ?></td><td><?= e($r['gpa']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
    <?php if (empty($transcript)): ?><p class="text-muted text-center py-4">No verified results recorded yet for this student.</p><?php endif; ?>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
