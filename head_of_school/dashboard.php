 <?php
/**
 * head_of_school/dashboard.php
 * Day-to-day operations dashboard for the Head of School with modern
 * interactive charts and real-time data.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['head_of_school', 'department_head']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

// ====== KPI Data ======
$totalStudents = (int) $pdo->query("SELECT COUNT(*) c FROM students WHERE status='active'")->fetch()['c'];
$totalStaff    = (int) $pdo->query("SELECT COUNT(*) c FROM staff WHERE status='active'")->fetch()['c'];
$totalClasses  = (int) $pdo->query("SELECT COUNT(*) c FROM classes")->fetch()['c'];

$todayAttendance = $pdo->query(
    "SELECT SUM(status='present') AS present_count, COUNT(*) AS total_count
     FROM student_attendance WHERE attendance_date = CURDATE()"
)->fetch();
$attendanceRate = ($todayAttendance && $todayAttendance['total_count'] > 0)
    ? round(($todayAttendance['present_count'] / $todayAttendance['total_count']) * 100, 1) : null;

$openDiscipline = (int) $pdo->query(
    "SELECT COUNT(*) c FROM discipline_records WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
)->fetch()['c'];

// ====== Pending academic tasks ======
$pendingMarks = (int) $pdo->query(
    "SELECT COUNT(*) c FROM exam_marks WHERE verification_status = 'pending' AND submitted_at IS NOT NULL"
)->fetch()['c'];

$draftReports = (int) $pdo->query(
    "SELECT COUNT(*) c FROM report_cards WHERE status = 'draft'"
)->fetch()['c'];

// ====== Weekly attendance trend ======
$weeklyAtt = $pdo->query(
    "SELECT attendance_date,
            SUM(status='present') AS present,
            COUNT(*) AS total
     FROM student_attendance
     WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY attendance_date ORDER BY attendance_date"
)->fetchAll();
$attDates = [];
$attPresent = [];
$attTotal = [];
foreach ($weeklyAtt as $a) {
    $attDates[] = date('D', strtotime($a['attendance_date']));
    $attPresent[] = (int) $a['present'];
    $attTotal[] = (int) $a['total'];
}

// ====== Class distribution ======
$classDist = $pdo->query(
    "SELECT cl.level_name, COUNT(s.student_id) AS cnt
     FROM students s
     JOIN classes c ON c.class_id = s.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE s.status='active'
     GROUP BY cl.level_name ORDER BY cl.sort_order"
)->fetchAll();
$classLabels = [];
$classCounts = [];
foreach ($classDist as $cd) {
    $classLabels[] = $cd['level_name'];
    $classCounts[] = (int) $cd['cnt'];
}

// ====== Recent discipline ======
$recentDiscipline = $pdo->query(
    "SELECT d.*, u.first_name, u.last_name, s.admission_no FROM discipline_records d
     JOIN students s ON s.student_id = d.student_id
     JOIN users u ON u.user_id = s.user_id
     ORDER BY d.created_at DESC LIMIT 6"
)->fetchAll();

// ====== Announcements ======
$announcements = $pdo->query(
    "SELECT a.*, u.first_name, u.last_name FROM announcements a
     JOIN users u ON u.user_id = a.posted_by
     ORDER BY a.created_at DESC LIMIT 5"
)->fetchAll();

// ====== Discipline category breakdown ======
$discCategories = $pdo->query(
    "SELECT category, COUNT(*) AS cnt FROM discipline_records
     WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY category"
)->fetchAll();
$discLabels = ['minor', 'moderate', 'severe'];
$discCounts = array_fill(0, 3, 0);
foreach ($discCategories as $dc) {
    $idx = array_search($dc['category'], $discLabels);
    if ($idx !== false) $discCounts[$idx] = (int) $dc['cnt'];
}

$pageTitle = 'Head of School Dashboard';
require APP_ROOT . '/includes/header.php';
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Welcome back, <?= e($_SESSION['full_name']) ?> <span class="badge bg-gold ms-2">Head of School</span></h1>
      <p class="mb-0">Daily operations overview &middot; <?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/head_of_school/reports.php')) ?>" class="btn btn-gold"><i class="fa fa-file-alt me-1"></i> Reports</a>
      <a href="<?= e(app_url('/communication/announcements.php')) ?>" class="btn btn-outline-light"><i class="fa fa-bullhorn me-1"></i> Announce</a>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search..." data-search="#dashboardTables" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> Today's Overview</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/head_of_school/students.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-user-graduate me-1"></i> Students</a>
    <a href="<?= e(app_url('/head_of_school/staff.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-id-badge me-1"></i> Staff</a>
    <a href="<?= e(app_url('/head_of_school/discipline.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-gavel me-1"></i> Discipline</a>
    <a href="<?= e(app_url('/head_of_school/attendance_overview.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-check me-1"></i> Attendance</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-user-graduate kpi-icon"></i>
      <div class="kpi-label">Active Students</div>
      <div class="kpi-value" data-counter="<?= $totalStudents ?>">0</div>
      <div class="kpi-sub">Across <?= $totalClasses ?> classes</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-chalkboard-teacher kpi-icon"></i>
      <div class="kpi-label">Active Staff</div>
      <div class="kpi-value" data-counter="<?= $totalStaff ?>">0</div>
      <div class="kpi-sub">Teaching & support</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card <?= $attendanceRate !== null && $attendanceRate >= 90 ? 'accent-green' : ($attendanceRate !== null ? 'accent-orange' : 'accent-red') ?>">
      <i class="fa fa-calendar-check kpi-icon"></i>
      <div class="kpi-label">Today's Attendance</div>
      <div class="kpi-value" data-counter="<?= $attendanceRate ?? 0 ?>" data-counter-suffix="%">0%</div>
      <div class="kpi-sub"><?= $attendanceRate !== null ? (int)$todayAttendance['present_count'] . ' of ' . (int)$todayAttendance['total_count'] . ' present' : 'Not recorded yet' ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card <?= $openDiscipline > 0 ? 'accent-red' : 'accent-green' ?>">
      <i class="fa fa-gavel kpi-icon"></i>
      <div class="kpi-label">Discipline Cases (30 days)</div>
      <div class="kpi-value" data-counter="<?= $openDiscipline ?>">0</div>
      <div class="kpi-sub">Requiring attention</div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-7 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-chart-line text-gold me-2"></i>Attendance Trend (Last 7 Days)</div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="attChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-pie-chart text-gold me-2"></i>Discipline by Category</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div class="chart-container-sm" style="width:200px;height:200px;">
          <canvas id="discChart"></canvas>
        </div>
      </div>
      <div class="card-footer bg-white text-center">
        <?php foreach ($discLabels as $i => $label): ?>
          <span class="badge bg-<?= $i === 0 ? 'success' : ($i === 1 ? 'warning' : 'danger') ?> me-1"><?= e(ucfirst($label)) ?>: <?= $discCounts[$i] ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-users text-gold me-2"></i>Student Distribution by Class</div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="classDistChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-6 animate-fade-in animate-delay-3">
    <div class="row g-3">
      <div class="col-6">
        <div class="gradient-card gradient-green">
          <i class="fa fa-check-double gradient-icon"></i>
          <div class="gradient-value" data-counter="<?= $pendingMarks ?>">0</div>
          <div class="gradient-label">Marks Pending<br>Verification</div>
        </div>
      </div>
      <div class="col-6">
        <div class="gradient-card gradient-purple">
          <i class="fa fa-scroll gradient-icon"></i>
          <div class="gradient-value" data-counter="<?= $draftReports ?>">0</div>
          <div class="gradient-label">Report Cards<br>Awaiting Publish</div>
        </div>
      </div>
    </div>
    <div class="card mt-3">
      <div class="card-header"><i class="fa fa-bullhorn text-gold me-2"></i>Recent Announcements</div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($announcements as $a): ?>
            <li class="activity-item">
              <div class="activity-icon icon-gold"><i class="fa fa-bullhorn"></i></div>
              <div class="activity-content">
                <div class="activity-title"><?= e($a['title']) ?></div>
                <div class="activity-text">by <?= e($a['first_name'] . ' ' . $a['last_name']) ?></div>
              </div>
              <div class="activity-time"><?= e(date('d M', strtotime($a['created_at']))) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($announcements)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No announcements yet.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="card-footer bg-white">
        <a href="<?= e(app_url('/communication/announcements.php')) ?>" class="small">Post or manage announcements &rarr;</a>
      </div>
    </div>
  </div>
</div>

<div class="card animate-fade-in animate-delay-4">
  <div class="card-header"><i class="fa fa-gavel text-gold me-2"></i>Recent Discipline Records</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Student</th><th>Category</th><th>Description</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recentDiscipline as $d): ?>
          <tr>
            <td><?= e($d['first_name'] . ' ' . $d['last_name']) ?> <span class="text-muted small">(<?= e($d['admission_no']) ?>)</span></td>
            <td>
              <span class="badge bg-<?= $d['category']==='severe' ? 'danger' : ($d['category']==='moderate' ? 'warning' : 'success') ?>">
                <?= e(ucfirst($d['category'])) ?>
              </span>
            </td>
            <td class="small"><?= e(mb_strimwidth($d['description'], 0, 60, '...')) ?></td>
            <td class="small text-muted"><?= format_date($d['incident_date']) ?></td>
            <td><a href="<?= e(app_url('/head_of_school/discipline.php')) ?>" class="btn btn-sm btn-outline-primary">View</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recentDiscipline)): ?><tr><td colspan="5" class="text-center text-muted py-4">No discipline records yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white">
    <a href="<?= e(app_url('/head_of_school/discipline.php')) ?>" class="small">View all discipline records &rarr;</a>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Attendance Trend Chart
  var attCtx = document.getElementById('attChart');
  if (attCtx) {
    new Chart(attCtx, {
      type: 'line',
      data: {
        labels: <?= json_encode($attDates) ?>,
        datasets: [{
          label: 'Present',
          data: <?= json_encode($attPresent) ?>,
          borderColor: '#1F8A55',
          backgroundColor: 'rgba(31,138,85,0.1)',
          fill: true,
          tension: 0.35,
          pointBackgroundColor: '#1F8A55',
          pointRadius: 4
        }, {
          label: 'Total',
          data: <?= json_encode($attTotal) ?>,
          borderColor: '#2B6CB0',
          backgroundColor: 'rgba(43,108,176,0.05)',
          fill: false,
          borderDash: [5,5],
          tension: 0.35,
          pointBackgroundColor: '#2B6CB0',
          pointRadius: 4
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, font: { size: 11 } } }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } },
        scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } }, x: { grid: { display: false }, ticks: { font: { size: 11 } } } }
      }
    });
  }

  // Discipline Chart
  var discCtx = document.getElementById('discChart');
  if (discCtx) {
    new Chart(discCtx, {
      type: 'doughnut',
      data: {
        labels: <?= json_encode($discLabels) ?>,
        datasets: [{ data: <?= json_encode($discCounts) ?>, backgroundColor: ['#1F8A55', '#DD6B20', '#C23B3B'], borderWidth: 0, hoverOffset: 8 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '70%',
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } }
      }
    });
  }

  // Class Distribution Chart
  var clsCtx = document.getElementById('classDistChart');
  if (clsCtx) {
    new Chart(clsCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($classLabels) ?>,
        datasets: [{
          label: 'Students',
          data: <?= json_encode($classCounts) ?>,
          backgroundColor: ['#102A43', '#2B6CB0', '#C8932A', '#6B46C1', '#DD6B20', '#1F8A55', '#334E68'],
          borderRadius: 6, borderSkipped: false
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } },
        scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } }
      }
    });
  }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>