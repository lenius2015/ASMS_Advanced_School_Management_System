<?php
/**
 * academic/attendance_overview.php
 * Academic Department overview of student attendance across all classes.
 * View daily attendance records per class, per student with filtering
 * by date range and class. Shows attendance statistics for each class.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'head_of_school', 'director', 'system_admin']);

$pdo = get_db_connection();

$classFilter = (int) ($_GET['class_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$statusFilter = $_GET['status'] ?? '';

// ---- Classes list ---------------------------------------------------------
$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count
     FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

// ---- Attendance data ------------------------------------------------------
$attendanceRecords = [];
$classStats = [];

if ($classFilter > 0) {
    $sql = "SELECT sa.*, u.first_name, u.last_name, s.admission_no
            FROM student_attendance sa
            JOIN students s ON s.student_id = sa.student_id
            JOIN users u ON u.user_id = s.user_id
            WHERE sa.class_id = :cid AND sa.attendance_date BETWEEN :from AND :to";
    $params = ['cid' => $classFilter, 'from' => $dateFrom, 'to' => $dateTo];

    if ($statusFilter !== '') {
        $sql .= ' AND sa.status = :status';
        $params['status'] = $statusFilter;
    }

    $sql .= ' ORDER BY sa.attendance_date DESC, u.first_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attendanceRecords = $stmt->fetchAll();

    // Class-wide stats
    $statsStmt = $pdo->prepare(
        "SELECT sa.status, COUNT(*) AS cnt
         FROM student_attendance sa
         WHERE sa.class_id = :cid AND sa.attendance_date BETWEEN :from AND :to
         GROUP BY sa.status"
    );
    $statsStmt->execute(['cid' => $classFilter, 'from' => $dateFrom, 'to' => $dateTo]);
    $classStats = array_column($statsStmt->fetchAll(), 'cnt', 'status');

    // Total attendance count
    $totalStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM student_attendance
         WHERE class_id = :cid AND attendance_date BETWEEN :from AND :to"
    );
    $totalStmt->execute(['cid' => $classFilter, 'from' => $dateFrom, 'to' => $dateTo]);
    $totalAttendance = (int) $totalStmt->fetch()['total'];
} else {
    // Summary per class
    $classStatsAll = $pdo->query(
        "SELECT c.class_id, cl.level_name, c.stream_name,
            COUNT(sa.attendance_id) AS total_records,
            SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN sa.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
         FROM classes c
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         LEFT JOIN student_attendance sa ON sa.class_id = c.class_id
         GROUP BY c.class_id
         ORDER BY cl.sort_order, c.stream_name"
    )->fetchAll();
}

$pageTitle = 'Attendance Overview';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Student Attendance Overview</h1>
<p class="text-muted">View daily attendance records across all classes. Filter by class, date range, and attendance status.</p>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Class</label>
        <select name="class_id" class="form-select" onchange="this.form.submit()">
          <option value="0">All Classes (Summary)</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['class_id'] ?>" <?= $classFilter === (int) $c['class_id'] ? 'selected' : '' ?>>
              <?= e($c['level_name'] . ' ' . $c['stream_name']) ?> (<?= (int) $c['student_count'] ?> students)
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
      <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['present','absent','late','excused'] as $st): ?>
            <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 align-self-end">
        <button class="btn btn-outline-primary w-100"><i class="fa fa-filter me-1"></i> Filter</button>
      </div>
      <?php if ($classFilter > 0): ?>
        <div class="col-md-1 align-self-end">
          <a href="<?= e(app_url('/academic/attendance_overview.php')) ?>?class_id=<?= $classFilter ?>&date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>" class="btn btn-outline-secondary w-100"><i class="fa fa-sync"></i></a>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($classFilter > 0): ?>
  <!-- Class Stats Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="asms-kpi-card accent-green">
        <i class="fa fa-check kpi-icon"></i>
        <div class="kpi-label">Present</div>
        <div class="kpi-value" data-counter="<?= (int) ($classStats['present'] ?? 0) ?>">0</div>
        <div class="kpi-sub">
          <?= $totalAttendance > 0 ? round(((int)($classStats['present'] ?? 0) / $totalAttendance) * 100, 1) . '%' : '0%' ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="asms-kpi-card accent-red">
        <i class="fa fa-times kpi-icon"></i>
        <div class="kpi-label">Absent</div>
        <div class="kpi-value" data-counter="<?= (int) ($classStats['absent'] ?? 0) ?>">0</div>
        <div class="kpi-sub">
          <?= $totalAttendance > 0 ? round(((int)($classStats['absent'] ?? 0) / $totalAttendance) * 100, 1) . '%' : '0%' ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="asms-kpi-card accent-orange">
        <i class="fa fa-clock kpi-icon"></i>
        <div class="kpi-label">Late</div>
        <div class="kpi-value" data-counter="<?= (int) ($classStats['late'] ?? 0) ?>">0</div>
        <div class="kpi-sub">
          <?= $totalAttendance > 0 ? round(((int)($classStats['late'] ?? 0) / $totalAttendance) * 100, 1) . '%' : '0%' ?>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="asms-kpi-card accent-blue">
        <i class="fa fa-check-circle kpi-icon"></i>
        <div class="kpi-label">Excused</div>
        <div class="kpi-value" data-counter="<?= (int) ($classStats['excused'] ?? 0) ?>">0</div>
        <div class="kpi-sub">
          <?= $totalAttendance > 0 ? round(((int)($classStats['excused'] ?? 0) / $totalAttendance) * 100, 1) . '%' : '0%' ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Detailed Records -->
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span><i class="fa fa-calendar-check text-gold me-2"></i>Attendance Records</span>
      <span class="text-muted small"><?= count($attendanceRecords) ?> record(s) found</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Student</th>
            <th>Admission No.</th>
            <th>Status</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attendanceRecords as $a): ?>
            <tr>
              <td><?= e(format_date($a['attendance_date'])) ?></td>
              <td><?= e($a['first_name'] . ' ' . $a['last_name']) ?></td>
              <td><code><?= e($a['admission_no']) ?></code></td>
              <td>
                <span class="badge bg-<?= $a['status'] === 'present' ? 'success' : ($a['status'] === 'absent' ? 'danger' : ($a['status'] === 'late' ? 'warning' : 'info')) ?>">
                  <?= e(ucfirst($a['status'])) ?>
                </span>
              </td>
              <td class="small text-muted"><?= e($a['remarks'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($attendanceRecords)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No attendance records found for the selected criteria.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <!-- Summary view: all classes -->
  <div class="card">
    <div class="card-header"><i class="fa fa-chart-bar text-gold me-2"></i>Attendance Summary by Class</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Class</th>
            <th>Total Records</th>
            <th>Present</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Excused</th>
            <th>Attendance Rate</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($classStatsAll as $cs): $total = (int) $cs['total_records']; ?>
            <tr>
              <td><?= e($cs['level_name'] . ' ' . $cs['stream_name']) ?></td>
              <td><?= $total ?></td>
              <td><span class="text-success"><?= (int) $cs['present_count'] ?></span></td>
              <td><span class="text-danger"><?= (int) $cs['absent_count'] ?></span></td>
              <td><span class="text-warning"><?= (int) $cs['late_count'] ?></span></td>
              <td><span class="text-info"><?= (int) $cs['excused_count'] ?></span></td>
              <td>
                <?php if ($total > 0): ?>
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:8px;">
                      <?php $pct = round(((int) $cs['present_count'] / $total) * 100, 1); ?>
                      <div class="progress-bar bg-success" style="width:<?= e($pct) ?>%"></div>
                    </div>
                    <small><?= e($pct) ?>%</small>
                  </div>
                <?php else: ?>
                  <span class="text-muted">No data</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="?class_id=<?= (int) $cs['class_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-eye"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($classStatsAll)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No classes found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>