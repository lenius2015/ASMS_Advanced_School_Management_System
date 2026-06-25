<?php
/**
 * director/system_admin.php
 * System settings: school identity, current academic period, and
 * security policy toggles.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'system_admin']);

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $updates = [
        'school_name'  => trim($_POST['school_name'] ?? ''),
        'school_motto' => trim($_POST['school_motto'] ?? ''),
        'current_academic_year_id' => (int) ($_POST['current_academic_year_id'] ?? 0),
        'current_term_id'          => (int) ($_POST['current_term_id'] ?? 0),
        'session_timeout_minutes'  => (int) ($_POST['session_timeout_minutes'] ?? 20),
    ];

    foreach ($updates as $key => $value) {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = :v2'
        )->execute(['k' => $key, 'v' => (string) $value, 'v2' => (string) $value]);
    }

    audit_log('update_settings', 'system_admin', 'system_settings', null, 'Updated system settings');
    flash_set('success', 'System settings updated successfully.');
    redirect(app_url('/director/system_admin.php'));
}

$years = $pdo->query('SELECT * FROM academic_years ORDER BY start_date DESC')->fetchAll();
$terms = $pdo->query('SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id ORDER BY t.start_date DESC')->fetchAll();

$currentSettings = [
    'school_name'  => get_setting($pdo, 'school_name', ''),
    'school_motto' => get_setting($pdo, 'school_motto', ''),
    'current_academic_year_id' => get_setting($pdo, 'current_academic_year_id', ''),
    'current_term_id'          => get_setting($pdo, 'current_term_id', ''),
    'session_timeout_minutes'  => get_setting($pdo, 'session_timeout_minutes', '20'),
];

$pageTitle = 'System Settings';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">System Settings</h1>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">School Identity &amp; Academic Period</div>
      <div class="card-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <div class="mb-3">
            <label class="form-label">School Name</label>
            <input type="text" name="school_name" class="form-control" value="<?= e($currentSettings['school_name']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">School Motto</label>
            <input type="text" name="school_motto" class="form-control" value="<?= e($currentSettings['school_motto']) ?>">
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Current Academic Year</label>
              <select name="current_academic_year_id" class="form-select">
                <?php foreach ($years as $y): ?>
                  <option value="<?= (int) $y['year_id'] ?>" <?= (string) $y['year_id'] === $currentSettings['current_academic_year_id'] ? 'selected' : '' ?>><?= e($y['year_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Current Term</label>
              <select name="current_term_id" class="form-select">
                <?php foreach ($terms as $t): ?>
                  <option value="<?= (int) $t['term_id'] ?>" <?= (string) $t['term_id'] === $currentSettings['current_term_id'] ? 'selected' : '' ?>>
                    <?= e($t['year_name'] . ' - ' . $t['term_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Session Idle Timeout (minutes)</label>
            <input type="number" name="session_timeout_minutes" class="form-control" min="5" max="120" value="<?= e($currentSettings['session_timeout_minutes']) ?>">
            <div class="form-text">Requires updating SESSION_TIMEOUT_SECONDS in config/config.php to take effect server-side.</div>
          </div>
          <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Security Policy (Reference)</div>
      <div class="card-body small">
        <ul class="list-unstyled mb-0">
          <li class="mb-2"><i class="fa fa-check-circle text-success me-2"></i>Passwords hashed with bcrypt</li>
          <li class="mb-2"><i class="fa fa-check-circle text-success me-2"></i>Account lockout after <?= MAX_LOGIN_ATTEMPTS ?> failed attempts (<?= LOCKOUT_MINUTES ?> min)</li>
          <li class="mb-2"><i class="fa fa-check-circle text-success me-2"></i>CSRF protection on all forms</li>
          <li class="mb-2"><i class="fa fa-check-circle text-success me-2"></i>Role-based access control enforced server-side</li>
          <li class="mb-2"><i class="fa fa-check-circle text-success me-2"></i>Session cookies are HttpOnly + SameSite=Lax</li>
          <li class="mb-2"><i class="fa fa-check-circle text-success me-2"></i>Full audit trail of sensitive actions</li>
          <li class="mb-0"><i class="fa fa-info-circle text-gold me-2"></i>2FA schema is in place; enable per-role enforcement when an OTP/SMS provider is connected</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
