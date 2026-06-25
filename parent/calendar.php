<?php
/**
 * parent/calendar.php
 * Simple school calendar view: term dates and recent announcements,
 * serving as the "school calendar" feature for parents.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['parent']);

$pdo = get_db_connection();

$terms = $pdo->query(
    "SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id
     ORDER BY t.start_date"
)->fetchAll();

$announcements = $pdo->query(
    "SELECT a.*, u.first_name, u.last_name FROM announcements a JOIN users u ON u.user_id = a.posted_by
     WHERE a.audience IN ('all','parents') ORDER BY a.created_at DESC LIMIT 15"
)->fetchAll();

$pageTitle = 'School Calendar';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">School Calendar</h1>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Academic Terms</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($terms as $t): $isCurrent = (bool) $t['is_current']; ?>
          <li class="list-group-item d-flex justify-content-between align-items-center <?= $isCurrent ? 'active' : '' ?>">
            <span><?= e($t['year_name'] . ' - ' . $t['term_name']) ?></span>
            <span class="<?= $isCurrent ? 'text-white' : 'text-muted' ?> small"><?= format_date($t['start_date']) ?> &ndash; <?= format_date($t['end_date']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Announcements</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($announcements as $a): ?>
          <li class="list-group-item">
            <div class="fw-semibold"><?= e($a['title']) ?></div>
            <div class="small"><?= e($a['body']) ?></div>
            <div class="text-muted small"><?= e(format_date($a['created_at'])) ?> by <?= e($a['first_name'] . ' ' . $a['last_name']) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (empty($announcements)): ?><li class="list-group-item text-muted">No announcements yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
