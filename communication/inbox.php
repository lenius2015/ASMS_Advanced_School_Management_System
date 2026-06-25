<?php
/**
 * communication/inbox.php
 * Simple internal direct-messaging system between any two users
 * (e.g. parent <-> class teacher, teacher <-> academic dept).
 */
require_once __DIR__ . '/../config/config.php';
require_login();

$pdo = get_db_connection();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    csrf_verify();
    $recipientId = (int) ($_POST['recipient_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($recipientId <= 0 || $body === '') {
        flash_set('error', 'Recipient and message body are required.');
    } else {
        $pdo->prepare(
            'INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s, :r, :subj, :body)'
        )->execute(['s' => $userId, 'r' => $recipientId, 'subj' => $subject ?: null, 'body' => $body]);
        $msgId = (int) $pdo->lastInsertId();

        notify_user($pdo, $recipientId, 'New Message', 'You have received a new message: ' . ($subject ?: mb_strimwidth($body, 0, 50, '...')), 'message', app_url('/communication/inbox.php'));

        audit_log('send_message', 'communication', 'messages', $msgId, 'Sent a direct message');
        flash_set('success', 'Message sent.');
    }
    redirect(app_url('/communication/inbox.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    csrf_verify();
    $msgId = (int) ($_POST['message_id'] ?? 0);
    $pdo->prepare('UPDATE messages SET is_read = 1, read_at = NOW() WHERE message_id = :id AND recipient_id = :uid')
        ->execute(['id' => $msgId, 'uid' => $userId]);
    redirect(app_url('/communication/inbox.php') . '?view=' . $msgId);
}

$tab = $_GET['tab'] ?? 'received';
$viewMsgId = (int) ($_GET['view'] ?? 0);

if ($tab === 'sent') {
    $stmt = $pdo->prepare(
        "SELECT m.*, u.first_name, u.last_name FROM messages m JOIN users u ON u.user_id = m.recipient_id
         WHERE m.sender_id = :uid ORDER BY m.created_at DESC"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT m.*, u.first_name, u.last_name FROM messages m JOIN users u ON u.user_id = m.sender_id
         WHERE m.recipient_id = :uid ORDER BY m.created_at DESC"
    );
}
$stmt->execute(['uid' => $userId]);
$messages = $stmt->fetchAll();

$selectedMessage = null;
if ($viewMsgId > 0) {
    foreach ($messages as $m) {
        if ((int) $m['message_id'] === $viewMsgId) { $selectedMessage = $m; break; }
    }
}

// Recipient list: anyone except self (kept simple; could be scoped further)
$recipients = $pdo->prepare('SELECT user_id, first_name, last_name, username FROM users WHERE user_id != :uid AND is_active = 1 ORDER BY first_name LIMIT 200');
$recipients->execute(['uid' => $userId]);
$recipientList = $recipients->fetchAll();

$pageTitle = 'Inbox';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">Messages</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newMessageModal"><i class="fa fa-pen me-1"></i> New Message</button>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='received'?'active':'' ?>" href="?tab=received">Inbox</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='sent'?'active':'' ?>" href="?tab=sent">Sent</a></li>
</ul>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="list-group list-group-flush">
        <?php foreach ($messages as $m): ?>
          <a href="?tab=<?= e($tab) ?>&view=<?= (int) $m['message_id'] ?>" class="list-group-item list-group-item-action <?= $viewMsgId === (int) $m['message_id'] ? 'active' : '' ?> <?= (!$m['is_read'] && $tab==='received') ? 'fw-bold' : '' ?>">
            <div class="d-flex justify-content-between">
              <span><?= e($m['first_name'] . ' ' . $m['last_name']) ?></span>
              <span class="small <?= $viewMsgId === (int) $m['message_id'] ? 'text-white-50' : 'text-muted' ?>"><?= e(date('d M', strtotime($m['created_at']))) ?></span>
            </div>
            <div class="small <?= $viewMsgId === (int) $m['message_id'] ? '' : 'text-muted' ?>"><?= e($m['subject'] ?: mb_strimwidth($m['body'],0,40,'...')) ?></div>
          </a>
        <?php endforeach; ?>
        <?php if (empty($messages)): ?><div class="list-group-item text-muted text-center py-4">No messages.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <?php if ($selectedMessage): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between">
          <span><?= e($selectedMessage['subject'] ?: '(No subject)') ?></span>
          <span class="text-muted small"><?= e(date('d M Y, H:i', strtotime($selectedMessage['created_at']))) ?></span>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3"><?= $tab === 'sent' ? 'To' : 'From' ?>: <?= e($selectedMessage['first_name'] . ' ' . $selectedMessage['last_name']) ?></p>
          <p style="white-space:pre-wrap;"><?= e($selectedMessage['body']) ?></p>
        </div>
        <?php if ($tab === 'received' && !$selectedMessage['is_read']): ?>
          <form method="POST" class="card-footer bg-white"><?php csrf_field(); ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="message_id" value="<?= (int) $selectedMessage['message_id'] ?>"><button class="btn btn-sm btn-outline-secondary">Mark as Read</button></form>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="card"><div class="card-body text-center text-muted py-5">Select a message to read it.</div></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="newMessageModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="send_message">
        <div class="modal-header"><h5 class="modal-title">New Message</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">To</label>
            <select name="recipient_id" class="form-select" required>
              <option value="">-- Select Recipient --</option>
              <?php foreach ($recipientList as $r): ?><option value="<?= (int) $r['user_id'] ?>"><?= e($r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['username'] . ')') ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Subject</label><input type="text" name="subject" class="form-control"></div>
          <div class="mb-2"><label class="form-label">Message</label><textarea name="body" class="form-control" rows="5" required></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Send</button></div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
