<?php
/**
 * director/performance_reports.php
 * School-wide academic performance analytics: average scores by class
 * and subject, for the current term's published results.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'school_board', 'head_of_school']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

$byClass = $pdo->prepare(
    "SELECT cl.level_name, c.stream_name, ROUND(AVG(rc.overall_average),1) AS avg_score, COUNT(*) AS student_count
     FROM report_cards rc
     JOIN students s ON s.student_id = rc.student_id
     JOIN classes c ON c.class_id = s.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE rc.term_id = :term AND rc.status = 'published'
     GROUP BY c.class_id ORDER BY cl.sort_order, c.stream_name"
);
$byClass->execute(['term' => $period['term_id']]);
$classRows = $byClass->fetchAll();

$bySubject = $pdo->prepare(
    "SELECT sub.subject_name, ROUND(AVG(tr.average_marks),1) AS avg_score, COUNT(*) AS entries
     FROM term_results tr
     JOIN class_subjects cs ON cs.class_subject_id = tr.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     WHERE tr.term_id = :term
     GROUP BY sub.subject_id ORDER BY avg_score DESC"
);
$bySubject->execute(['term' => $period['term_id']]);
$subjectRows = $bySubject->fetchAll();

$topStudents = $pdo->prepare(
    "SELECT u.first_name, u.last_name, s.admission_no, rc.overall_average, rc.overall_position, cl.level_name, c.stream_name
     FROM report_cards rc
     JOIN students s ON s.student_id = rc.student_id
     JOIN users u ON u.user_id = s.user_id
     JOIN classes c ON c.class_id = s.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE rc.term_id = :term AND rc.status = 'published'
     ORDER BY rc.overall_average DESC LIMIT 10"
);
$topStudents->execute(['term' => $period['term_id']]);
$topRows = $topStudents->fetchAll();

$pageTitle = 'Performance Reports';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">School Performance Reports</h1>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Average Score by Class</div>
      <div class="card-body"><canvas id="classChart" height="240"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Average Score by Subject</div>
      <div class="card-body"><canvas id="subjectChart" height="240"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Top 10 Performers (Current Term, Published Results)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Student</th><th>Admission No.</th><th>Class</th><th>Average</th><th>Position</th></tr></thead>
      <tbody>
        <?php $i = 1; foreach ($topRows as $t): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= e($t['first_name'] . ' ' . $t['last_name']) ?></td>
            <td><code><?= e($t['admission_no']) ?></code></td>
            <td><?= e($t['level_name'] . ' ' . $t['stream_name']) ?></td>
            <td class="fw-semibold"><?= e($t['overall_average']) ?>%</td>
            <td><?= $t['overall_position'] ? '#' . e($t['overall_position']) : '-' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($topRows)): ?><tr><td colspan="6" class="text-center text-muted py-4">No published results yet for this term.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('classChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($r) => $r['level_name'] . ' ' . $r['stream_name'], $classRows)) ?>,
    datasets: [{ label: 'Average %', data: <?= json_encode(array_map('floatval', array_column($classRows, 'avg_score'))) ?>, backgroundColor: '#102A43' }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
});
new Chart(document.getElementById('subjectChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($subjectRows, 'subject_name')) ?>,
    datasets: [{ label: 'Average %', data: <?= json_encode(array_map('floatval', array_column($subjectRows, 'avg_score'))) ?>, backgroundColor: '#C8932A' }]
  },
  options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, max: 100 } } }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
