<?php
/**
 * includes/header.php
 * Shared page header: HTML head, top navbar, and sidebar open tag.
 * Expects $pageTitle to be set by the including page. Must be included
 * AFTER require_login() / require_role() has already run.
 */

if (!isset($pdo)) {
    $pdo = get_db_connection();
}
$schoolName = get_setting($pdo, 'school_name', 'ASMS School');
$pageTitle  = $pageTitle ?? APP_NAME;

// Unread notification count for the bell icon
$unreadCount = 0;
if (current_user_id()) {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = :uid AND is_read = 0');
    $stmt->execute(['uid' => current_user_id()]);
    $unreadCount = (int) ($stmt->fetch()['c'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | <?= e($schoolName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="<?= e(app_url('/assets/css/style.css')) ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark asms-navbar sticky-top">
  <div class="container-fluid">
    <button class="btn btn-link text-white d-lg-none" type="button" id="sidebarToggleMobile"><i class="fa fa-bars"></i></button>
    <a class="navbar-brand fw-bold" href="<?= e(app_url('/index.php')) ?>">
      <i class="fa fa-graduation-cap me-2"></i><?= e($schoolName) ?>
    </a>
    <div class="ms-auto d-flex align-items-center gap-3">
      <a href="<?= e(app_url('/communication/notifications.php')) ?>" class="text-white position-relative" title="Notifications">
        <i class="fa fa-bell fs-5"></i>
        <?php if ($unreadCount > 0): ?>
          <span class="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle" style="font-size:.6rem;"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= e(app_url('/communication/inbox.php')) ?>" class="text-white" title="Messages"><i class="fa fa-envelope fs-5"></i></a>
      <div class="dropdown">
        <a class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" href="#" data-bs-toggle="dropdown">
          <?php
            $currentUserPhoto = $_SESSION['photo_path'] ?? null;
            $currentName = $_SESSION['full_name'] ?? 'User';
            $nameParts = explode(' ', $currentName, 2);
            $fn = $nameParts[0] ?? '';
            $ln = $nameParts[1] ?? '';
            echo render_avatar($currentUserPhoto, $fn, $ln, 32, 'me-1');
          ?>
          <span class="d-none d-md-inline"><?= e($currentName) ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-muted">Role: <?= e(str_replace('_', ' ', current_role() ?? '')) ?></span></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= e(app_url('/profile/view.php')) ?>"><i class="fa fa-user me-2"></i>My Profile</a></li>
          <li><a class="dropdown-item" href="<?= e(app_url('/profile/edit.php')) ?>"><i class="fa fa-edit me-2"></i>Edit Profile</a></li>
          <li><a class="dropdown-item" href="<?= e(app_url('/auth/change_password.php')) ?>"><i class="fa fa-key me-2"></i>Change Password</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= e(app_url('/auth/logout.php')) ?>"><i class="fa fa-sign-out-alt me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="d-flex">
<?php require APP_ROOT . '/includes/sidebar.php'; ?>
  <main class="flex-grow-1 p-3 p-md-4 asms-main">
    <div class="container-fluid">
      <?php flash_render(); ?>
