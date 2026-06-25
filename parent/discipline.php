<?php
/**
 * parent/discipline.php
 * Discipline history for the selected child (read-only).
 */
require_once __DIR__ . '/../config/config.php';
require_role(['parent']);

$pdo = get_db_connection();
$userId = current_user_id();
$studentId = (int) ($_GET['student_id'] ?? 0) ?: get_default_child($pdo, $userId);
$verifiedStudentId = $studentId ? verify_guardian_owns_student($pdo, $userId, $studentId) : null;

$pageTitle = 'Discipline Reports';
require APP_ROOT . '/includes/header.php';

if (!$verifiedStudentId) {
    echo '<div class="alert alert-warning">No child found or you do not have access to view this record.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$studentInfo = $pdo->prepare("SELECT u.first_name, u.last_name FROM students s JOIN users u ON u.user_id = s.user_id WHERE s.student_id = :id");
$studentInfo->execute(['id' => $verifiedStudentId]);
$student = $studentInfo->fetch();

$records = $pdo->prepare('SELECT * FROM discipline_records WHERE student_id = :id ORDER BY incident_date DESC');
$records->execute(['id' => $verifiedStudentId]);
$rows = $records->fetchAll();
?>

<h1 class="h3 mb-1">Discipline Reports</h1>
<p class="text-muted mb-4"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Action Taken</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= format_date($r['incident_date']) ?></td>
            <td><span class="badge badge-status-<?= $r['category']==='severe' ? 'overdue' : ($r['category']==='moderate' ? 'pending' : 'active') ?>"><?= e(ucfirst($r['category'])) ?></span></td>
            <td><?= e($r['description']) ?></td>
            <td class="small"><?= e($r['action_taken'] ?: '-') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="4" class="text-center text-muted py-4">No discipline records. Clean record!</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
