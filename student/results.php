<?php
/**
 * student/results.php
 * The student's own published report cards across terms.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['student']);

$pdo = get_db_connection();
$userId = current_user_id();

$studentStmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :uid');
$studentStmt->execute(['uid' => $userId]);
$student = $studentStmt->fetch();

$pageTitle = 'My Results';
require APP_ROOT . '/includes/header.php';

if (!$student) {
    echo '<div class="alert alert-warning">Your student profile could not be found.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}
$studentId = (int) $student['student_id'];

$cards = $pdo->prepare(
    "SELECT rc.*, t.term_name, y.year_name FROM report_cards rc
     JOIN terms t ON t.term_id = rc.term_id JOIN academic_years y ON y.year_id = t.year_id
     WHERE rc.student_id = :id AND rc.status = 'published' ORDER BY t.start_date DESC"
);
$cards->execute(['id' => $studentId]);
$reportCards = $cards->fetchAll();

$selectedTermId = (int) ($_GET['term_id'] ?? ($reportCards[0]['term_id'] ?? 0));
$subjectResults = [];
if ($selectedTermId > 0) {
    $stmt = $pdo->prepare(
        "SELECT sub.subject_name, tr.average_marks, tr.grade_letter, tr.gpa FROM term_results tr
         JOIN class_subjects cs ON cs.class_subject_id = tr.class_subject_id
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         WHERE tr.student_id = :id AND tr.term_id = :term ORDER BY sub.subject_name"
    );
    $stmt->execute(['id' => $studentId, 'term' => $selectedTermId]);
    $subjectResults = $stmt->fetchAll();
}
?>

<h1 class="h3 mb-4">My Results</h1>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Term History</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($reportCards as $c): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center <?= (int) $c['term_id'] === $selectedTermId ? 'active' : '' ?>">
            <a href="?term_id=<?= (int) $c['term_id'] ?>" class="text-decoration-none <?= (int) $c['term_id'] === $selectedTermId ? 'text-white' : '' ?>"><?= e($c['year_name'] . ' - ' . $c['term_name']) ?></a>
            <span class="badge bg-light text-dark"><?= e($c['overall_average']) ?>%</span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($reportCards)): ?><li class="list-group-item text-muted">No published results yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>

  <div class="col-lg-8">
    <?php $current = null; foreach ($reportCards as $c) { if ((int) $c['term_id'] === $selectedTermId) { $current = $c; break; } } ?>
    <?php if ($current): ?>
      <div class="report-card-sheet">
        <div class="text-center mb-3">
          <h2 class="h5 mb-0"><?= e(get_setting($pdo, 'school_name')) ?></h2>
          <p class="text-muted small mb-0">Report Card &mdash; <?= e($current['year_name'] . ' - ' . $current['term_name']) ?></p>
        </div>
        <div class="row text-center mb-3">
          <div class="col-4"><div class="small text-muted">Overall Average</div><div class="h4"><?= e($current['overall_average']) ?>%</div></div>
          <div class="col-4"><div class="small text-muted">Position</div><div class="h4">#<?= e($current['overall_position']) ?> / <?= e($current['class_size']) ?></div></div>
          <div class="col-4"><div class="small text-muted">Attendance</div><div class="h4"><?= e($current['attendance_percent']) ?>%</div></div>
        </div>
        <table class="table table-sm">
          <thead><tr><th>Subject</th><th>Average %</th><th>Grade</th><th>GPA</th></tr></thead>
          <tbody>
            <?php foreach ($subjectResults as $sr): ?>
              <tr><td><?= e($sr['subject_name']) ?></td><td><?= e($sr['average_marks']) ?></td><td><?= e($sr['grade_letter']) ?></td><td><?= e($sr['gpa']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="text-end no-print"><button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fa fa-print me-1"></i> Print</button></div>
      </div>
    <?php else: ?>
      <div class="alert alert-info">No published report card available yet.</div>
    <?php endif; ?>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
