<?php
/**
 * student/dashboard.php
 * The student's own overview: latest result, attendance, fee status,
 * timetable, and notices with interactive visualizations.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['student']);

$pdo = get_db_connection();
$userId = current_user_id();
$period = get_current_period($pdo);

$studentStmt = $pdo->prepare(
    "SELECT s.*, cl.level_name, c.stream_name FROM students s
     LEFT JOIN classes c ON c.class_id = s.class_id
     LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE s.user_id = :uid"
);
$studentStmt->execute(['uid' => $userId]);
$student = $studentStmt->fetch();

$pageTitle = 'My Dashboard';
require APP_ROOT . '/includes/header.php';

if (!$student) {
    echo '<div class="alert alert-warning">Your student profile could not be found. Please contact the school office.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$sid = (int) $student['student_id'];

// ====== Report card ======
$reportCard = $pdo->prepare("SELECT * FROM report_cards WHERE student_id = :id AND term_id = :term AND status='published'");
$reportCard->execute(['id' => $sid, 'term' => $period['term_id']]);
$currentReport = $reportCard->fetch();

// ====== Attendance stats ======
$attendanceStmt = $pdo->prepare("SELECT SUM(status='present') AS p, SUM(status='absent') AS a, SUM(status='late') AS l, COUNT(*) AS t FROM student_attendance WHERE student_id = :id");
$attendanceStmt->execute(['id' => $sid]);
$att = $attendanceStmt->fetch();
$attRate = ($att && $att['t'] > 0) ? round(($att['p'] / $att['t']) * 100, 1) : null;

// ====== Weekly attendance ======
$weeklyAtt = $pdo->prepare(
    "SELECT attendance_date, status FROM student_attendance
     WHERE student_id = :id AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     ORDER BY attendance_date"
);
$weeklyAtt->execute(['id' => $sid]);
$myAttDates = [];
$myAttStatus = [];
foreach ($weeklyAtt as $a) {
    $myAttDates[] = date('D', strtotime($a['attendance_date']));
    $myAttStatus[] = $a['status'];
}

// ====== Fee balance ======
$balanceStmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) AS bal, COALESCE(SUM(total_amount),0) AS total FROM invoices WHERE student_id = :id");
$balanceStmt->execute(['id' => $sid]);
$feeData = $balanceStmt->fetch();
$balance = $feeData['bal'];
$totalBilled = $feeData['total'];
$paidAmount = $totalBilled - $balance;
$paymentRate = $totalBilled > 0 ? round(($paidAmount / $totalBilled) * 100, 1) : 0;

// ====== Recent results (last 5 subjects) ======
$results = $pdo->prepare(
    "SELECT tr.*, sub.subject_name FROM term_results tr
     JOIN class_subjects cs ON cs.class_subject_id = tr.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     WHERE tr.student_id = :id AND tr.term_id = :term
     ORDER BY tr.average_marks DESC LIMIT 5"
);
$results->execute(['id' => $sid, 'term' => $period['term_id']]);
$subjectResults = $results->fetchAll();

// ====== Today's timetable ======
$today = date('l');
$timetable = $pdo->prepare(
    "SELECT t.*, sub.subject_name, cl.level_name, c.stream_name
     FROM timetable t
     JOIN class_subjects cs ON cs.class_subject_id = t.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE c.class_id = :cid AND t.day_of_week = :day
     ORDER BY t.start_time"
);
$timetable->execute(['cid' => $student['class_id'], 'day' => $today]);
$todaysClasses = $timetable->fetchAll();

// ====== Notices ======
$notices = $pdo->query("SELECT title, body, created_at FROM announcements WHERE audience IN ('all','students') ORDER BY created_at DESC LIMIT 5")->fetchAll();

// ====== Results average for chart ======
$resultSubjects = [];
$resultMarks = [];
foreach ($subjectResults as $r) {
    $resultSubjects[] = $r['subject_name'];
    $resultMarks[] = (float) $r['average_marks'];
}
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Welcome, <?= e($_SESSION['full_name']) ?> <span class="badge bg-gold ms-2">Student</span></h1>
      <p class="mb-0"><?= e($student['level_name'] ? $student['level_name'] . ' ' . $student['stream_name'] : 'Unassigned class') ?> &middot; <?= e($student['admission_no']) ?></p>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search..." data-search="#studentTables" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> My Dashboard</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/student/results.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-graduation-cap me-1"></i> Results</a>
    <a href="<?= e(app_url('/student/timetable.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-alt me-1"></i> Timetable</a>
    <a href="<?= e(app_url('/student/attendance.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-check me-1"></i> Attendance</a>
    <a href="<?= e(app_url('/student/fees.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-money-check-alt me-1"></i> Fees</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-graduation-cap kpi-icon"></i>
      <div class="kpi-label">Term Average</div>
      <div class="kpi-value" data-counter="<?= $currentReport ? (float)$currentReport['overall_average'] : 0 ?>" data-counter-suffix="%">0%</div>
      <div class="kpi-sub"><?= $currentReport ? 'Position: ' . (int)$currentReport['overall_position'] . ' of ' . (int)$currentReport['class_size'] : 'Not yet published' ?></div>
    </div>
  </div>
  <div class="col-md-4 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card <?= $attRate !== null && $attRate >= 90 ? 'accent-green' : ($attRate !== null ? 'accent-orange' : 'accent-red') ?>">
      <i class="fa fa-calendar-check kpi-icon"></i>
      <div class="kpi-label">Attendance Rate</div>
      <div class="kpi-value" data-counter="<?= $attRate ?? 0 ?>" data-counter-suffix="%">0%</div>
      <div class="kpi-sub"><?= $att ? (int)$att['p'] . ' present, ' . (int)$att['a'] . ' absent' : 'No records' ?></div>
    </div>
  </div>
  <div class="col-md-4 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card <?= $balance > 0 ? 'accent-red' : 'accent-green' ?>">
      <i class="fa fa-money-check-alt kpi-icon"></i>
      <div class="kpi-label">Fee Balance</div>
      <div class="kpi-value"><?= format_money($balance) ?></div>
      <div class="kpi-sub"><?= $paymentRate ?>% paid</div>
    </div>
  </div>
</div>

<!-- Charts & Info Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-5 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-chart-bar text-gold me-2"></i>My Subject Results</div>
      <div class="card-body">
        <?php if (!empty($subjectResults)): ?>
          <div class="chart-container">
            <canvas id="resultChart"></canvas>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <i class="fa fa-graduation-cap"></i>
            <h5>No Results Yet</h5>
            <p>Your term results will appear here once published.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-calendar-alt text-gold me-2"></i>Today's Classes</div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($todaysClasses as $c): ?>
            <li class="activity-item">
              <div class="activity-icon icon-info"><i class="fa fa-book"></i></div>
              <div class="activity-content">
                <div class="activity-title"><?= e($c['subject_name']) ?></div>
                <div class="activity-text"><?= e($c['start_time'] . ' - ' . $c['end_time']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($todaysClasses)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No classes scheduled today.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="card-footer bg-white">
        <a href="<?= e(app_url('/student/timetable.php')) ?>" class="small">View full timetable &rarr;</a>
      </div>
    </div>
  </div>
  <div class="col-lg-3 animate-fade-in animate-delay-4">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-bullhorn text-gold me-2"></i>Notices</div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($notices as $n): ?>
            <li class="activity-item">
              <div class="activity-icon icon-gold"><i class="fa fa-bullhorn"></i></div>
              <div class="activity-content">
                <div class="activity-title"><?= e($n['title']) ?></div>
                <div class="activity-text"><?= e(mb_strimwidth($n['body'], 0, 60, '...')) ?></div>
              </div>
              <div class="activity-time"><?= e(date('d M', strtotime($n['created_at']))) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($notices)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No notices yet.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="card-footer bg-white">
        <a href="<?= e(app_url('/student/notices.php')) ?>" class="small">View all notices &rarr;</a>
      </div>
    </div>
  </div>
</div>

<!-- Quick Links -->
<div class="row g-3 mb-4 animate-fade-in animate-delay-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-bolt text-gold me-2"></i>Quick Links</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="<?= e(app_url('/student/results.php')) ?>" class="quick-action-item"><i class="fa fa-graduation-cap"></i><span>My Results</span></a>
          <a href="<?= e(app_url('/student/timetable.php')) ?>" class="quick-action-item"><i class="fa fa-calendar-alt"></i><span>Timetable</span></a>
          <a href="<?= e(app_url('/student/attendance.php')) ?>" class="quick-action-item"><i class="fa fa-calendar-check"></i><span>Attendance</span></a>
          <a href="<?= e(app_url('/student/fees.php')) ?>" class="quick-action-item"><i class="fa fa-money-check-alt"></i><span>Fee Status</span></a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var resultCtx = document.getElementById('resultChart');
  if (resultCtx) {
    new Chart(resultCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($resultSubjects) ?>,
        datasets: [{
          label: 'Average',
          data: <?= json_encode($resultMarks) ?>,
          backgroundColor: ['#102A43', '#2B6CB0', '#C8932A', '#6B46C1', '#1F8A55'],
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12, callbacks: { label: function(ctx) { return ctx.raw + '%'; } } }
        },
        scales: {
          x: { beginAtZero: true, max: 100, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 10 }, callback: function(v) { return v + '%'; } } },
          y: { grid: { display: false }, ticks: { font: { size: 10 } } }
        }
      }
    });
  }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>