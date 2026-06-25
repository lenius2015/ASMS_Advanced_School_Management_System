<?php
/**
 * teacher/my_classes.php
 * Lists the classes/subjects the teacher is assigned to, with a roster
 * link for each.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['subject_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

$stmt = $pdo->prepare(
    "SELECT cs.class_subject_id, cs.class_id, sub.subject_name, cl.level_name, c.stream_name,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count
     FROM class_subjects cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE cs.teacher_id = :tid ORDER BY cl.sort_order"
);
$stmt->execute(['tid' => $teacherId]);
$assignments = $stmt->fetchAll();

$rosterClassId = (int) ($_GET['class_id'] ?? 0);
$roster = [];
if ($rosterClassId > 0) {
    $rStmt = $pdo->prepare(
        "SELECT s.student_id, u.first_name, u.last_name, s.admission_no FROM students s
         JOIN users u ON u.user_id = s.user_id WHERE s.class_id = :cid AND s.status='active' ORDER BY u.first_name"
    );
    $rStmt->execute(['cid' => $rosterClassId]);
    $roster = $rStmt->fetchAll();
}

$pageTitle = 'My Classes';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">My Classes &amp; Subjects</h1>

<div class="card mb-4">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Class</th><th>Subject</th><th>Students</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($assignments as $a): ?>
          <tr>
            <td><?= e($a['level_name'] . ' ' . $a['stream_name']) ?></td>
            <td><?= e($a['subject_name']) ?></td>
            <td><?= (int) $a['student_count'] ?></td>
            <td><a href="?class_id=<?= (int) $a['class_id'] ?>" class="btn btn-sm btn-outline-primary">View Roster</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($assignments)): ?><tr><td colspan="4" class="text-center text-muted py-4">No classes assigned yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($rosterClassId > 0): ?>
  <div class="card">
    <div class="card-header">Class Roster</div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Admission No.</th><th>Name</th></tr></thead>
        <tbody>
          <?php foreach ($roster as $r): ?>
            <tr><td><code><?= e($r['admission_no']) ?></code></td><td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
