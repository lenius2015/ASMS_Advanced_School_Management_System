<?php
/**
 * parent/results.php
 * Shows the selected child's published report cards and subject-level
 * breakdown. Only published results are visible to parents.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['parent']);

$pdo = get_db_connection();
$userId = current_user_id();
$studentId = (int) ($_GET['student_id'] ?? 0) ?: get_default_child($pdo, $userId);
$verifiedStudentId = $studentId ? verify_guardian_owns_student($pdo, $userId, $studentId) : null;

$pageTitle = 'Academic Results';
require APP_ROOT . '/includes/header.php';

if (!$verifiedStudentId) {
    echo '<div class="alert alert-warning">No child found or you do not have access to view this record.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$studentInfo = $pdo->prepare("SELECT u.first_name, u.last_name, s.admission_no FROM students s JOIN users u ON u.user_id = s.user_id WHERE s.student_id = :id");
$studentInfo->execute(['id' => $verifiedStudentId]);
$student = $studentInfo->fetch();

$reportCards = $pdo->prepare(
    "SELECT rc.*, t.term_name, y.year_name FROM report_cards rc
     JOIN terms t ON t.term_id = rc.term_id JOIN academic_years y ON y.year_id = t.year_id
     WHERE rc.student_id = :id AND rc.status = 'published' ORDER BY t.start_date DESC"
);
$reportCards->execute(['id' => $verifiedStudentId]);
$cards = $reportCards->fetchAll();

$selectedTermId = (int) ($_GET['term_id'] ?? ($cards[0]['term_id'] ?? 0));
$subjectResults = [];
if ($selectedTermId > 0) {
    $stmt = $pdo->prepare(
        "SELECT sub.subject_name, tr.average_marks, tr.grade_letter, tr.gpa, tr.subject_position FROM term_results tr
         JOIN class_subjects cs ON cs.class_subject_id = tr.class_subject_id
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         WHERE tr.student_id = :id AND tr.term_id = :term ORDER BY sub.subject_name"
    );
    $stmt->execute(['id' => $verifiedStudentId, 'term' => $selectedTermId]);
    $subjectResults = $stmt->fetchAll();
}
?>

<h1 class="h3 mb-1">Academic Results</h1>
<p class="text-muted mb-4"><?= e($student['first_name'] . ' ' . $student['last_name']) ?> (<?= e($student['admission_no']) ?>)</p>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Term History</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($cards as $c): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center <?= (int) $c['term_id'] === $selectedTermId ? 'active' : '' ?>">
            <a href="?student_id=<?= $verifiedStudentId ?>&term_id=<?= (int) $c['term_id'] ?>" class="text-decoration-none <?= (int) $c['term_id'] === $selectedTermId ? 'text-white' : '' ?>">
              <?= e($c['year_name'] . ' - ' . $c['term_name']) ?>
            </a>
            <span class="badge bg-light text-dark"><?= e($c['overall_average']) ?>%</span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($cards)): ?><li class="list-group-item text-muted">No published results yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>

  <div class="col-lg-8">
    <?php
    $current = null;
    foreach ($cards as $c) { if ((int) $c['term_id'] === $selectedTermId) { $current = $c; break; } }
    ?>
    <?php if ($current): ?>
      <div class="report-card-sheet mb-4">
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
        <?php if ($current['class_teacher_remarks']): ?><p class="small"><strong>Class Teacher's Remarks:</strong> <?= e($current['class_teacher_remarks']) ?></p><?php endif; ?>
        <?php if ($current['head_remarks']): ?><p class="small"><strong>Head of School's Remarks:</strong> <?= e($current['head_remarks']) ?></p><?php endif; ?>
        <div class="text-end no-print"><button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fa fa-print me-1"></i> Print Report Card</button></div>
      </div>
    <?php else: ?>
      <div class="alert alert-info">No published report card available yet for this term.</div>
    <?php endif; ?>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
