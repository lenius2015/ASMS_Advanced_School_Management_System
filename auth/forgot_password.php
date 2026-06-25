<?php
/**
 * auth/forgot_password.php
 * Generates a password reset token. In production, this would be emailed
 * to the user; for this demo build it is shown on-screen with a note,
 * since no SMTP/mail service is configured out of the box.
 */
require_once __DIR__ . '/../config/config.php';

$pdo = get_db_connection();
$message = '';
$resetLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $identifier = trim($_POST['identifier'] ?? '');

    $stmt = $pdo->prepare('SELECT user_id, email FROM users WHERE username = :u OR email = :u LIMIT 1');
    $stmt->execute(['u' => $identifier]);
    $user = $stmt->fetch();

    // Always show the same generic message, whether or not the account
    // exists, to avoid leaking which usernames/emails are registered.
    $message = 'If an account matching that information exists, password reset instructions have been generated.';

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');

        $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:uid, :hash, :exp)')
            ->execute(['uid' => $user['user_id'], 'hash' => $tokenHash, 'exp' => $expires]);

        audit_log('password_reset_requested', 'auth', 'users', (int) $user['user_id'], 'Password reset token generated');

        // NOTE: wire this to a real mail/SMS service in production instead
        // of displaying it. Shown here only because this demo has no mail server.
        $resetLink = app_url('/auth/reset_password.php') . '?token=' . $token . '&uid=' . $user['user_id'];
    }
}

$pageTitle = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="<?= e(app_url('/assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="asms-auth-wrapper">
  <div class="asms-auth-card">
    <div class="text-center mb-3">
      <div class="asms-logo-mark"><i class="fa fa-unlock-alt"></i></div>
      <h1 class="h5 mb-1">Reset Your Password</h1>
      <p class="text-muted small">Enter your username or email to receive reset instructions.</p>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-info small"><?= e($message) ?></div>
      <?php if ($resetLink): ?>
        <div class="alert alert-warning small">
          <strong>Demo mode notice:</strong> no email server is configured, so here is the reset link directly.
          In production this is emailed, never shown on screen.<br>
          <a href="<?= e($resetLink) ?>"><?= e($resetLink) ?></a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="POST" action="<?= e(app_url('/auth/forgot_password.php')) ?>">
      <?php csrf_field(); ?>
      <div class="mb-3">
        <label class="form-label">Username or Email</label>
        <input type="text" name="identifier" class="form-control" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary w-100">Send Reset Instructions</button>
    </form>
    <p class="text-center small mt-3"><a href="<?= e(app_url('/auth/login.php')) ?>">Back to Sign In</a></p>
  </div>
</div>
</body>
</html>
