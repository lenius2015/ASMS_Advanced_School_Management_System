<?php
/**
 * auth/change_password.php
 * Lets the logged-in user change their password. Forced on first login
 * (must_change_password flag) and otherwise accessible from the user menu.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

$pdo = get_db_connection();
$error = '';
$success = '';
$forced = !empty($_SESSION['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $current = (string) ($_POST['current_password'] ?? '');
    $newPwd   = (string) ($_POST['new_password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
    $stmt->execute(['id' => current_user_id()]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'Your current password is incorrect.';
    } elseif (strlen($newPwd) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $newPwd) || !preg_match('/[0-9]/', $newPwd)) {
        $error = 'New password must include at least one uppercase letter and one number.';
    } elseif ($newPwd !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } elseif (password_verify($newPwd, $row['password_hash'])) {
        $error = 'New password must be different from your current password.';
    } else {
        $newHash = password_hash($newPwd, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash = :h, must_change_password = 0 WHERE user_id = :id')
            ->execute(['h' => $newHash, 'id' => current_user_id()]);
        $_SESSION['must_change_password'] = false;
        audit_log('change_password', 'auth', 'users', current_user_id(), 'User changed their password');
        flash_set('success', 'Password updated successfully.');
        redirect(role_home_url(current_role()));
    }
}

$pageTitle = 'Change Password';
if (!$forced) {
    require APP_ROOT . '/includes/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . e($pageTitle) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">';
    echo '<link href="' . e(app_url('/assets/css/style.css')) . '" rel="stylesheet"></head><body>';
    echo '<div class="asms-auth-wrapper"><div class="asms-auth-card">';
}
?>

<?php if ($forced): ?>
  <div class="text-center mb-3">
    <div class="asms-logo-mark"><i class="fa fa-key"></i></div>
    <h1 class="h5 mb-1">Set a New Password</h1>
    <p class="text-muted small">For your security, you must change the default password before continuing.</p>
  </div>
<?php else: ?>
  <h1 class="h4 mb-4"><i class="fa fa-key text-gold me-2"></i>Change Password</h1>
  <div class="card" style="max-width:500px;">
    <div class="card-body">
<?php endif; ?>

<?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success small"><?= e($success) ?></div><?php endif; ?>

<form method="POST" action="<?= e(app_url('/auth/change_password.php')) ?>">
  <?php csrf_field(); ?>
  <div class="mb-3">
    <label class="form-label">Current Password</label>
    <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
  </div>
  <div class="mb-3">
    <label class="form-label">New Password</label>
    <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
    <div class="form-text">At least 8 characters, including one uppercase letter and one number.</div>
  </div>
  <div class="mb-3">
    <label class="form-label">Confirm New Password</label>
    <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
  </div>
  <button type="submit" class="btn btn-primary w-100">Update Password</button>
</form>

<?php if ($forced): ?>
  </div></div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  </body></html>
<?php else: ?>
    </div>
  </div>
  <?php require APP_ROOT . '/includes/footer.php'; ?>
<?php endif; ?>
