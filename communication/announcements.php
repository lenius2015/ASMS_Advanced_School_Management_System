<?php
/**
 * communication/announcements.php
 * Create and view announcements. Posting is restricted to staff roles
 * that manage communication; everyone with access can view announcements
 * relevant to their audience.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

$pdo = get_db_connection();
$canPost = in_array(current_role(), ['director', 'head_of_school', 'department_head', 'academic_officer', 'class_teacher'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_announcement') {
    if (!$canPost) {
        flash_set('error', 'You do not have permission to post announcements.');
        redirect(app_url('/communication/announcements.php'));
    }
    csrf_verify();

    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $audience = $_POST['audience'] ?? 'all';
    $classId = (int) ($_POST['class_id'] ?? 0) ?: null;
    $priority = $_POST['priority'] ?? 'normal';

    if ($title === '' || $body === '') {
        flash_set('error', 'Title and message body are required.');
    } else {
        $pdo->prepare(
            'INSERT INTO announcements (title, body, audience, class_id, priority, posted_by) VALUES (:t, :b, :aud, :cid, :pri, :by)'
        )->execute(['t' => $title, 'b' => $body, 'aud' => $audience, 'cid' => $classId, 'pri' => $priority, 'by' => current_user_id()]);
        audit_log('post_announcement', 'communication', 'announcements', (int) $pdo->lastInsertId(), "Posted announcement: {$title}");
        flash_set('success', 'Announcement posted.');
    }
    redirect(app_url('/communication/announcements.php'));
}

// Determine which audiences this role is allowed to see
$roleAudienceMap = [
    'director' => ['all','staff','board'], 'school_board' => ['all','board'],
    'head_of_school' => ['all','staff'], 'department_head' => ['all','staff'],
    'bursar' => ['all','staff'], 'academic_officer' => ['all','staff'],
    'subject_teacher' => ['all','staff'], 'class_teacher' => ['all','staff'],
    'parent' => ['all','parents'], 'student' => ['all','students'], 'system_admin' => ['all','staff'],
];
$visibleAudiences = $roleAudienceMap[current_role()] ?? ['all'];
$placeholders = implode(',', array_fill(0, count($visibleAudiences), '?'));

// ---- Handle Delete Announcement ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_announcement') {
    csrf_verify();
    $announcementId = (int) ($_POST['announcement_id'] ?? 0);
    if ($announcementId > 0) {
        $pdo->prepare('DELETE FROM announcements WHERE announcement_id = :id')->execute(['id' => $announcementId]);
        flash_set('success', 'Announcement deleted.');
    }
    redirect(app_url('/communication/announcements.php'));
}

$stmt = $pdo->prepare("SELECT a.*, u.first_name, u.last_name FROM announcements a JOIN users u ON u.user_id = a.posted_by WHERE a.audience IN ({$placeholders}) OR a.audience = 'specific_class' ORDER BY a.created_at DESC LIMIT 50");
$stmt->execute($visibleAudiences);
$announcements = $stmt->fetchAll();

$classes = $pdo->query("SELECT c.class_id, cl.level_name, c.stream_name FROM classes c JOIN class_levels cl ON cl.class_level_id = c.class_level_id")->fetchAll();

$pageTitle = 'Announcements';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">Announcements</h1>
  <?php if ($canPost): ?>
    <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newAnnouncementModal"><i class="fa fa-plus me-1"></i> New Announcement</button>
  <?php endif; ?>
</div>

<div class="row g-3">
  <?php foreach ($announcements as $a): ?>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <h5 class="card-title"><?= e($a['title']) ?></h5>
            <div class="d-flex gap-1">
              <span class="badge bg-<?= $a['priority']==='urgent' ? 'danger' : ($a['priority']==='important' ? 'warning' : 'secondary') ?>"><?= e(ucfirst($a['priority'])) ?></span>
              <?php if ($canPost || current_role() === 'director' || current_role() === 'system_admin'): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this announcement?')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_announcement">
                  <input type="hidden" name="announcement_id" value="<?= (int) $a['announcement_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <p class="card-text"><?= nl2br(e($a['body'])) ?></p>
          <p class="text-muted small mb-0">By <?= e($a['first_name'] . ' ' . $a['last_name']) ?> &middot; <?= e(format_date($a['created_at'])) ?> &middot; Audience: <?= e(str_replace('_',' ',ucfirst($a['audience']))) ?></p>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($announcements)): ?><div class="col-12"><div class="alert alert-info text-center">No announcements yet.</div></div><?php endif; ?>
</div>

<?php if ($canPost): ?>
<div class="modal fade" id="newAnnouncementModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="post_announcement">
        <div class="modal-header"><h5 class="modal-title">Post Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Message</label><textarea name="body" class="form-control" rows="4" required></textarea></div>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Audience</label>
              <select name="audience" class="form-select" id="audienceSelect">
                <option value="all">Everyone</option>
                <option value="staff">Staff Only</option>
                <option value="parents">Parents Only</option>
                <option value="students">Students Only</option>
                <option value="board">School Board</option>
                <option value="specific_class">Specific Class</option>
              </select>
            </div>
            <div class="col-md-4" id="classSelectWrap" style="display:none;">
              <label class="form-label">Class</label>
              <select name="class_id" class="form-select">
                <?php foreach ($classes as $c): ?><option value="<?= (int) $c['class_id'] ?>"><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Priority</label>
              <select name="priority" class="form-select">
                <option value="normal">Normal</option><option value="important">Important</option><option value="urgent">Urgent</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Post</button></div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('audienceSelect')?.addEventListener('change', function () {
  document.getElementById('classSelectWrap').style.display = this.value === 'specific_class' ? 'block' : 'none';
});
</script>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
