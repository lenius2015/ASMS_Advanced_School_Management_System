<?php
/**
 * auth/reset_password.php
 * Validates a password reset token (from forgot_password.php) and lets
 * the user set a new password.
 */
require_once __DIR__ . '/../config/config.php';

$pdo = get_db_connection();
$error = '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$uid   = (int) ($_GET['uid'] ?? $_POST['uid'] ?? 0);

function find_valid_reset(PDO $pdo, int $uid, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM password_resets WHERE user_id = :uid AND used_at IS NULL AND expires_at > NOW()
         ORDER BY reset_id DESC LIMIT 5'
    );
    $stmt->execute(['uid' => $uid]);
    foreach ($stmt->fetchAll() as $row) {
        if (hash_equals($row['token_hash'], hash('sha256', $token))) {
            return $row;
        }
    }
    return null;
}

$validReset = ($uid && $token) ? find_valid_reset($pdo, $uid, $token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!$validReset) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $newPwd  = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (strlen($newPwd) < 8 || !preg_match('/[A-Z]/', $newPwd) || !preg_match('/[0-9]/', $newPwd)) {
            $error = 'Password must be at least 8 characters and include an uppercase letter and a number.';
        } elseif ($newPwd !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPwd, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash = :h, must_change_password = 0, failed_login_attempts = 0, locked_until = NULL WHERE user_id = :uid')
                ->execute(['h' => $hash, 'uid' => $uid]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = :rid')
                ->execute(['rid' => $validReset['reset_id']]);

            audit_log('password_reset_completed', 'auth', 'users', $uid, 'Password reset via token');
            flash_set('success', 'Your password has been reset. Please sign in.');
            redirect(app_url('/auth/login.php'));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e(app_url('/assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="asms-auth-wrapper">
  <div class="asms-auth-card">
    <h1 class="h5 mb-3 text-center">Set a New Password</h1>

    <?php if (!$validReset): ?>
      <div class="alert alert-danger small">This reset link is invalid or has expired. <a href="<?= e(app_url('/auth/forgot_password.php')) ?>">Request a new one</a>.</div>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="uid" value="<?= (int) $uid ?>">
        <div class="mb-3">
          <label class="form-label">New Password</label>
          <input type="password" name="new_password" class="form-control" required minlength="8">
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm New Password</label>
          <input type="password" name="confirm_password" class="form-control" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary w-100">Reset Password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
