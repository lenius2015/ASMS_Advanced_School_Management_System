<?php
/**
 * class_teacher/subjects.php
 * List all subjects assigned to the class teacher's homeroom class,
 * with details about the subject teacher (name, email, phone, department).
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

// Get the teacher's assigned class
$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name, c.class_level_id FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Class Subjects';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher for any class. Please contact the Academic Department.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// Get all subjects with teacher info for this class
$stmt = $pdo->prepare(
    "SELECT cs.class_subject_id, sub.subject_name, sub.subject_code, sub.subject_id,
            u.user_id AS teacher_user_id, u.first_name AS t_fn, u.last_name AS t_ln,
            u.email AS t_email, u.phone AS t_phone, u.photo_path AS t_photo,
            d.department_name, st.job_title
     FROM class_subjects cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     LEFT JOIN users u ON u.user_id = cs.teacher_id
     LEFT JOIN staff st ON st.user_id = u.user_id
     LEFT JOIN departments d ON d.department_id = st.department_id
     WHERE cs.class_id = :cid
     ORDER BY sub.subject_name"
);
$stmt->execute(['cid' => $myClass['class_id']]);
$subjects = $stmt->fetchAll();

$totalSubjects = count($subjects);
$assignedTeachers = 0;
foreach ($subjects as $s) {
    if ($s['teacher_user_id']) $assignedTeachers++;
}
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Class Subjects: <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>
      <p class="mb-0"><?= $totalSubjects ?> subjects &middot; <?= $assignedTeachers ?> teachers assigned</p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/class_teacher/dashboard.php')) ?>" class="btn btn-outline-light"><i class="fa fa-arrow-left me-1"></i> Back to Dashboard</a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-book kpi-icon"></i>
      <div class="kpi-label">Total Subjects</div>
      <div class="kpi-value" data-counter="<?= $totalSubjects ?>">0</div>
      <div class="kpi-sub">For this class</div>
    </div>
  </div>
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-chalkboard-teacher kpi-icon"></i>
      <div class="kpi-label">Teachers Assigned</div>
      <div class="kpi-value" data-counter="<?= $assignedTeachers ?>">0</div>
      <div class="kpi-sub">Subject teachers</div>
    </div>
  </div>
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card <?= $assignedTeachers === $totalSubjects ? 'accent-green' : 'accent-orange' ?>">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Coverage</div>
      <div class="kpi-value"><?= $totalSubjects > 0 ? round(($assignedTeachers / $totalSubjects) * 100) : 0 ?>%</div>
      <div class="kpi-sub">Subjects with teachers</div>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1 mb-4">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search subjects..." data-search="#subjectsTable" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> All Subjects</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/class_teacher/timetable.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-alt me-1"></i> View Timetable</a>
    <a href="<?= e(app_url('/class_teacher/teachers.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-users me-1"></i> All Teachers</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<div class="card animate-fade-in animate-delay-2">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fa fa-book text-gold me-2"></i>Subject List</span>
    <span class="text-muted small"><?= $totalSubjects ?> subjects</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0" id="subjectsTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Subject</th>
          <th>Code</th>
          <th>Teacher</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Department</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($subjects as $s): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><strong><?= e($s['subject_name']) ?></strong></td>
            <td><code><?= e($s['subject_code']) ?></code></td>
            <td>
              <?php if ($s['teacher_user_id']): ?>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($s['t_photo']): ?>
                    <img src="<?= e($s['t_photo']) ?>" class="rounded-circle" width="32" height="32" style="object-fit:cover;">
                  <?php else: ?>
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:12px;">
                      <?= e(strtoupper(substr($s['t_fn'], 0, 1) . substr($s['t_ln'], 0, 1))) ?>
                    </div>
                  <?php endif; ?>
                  <span><?= e($s['t_fn'] . ' ' . $s['t_ln']) ?></span>
                </div>
              <?php else: ?>
                <span class="text-muted"><em>Unassigned</em></span>
              <?php endif; ?>
            </td>
            <td><?= $s['t_email'] ? e($s['t_email']) : '<span class="text-muted">-</span>' ?></td>
            <td><?= $s['t_phone'] ? e($s['t_phone']) : '<span class="text-muted">-</span>' ?></td>
            <td><?= $s['department_name'] ? e($s['department_name']) : '<span class="text-muted">-</span>' ?></td>
            <td>
              <?php if ($s['teacher_user_id']): ?>
                <span class="badge bg-success">Assigned</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Pending</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($subjects)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No subjects assigned to this class yet. Contact the Academic Department.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center">
    <span class="text-muted small">Showing <?= $totalSubjects ?> subject(s)</span>
    <button class="btn btn-sm btn-outline-primary" onclick="exportTableToCSV('subjectsTable', '<?= e($myClass['level_name'] . '_' . $myClass['stream_name']) ?>_subjects.csv')">
      <i class="fa fa-download me-1"></i> Export CSV
    </button>
  </div>
</div>

<script>
function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    let csv = [];
    const rows = table.querySelectorAll('tr');
    for (let r of rows) {
        const cols = r.querySelectorAll('td, th');
        let row = [];
        for (let c of cols) {
            let text = c.innerText.replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        csv.push(row.join(','));
    }
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
}
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>