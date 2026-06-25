<?php
/**
 * academic/lesson_reports.php
 * Academic Department view of lesson attendance records.
 * Shows per-student details: what was taught, by whom, when, and student attendance status.
 * Accessible to academic_officer, head_of_school, director.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'head_of_school', 'director', 'system_admin']);

$pdo = get_db_connection();

// ---- Filters ----
$teacherFilter = (int) ($_GET['teacher_id'] ?? 0);
$classFilter   = (int) ($_GET['class_id'] ?? 0);
$subjectFilter = (int) ($_GET['subject_id'] ?? 0);
$dateFrom      = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo        = $_GET['date_to'] ?? date('Y-m-d');
$view          = $_GET['view'] ?? 'detail'; // 'detail' or 'summary'

// ---- Data for filter dropdowns ----
$teachers = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name FROM users u
     JOIN roles r ON r.role_id = u.role_id
     WHERE r.role_name IN ('subject_teacher','class_teacher') AND u.is_active = 1
     ORDER BY u.first_name"
)->fetchAll();

$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name
     FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$subjects = $pdo->query("SELECT subject_id, subject_name FROM subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();

// ---- Build query ----
$where = [];
$params = [];

if ($teacherFilter > 0) {
    $where[] = 'la.teacher_id = :tid';
    $params['tid'] = $teacherFilter;
}
if ($classFilter > 0) {
    $where[] = 'c.class_id = :cid';
    $params['cid'] = $classFilter;
}
if ($subjectFilter > 0) {
    $where[] = 'cs.subject_id = :sid';
    $params['sid'] = $subjectFilter;
}

$where[] = 'la.lesson_date BETWEEN :from AND :to';
$params['from'] = $dateFrom;
$params['to'] = $dateTo;

$whereClause = implode(' AND ', $where);

// ---- Per-student detail view ----
$records = [];
$totalPresent = 0;
$totalAbsent = 0;
$totalLate = 0;
$totalExcused = 0;
$totalStudents = 0;

if ($view === 'detail') {
    $sql = "SELECT la.lesson_attendance_id, la.lesson_date, la.topic_covered, la.lesson_notes,
                   tt.day_of_week, tt.start_time, tt.end_time,
                   sub.subject_name, cl.level_name, c.stream_name,
                   t.first_name AS t_first, t.last_name AS t_last,
                   las.student_id, las.status AS student_status,
                   u_s.first_name AS s_first, u_s.last_name AS s_last, stu.admission_no
            FROM lesson_attendance la
            JOIN timetable tt ON tt.timetable_id = la.timetable_id
            JOIN class_subjects cs ON cs.class_subject_id = la.class_subject_id
            JOIN subjects sub ON sub.subject_id = cs.subject_id
            JOIN classes c ON c.class_id = cs.class_id
            JOIN class_levels cl ON cl.class_level_id = c.class_level_id
            JOIN users t ON t.user_id = la.teacher_id
            JOIN lesson_attendance_students las ON las.lesson_attendance_id = la.lesson_attendance_id
            JOIN students stu ON stu.student_id = las.student_id
            JOIN users u_s ON u_s.user_id = stu.user_id
            WHERE {$whereClause}
            ORDER BY la.lesson_date DESC, tt.start_time, u_s.first_name
            LIMIT 2000";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    // Stats
    foreach ($records as $r) {
        $totalStudents++;
        switch ($r['student_status']) {
            case 'present': $totalPresent++; break;
            case 'absent':  $totalAbsent++; break;
            case 'late':    $totalLate++; break;
            case 'excused': $totalExcused++; break;
        }
    }
}

// ---- Summary view ----
$summaryRecords = [];
if ($view === 'summary') {
    $sql = "SELECT la.lesson_attendance_id, la.lesson_date, la.topic_covered,
                   tt.day_of_week, tt.start_time, tt.end_time,
                   sub.subject_name, cl.level_name, c.stream_name,
                   t.first_name AS t_first, t.last_name AS t_last,
                   COUNT(las.lesson_student_id) AS total_students,
                   SUM(CASE WHEN las.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                   SUM(CASE WHEN las.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                   SUM(CASE WHEN las.status = 'late' THEN 1 ELSE 0 END) AS late_count,
                   SUM(CASE WHEN las.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
            FROM lesson_attendance la
            JOIN timetable tt ON tt.timetable_id = la.timetable_id
            JOIN class_subjects cs ON cs.class_subject_id = la.class_subject_id
            JOIN subjects sub ON sub.subject_id = cs.subject_id
            JOIN classes c ON c.class_id = cs.class_id
            JOIN class_levels cl ON cl.class_level_id = c.class_level_id
            JOIN users t ON t.user_id = la.teacher_id
            JOIN lesson_attendance_students las ON las.lesson_attendance_id = la.lesson_attendance_id
            WHERE {$whereClause}
            GROUP BY la.lesson_attendance_id
            ORDER BY la.lesson_date DESC, tt.start_time
            LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $summaryRecords = $stmt->fetchAll();

    foreach ($summaryRecords as $r) {
        $totalStudents += (int) $r['total_students'];
        $totalPresent += (int) $r['present_count'];
        $totalAbsent += (int) $r['absent_count'];
        $totalLate += (int) $r['late_count'];
        $totalExcused += (int) $r['excused_count'];
    }
}

$pageTitle = 'Lesson Reports';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4"><i class="fa fa-chart-pie text-gold me-2"></i>Lesson Attendance Reports</h1>
<p class="text-muted">View detailed lesson attendance records: what was taught, by whom, and per-student status.</p>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-2">
        <label class="form-label">Teacher</label>
        <select name="teacher_id" class="form-select">
          <option value="0">All Teachers</option>
          <?php foreach ($teachers as $t): ?>
            <option value="<?= (int) $t['user_id'] ?>" <?= $teacherFilter === (int) $t['user_id'] ? 'selected' : '' ?>>
              <?= e($t['first_name'] . ' ' . $t['last_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Class</label>
        <select name="class_id" class="form-select">
          <option value="0">All Classes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['class_id'] ?>" <?= $classFilter === (int) $c['class_id'] ? 'selected' : '' ?>>
              <?= e($c['level_name'] . ' ' . $c['stream_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Subject</label>
        <select name="subject_id" class="form-select">
          <option value="0">All Subjects</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int) $s['subject_id'] ?>" <?= $subjectFilter === (int) $s['subject_id'] ? 'selected' : '' ?>>
              <?= e($s['subject_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">From Date</label>
        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">To Date</label>
        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
      </div>
      <div class="col-md-2 align-self-end">
        <label class="form-label">View</label>
        <div class="d-flex gap-1">
          <select name="view" class="form-select">
            <option value="detail" <?= $view === 'detail' ? 'selected' : '' ?>>Per Student</option>
            <option value="summary" <?= $view === 'summary' ? 'selected' : '' ?>>Summary</option>
          </select>
          <button class="btn btn-primary"><i class="fa fa-filter"></i></button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
  <div class="col-md-2">
    <div class="asms-kpi-card accent-purple">
      <i class="fa fa-book-open kpi-icon"></i>
      <div class="kpi-label">Lessons</div>
      <div class="kpi-value"><?= $view === 'detail' ? count(array_unique(array_column($records, 'lesson_attendance_id'))) : count($summaryRecords) ?></div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-check kpi-icon"></i>
      <div class="kpi-label">Present</div>
      <div class="kpi-value" data-counter="<?= $totalPresent ?>">0</div>
      <div class="kpi-sub"><?= $totalStudents > 0 ? round(($totalPresent / $totalStudents) * 100, 1) . '%' : '0%' ?></div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="asms-kpi-card accent-red">
      <i class="fa fa-times kpi-icon"></i>
      <div class="kpi-label">Absent</div>
      <div class="kpi-value" data-counter="<?= $totalAbsent ?>">0</div>
      <div class="kpi-sub"><?= $totalStudents > 0 ? round(($totalAbsent / $totalStudents) * 100, 1) . '%' : '0%' ?></div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="asms-kpi-card accent-orange">
      <i class="fa fa-clock kpi-icon"></i>
      <div class="kpi-label">Late</div>
      <div class="kpi-value" data-counter="<?= $totalLate ?>">0</div>
      <div class="kpi-sub"><?= $totalStudents > 0 ? round(($totalLate / $totalStudents) * 100, 1) . '%' : '0%' ?></div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Excused</div>
      <div class="kpi-value" data-counter="<?= $totalExcused ?>">0</div>
      <div class="kpi-sub"><?= $totalStudents > 0 ? round(($totalExcused / $totalStudents) * 100, 1) . '%' : '0%' ?></div>
    </div>
  </div>
</div>

<!-- Per-Student Detail View -->
<?php if ($view === 'detail'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span><i class="fa fa-list text-gold me-2"></i>Per-Student Lesson Records</span>
      <span class="text-muted small"><?= count($records) ?> record(s)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Day</th>
            <th>Time</th>
            <th>Teacher</th>
            <th>Class</th>
            <th>Subject</th>
            <th>Topic Covered</th>
            <th>Student</th>
            <th>Admission No.</th>
            <th>Status</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r): ?>
            <tr>
              <td class="text-nowrap"><?= e(format_date($r['lesson_date'])) ?></td>
              <td><?= e($r['day_of_week']) ?></td>
              <td class="text-nowrap"><?= e(substr($r['start_time'],0,5)) ?>-<?= e(substr($r['end_time'],0,5)) ?></td>
              <td><?= e($r['t_first'] . ' ' . $r['t_last']) ?></td>
              <td><?= e($r['level_name'] . ' ' . $r['stream_name']) ?></td>
              <td><?= e($r['subject_name']) ?></td>
              <td><?= e($r['topic_covered']) ?></td>
              <td><?= e($r['s_first'] . ' ' . $r['s_last']) ?></td>
              <td><code><?= e($r['admission_no']) ?></code></td>
              <td>
                <span class="badge bg-<?= $r['student_status'] === 'present' ? 'success' : ($r['student_status'] === 'absent' ? 'danger' : ($r['student_status'] === 'late' ? 'warning' : 'info')) ?>">
                  <?= e(ucfirst($r['student_status'])) ?>
                </span>
              </td>
              <td class="small text-muted"><?= e($r['lesson_notes'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($records)): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No records found for the selected filters.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Summary View -->
<?php if ($view === 'summary'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span><i class="fa fa-chart-bar text-gold me-2"></i>Lesson Summary</span>
      <span class="text-muted small"><?= count($summaryRecords) ?> lesson(s)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Day</th>
            <th>Time</th>
            <th>Teacher</th>
            <th>Class</th>
            <th>Subject</th>
            <th>Topic Covered</th>
            <th>Total</th>
            <th>Present</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Excused</th>
            <th>Rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summaryRecords as $r):
            $total = (int) $r['total_students'];
            $present = (int) $r['present_count'];
            $rate = $total > 0 ? round(($present / $total) * 100, 1) : 0;
          ?>
            <tr>
              <td class="text-nowrap"><?= e(format_date($r['lesson_date'])) ?></td>
              <td><?= e($r['day_of_week']) ?></td>
              <td class="text-nowrap"><?= e(substr($r['start_time'],0,5)) ?>-<?= e(substr($r['end_time'],0,5)) ?></td>
              <td><?= e($r['t_first'] . ' ' . $r['t_last']) ?></td>
              <td><?= e($r['level_name'] . ' ' . $r['stream_name']) ?></td>
              <td><?= e($r['subject_name']) ?></td>
              <td><?= e($r['topic_covered']) ?></td>
              <td><?= $total ?></td>
              <td class="text-success"><?= $present ?></td>
              <td class="text-danger"><?= (int) $r['absent_count'] ?></td>
              <td class="text-warning"><?= (int) $r['late_count'] ?></td>
              <td class="text-info"><?= (int) $r['excused_count'] ?></td>
              <td>
                <div class="d-flex align-items-center gap-1">
                  <div class="progress flex-grow-1" style="height:6px;min-width:40px;">
                    <div class="progress-bar bg-success" style="width:<?= $rate ?>%"></div>
                  </div>
                  <small><?= $rate ?>%</small>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($summaryRecords)): ?>
            <tr><td colspan="13" class="text-center text-muted py-4">No records found for the selected filters.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Export hint -->
<div class="mt-3 text-end">
  <a href="?<?= e($_SERVER['QUERY_STRING']) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync me-1"></i>Refresh</a>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
</write_to_file>
<task_progress>
- [x] Create database migration for lesson_attendance tables
- [x] Run migration
- [x] Update teacher/lesson_attendance.php - link to timetable slots, add topic/notes
- [x] Create academic/lesson_reports.php - per-student lesson reports with filters
- [ ] Update teacher dashboard with recent lesson widget
- [ ] Update sidebar navigation for both academic and teacher
- [ ] Test everything
</task_progress>
</write_to_file>