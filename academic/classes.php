<?php
/**
 * academic/classes.php
 * Manage classes (level + stream), assign class teachers, and link
 * subjects with their respective subject teachers per class.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_class') {
    csrf_verify();
    $classLevelId = (int) ($_POST['class_level_id'] ?? 0);
    $streamName = trim($_POST['stream_name'] ?? 'A');
    $capacity = (int) ($_POST['capacity'] ?? 40);

    if ($classLevelId <= 0 || $streamName === '') {
        flash_set('error', 'Class level and stream name are required.');
    } else {
        try {
            $pdo->prepare('INSERT INTO classes (class_level_id, stream_name, year_id, capacity) VALUES (:cl, :sn, :y, :cap)')
                ->execute(['cl' => $classLevelId, 'sn' => $streamName, 'y' => $period['year_id'], 'cap' => $capacity]);
            audit_log('create_class', 'academics', 'classes', (int) $pdo->lastInsertId(), 'Created new class');
            flash_set('success', 'Class created.');
        } catch (PDOException $e) {
            flash_set('error', 'That class/stream already exists for this academic year.');
        }
    }
    redirect(app_url('/academic/classes.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_class_teacher') {
    csrf_verify();
    $classId = (int) ($_POST['class_id'] ?? 0);
    $teacherId = (int) ($_POST['teacher_id'] ?? 0) ?: null;
    $pdo->prepare('UPDATE classes SET class_teacher_id = :t WHERE class_id = :id')->execute(['t' => $teacherId, 'id' => $classId]);
    audit_log('assign_class_teacher', 'academics', 'classes', $classId, 'Assigned class teacher');
    flash_set('success', 'Class teacher updated.');
    redirect(app_url('/academic/classes.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_subject') {
    csrf_verify();
    $classId = (int) ($_POST['class_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $teacherId = (int) ($_POST['subject_teacher_id'] ?? 0) ?: null;

    $pdo->prepare(
        'INSERT INTO class_subjects (class_id, subject_id, teacher_id) VALUES (:c, :s, :t)
         ON DUPLICATE KEY UPDATE teacher_id = :t2'
    )->execute(['c' => $classId, 's' => $subjectId, 't' => $teacherId, 't2' => $teacherId]);
    audit_log('assign_subject_teacher', 'academics', 'class_subjects', null, 'Assigned subject teacher');
    flash_set('success', 'Subject assignment saved.');
    redirect(app_url('/academic/classes.php') . '?view=' . $classId);
}

$classes = $pdo->query(
    "SELECT c.*, cl.level_name, u.first_name AS ct_fn, u.last_name AS ct_ln,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count
     FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     LEFT JOIN users u ON u.user_id = c.class_teacher_id
     ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$classLevels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();
$subjects = $pdo->query('SELECT * FROM subjects WHERE is_active = 1 ORDER BY subject_name')->fetchAll();
$classTeachers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role_id = (SELECT role_id FROM roles WHERE role_name='class_teacher') AND is_active=1")->fetchAll();
$subjectTeachers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role_id = (SELECT role_id FROM roles WHERE role_name='subject_teacher') AND is_active=1")->fetchAll();

$viewClassId = (int) ($_GET['view'] ?? 0);
$classSubjects = [];
if ($viewClassId > 0) {
    $stmt = $pdo->prepare(
        "SELECT cs.*, sub.subject_name, u.first_name, u.last_name FROM class_subjects cs
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         LEFT JOIN users u ON u.user_id = cs.teacher_id
         WHERE cs.class_id = :id ORDER BY sub.subject_name"
    );
    $stmt->execute(['id' => $viewClassId]);
    $classSubjects = $stmt->fetchAll();
}

$pageTitle = 'Classes & Subjects';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">Classes &amp; Subjects</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newClassModal"><i class="fa fa-plus me-1"></i> New Class</button>
</div>

<div class="card mb-4">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Class</th><th>Students</th><th>Class Teacher</th><th>Capacity</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($classes as $c): ?>
          <tr class="<?= $viewClassId === (int) $c['class_id'] ? 'table-active' : '' ?>">
            <td><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></td>
            <td><?= (int) $c['student_count'] ?> / <?= (int) $c['capacity'] ?></td>
            <td>
              <form method="POST" class="d-flex gap-1">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="assign_class_teacher">
                <input type="hidden" name="class_id" value="<?= (int) $c['class_id'] ?>">
                <select name="teacher_id" class="form-select form-select-sm" onchange="this.form.submit()">
                  <option value="">-- Unassigned --</option>
                  <?php foreach ($classTeachers as $t): ?>
                    <option value="<?= (int) $t['user_id'] ?>" <?= $c['class_teacher_id'] == $t['user_id'] ? 'selected' : '' ?>><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td><?= (int) $c['capacity'] ?></td>
            <td><a href="?view=<?= (int) $c['class_id'] ?>" class="btn btn-sm btn-outline-primary">Manage Subjects</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($viewClassId > 0): ?>
  <div class="card">
    <div class="card-header">Subject Assignments for Selected Class</div>
    <div class="card-body">
      <form method="POST" class="row g-2 mb-3">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="assign_subject">
        <input type="hidden" name="class_id" value="<?= $viewClassId ?>">
        <div class="col-md-4">
          <select name="subject_id" class="form-select" required>
            <option value="">-- Subject --</option>
            <?php foreach ($subjects as $s): ?><option value="<?= (int) $s['subject_id'] ?>"><?= e($s['subject_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <select name="subject_teacher_id" class="form-select">
            <option value="">-- Unassigned Teacher --</option>
            <?php foreach ($subjectTeachers as $t): ?><option value="<?= (int) $t['user_id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Assign</button></div>
      </form>

      <table class="table table-sm">
        <thead><tr><th>Subject</th><th>Teacher</th></tr></thead>
        <tbody>
          <?php foreach ($classSubjects as $cs): ?>
            <tr><td><?= e($cs['subject_name']) ?></td><td><?= e($cs['first_name'] ? $cs['first_name'] . ' ' . $cs['last_name'] : 'Unassigned') ?></td></tr>
          <?php endforeach; ?>
          <?php if (empty($classSubjects)): ?><tr><td colspan="2" class="text-center text-muted py-3">No subjects assigned to this class yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="modal fade" id="newClassModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_class">
        <div class="modal-header"><h5 class="modal-title">Create New Class</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Class Level</label>
            <select name="class_level_id" class="form-select" required>
              <?php foreach ($classLevels as $cl): ?><option value="<?= (int) $cl['class_level_id'] ?>"><?= e($cl['level_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Stream Name</label><input type="text" name="stream_name" class="form-control" placeholder="e.g. A, B, Blue" required></div>
          <div class="mb-2"><label class="form-label">Capacity</label><input type="number" name="capacity" class="form-control" value="40" min="1"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create</button></div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
