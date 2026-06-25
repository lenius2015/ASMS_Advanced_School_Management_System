<?php
/**
 * academic/timetable.php
 * Build the weekly timetable: assign a day/time/room slot to a
 * class-subject pairing (which already has its teacher set).
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$classFilter = (int) ($_GET['class_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_slot') {
    csrf_verify();
    $classSubjectId = (int) ($_POST['class_subject_id'] ?? 0);
    $day = $_POST['day_of_week'] ?? '';
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';
    $room = trim($_POST['room'] ?? '');

    if ($classSubjectId <= 0 || $day === '' || $start === '' || $end === '') {
        flash_set('error', 'All fields except room are required.');
    } else {
        $pdo->prepare(
            'INSERT INTO timetable (class_subject_id, day_of_week, start_time, end_time, room) VALUES (:cs, :day, :start, :end, :room)'
        )->execute(['cs' => $classSubjectId, 'day' => $day, 'start' => $start, 'end' => $end, 'room' => $room ?: null]);
        audit_log('add_timetable_slot', 'academics', 'timetable', (int) $pdo->lastInsertId(), 'Added timetable slot');
        flash_set('success', 'Timetable slot added.');
    }
    redirect(app_url('/academic/timetable.php') . ($classFilter ? '?class_id=' . $classFilter : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_slot') {
    csrf_verify();
    $slotId = (int) ($_POST['timetable_id'] ?? 0);
    $pdo->prepare('DELETE FROM timetable WHERE timetable_id = :id')->execute(['id' => $slotId]);
    flash_set('success', 'Timetable slot removed.');
    redirect(app_url('/academic/timetable.php') . ($classFilter ? '?class_id=' . $classFilter : ''));
}

$classes = $pdo->query("SELECT c.class_id, cl.level_name, c.stream_name FROM classes c JOIN class_levels cl ON cl.class_level_id = c.class_level_id ORDER BY cl.sort_order")->fetchAll();

$classSubjects = [];
$slots = [];
if ($classFilter > 0) {
    $csStmt = $pdo->prepare(
        "SELECT cs.class_subject_id, sub.subject_name FROM class_subjects cs
         JOIN subjects sub ON sub.subject_id = cs.subject_id WHERE cs.class_id = :cid ORDER BY sub.subject_name"
    );
    $csStmt->execute(['cid' => $classFilter]);
    $classSubjects = $csStmt->fetchAll();

    $slotsStmt = $pdo->prepare(
        "SELECT tt.*, sub.subject_name, u.first_name, u.last_name FROM timetable tt
         JOIN class_subjects cs ON cs.class_subject_id = tt.class_subject_id
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         LEFT JOIN users u ON u.user_id = cs.teacher_id
         WHERE cs.class_id = :cid
         ORDER BY FIELD(tt.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), tt.start_time"
    );
    $slotsStmt->execute(['cid' => $classFilter]);
    $slots = $slotsStmt->fetchAll();
}

$pageTitle = 'Timetable Management';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Timetable Management</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4">
        <select name="class_id" class="form-select" onchange="this.form.submit()">
          <option value="0">-- Select a Class --</option>
          <?php foreach ($classes as $c): ?><option value="<?= (int) $c['class_id'] ?>" <?= $classFilter === (int) $c['class_id'] ? 'selected' : '' ?>><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if ($classFilter > 0): ?>
  <div class="card mb-4">
    <div class="card-header">Add Timetable Slot</div>
    <div class="card-body">
      <form method="POST" class="row g-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="add_slot">
        <div class="col-md-3">
          <select name="class_subject_id" class="form-select" required>
            <option value="">-- Subject --</option>
            <?php foreach ($classSubjects as $cs): ?><option value="<?= (int) $cs['class_subject_id'] ?>"><?= e($cs['subject_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="day_of_week" class="form-select" required>
            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday'] as $d): ?><option value="<?= $d ?>"><?= $d ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><input type="time" name="start_time" class="form-control" required></div>
        <div class="col-md-2"><input type="time" name="end_time" class="form-control" required></div>
        <div class="col-md-2"><input type="text" name="room" class="form-control" placeholder="Room"></div>
        <div class="col-md-1"><button class="btn btn-primary w-100"><i class="fa fa-plus"></i></button></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Weekly Timetable</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($slots as $s): ?>
            <tr>
              <td><?= e($s['day_of_week']) ?></td>
              <td><?= e(substr($s['start_time'],0,5)) ?> - <?= e(substr($s['end_time'],0,5)) ?></td>
              <td><?= e($s['subject_name']) ?></td>
              <td class="small text-muted"><?= e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: 'Unassigned') ?></td>
              <td><?= e($s['room'] ?: '-') ?></td>
              <td>
                <form method="POST" data-confirm="Remove this timetable slot?">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_slot">
                  <input type="hidden" name="timetable_id" value="<?= (int) $s['timetable_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($slots)): ?><tr><td colspan="6" class="text-center text-muted py-4">No timetable slots yet for this class.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
