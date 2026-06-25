<?php
/**
 * class_teacher/dashboard.php
 * Class teacher (homeroom) dashboard: attendance, discipline, student
 * progress with interactive visualizations.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();
$period = get_current_period($pdo);

// ====== My Class ======
$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Class Teacher Dashboard';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher for any class. Please contact the Academic Department.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ====== Student count ======
$studentCount = $pdo->prepare("SELECT COUNT(*) c FROM students WHERE class_id = :cid AND status='active'");
$studentCount->execute(['cid' => $myClass['class_id']]);
$totalStudents = (int) $studentCount->fetch()['c'];

// ====== Gender distribution ======
$maleStmt = $pdo->prepare("SELECT COUNT(*) c FROM students WHERE class_id = :cid AND status='active' AND gender='male'");
$maleStmt->execute(['cid' => $myClass['class_id']]);
$maleCount = (int) $maleStmt->fetch()['c'];
$femaleCount = $totalStudents - $maleCount;

// ====== Today's attendance ======
$todayAttendance = $pdo->prepare(
    "SELECT SUM(status='present') AS present_count, COUNT(*) AS total_count FROM student_attendance
     WHERE class_id = :cid AND attendance_date = CURDATE()"
);
$todayAttendance->execute(['cid' => $myClass['class_id']]);
$att = $todayAttendance->fetch();
$attendanceTaken = $att && $att['total_count'] > 0;
$attendanceRate = $attendanceTaken ? round(($att['present_count'] / $att['total_count']) * 100, 1) : null;

// ====== Weekly attendance trend ======
$weeklyAtt = $pdo->prepare(
    "SELECT attendance_date,
            SUM(status='present') AS present,
            SUM(status='absent') AS absent,
            SUM(status='late') AS late,
            COUNT(*) AS total
     FROM student_attendance
     WHERE class_id = :cid AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY attendance_date ORDER BY attendance_date"
);
$weeklyAtt->execute(['cid' => $myClass['class_id']]);
$attDates = [];
$attPresent = [];
$attAbsent = [];
$attLate = [];
foreach ($weeklyAtt as $a) {
    $attDates[] = date('D', strtotime($a['attendance_date']));
    $attPresent[] = (int) $a['present'];
    $attAbsent[] = (int) $a['absent'];
    $attLate[] = (int) $a['late'];
}

// ====== Overall attendance stats ======
$overallAtt = $pdo->prepare(
    "SELECT SUM(status='present') AS p, SUM(status='absent') AS a, SUM(status='late') AS l, COUNT(*) AS t
     FROM student_attendance WHERE class_id = :cid"
);
$overallAtt->execute(['cid' => $myClass['class_id']]);
$overall = $overallAtt->fetch();
$overallRate = $overall && $overall['t'] > 0 ? round(($overall['p'] / $overall['t']) * 100, 1) : 0;

// ====== Discipline records ======
$recentDiscipline = $pdo->prepare(
    "SELECT d.*, u.first_name, u.last_name FROM discipline_records d
     JOIN students s ON s.student_id = d.student_id JOIN users u ON u.user_id = s.user_id
     WHERE s.class_id = :cid ORDER BY d.created_at DESC LIMIT 5"
);
$recentDiscipline->execute(['cid' => $myClass['class_id']]);
$disciplineRows = $recentDiscipline->fetchAll();
$discCount = count($disciplineRows);

// ====== Student list for quick info ======
$studentsList = $pdo->prepare(
    "SELECT s.student_id, u.first_name, u.last_name, s.admission_no, s.gender
     FROM students s JOIN users u ON u.user_id = s.user_id
     WHERE s.class_id = :cid AND s.status='active'
     ORDER BY u.first_name LIMIT 5"
);
$studentsList->execute(['cid' => $myClass['class_id']]);
$recentStudents = $studentsList->fetchAll();
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">My Class: <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?> <span class="badge bg-gold ms-2">Class Teacher</span></h1>
      <p class="mb-0"><?= e(date('l, d F Y')) ?> &middot; <?= $totalStudents ?> students</p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/class_teacher/attendance.php')) ?>" class="btn btn-gold"><i class="fa fa-calendar-check me-1"></i> Take Attendance</a>
      <a href="<?= e(app_url('/class_teacher/my_class.php')) ?>" class="btn btn-outline-light"><i class="fa fa-users me-1"></i> View Class</a>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search students..." data-search="#classTables" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> My Class</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/class_teacher/attendance.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-check me-1"></i> Attendance</a>
    <a href="<?= e(app_url('/class_teacher/my_class.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-users me-1"></i> Class Roster</a>
    <a href="<?= e(app_url('/class_teacher/subjects.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-book me-1"></i> Subjects</a>
    <a href="<?= e(app_url('/class_teacher/timetable.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-alt me-1"></i> Timetable</a>
    <a href="<?= e(app_url('/class_teacher/teachers.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-chalkboard-teacher me-1"></i> Teachers</a>
    <a href="<?= e(app_url('/class_teacher/class_leaders.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-user-tie me-1"></i> Leaders</a>
    <a href="<?= e(app_url('/class_teacher/discipline.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-gavel me-1"></i> Discipline</a>
    <a href="<?= e(app_url('/class_teacher/progress.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-chart-line me-1"></i> Progress</a>
    <a href="<?= e(app_url('/class_teacher/compile_results.php')) ?>" class="btn btn-sm btn-outline-gold"><i class="fa fa-file-alt me-1"></i> Compile Results</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<?php if (!$attendanceTaken): ?>
  <div class="alert alert-warning animate-fade-in d-flex justify-content-between align-items-center">
    <span><i class="fa fa-exclamation-triangle me-2"></i>You haven't taken attendance for today yet.</span>
    <a href="<?= e(app_url('/class_teacher/attendance.php')) ?>" class="btn btn-sm btn-gold">Take Attendance Now</a>
  </div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-user-graduate kpi-icon"></i>
      <div class="kpi-label">Students</div>
      <div class="kpi-value" data-counter="<?= $totalStudents ?>">0</div>
      <div class="kpi-sub"><?= $maleCount ?> M &middot; <?= $femaleCount ?> F</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card <?= $attendanceTaken ? ($attendanceRate >= 90 ? 'accent-green' : 'accent-orange') : 'accent-red' ?>">
      <i class="fa fa-calendar-check kpi-icon"></i>
      <div class="kpi-label">Today's Attendance</div>
      <div class="kpi-value"><?= $attendanceTaken ? e($attendanceRate) . '%' : '—' ?></div>
      <div class="kpi-sub"><?= $attendanceTaken ? (int)$att['present_count'] . '/' . (int)$att['total_count'] . ' present' : 'Not taken' ?></div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-chart-line kpi-icon"></i>
      <div class="kpi-label">Overall Attendance</div>
      <div class="kpi-value"><?= e($overallRate) ?>%</div>
      <div class="kpi-sub">Cumulative rate</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card <?= $discCount > 0 ? 'accent-red' : 'accent-green' ?>">
      <i class="fa fa-gavel kpi-icon"></i>
      <div class="kpi-label">Discipline Cases</div>
      <div class="kpi-value" data-counter="<?= $discCount ?>">0</div>
      <div class="kpi-sub">Recent incidents</div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-7 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-chart-bar text-gold me-2"></i>Attendance Trend (Last 7 Days)</div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="attendanceTrendChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-users text-gold me-2"></i>Class Roster (Recent)</div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($recentStudents as $s): ?>
            <li class="activity-item">
              <div class="activity-icon icon-<?= $s['gender'] === 'male' ? 'info' : 'gold' ?>">
                <i class="fa fa-user"></i>
              </div>
              <div class="activity-content">
                <div class="activity-title"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
                <div class="activity-text"><?= e($s['admission_no']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="card-footer bg-white">
        <a href="<?= e(app_url('/class_teacher/my_class.php')) ?>" class="small">View full class roster &rarr;</a>
      </div>
    </div>
  </div>
</div>

<!-- Discipline Records -->
<div class="card animate-fade-in animate-delay-3">
  <div class="card-header"><i class="fa fa-gavel text-gold me-2"></i>Recent Discipline Records</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Student</th><th>Category</th><th>Description</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($disciplineRows as $d): ?>
          <tr>
            <td><?= e($d['first_name'] . ' ' . $d['last_name']) ?></td>
            <td>
              <span class="badge bg-<?= $d['category']==='severe' ? 'danger' : ($d['category']==='moderate' ? 'warning' : 'success') ?>">
                <?= e(ucfirst($d['category'])) ?>
              </span>
            </td>
            <td class="small"><?= e(mb_strimwidth($d['description'], 0, 60, '...')) ?></td>
            <td class="small text-muted"><?= format_date($d['incident_date']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($disciplineRows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No discipline records for this class.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white">
    <a href="<?= e(app_url('/class_teacher/discipline.php')) ?>" class="small">Manage discipline records &rarr;</a>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var attCtx = document.getElementById('attendanceTrendChart');
  if (attCtx) {
    new Chart(attCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($attDates) ?>,
        datasets: [
          { label: 'Present', data: <?= json_encode($attPresent) ?>, backgroundColor: '#1F8A55', borderRadius: 4 },
          { label: 'Absent', data: <?= json_encode($attAbsent) ?>, backgroundColor: '#C23B3B', borderRadius: 4 },
          { label: 'Late', data: <?= json_encode($attLate) ?>, backgroundColor: '#DD6B20', borderRadius: 4 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, font: { size: 11 } } },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 }
        },
        scales: {
          y: { beginAtZero: true, stacked: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } },
          x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } }
        }
      }
    });
  }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>