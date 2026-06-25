<?php
/**
 * teacher/dashboard.php
 * Subject teacher's dashboard: classes taught, pending mark entry,
 * timetable, and attendance with interactive visualizations.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['subject_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

// ====== My Classes ======
$myClasses = $pdo->prepare(
    "SELECT cs.class_subject_id, sub.subject_name, cl.level_name, c.stream_name,
            (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count
     FROM class_subjects cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE cs.teacher_id = :tid ORDER BY cl.sort_order"
);
$myClasses->execute(['tid' => $teacherId]);
$classes = $myClasses->fetchAll();
$totalClasses = count($classes);
$totalStudents = array_sum(array_column($classes, 'student_count'));

// ====== Pending exams ======
$pendingExams = $pdo->prepare(
    "SELECT e.exam_id, e.exam_name, cs.class_subject_id, sub.subject_name, cl.level_name, c.stream_name,
            e.status AS exam_status
     FROM exams e
     JOIN class_subjects cs ON cs.teacher_id = :tid
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE e.status IN ('scheduled','ongoing','marks_pending')
     ORDER BY e.created_at DESC LIMIT 10"
);
$pendingExams->execute(['tid' => $teacherId]);
$examTasks = $pendingExams->fetchAll();

// ====== Rejected marks count ======
$rejectedCount = $pdo->prepare(
    "SELECT COUNT(*) c FROM exam_marks em
     JOIN class_subjects cs ON cs.class_subject_id = em.class_subject_id
     WHERE cs.teacher_id = :tid AND em.verification_status = 'rejected'"
);
$rejectedCount->execute(['tid' => $teacherId]);
$rejected = (int) $rejectedCount->fetch()['c'];

// ====== Submitted marks count ======
$submittedCount = $pdo->prepare(
    "SELECT COUNT(*) c FROM exam_marks em
     JOIN class_subjects cs ON cs.class_subject_id = em.class_subject_id
     WHERE cs.teacher_id = :tid AND em.submitted_at IS NOT NULL"
);
$submittedCount->execute(['tid' => $teacherId]);
$submitted = (int) $submittedCount->fetch()['c'];

// ====== Today's timetable ======
$today = date('l');
$todayTimetable = $pdo->prepare(
    "SELECT t.*, sub.subject_name, cl.level_name, c.stream_name
     FROM timetable t
     JOIN class_subjects cs ON cs.class_subject_id = t.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE cs.teacher_id = :tid AND t.day_of_week = :day
     ORDER BY t.start_time"
);
$todayTimetable->execute(['tid' => $teacherId, 'day' => $today]);
$todaysLessons = $todayTimetable->fetchAll();

// ====== Marks status for chart ======
$totalMarks = $submitted + $rejected;
$pendingMarksEntry = $pdo->prepare(
    "SELECT COUNT(*) c FROM exam_marks em
     JOIN class_subjects cs ON cs.class_subject_id = em.class_subject_id
     WHERE cs.teacher_id = :tid AND em.marks_obtained IS NULL AND em.is_absent = 0"
);
$pendingMarksEntry->execute(['tid' => $teacherId]);
$pendingEntry = (int) $pendingMarksEntry->fetch()['c'];

$pageTitle = 'Teacher Dashboard';
require APP_ROOT . '/includes/header.php';
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Welcome, <?= e($_SESSION['full_name']) ?> <span class="badge bg-gold ms-2">Subject Teacher</span></h1>
      <p class="mb-0"><?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/teacher/enter_marks.php')) ?>" class="btn btn-gold"><i class="fa fa-edit me-1"></i> Enter Marks</a>
      <a href="<?= e(app_url('/teacher/my_classes.php')) ?>" class="btn btn-outline-light"><i class="fa fa-users me-1"></i> My Classes</a>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search classes..." data-search="#classesTable" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> My Dashboard</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/teacher/enter_marks.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-edit me-1"></i> Enter Marks</a>
    <a href="<?= e(app_url('/teacher/my_classes.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-users me-1"></i> My Classes</a>
    <a href="<?= e(app_url('/teacher/timetable.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-alt me-1"></i> Timetable</a>
    <a href="<?= e(app_url('/teacher/lesson_attendance.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-chalkboard-teacher me-1"></i> Lesson Attendance</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<?php if ($rejected > 0): ?>
  <div class="alert alert-warning animate-fade-in">
    <i class="fa fa-exclamation-triangle me-2"></i>
    You have <strong><?= $rejected ?></strong> rejected mark entry batch(es) that need correction.
    <a href="<?= e(app_url('/teacher/enter_marks.php')) ?>" class="alert-link">Review now &rarr;</a>
  </div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-chalkboard-teacher kpi-icon"></i>
      <div class="kpi-label">My Classes</div>
      <div class="kpi-value" data-counter="<?= $totalClasses ?>">0</div>
      <div class="kpi-sub">Assigned subjects</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-user-graduate kpi-icon"></i>
      <div class="kpi-label">My Students</div>
      <div class="kpi-value" data-counter="<?= $totalStudents ?>">0</div>
      <div class="kpi-sub">Across all classes</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Marks Submitted</div>
      <div class="kpi-value" data-counter="<?= $submitted ?>">0</div>
      <div class="kpi-sub">Entries completed</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card <?= $pendingEntry > 0 ? 'accent-orange' : 'accent-green' ?>">
      <i class="fa fa-hourglass-half kpi-icon"></i>
      <div class="kpi-label">Pending Entry</div>
      <div class="kpi-value" data-counter="<?= $pendingEntry ?>">0</div>
      <div class="kpi-sub">Marks to be entered</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-5 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-calendar-alt text-gold me-2"></i>Today's Schedule</div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($todaysLessons as $l): ?>
            <li class="activity-item">
              <div class="activity-icon icon-info"><i class="fa fa-clock"></i></div>
              <div class="activity-content">
                <div class="activity-title"><?= e($l['subject_name']) ?></div>
                <div class="activity-text"><?= e($l['level_name'] . ' ' . $l['stream_name']) ?></div>
              </div>
              <div class="activity-time">
                <?= e(date('H:i', strtotime($l['start_time'])))?>-<?= e(date('H:i', strtotime($l['end_time'])))?>
                <a href="<?= e(app_url('/teacher/lesson_attendance.php')) ?>?date=<?= e(date('Y-m-d')) ?>&slot=<?= (int)$l['timetable_id'] ?>" class="btn btn-sm btn-outline-success ms-1" title="Record lesson"><i class="fa fa-check"></i></a>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($todaysLessons)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No lessons scheduled today.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="card-footer bg-white">
        <a href="<?= e(app_url('/teacher/timetable.php')) ?>" class="small">View full timetable &rarr;</a>
      </div>
    </div>
  </div>

  <div class="col-lg-7 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-book text-gold me-2"></i>My Classes & Subjects</div>
      <div class="table-responsive" id="classesTable">
        <table class="table table-hover mb-0">
          <thead><tr><th>Class</th><th>Subject</th><th>Students</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($classes as $c): ?>
              <tr>
                <td><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></td>
                <td><?= e($c['subject_name']) ?></td>
                <td><?= (int) $c['student_count'] ?></td>
                <td><a href="<?= e(app_url('/teacher/enter_marks.php')) ?>?class_subject_id=<?= (int)$c['class_subject_id'] ?>" class="btn btn-sm btn-outline-primary">Enter Marks</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($classes)): ?><tr><td colspan="4" class="text-center text-muted py-4">No classes assigned yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card animate-fade-in animate-delay-3">
  <div class="card-header"><i class="fa fa-pencil-alt text-gold me-2"></i>Exams Awaiting Marks</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Exam</th><th>Subject / Class</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($examTasks as $t): ?>
          <tr>
            <td><?= e($t['exam_name']) ?></td>
            <td class="small"><?= e($t['subject_name'] . ' — ' . $t['level_name'] . ' ' . $t['stream_name']) ?></td>
            <td><span class="badge bg-<?= $t['exam_status'] === 'marks_pending' ? 'warning' : ($t['exam_status'] === 'ongoing' ? 'primary' : 'secondary') ?>"><?= e(str_replace('_',' ',ucfirst($t['exam_status']))) ?></span></td>
            <td><a href="<?= e(app_url('/teacher/enter_marks.php')) ?>?exam_id=<?= (int)$t['exam_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-edit"></i> Enter</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($examTasks)): ?><tr><td colspan="4" class="text-center text-muted py-4">No pending exam tasks. All caught up!</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>