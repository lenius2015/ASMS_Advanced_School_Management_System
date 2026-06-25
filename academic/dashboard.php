<?php
/**
 * academic/dashboard.php
 * Academic Department dashboard: exam pipeline, verification workflow,
 * and academic data with interactive visualizations.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

// ====== KPI Data ======
$totalStudents = (int) $pdo->query("SELECT COUNT(*) c FROM students WHERE status='active'")->fetch()['c'];
$totalClasses  = (int) $pdo->query("SELECT COUNT(*) c FROM classes")->fetch()['c'];
$totalSubjects = (int) $pdo->query("SELECT COUNT(*) c FROM subjects WHERE is_active=1")->fetch()['c'];

$examStats = $pdo->prepare(
    "SELECT status, COUNT(*) AS c FROM exams WHERE term_id = :term GROUP BY status"
);
$examStats->execute(['term' => $period['term_id']]);
$examStatusCounts = array_column($examStats->fetchAll(), 'c', 'status');

$pendingVerification = (int) $pdo->query(
    "SELECT COUNT(*) c FROM exam_marks WHERE verification_status = 'pending' AND submitted_at IS NOT NULL"
)->fetch()['c'];

// ====== Attendance KPIs ======
$attendanceToday = $pdo->query(
    "SELECT COUNT(*) AS total,
        SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS absent
     FROM student_attendance WHERE attendance_date = CURDATE()"
)->fetch();
$attendanceTodayTotal = (int) ($attendanceToday['total'] ?? 0);
$attendanceTodayPresent = (int) ($attendanceToday['present'] ?? 0);
$attendanceTodayAbsent = (int) ($attendanceToday['absent'] ?? 0);
$attendanceTodayPct = $attendanceTodayTotal > 0 ? round(($attendanceTodayPresent / $attendanceTodayTotal) * 100, 1) : 0;

$totalTeachers = (int) $pdo->query(
    "SELECT COUNT(*) c FROM users WHERE role_id = (SELECT role_id FROM roles WHERE role_name='subject_teacher') AND is_active=1"
)->fetch()['c'];

$pendingSubmissions = (int) $pdo->query(
    "SELECT COUNT(DISTINCT em.exam_id) c FROM exam_marks em WHERE em.submitted_at IS NULL AND em.entered_by IS NOT NULL"
)->fetch()['c'];

$draftReportCount = $pdo->prepare(
    "SELECT COUNT(*) c FROM report_cards WHERE term_id = :term AND status = 'draft'"
);
$draftReportCount->execute(['term' => $period['term_id']]);
$draftReports = (int) $draftReportCount->fetch()['c'];

$publishedReports = $pdo->prepare(
    "SELECT COUNT(*) c FROM report_cards WHERE term_id = :term AND status = 'published'"
);
$publishedReports->execute(['term' => $period['term_id']]);
$publishedCount = (int) $publishedReports->fetch()['c'];

// ====== Exams per type ======
$examsByType = $pdo->prepare(
    "SELECT et.type_name, COUNT(e.exam_id) AS cnt
     FROM exams e
     JOIN exam_types et ON et.exam_type_id = e.exam_type_id
     WHERE e.term_id = :term
     GROUP BY et.type_name ORDER BY cnt DESC"
);
$examsByType->execute(['term' => $period['term_id']]);
$examTypeLabels = [];
$examTypeCounts = [];
foreach ($examsByType as $et) {
    $examTypeLabels[] = $et['type_name'];
    $examTypeCounts[] = (int) $et['cnt'];
}

// ====== Verification pipeline ======
$verifPipeline = $pdo->query(
    "SELECT verification_status, COUNT(*) AS cnt FROM exam_marks
     WHERE submitted_at IS NOT NULL
     GROUP BY verification_status"
)->fetchAll();
$verifLabels = [];
$verifCounts = [];
$verifColors = [];
foreach ($verifPipeline as $vp) {
    $verifLabels[] = ucfirst($vp['verification_status']);
    $verifCounts[] = (int) $vp['cnt'];
    $verifColors[] = $vp['verification_status'] === 'verified' ? '#1F8A55' : ($vp['verification_status'] === 'rejected' ? '#C23B3B' : '#DD6B20');
}

// ====== Recent marks submissions ======
$recentSubmissions = $pdo->query(
    "SELECT em.*, sub.subject_name, u.first_name, u.last_name, cl.level_name, c.stream_name
     FROM exam_marks em
     JOIN class_subjects cs ON cs.class_subject_id = em.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     JOIN users u ON u.user_id = em.entered_by
     WHERE em.submitted_at IS NOT NULL
     ORDER BY em.submitted_at DESC LIMIT 10"
)->fetchAll();

// ====== Exams by status for pipeline ======
$pipelineStatuses = ['scheduled', 'ongoing', 'marks_pending', 'submitted', 'verified', 'published'];
$pipelineLabels = ['Scheduled', 'Ongoing', 'Marks Pending', 'Submitted', 'Verified', 'Published'];
$pipelineCounts = [];
foreach ($pipelineStatuses as $st) {
    $pipelineCounts[] = (int) ($examStatusCounts[$st] ?? 0);
}

$pageTitle = 'Academic Department Dashboard';
require APP_ROOT . '/includes/header.php';
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Academic Department <span class="badge bg-gold ms-2">Officer</span></h1>
      <p class="mb-0">Examination & results management &middot; <?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/academic/exams.php')) ?>" class="btn btn-gold"><i class="fa fa-pencil-alt me-1"></i> Manage Exams</a>
      <a href="<?= e(app_url('/academic/publish_results.php')) ?>" class="btn btn-outline-light"><i class="fa fa-bullhorn me-1"></i> Publish Results</a>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search..." data-search="#academicTables" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> Academic Overview</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/academic/exams.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-pencil-alt me-1"></i> Manage Exams</a>
    <a href="<?= e(app_url('/academic/students.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-user-graduate me-1"></i> Students</a>
    <a href="<?= e(app_url('/academic/classes.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-chalkboard me-1"></i> Classes</a>
    <a href="<?= e(app_url('/academic/verify_marks.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-check-double me-1"></i> Verify</a>
    <a href="<?= e(app_url('/academic/edit_marks.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-edit me-1"></i> Edit Marks</a>
    <a href="<?= e(app_url('/academic/attendance_overview.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-check me-1"></i> Attendance</a>
    <a href="<?= e(app_url('/academic/lesson_reports.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-chalkboard-teacher me-1"></i> Lesson Reports</a>
    <a href="<?= e(app_url('/academic/timetable.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-alt me-1"></i> Timetable</a>
    <a href="<?= e(app_url('/academic/reports.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-chart-bar me-1"></i> Reports</a>
    <a href="<?= e(app_url('/academic/prepare_examination.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-cogs me-1"></i> Prepare Exam</a>
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
      <i class="fa fa-book kpi-icon"></i>
      <div class="kpi-label">Active Subjects</div>
      <div class="kpi-value" data-counter="<?= $totalSubjects ?>">0</div>
      <div class="kpi-sub">Curriculum offerings</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card <?= $pendingVerification > 0 ? 'accent-orange' : 'accent-green' ?>">
      <i class="fa fa-check-double kpi-icon"></i>
      <div class="kpi-label">Pending Verification</div>
      <div class="kpi-value" data-counter="<?= $pendingVerification ?>">0</div>
      <div class="kpi-sub">Marks awaiting review</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card <?= $draftReports > 0 ? 'accent-red' : 'accent-green' ?>">
      <i class="fa fa-scroll kpi-icon"></i>
      <div class="kpi-label">Draft Report Cards</div>
      <div class="kpi-value" data-counter="<?= $draftReports ?>">0</div>
      <div class="kpi-sub"><?= $publishedCount ?> already published</div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-7 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-tasks text-gold me-2"></i>Examination Pipeline (Current Term)</div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="pipelineChart"></canvas>
        </div>
      </div>
      <div class="card-footer bg-white">
        <p class="text-muted small mb-0">
          <i class="fa fa-arrow-right me-1"></i>
          Workflow: Scheduled &rarr; Ongoing &rarr; Marks Entry &rarr; Submitted &rarr; Verified &rarr; Published
        </p>
      </div>
    </div>
  </div>
  <div class="col-lg-5 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-pie-chart text-gold me-2"></i>Verification Status Breakdown</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div class="chart-container-sm" style="width:200px;height:200px;">
          <canvas id="verifChart"></canvas>
        </div>
      </div>
      <div class="card-footer bg-white text-center">
        <?php foreach ($verifPipeline as $vp): ?>
          <span class="badge bg-<?= $vp['verification_status'] === 'verified' ? 'success' : ($vp['verification_status'] === 'rejected' ? 'danger' : 'warning') ?> me-1">
            <?= e(ucfirst($vp['verification_status'])) ?>: <?= (int)$vp['cnt'] ?>
          </span>
        <?php endforeach; ?>
        <?php if (empty($verifPipeline)): ?><span class="text-muted small">No marks submitted yet</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-pencil-alt text-gold me-2"></i>Exams by Type</div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="examTypeChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-6 animate-fade-in animate-delay-3">
    <div class="row g-3">
      <div class="col-6">
        <div class="gradient-card gradient-navy">
          <i class="fa fa-chalkboard gradient-icon"></i>
          <div class="gradient-value" data-counter="<?= $totalClasses ?>">0</div>
          <div class="gradient-label">Active Classes</div>
        </div>
      </div>
      <div class="col-6">
        <div class="gradient-card gradient-gold">
          <i class="fa fa-scroll gradient-icon"></i>
          <div class="gradient-value" data-counter="<?= $publishedCount ?>">0</div>
          <div class="gradient-label">Published<br>Report Cards</div>
        </div>
      </div>
    </div>
    <div class="card mt-3">
      <div class="card-header"><i class="fa fa-bolt text-gold me-2"></i>Quick Actions</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="<?= e(app_url('/academic/exams.php')) ?>" class="quick-action-item"><i class="fa fa-pencil-alt"></i><span>Schedule Exam</span></a>
          <a href="<?= e(app_url('/academic/verify_marks.php')) ?>" class="quick-action-item"><i class="fa fa-check-double"></i><span>Verify Marks</span></a>
          <a href="<?= e(app_url('/academic/publish_results.php')) ?>" class="quick-action-item"><i class="fa fa-bullhorn"></i><span>Publish Results</span></a>
          <a href="<?= e(app_url('/academic/promotions.php')) ?>" class="quick-action-item"><i class="fa fa-level-up-alt"></i><span>Promotions</span></a>
          <a href="<?= e(app_url('/academic/timetable.php')) ?>" class="quick-action-item"><i class="fa fa-calendar-alt"></i><span>Timetable</span></a>
          <a href="<?= e(app_url('/academic/lesson_reports.php')) ?>" class="quick-action-item"><i class="fa fa-chalkboard-teacher"></i><span>Lesson Reports</span></a>
          <a href="<?= e(app_url('/academic/transcripts.php')) ?>" class="quick-action-item"><i class="fa fa-scroll"></i><span>Transcripts</span></a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Recent Submissions -->
<div class="card animate-fade-in animate-delay-4">
  <div class="card-header"><i class="fa fa-upload text-gold me-2"></i>Recent Mark Submissions</div>
  <div class="table-responsive" id="academicTables">
    <table class="table table-hover mb-0">
      <thead><tr><th>Teacher</th><th>Subject</th><th>Class</th><th>Submitted</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($recentSubmissions as $s): ?>
          <tr>
            <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <td><?= e($s['subject_name']) ?></td>
            <td><?= e($s['level_name'] . ' ' . $s['stream_name']) ?></td>
            <td class="small text-muted"><?= e(date('d M H:i', strtotime($s['submitted_at']))) ?></td>
            <td>
              <span class="badge bg-<?= $s['verification_status'] === 'verified' ? 'success' : ($s['verification_status'] === 'rejected' ? 'danger' : 'warning') ?>">
                <?= e(ucfirst($s['verification_status'])) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recentSubmissions)): ?><tr><td colspan="5" class="text-center text-muted py-4">No submissions yet this term.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Pipeline Chart
  var pipeCtx = document.getElementById('pipelineChart');
  if (pipeCtx) {
    new Chart(pipeCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($pipelineLabels) ?>,
        datasets: [{
          label: 'Exams',
          data: <?= json_encode($pipelineCounts) ?>,
          backgroundColor: ['#334E68', '#2B6CB0', '#DD6B20', '#C8932A', '#1F8A55', '#6B46C1'],
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 }
        },
        scales: {
          y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 }, stepSize: 1 } },
          x: { grid: { display: false }, ticks: { font: { size: 10 } } }
        }
      }
    });
  }

  // Verification Chart
  var verifCtx = document.getElementById('verifChart');
  if (verifCtx) {
    new Chart(verifCtx, {
      type: 'doughnut',
      data: {
        labels: <?= json_encode($verifLabels) ?>,
        datasets: [{ data: <?= json_encode($verifCounts) ?>, backgroundColor: <?= json_encode($verifColors) ?>, borderWidth: 0, hoverOffset: 8 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '70%',
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } }
      }
    });
  }

  // Exam Type Chart
  var examTypeCtx = document.getElementById('examTypeChart');
  if (examTypeCtx) {
    new Chart(examTypeCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($examTypeLabels) ?>,
        datasets: [{
          label: 'Count',
          data: <?= json_encode($examTypeCounts) ?>,
          backgroundColor: ['#102A43', '#2B6CB0', '#C8932A', '#6B46C1', '#DD6B20', '#1F8A55'],
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 } },
        scales: {
          x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 }, stepSize: 1 } },
          y: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
      }
    });
  }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>