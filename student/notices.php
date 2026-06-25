<?php
/**
 * student/notices.php
 * School-wide announcements visible to students.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['student']);

$pdo = get_db_connection();

$notices = $pdo->query(
    "SELECT a.*, u.first_name, u.last_name FROM announcements a JOIN users u ON u.user_id = a.posted_by
     WHERE a.audience IN ('all','students') ORDER BY a.created_at DESC LIMIT 30"
)->fetchAll();

$pageTitle = 'School Notices';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">School Notices</h1>

<div class="card">
  <ul class="list-group list-group-flush">
    <?php foreach ($notices as $n): ?>
      <li class="list-group-item">
        <div class="d-flex justify-content-between">
          <span class="fw-semibold"><?= e($n['title']) ?></span>
          <span class="badge bg-<?= $n['priority']==='urgent' ? 'danger' : ($n['priority']==='important' ? 'warning' : 'secondary') ?>"><?= e(ucfirst($n['priority'])) ?></span>
        </div>
        <p class="mb-1"><?= e($n['body']) ?></p>
        <div class="text-muted small"><?= e(format_date($n['created_at'])) ?> by <?= e($n['first_name'] . ' ' . $n['last_name']) ?></div>
      </li>
    <?php endforeach; ?>
    <?php if (empty($notices)): ?><li class="list-group-item text-muted text-center py-4">No notices yet.</li><?php endif; ?>
  </ul>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
