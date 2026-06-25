<?php
/**
 * communication/notifications.php
 * Communication Control Panel - System-wide messaging and notification management.
 * Admin can send broadcast messages, control who can send/receive messages,
 * and manage system notifications. Students can be restricted from sending messages.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin']);

$pdo = get_db_connection();

// ====== Handle sending broadcast message ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_broadcast') {
    csrf_verify();
    
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $targetRole = $_POST['target_role'] ?? 'all';
    $type = $_POST['type'] ?? 'system';
    $priority = $_POST['priority'] ?? 'normal';
    
    if ($title === '' || $body === '') {
        flash_set('error', 'Title and message body are required.');
        redirect(app_url('/communication/notifications.php'));
    }
    
    // Build query to get target users
    $sql = 'SELECT user_id FROM users WHERE is_active = 1';
    $params = [];
    if ($targetRole !== 'all') {
        $sql .= ' AND role_id = (SELECT role_id FROM roles WHERE role_name = :role)';
        $params['role'] = $targetRole;
    }
    
    $users = $pdo->prepare($sql);
    $users->execute($params);
    $userIds = $users->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($userIds)) {
        flash_set('error', 'No active users found for the selected target.');
        redirect(app_url('/communication/notifications.php'));
    }
    
    // Insert notification for each user
    $insert = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, body, type, link, created_at) 
         VALUES (:uid, :title, :body, :type, :link, NOW())'
    );
    
    $link = $_POST['link'] ?? null;
    $sent = 0;
    foreach ($userIds as $uid) {
        $insert->execute([
            'uid' => $uid,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'link' => $link ?: null,
        ]);
        $sent++;
    }
    
    audit_log('broadcast_notification', 'communication', 'notifications', null, "Broadcast '{$title}' to {$sent} users ({$targetRole})");
    flash_set('success', "Broadcast message sent to {$sent} users.");
    redirect(app_url('/communication/notifications.php'));
}

// ====== Handle communication settings update ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_comm_settings') {
    csrf_verify();
    
    $settings = [
        'comm_student_can_send' => $_POST['comm_student_can_send'] ?? '0',
        'comm_student_can_receive' => $_POST['comm_student_can_receive'] ?? '1',
        'comm_parent_can_send' => $_POST['comm_parent_can_send'] ?? '1',
        'comm_teacher_can_send' => $_POST['comm_teacher_can_send'] ?? '1',
        'comm_require_moderation' => $_POST['comm_require_moderation'] ?? '0',
        'comm_allow_attachments' => $_POST['comm_allow_attachments'] ?? '1',
        'comm_max_message_length' => (int) ($_POST['comm_max_message_length'] ?? 2000),
    ];
    
    foreach ($settings as $key => $value) {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = :v2'
        )->execute(['k' => $key, 'v' => (string) $value, 'v2' => (string) $value]);
    }
    
    audit_log('update_comm_settings', 'communication', 'system_settings', null, 'Updated communication control settings');
    flash_set('success', 'Communication settings updated successfully.');
    redirect(app_url('/communication/notifications.php'));
}

// ====== Handle clear notifications ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_notifications') {
    csrf_verify();
    $targetRole = $_POST['target_role'] ?? 'all';
    
    if ($targetRole === 'all') {
        $pdo->exec('DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $count = $pdo->rowCount();
    } else {
        $stmt = $pdo->prepare(
            'DELETE n FROM notifications n 
             JOIN users u ON u.user_id = n.user_id 
             WHERE u.role_id = (SELECT role_id FROM roles WHERE role_name = :role)
             AND n.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $stmt->execute(['role' => $targetRole]);
        $count = $stmt->rowCount();
    }
    
    flash_set('success', "Cleared {$count} old notifications.");
    redirect(app_url('/communication/notifications.php'));
}

// ====== Load data ======
$roles = $pdo->query('SELECT * FROM roles ORDER BY role_name')->fetchAll();

// Get current communication settings
$commSettings = [
    'comm_student_can_send' => get_setting($pdo, 'comm_student_can_send', '0'),
    'comm_student_can_receive' => get_setting($pdo, 'comm_student_can_receive', '1'),
    'comm_parent_can_send' => get_setting($pdo, 'comm_parent_can_send', '1'),
    'comm_teacher_can_send' => get_setting($pdo, 'comm_teacher_can_send', '1'),
    'comm_require_moderation' => get_setting($pdo, 'comm_require_moderation', '0'),
    'comm_allow_attachments' => get_setting($pdo, 'comm_allow_attachments', '1'),
    'comm_max_message_length' => get_setting($pdo, 'comm_max_message_length', '2000'),
];

// Get recent notifications
$recentNotifications = $pdo->query(
    "SELECT n.*, u.first_name, u.last_name, u.username, r.role_name
     FROM notifications n
     LEFT JOIN users u ON u.user_id = n.user_id
     LEFT JOIN roles r ON r.role_id = u.role_id
     ORDER BY n.created_at DESC LIMIT 50"
)->fetchAll();

// Count unread notifications per role
$unreadStats = $pdo->query(
    "SELECT r.role_name, COUNT(*) as cnt
     FROM notifications n
     JOIN users u ON u.user_id = n.user_id
     JOIN roles r ON r.role_id = u.role_id
     WHERE n.is_read = 0
     GROUP BY r.role_name ORDER BY cnt DESC"
)->fetchAll();

$pageTitle = 'Communication Control';
require APP_ROOT . '/includes/header.php';
?>

<style>
.comm-card { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 16px; }
.comm-card .comm-header { background: #f7fafc; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 600; border-radius: 8px 8px 0 0; }
.comm-card .comm-body { padding: 16px; }
.toggle-switch { position: relative; display: inline-block; width: 48px; height: 24px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e0; transition: .3s; border-radius: 24px; }
.toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
.toggle-switch input:checked + .toggle-slider { background-color: #2B6CB0; }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); }
.role-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.role-badge.student { background: #ebf8ff; color: #2b6cb0; }
.role-badge.parent { background: #fefcbf; color: #975a16; }
.role-badge.teacher { background: #c6f6d5; color: #276749; }
.role-badge.admin { background: #fed7d7; color: #9b2c2c; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-comment-dots text-gold me-2"></i>Communication Control</h1>
  <div>
    <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#broadcastModal">
      <i class="fa fa-bullhorn me-1"></i> Send Broadcast
    </button>
  </div>
</div>

<!-- Unread Stats Row -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-bell text-gold me-2"></i>Unread Notifications by Role</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3">
          <?php 
          $totalUnread = 0;
          foreach ($unreadStats as $stat): 
            $totalUnread += (int) $stat['cnt'];
          ?>
            <div class="text-center p-3 border rounded" style="min-width:100px;">
              <div class="h4 mb-1"><?= (int) $stat['cnt'] ?></div>
              <small class="text-muted"><?= e(str_replace('_', ' ', ucfirst($stat['role_name']))) ?></small>
            </div>
          <?php endforeach; ?>
          <?php if (empty($unreadStats)): ?>
            <div class="text-muted">No unread notifications.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Communication Settings -->
  <div class="col-lg-5">
    <div class="comm-card">
      <div class="comm-header">
        <i class="fa fa-sliders-h me-2"></i>Messaging Permissions
        <span class="badge bg-info ms-2">WhatsApp-Style Control</span>
      </div>
      <div class="comm-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_comm_settings">
          
          <div class="mb-3">
            <label class="fw-semibold d-block mb-2">Student Messaging</label>
            <div class="d-flex align-items-center mb-2">
              <label class="toggle-switch me-2">
                <input type="checkbox" name="comm_student_can_send" value="1" <?= $commSettings['comm_student_can_send'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span class="small">Students can <strong>send</strong> messages</span>
            </div>
            <div class="d-flex align-items-center">
              <label class="toggle-switch me-2">
                <input type="checkbox" name="comm_student_can_receive" value="1" <?= $commSettings['comm_student_can_receive'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span class="small">Students can <strong>receive</strong> messages</span>
            </div>
            <div class="text-muted small mt-1">
              <i class="fa fa-info-circle me-1"></i>
              When disabled, students are restricted from sending messages (like WhatsApp group restrictions).
            </div>
          </div>
          
          <div class="mb-3">
            <label class="fw-semibold d-block mb-2">Parent Messaging</label>
            <div class="d-flex align-items-center mb-2">
              <label class="toggle-switch me-2">
                <input type="checkbox" name="comm_parent_can_send" value="1" <?= $commSettings['comm_parent_can_send'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span class="small">Parents can <strong>send</strong> messages</span>
            </div>
          </div>
          
          <div class="mb-3">
            <label class="fw-semibold d-block mb-2">Teacher Messaging</label>
            <div class="d-flex align-items-center mb-2">
              <label class="toggle-switch me-2">
                <input type="checkbox" name="comm_teacher_can_send" value="1" <?= $commSettings['comm_teacher_can_send'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span class="small">Teachers can <strong>send</strong> messages</span>
            </div>
          </div>
          
          <hr>
          
          <div class="mb-3">
            <label class="fw-semibold d-block mb-2">Moderation & Restrictions</label>
            <div class="d-flex align-items-center mb-2">
              <label class="toggle-switch me-2">
                <input type="checkbox" name="comm_require_moderation" value="1" <?= $commSettings['comm_require_moderation'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span class="small">Require moderation for all messages</span>
            </div>
            <div class="d-flex align-items-center mb-2">
              <label class="toggle-switch me-2">
                <input type="checkbox" name="comm_allow_attachments" value="1" <?= $commSettings['comm_allow_attachments'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <span class="small">Allow file attachments in messages</span>
            </div>
            <div class="mt-2">
              <label class="form-label small">Max message length (characters)</label>
              <input type="number" name="comm_max_message_length" class="form-control form-control-sm" 
                     value="<?= (int) $commSettings['comm_max_message_length'] ?>" min="100" max="10000" style="width:150px;">
            </div>
          </div>
          
          <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Save Settings</button>
        </form>
      </div>
    </div>
    
    <!-- Clear Old Notifications -->
    <div class="comm-card">
      <div class="comm-header"><i class="fa fa-trash-alt me-2"></i>Clean Up Notifications</div>
      <div class="comm-body">
        <form method="POST" onsubmit="return confirm('Delete old notifications? This cannot be undone.')">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="clear_notifications">
          <div class="row g-2">
            <div class="col-8">
              <select name="target_role" class="form-select form-select-sm">
                <option value="all">All Roles</option>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= e($r['role_name']) ?>"><?= e(str_replace('_', ' ', ucfirst($r['role_name']))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                <i class="fa fa-trash me-1"></i> Clear Old
              </button>
            </div>
          </div>
          <small class="text-muted">Removes notifications older than 30 days.</small>
        </form>
      </div>
    </div>
  </div>
  
  <!-- Recent Notifications -->
  <div class="col-lg-7">
    <div class="comm-card">
      <div class="comm-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-history me-2"></i>Recent Notifications</span>
        <span class="badge bg-secondary"><?= count($recentNotifications) ?> total</span>
      </div>
      <div class="comm-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 small">
            <thead>
              <tr>
                <th>Title</th>
                <th>Target</th>
                <th>Type</th>
                <th>Sent</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentNotifications as $n): ?>
                <tr>
                  <td class="fw-medium"><?= e($n['title']) ?></td>
                  <td>
                    <?php if ($n['role_name']): ?>
                      <span class="role-badge <?= e($n['role_name']) ?>">
                        <?= e(str_replace('_', ' ', ucfirst($n['role_name']))) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-muted">System</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge bg-info"><?= e($n['type']) ?></span></td>
                  <td class="text-muted"><?= e(date('d M H:i', strtotime($n['created_at']))) ?></td>
                  <td>
                    <?php if ($n['is_read']): ?>
                      <span class="text-muted">Read</span>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark">Unread</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($recentNotifications)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No notifications sent yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Broadcast Modal -->
<div class="modal fade" id="broadcastModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="send_broadcast">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa fa-bullhorn text-gold me-2"></i>Send Broadcast Message</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Target Audience <span class="required-mark">*</span></label>
            <select name="target_role" class="form-select" required>
              <option value="all">All Users</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= e($r['role_name']) ?>"><?= e(str_replace('_', ' ', ucfirst($r['role_name']))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Notification Type</label>
            <select name="type" class="form-select">
              <option value="system">System Notification</option>
              <option value="info">Information</option>
              <option value="warning">Warning</option>
              <option value="alert">Alert</option>
              <option value="reminder">Reminder</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Title <span class="required-mark">*</span></label>
            <input type="text" name="title" class="form-control" required maxlength="200" placeholder="e.g. School Holiday Announcement">
          </div>
          <div class="mb-3">
            <label class="form-label">Message <span class="required-mark">*</span></label>
            <textarea name="body" class="form-control" rows="5" required maxlength="5000" placeholder="Type your broadcast message here..."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Link (optional)</label>
            <input type="text" name="link" class="form-control" placeholder="e.g. /academic/results.php">
            <small class="text-muted">Relative path to a page users should visit.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold"><i class="fa fa-paper-plane me-1"></i> Send Broadcast</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>