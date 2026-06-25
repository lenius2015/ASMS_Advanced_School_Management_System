<?php
/**
 * teacher/timetable.php
 * Read-only view of the subject teacher's own weekly teaching schedule.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['subject_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

$stmt = $pdo->prepare(
    "SELECT tt.*, sub.subject_name, cl.level_name, c.stream_name FROM timetable tt
     JOIN class_subjects cs ON cs.class_subject_id = tt.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE cs.teacher_id = :tid
     ORDER BY FIELD(tt.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), tt.start_time"
);
$stmt->execute(['tid' => $teacherId]);
$slots = $stmt->fetchAll();

$byDay = [];
foreach ($slots as $s) {
    $byDay[$s['day_of_week']][] = $s;
}

$pageTitle = 'My Timetable';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">My Timetable</h1>

<div class="row g-3">
  <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday'] as $day): ?>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-header"><?= e($day) ?></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($byDay[$day] ?? [] as $slot): ?>
            <li class="list-group-item">
              <div class="fw-semibold"><?= e(substr($slot['start_time'],0,5)) ?> - <?= e(substr($slot['end_time'],0,5)) ?></div>
              <div><?= e($slot['subject_name']) ?> &middot; <?= e($slot['level_name'] . ' ' . $slot['stream_name']) ?></div>
              <div class="text-muted"><?= e($slot['room'] ?: 'No room set') ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($byDay[$day])): ?><li class="list-group-item text-muted">No lessons scheduled.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
