<?php
/**
 * head_of_school/reports.php
 * Consolidated operational reports: enrollment, staffing, and attendance
 * snapshots for day-to-day school management.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['head_of_school', 'department_head']);

$pdo = get_db_connection();

$enrollmentByClass = $pdo->query(
    "SELECT cl.level_name, c.stream_name, COUNT(s.student_id) AS student_count
     FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     LEFT JOIN students s ON s.class_id = c.class_id AND s.status = 'active'
     GROUP BY c.class_id ORDER BY cl.sort_order"
)->fetchAll();

$staffByDepartment = $pdo->query(
    "SELECT d.department_name, COUNT(st.staff_id) AS staff_count
     FROM departments d LEFT JOIN staff st ON st.department_id = d.department_id AND st.status='active'
     GROUP BY d.department_id"
)->fetchAll();

$genderBreakdown = $pdo->query(
    "SELECT gender, COUNT(*) AS c FROM students WHERE status='active' GROUP BY gender"
)->fetchAll();

$pageTitle = 'School Reports';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">School Reports</h1>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card mb-4">
      <div class="card-header">Enrollment by Class</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Class</th><th>Students</th></tr></thead>
          <tbody>
            <?php foreach ($enrollmentByClass as $e): ?>
              <tr><td><?= e($e['level_name'] . ' ' . $e['stream_name']) ?></td><td><?= (int) $e['student_count'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Gender Breakdown (Active Students)</div>
      <div class="card-body"><canvas id="genderChart" height="200"></canvas></div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">Staff by Department</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Department</th><th>Staff Count</th></tr></thead>
          <tbody>
            <?php foreach ($staffByDepartment as $s): ?>
              <tr><td><?= e($s['department_name']) ?></td><td><?= (int) $s['staff_count'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('genderChart'), {
  type: 'pie',
  data: {
    labels: <?= json_encode(array_map(fn($g) => ucfirst($g['gender'] ?? 'Unspecified'), $genderBreakdown)) ?>,
    datasets: [{ data: <?= json_encode(array_map('intval', array_column($genderBreakdown, 'c'))) ?>, backgroundColor: ['#102A43', '#C8932A', '#334E68'] }]
  },
  options: { plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
