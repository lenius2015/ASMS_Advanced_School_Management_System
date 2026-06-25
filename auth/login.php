<?php
/**
 * auth/login.php
 * Single login page for all roles. After successful authentication,
 * the user is redirected to their role-specific dashboard.
 */
require_once __DIR__ . '/../config/config.php';

if (is_logged_in()) {
    redirect(role_home_url(current_role()));
}

$error = '';
$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $result = attempt_login($pdo, $username, $password);
        if ($result['ok']) {
            audit_log('login', 'auth', 'users', current_user_id(), 'User logged in');
            $next = $_GET['next'] ?? null;
            // Only allow relative, same-site paths for "next" to prevent open-redirect
            // (block protocol-relative URLs like //evil.com and absolute URLs).
            if ($next !== null && (!str_starts_with($next, '/') || str_starts_with($next, '//'))) {
                $next = null;
            }
            if ($result['must_change_password']) {
                redirect(app_url('/auth/change_password.php'));
            }
            redirect($next ? $next : role_home_url($result['role_name']));
        } else {
            $error = $result['message'];
        }
    }
}

$schoolName = get_setting($pdo, 'school_name', 'ASMS School');
$sessionExpired = isset($_GET['expired']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In | <?= e($schoolName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="<?= e(app_url('/assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="asms-auth-wrapper">
  <div class="asms-auth-card">
    <div class="text-center">
      <div class="asms-logo-mark"><i class="fa fa-graduation-cap"></i></div>
      <h1 class="h4 mb-1"><?= e($schoolName) ?></h1>
      <p class="text-muted small mb-4">Advanced School Management System</p>
    </div>

    <?php if ($sessionExpired): ?>
      <div class="alert alert-warning small">Your session expired or you were signed out. Please sign in again.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger small"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= e(app_url('/auth/login.php')) ?><?= isset($_GET['next']) ? '?next=' . e(urlencode($_GET['next'])) : '' ?>" novalidate>
      <?php csrf_field(); ?>
      <div class="mb-3">
        <label for="username" class="form-label">Email Address</label>
        <input type="email" class="form-control" id="username" name="username" required autofocus autocomplete="email" placeholder="e.g. director@example.com">
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
          <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
          <button type="button" class="btn btn-outline-secondary" id="togglePwd" tabindex="-1"><i class="fa fa-eye"></i></button>
        </div>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="<?= e(app_url('/auth/forgot_password.php')) ?>" class="small">Forgot password?</a>
      </div>
      <button type="submit" class="btn btn-primary w-100">Sign In</button>
    </form>

    <p class="text-center text-muted small mt-4 mb-0">
      Every login is recorded for security. Your role determines what you can see.
    </p>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePwd').addEventListener('click', function () {
  var pwd = document.getElementById('password');
  var icon = this.querySelector('i');
  if (pwd.type === 'password') { pwd.type = 'text'; icon.className = 'fa fa-eye-slash'; }
  else { pwd.type = 'password'; icon.className = 'fa fa-eye'; }
});
</script>
</body>
</html>
