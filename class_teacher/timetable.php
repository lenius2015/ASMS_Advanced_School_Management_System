<?php
/**
 * class_teacher/timetable.php
 * Display the weekly timetable for the class teacher's homeroom class
 * in a visual grid format (Monday-Friday, time slots).
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

// Get the teacher's assigned class
$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Class Timetable';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher for any class.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// Get all timetable slots for this class
$slotsStmt = $pdo->prepare(
    "SELECT tt.*, sub.subject_name, sub.subject_code,
            u.first_name AS t_fn, u.last_name AS t_ln, u.photo_path AS t_photo,
            cs.teacher_id
     FROM timetable tt
     JOIN class_subjects cs ON cs.class_subject_id = tt.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     LEFT JOIN users u ON u.user_id = cs.teacher_id
     WHERE cs.class_id = :cid
     ORDER BY FIELD(tt.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), tt.start_time"
);
$slotsStmt->execute(['cid' => $myClass['class_id']]);
$slots = $slotsStmt->fetchAll();

// Organize slots by day
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$timetable = [];
foreach ($days as $day) {
    $timetable[$day] = [];
}
foreach ($slots as $s) {
    if (isset($timetable[$s['day_of_week']])) {
        $timetable[$s['day_of_week']][] = $s;
    }
}

// Get unique time slots across all days for the grid
$allTimes = [];
foreach ($slots as $s) {
    $key = $s['start_time'] . '-' . $s['end_time'];
    $allTimes[$key] = ['start' => substr($s['start_time'], 0, 5), 'end' => substr($s['end_time'], 0, 5)];
}
ksort($allTimes);

$totalSlots = count($slots);
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Class Timetable: <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>
      <p class="mb-0"><?= $totalSlots ?> scheduled slots &middot; Week view</p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/class_teacher/subjects.php')) ?>" class="btn btn-outline-light"><i class="fa fa-book me-1"></i> Subjects</a>
      <a href="<?= e(app_url('/class_teacher/dashboard.php')) ?>" class="btn btn-outline-light"><i class="fa fa-arrow-left me-1"></i> Dashboard</a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-calendar-alt kpi-icon"></i>
      <div class="kpi-label">Total Slots</div>
      <div class="kpi-value" data-counter="<?= $totalSlots ?>">0</div>
      <div class="kpi-sub">Weekly periods</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-clock kpi-icon"></i>
      <div class="kpi-label">Periods/Day</div>
      <div class="kpi-value"><?= $totalSlots > 0 ? round($totalSlots / 5) : 0 ?></div>
      <div class="kpi-sub">Average (Mon-Fri)</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-chalkboard-teacher kpi-icon"></i>
      <div class="kpi-label">Subjects</div>
      <div class="kpi-value"><?= count(array_unique(array_column($slots, 'subject_name'))) ?></div>
      <div class="kpi-sub">Unique subjects</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card accent-gold">
      <i class="fa fa-door-open kpi-icon"></i>
      <div class="kpi-label">Rooms</div>
      <div class="kpi-value"><?= count(array_unique(array_filter(array_column($slots, 'room')))) ?></div>
      <div class="kpi-sub">In use</div>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1 mb-4">
  <div class="action-left">
    <span class="filter-badge"><i class="fa fa-filter"></i> Weekly Timetable</span>
  </div>
  <div class="action-right">
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<?php if (empty($slots)): ?>
  <div class="alert alert-warning animate-fade-in">
    <i class="fa fa-exclamation-triangle me-2"></i>No timetable has been created for this class yet. Please contact the Academic Department.
  </div>
<?php else: ?>
  <!-- Timetable Grid -->
  <div class="card animate-fade-in animate-delay-2">
    <div class="card-header">
      <i class="fa fa-calendar-alt text-gold me-2"></i>Weekly Schedule
    </div>
    <div class="table-responsive">
      <table class="table table-bordered timetable-grid mb-0">
        <thead>
          <tr>
            <th style="width:100px;min-width:100px;" class="text-center">Time</th>
            <?php foreach ($days as $day): ?>
              <th class="text-center"><?= e($day) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($allTimes)): ?>
            <?php foreach ($allTimes as $timeKey => $time): ?>
              <tr>
                <td class="text-center align-middle fw-bold small bg-light">
                  <?= e($time['start']) ?> - <?= e($time['end']) ?>
                </td>
                <?php foreach ($days as $day): ?>
                  <td class="align-middle" style="height:80px;">
                    <?php
                    $daySlots = $timetable[$day] ?? [];
                    $found = false;
                    foreach ($daySlots as $slot):
                        $slotKey = $slot['start_time'] . '-' . $slot['end_time'];
                        if ($slotKey === $timeKey):
                            $found = true;
                    ?>
                        <div class="timetable-slot p-2 rounded border-start border-4 border-primary h-100">
                          <div class="fw-bold small"><?= e($slot['subject_name']) ?></div>
                          <div class="text-muted small">
                            <?php if ($slot['t_fn']): ?>
                              <i class="fa fa-user me-1"></i><?= e($slot['t_fn'] . ' ' . $slot['t_ln']) ?>
                            <?php else: ?>
                              <em>Unassigned</em>
                            <?php endif; ?>
                          </div>
                          <?php if ($slot['room']): ?>
                            <div class="text-muted small"><i class="fa fa-door-open me-1"></i><?= e($slot['room']) ?></div>
                          <?php endif; ?>
                        </div>
                    <?php
                        endif;
                    endforeach;
                    if (!$found):
                    ?>
                      <div class="text-muted text-center small py-3">-</div>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No timetable slots available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Detailed List View -->
  <div class="card mt-4 animate-fade-in animate-delay-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="fa fa-list text-gold me-2"></i>All Slots (Detailed)</span>
      <span class="text-muted small"><?= $totalSlots ?> slots</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="timetableList">
        <thead>
          <tr>
            <th>Day</th>
            <th>Time</th>
            <th>Subject</th>
            <th>Teacher</th>
            <th>Room</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($slots as $s): ?>
            <tr>
              <td><span class="badge bg-secondary"><?= e($s['day_of_week']) ?></span></td>
              <td><?= e(substr($s['start_time'], 0, 5)) ?> - <?= e(substr($s['end_time'], 0, 5)) ?></td>
              <td><strong><?= e($s['subject_name']) ?></strong> <code><?= e($s['subject_code']) ?></code></td>
              <td>
                <?php if ($s['t_fn']): ?>
                  <?= e($s['t_fn'] . ' ' . $s['t_ln']) ?>
                <?php else: ?>
                  <span class="text-muted"><em>Unassigned</em></span>
                <?php endif; ?>
              </td>
              <td><?= e($s['room'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($slots)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No timetable slots found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<style>
.timetable-grid th {
  background-color: #0A1C2E;
  color: #fff;
  font-size: 0.85rem;
  padding: 10px 8px;
}
.timetable-grid td {
  vertical-align: top;
  padding: 6px;
}
.timetable-slot {
  background: #f8f9fa;
  transition: all 0.2s ease;
  cursor: default;
}
.timetable-slot:hover {
  background: #e9ecef;
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
</style>

<?php require APP_ROOT . '/includes/footer.php'; ?>