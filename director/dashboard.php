<?php
/**
 * director/dashboard.php
 * Super Administrator dashboard: school-wide KPIs across academics,
 * finance, staff, and security with interactive charts and live data.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin']);

$pdo = get_db_connection();
$period = get_current_period($pdo);
$userId = current_user_id();

// ====== KPI Data ======
$totalStudents = (int) $pdo->query("SELECT COUNT(*) c FROM students WHERE status='active'")->fetch()['c'];
$totalStaff    = (int) $pdo->query("SELECT COUNT(*) c FROM staff WHERE status='active'")->fetch()['c'];
$totalClasses  = (int) $pdo->query("SELECT COUNT(*) c FROM classes")->fetch()['c'];

$financeStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0) AS billed, COALESCE(SUM(amount_paid),0) AS collected, COALESCE(SUM(balance),0) AS outstanding
     FROM invoices WHERE term_id = :term"
);
$financeStmt->execute(['term' => $period['term_id']]);
$finance = $financeStmt->fetch();
$collectionRate = $finance['billed'] > 0 ? round(($finance['collected'] / $finance['billed']) * 100, 1) : 0;
$outstandingBalance = $finance['outstanding'];

$pendingMarks = (int) $pdo->query(
    "SELECT COUNT(*) c FROM exam_marks WHERE verification_status = 'pending' AND submitted_at IS NOT NULL"
)->fetch()['c'];

$maleStudents = (int) $pdo->query("SELECT COUNT(*) c FROM students WHERE status='active' AND gender='male'")->fetch()['c'];
$femaleStudents = (int) $pdo->query("SELECT COUNT(*) c FROM students WHERE status='active' AND gender='female'")->fetch()['c'];

// ====== Attendance Today ======
$attendanceToday = $pdo->query(
    "SELECT SUM(status='present') AS present_count, COUNT(*) AS total_count
     FROM student_attendance WHERE attendance_date = CURDATE()"
)->fetch();
$attRate = ($attendanceToday && $attendanceToday['total_count'] > 0) ? round(($attendanceToday['present_count'] / $attendanceToday['total_count']) * 100, 1) : null;

// ====== Weekly attendance trend (last 7 days) ======
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

// ====== Fee collection trend (last 6 months) ======
$feeTrend = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%b') AS month,
            COALESCE(SUM(amount),0) AS collected
     FROM payments
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
     ORDER BY MIN(created_at)"
)->fetchAll();
$feeMonths = [];
$feeCollected = [];
foreach ($feeTrend as $ft) {
    $feeMonths[] = $ft['month'];
    $feeCollected[] = (float) $ft['collected'];
}

// ====== Recent Activities ======
$recentAudit = $pdo->query(
    "SELECT a.*, u.first_name, u.last_name FROM audit_logs a
     LEFT JOIN users u ON u.user_id = a.user_id
     ORDER BY a.audit_id DESC LIMIT 10"
)->fetchAll();

$recentLogins = $pdo->query(
    "SELECT l.*, u.first_name, u.last_name FROM login_activity l
     LEFT JOIN users u ON u.user_id = l.user_id
     ORDER BY l.login_id DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Director Dashboard';
require APP_ROOT . '/includes/header.php';
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Welcome back, <?= e($_SESSION['full_name']) ?> <span class="badge bg-gold ms-2"><?= current_role() === 'system_admin' ? 'System Admin' : 'Director' ?></span></h1>
      <p class="mb-0">School-wide overview &middot; <?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/director/finance_overview.php')) ?>" class="btn btn-gold"><i class="fa fa-coins me-1"></i> Financial Report</a>
      <a href="<?= e(app_url('/communication/announcements.php')) ?>" class="btn btn-outline-light"><i class="fa fa-bullhorn me-1"></i> Announcement</a>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search dashboard..." data-search="#dashboardTables" style="width:260px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> All Data</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/director/students.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-user-graduate me-1"></i> Students</a>
    <a href="<?= e(app_url('/director/staff.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-id-badge me-1"></i> Staff</a>
    <a href="<?= e(app_url('/director/users.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-users-cog me-1"></i> Users</a>
    <a href="<?= e(app_url('/director/audit_logs.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-shield-alt me-1"></i> Audit Logs</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<!-- KPI Cards Row -->
<div class="row g-3 mb-4" id="dashboardTables">
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-user-graduate kpi-icon"></i>
      <div class="kpi-label">Active Students</div>
      <div class="kpi-value" data-counter="<?= $totalStudents ?>">0</div>
      <div class="kpi-sub"><?= $maleStudents ?> M &middot; <?= $femaleStudents ?> F</div>
      <div class="mt-2"><a href="<?= e(app_url('/director/students.php')) ?>" class="small">Manage Students <i class="fa fa-arrow-right"></i></a></div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-chalkboard-teacher kpi-icon"></i>
      <div class="kpi-label">Active Staff</div>
      <div class="kpi-value" data-counter="<?= $totalStaff ?>">0</div>
      <div class="kpi-sub">Across <?= $totalClasses ?> classes</div>
      <div class="mt-2"><a href="<?= e(app_url('/director/staff.php')) ?>" class="small">Manage Staff <i class="fa fa-arrow-right"></i></a></div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card <?= $collectionRate >= 80 ? 'accent-green' : ($collectionRate >= 50 ? 'accent-orange' : 'accent-red') ?>">
      <i class="fa fa-percentage kpi-icon"></i>
      <div class="kpi-label">Fee Collection Rate</div>
      <div class="kpi-value" data-counter="<?= $collectionRate ?>" data-counter-suffix="%">0%</div>
      <div class="kpi-sub"><?= format_money($outstandingBalance) ?> outstanding</div>
      <div class="mt-2"><a href="<?= e(app_url('/director/finance_overview.php')) ?>" class="small">View Finance <i class="fa fa-arrow-right"></i></a></div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card <?= $pendingMarks > 0 ? 'accent-red' : 'accent-green' ?>">
      <i class="fa fa-check-double kpi-icon"></i>
      <div class="kpi-label">Pending Verification</div>
      <div class="kpi-value" data-counter="<?= $pendingMarks ?>">0</div>
      <div class="kpi-sub">Marks awaiting review</div>
      <div class="mt-2"><a href="<?= e(app_url('/academic/verify_marks.php')) ?>" class="small">Verify Now <i class="fa fa-arrow-right"></i></a></div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-8 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between">
        <span><i class="fa fa-calendar-check text-gold me-2"></i>Attendance Trend (Last 7 Days)</span>
        <a href="<?= e(app_url('/head_of_school/attendance_overview.php')) ?>" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="attendanceChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between">
        <span><i class="fa fa-venus-mars text-gold me-2"></i>Gender Distribution</span>
        <a href="<?= e(app_url('/director/students.php')) ?>" class="btn btn-sm btn-outline-primary">View</a>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div class="chart-container-sm" style="width:200px;height:200px;">
          <canvas id="genderChart"></canvas>
        </div>
      </div>
      <div class="card-footer bg-white text-center">
        <span class="badge bg-success me-2">Male: <?= $maleStudents ?></span>
        <span class="badge bg-gold">Female: <?= $femaleStudents ?></span>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between">
        <span><i class="fa fa-users text-gold me-2"></i>Student Distribution by Class Level</span>
        <a href="<?= e(app_url('/academic/classes.php')) ?>" class="btn btn-sm btn-outline-primary">Manage Classes</a>
      </div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="classChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-6 animate-fade-in animate-delay-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between">
        <span><i class="fa fa-coins text-gold me-2"></i>Fee Collection Trend (6 Months)</span>
        <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="btn btn-sm btn-outline-primary">Full Report</a>
      </div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="feeChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bottom Row: Financial Summary + Recent Activity -->
<div class="row g-3 mb-4">
  <div class="col-xl-4 animate-fade-in animate-delay-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between">
        <span><i class="fa fa-coins text-gold me-2"></i>Financial Summary</span>
        <a href="<?= e(app_url('/director/finance_overview.php')) ?>" class="btn btn-sm btn-outline-primary">Details</a>
      </div>
      <div class="card-body">
        <div class="stat-row">
          <span class="stat-label">Total Billed</span>
          <span class="stat-value"><?= format_money($finance['billed']) ?></span>
        </div>
        <div class="stat-row">
          <span class="stat-label">Collected</span>
          <span class="stat-value text-green"><?= format_money($finance['collected']) ?></span>
        </div>
        <div class="stat-row">
          <span class="stat-label">Outstanding</span>
          <span class="stat-value text-red"><?= format_money($finance['outstanding']) ?></span>
        </div>
        <div class="mt-3">
          <div class="d-flex justify-content-between small mb-1">
            <span>Collection Progress</span>
            <span><?= $collectionRate ?>%</span>
          </div>
          <div class="progress">
            <div class="progress-bar bg-gold progress-bar-animated" data-width="<?= $collectionRate ?>" style="width:0%"></div>
          </div>
        </div>
      </div>
      <div class="card-footer bg-white d-flex gap-2">
        <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-file-invoice"></i> Invoices</a>
        <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-money-bill-wave"></i> Record Payment</a>
      </div>
    </div>
  </div>

  <div class="col-xl-4 animate-fade-in animate-delay-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between">
        <span><i class="fa fa-history text-gold me-2"></i>Recent System Activity</span>
        <a href="<?= e(app_url('/director/audit_logs.php')) ?>" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($recentAudit as $a): ?>
            <li class="activity-item">
              <div class="activity-icon icon-<?= $a['action'] === 'create' ? 'success' : ($a['action'] === 'delete' ? 'danger' : 'info') ?>">
                <i class="fa fa-<?= $a['action'] === 'create' ? 'plus' : ($a['action'] === 'delete' ? 'trash' : 'pen') ?>"></i>
              </div>
              <div class="activity-content">
                <div class="activity-title"><?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'System') ?></div>
                <div class="activity-text"><?= e($a['description']) ?></div>
              </div>
              <div class="activity-time"><?= e(date('d M H:i', strtotime($a['created_at']))) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($recentAudit)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No activity recorded yet.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-xl-4 animate-fade-in animate-delay-5">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between">
        <span><i class="fa fa-sign-in-alt text-gold me-2"></i>Recent Login Activity</span>
        <a href="<?= e(app_url('/director/audit_logs.php')) ?>" class="btn btn-sm btn-outline-primary">Security Logs</a>
      </div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($recentLogins as $l): ?>
            <li class="activity-item">
              <div class="activity-icon icon-<?= $l['status'] === 'success' ? 'success' : ($l['status'] === 'logout' ? 'info' : 'danger') ?>">
                <i class="fa fa-<?= $l['status'] === 'success' ? 'check' : ($l['status'] === 'logout' ? 'sign-out-alt' : 'times') ?>"></i>
              </div>
              <div class="activity-content">
                <div class="activity-title">
                  <?= e(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: ($l['username_attempted'] ?: 'Unknown')) ?>
                </div>
                <div class="activity-text">
                  <span class="badge bg-<?= $l['status'] === 'success' ? 'success' : ($l['status'] === 'logout' ? 'secondary' : 'danger') ?>">
                    <?= e(str_replace('_',' ',$l['status'])) ?>
                  </span>
                </div>
              </div>
              <div class="activity-time"><?= e(date('H:i', strtotime($l['created_at']))) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($recentLogins)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No login activity yet.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- Quick CRUD Links -->
<div class="row animate-fade-in animate-delay-5">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-bolt text-gold me-2"></i>Quick Management Actions</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="<?= e(app_url('/director/students.php')) ?>" class="quick-action-item"><i class="fa fa-user-graduate"></i><span>Manage Students</span></a>
          <a href="<?= e(app_url('/director/staff.php')) ?>" class="quick-action-item"><i class="fa fa-id-badge"></i><span>Manage Staff</span></a>
          <a href="<?= e(app_url('/director/users.php')) ?>" class="quick-action-item"><i class="fa fa-users-cog"></i><span>Manage Users</span></a>
          <a href="<?= e(app_url('/director/roles.php')) ?>" class="quick-action-item"><i class="fa fa-user-shield"></i><span>Roles & Permissions</span></a>
          <a href="<?= e(app_url('/director/system_admin.php')) ?>" class="quick-action-item"><i class="fa fa-cogs"></i><span>System Settings</span></a>
          <a href="<?= e(app_url('/academic/exams.php')) ?>" class="quick-action-item"><i class="fa fa-pencil-alt"></i><span>Manage Exams</span></a>
          <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="quick-action-item"><i class="fa fa-file-invoice"></i><span>Invoices</span></a>
          <a href="<?= e(app_url('/communication/announcements.php')) ?>" class="quick-action-item"><i class="fa fa-bullhorn"></i><span>Announcements</span></a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Chart.js Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Attendance Trend Chart
  var attCtx = document.getElementById('attendanceChart');
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
          pointRadius: 4,
          pointHoverRadius: 6
        }, {
          label: 'Total',
          data: <?= json_encode($attTotal) ?>,
          borderColor: '#2B6CB0',
          backgroundColor: 'rgba(43,108,176,0.05)',
          fill: false,
          borderDash: [5,5],
          tension: 0.35,
          pointBackgroundColor: '#2B6CB0',
          pointRadius: 4,
          pointHoverRadius: 6
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, font: { size: 11 } } }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } },
        scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } }, x: { grid: { display: false }, ticks: { font: { size: 11 } } } }
      }
    });
  }

  // Gender Distribution Chart
  var genderCtx = document.getElementById('genderChart');
  if (genderCtx) {
    new Chart(genderCtx, {
      type: 'doughnut',
      data: {
        labels: ['Male', 'Female'],
        datasets: [{ data: [<?= $maleStudents ?>, <?= $femaleStudents ?>], backgroundColor: ['#1F8A55', '#C8932A'], borderWidth: 0, hoverOffset: 8 }]
      },
      options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } } }
    });
  }

  // Class Distribution Chart
  var classCtx = document.getElementById('classChart');
  if (classCtx) {
    new Chart(classCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($classLabels) ?>,
        datasets: [{ label: 'Students', data: <?= json_encode($classCounts) ?>, backgroundColor: ['#102A43', '#2B6CB0', '#C8932A', '#6B46C1', '#DD6B20', '#1F8A55', '#334E68'], borderRadius: 6, borderSkipped: false }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } }
    });
  }

  // Fee Collection Trend Chart
  var feeCtx = document.getElementById('feeChart');
  if (feeCtx) {
    new Chart(feeCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($feeMonths) ?>,
        datasets: [{ label: 'Collected (TZS)', data: <?= json_encode($feeCollected) ?>, backgroundColor: 'rgba(200,147,42,0.7)', borderColor: '#C8932A', borderWidth: 2, borderRadius: 6, borderSkipped: false }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12, callbacks: { label: function(ctx) { return 'TZS ' + Number(ctx.raw).toLocaleString(); } } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 }, callback: function(v) { return 'TZS ' + (v/1000).toFixed(0) + 'k'; } } }, x: { grid: { display: false }, ticks: { font: { size: 11 } } } } }
    });
  }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>