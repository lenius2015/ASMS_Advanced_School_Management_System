<?php
/**
 * class_teacher/my_class.php
 * Roster of students in the teacher's homeroom class, with links into
 * each student's full profile.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'My Class';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT s.*, u.first_name, u.last_name FROM students s JOIN users u ON u.user_id = s.user_id
     WHERE s.class_id = :cid ORDER BY u.first_name"
);
$stmt->execute(['cid' => $myClass['class_id']]);
$students = $stmt->fetchAll();
?>

<h1 class="h3 mb-4">My Class: <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>

<div class="card">
  <div class="card-body">
    <input type="text" class="form-control mb-3" placeholder="Search students..." data-table-filter="#rosterTable">
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0" id="rosterTable">
      <thead><tr><th>Admission No.</th><th>Name</th><th>Gender</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td><code><?= e($s['admission_no']) ?></code></td>
            <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <td><?= e(ucfirst($s['gender'] ?? '-')) ?></td>
            <td><span class="badge badge-status-<?= e($s['status']) ?>"><?= e(ucfirst($s['status'])) ?></span></td>
            <td><a href="<?= e(app_url('/director/student_profile.php')) ?>?id=<?= (int) $s['student_id'] ?>" class="btn btn-sm btn-outline-primary">View Profile</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="5" class="text-center text-muted py-4">No students in this class.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
