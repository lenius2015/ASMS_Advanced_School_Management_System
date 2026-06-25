<?php
/**
 * head_of_school/attendance_overview.php
 * Cross-class attendance overview with a trend chart and a per-class
 * breakdown for the selected date.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['head_of_school', 'department_head', 'director', 'system_admin']);

$pdo = get_db_connection();
$selectedDate = $_GET['date'] ?? date('Y-m-d');

$byClass = $pdo->prepare(
    "SELECT cl.level_name, c.stream_name,
        SUM(sa.status='present') AS present_count,
        SUM(sa.status='absent') AS absent_count,
        SUM(sa.status='late') AS late_count,
        COUNT(*) AS total
     FROM student_attendance sa
     JOIN classes c ON c.class_id = sa.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE sa.attendance_date = :date
     GROUP BY c.class_id ORDER BY cl.sort_order"
);
$byClass->execute(['date' => $selectedDate]);
$classRows = $byClass->fetchAll();

$trend = $pdo->query(
    "SELECT attendance_date, SUM(status='present') AS present_count, COUNT(*) AS total
     FROM student_attendance
     WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     GROUP BY attendance_date ORDER BY attendance_date"
)->fetchAll();

$pageTitle = 'Attendance Overview';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Attendance Overview</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Date</label>
        <input type="date" name="date" class="form-control" value="<?= e($selectedDate) ?>" onchange="this.form.submit()">
      </div>
    </form>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Attendance by Class &mdash; <?= e(format_date($selectedDate)) ?></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Class</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr></thead>
          <tbody>
            <?php foreach ($classRows as $c): $rate = $c['total'] > 0 ? round(($c['present_count']/$c['total'])*100,1) : 0; ?>
              <tr>
                <td><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></td>
                <td class="text-success"><?= (int) $c['present_count'] ?></td>
                <td class="text-danger"><?= (int) $c['absent_count'] ?></td>
                <td class="text-warning"><?= (int) $c['late_count'] ?></td>
                <td><?= e($rate) ?>%</td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($classRows)): ?><tr><td colspan="5" class="text-center text-muted py-3">No attendance recorded for this date.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">14-Day Attendance Trend</div>
      <div class="card-body"><canvas id="trendChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const trendLabels = <?= json_encode(array_map(fn($r) => date('d M', strtotime($r['attendance_date'])), $trend)) ?>;
const trendRates = <?= json_encode(array_map(fn($r) => $r['total'] > 0 ? round(($r['present_count']/$r['total'])*100,1) : 0, $trend)) ?>;
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: { labels: trendLabels, datasets: [{ label: 'Attendance %', data: trendRates, borderColor: '#1F8A55', backgroundColor: 'rgba(31,138,85,0.15)', fill: true, tension: 0.3 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
