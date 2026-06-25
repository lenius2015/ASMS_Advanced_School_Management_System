<?php
/**
 * student/timetable.php
 * The student's own weekly class timetable, derived from their class's
 * subject schedule.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['student']);

$pdo = get_db_connection();
$userId = current_user_id();

$studentStmt = $pdo->prepare('SELECT class_id FROM students WHERE user_id = :uid');
$studentStmt->execute(['uid' => $userId]);
$student = $studentStmt->fetch();

$pageTitle = 'My Timetable';
require APP_ROOT . '/includes/header.php';

if (!$student || !$student['class_id']) {
    echo '<div class="alert alert-warning">Your class assignment could not be found.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT tt.*, sub.subject_name, u.first_name, u.last_name FROM timetable tt
     JOIN class_subjects cs ON cs.class_subject_id = tt.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     LEFT JOIN users u ON u.user_id = cs.teacher_id
     WHERE cs.class_id = :cid
     ORDER BY FIELD(tt.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), tt.start_time"
);
$stmt->execute(['cid' => $student['class_id']]);
$slots = $stmt->fetchAll();

$byDay = [];
foreach ($slots as $s) {
    $byDay[$s['day_of_week']][] = $s;
}
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
              <div><?= e($slot['subject_name']) ?></div>
              <div class="text-muted"><?= e(trim(($slot['first_name'] ?? '') . ' ' . ($slot['last_name'] ?? '')) ?: 'TBA') ?> &middot; <?= e($slot['room'] ?: 'No room') ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($byDay[$day])): ?><li class="list-group-item text-muted">No lessons.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
