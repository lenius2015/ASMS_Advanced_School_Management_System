<?php
/**
 * director/audit_logs.php
 * Security module: view audit trail and login activity for the whole system.
 * Supports delete of individual entries and bulk purge of old data.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin']);

$pdo = get_db_connection();

$moduleFilter = $_GET['module'] ?? '';
$tab = $_GET['tab'] ?? 'audit';

// ---- Handle Delete Individual Audit Entry -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_audit_log') {
    csrf_verify();
    $auditId = (int) ($_POST['audit_id'] ?? 0);
    if ($auditId > 0) {
        $pdo->prepare('DELETE FROM audit_logs WHERE audit_id = :id')->execute(['id' => $auditId]);
        flash_set('success', 'Audit log entry deleted.');
    }
    redirect(app_url('/director/audit_logs.php'));
}

// ---- Handle Bulk Purge Audit Logs -------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_audit_logs') {
    csrf_verify();
    $days = (int) ($_POST['days'] ?? 0);
    if ($days > 0) {
        $stmt = $pdo->prepare('DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->execute(['days' => $days]);
        $count = $stmt->rowCount();
        audit_log('purge_audit_logs', 'security', 'audit_logs', 0, "Purged {$count} audit log entries older than {$days} days");
        flash_set('success', "Deleted {$count} audit log entries older than {$days} days.");
    }
    redirect(app_url('/director/audit_logs.php'));
}

// ---- Handle Delete Individual Login Entry ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_login_log') {
    csrf_verify();
    $loginId = (int) ($_POST['login_id'] ?? 0);
    if ($loginId > 0) {
        $pdo->prepare('DELETE FROM login_activity WHERE login_id = :id')->execute(['id' => $loginId]);
        flash_set('success', 'Login activity entry deleted.');
    }
    redirect(app_url('/director/audit_logs.php'));
}

// ---- Handle Bulk Purge Login Logs -------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_login_logs') {
    csrf_verify();
    $days = (int) ($_POST['days'] ?? 0);
    if ($days > 0) {
        $stmt = $pdo->prepare('DELETE FROM login_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->execute(['days' => $days]);
        $count = $stmt->rowCount();
        audit_log('purge_login_logs', 'security', 'login_activity', 0, "Purged {$count} login log entries older than {$days} days");
        flash_set('success', "Deleted {$count} login activity entries older than {$days} days.");
    }
    redirect(app_url('/director/audit_logs.php'));
}

// ---- Data Queries ------------------------------------------------------
$sql = "SELECT a.*, u.first_name, u.last_name, u.username FROM audit_logs a
        LEFT JOIN users u ON u.user_id = a.user_id WHERE 1=1";
$params = [];
if ($moduleFilter !== '') {
    $sql .= ' AND a.module = :module';
    $params['module'] = $moduleFilter;
}
$sql .= ' ORDER BY a.audit_id DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$auditRows = $stmt->fetchAll();

$loginRows = $pdo->query(
    "SELECT l.*, u.first_name, u.last_name FROM login_activity l
     LEFT JOIN users u ON u.user_id = l.user_id
     ORDER BY l.login_id DESC LIMIT 300"
)->fetchAll();

$modules = $pdo->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit & Security Logs';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Audit & Security Logs</h1>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="?tab=audit">Audit Trail</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'logins' ? 'active' : '' ?>" href="?tab=logins">Login Activity</a></li>
</ul>

<?php if ($tab === 'audit'): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-4">
          <form method="GET">
            <input type="hidden" name="tab" value="audit">
            <select name="module" class="form-select" onchange="this.form.submit()">
              <option value="">All Modules</option>
              <?php foreach ($modules as $m): ?>
                <option value="<?= e($m) ?>" <?= $moduleFilter === $m ? 'selected' : '' ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <div class="col-md-5">
          <form method="POST" class="d-inline" onsubmit="var days = prompt('Delete audit logs older than how many days?', '90'); if(days) { this.querySelector('[name=days]').value = days; return true; } return false;">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="purge_audit_logs">
            <input type="hidden" name="days" value="90">
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa fa-trash me-1"></i> Purge Old Logs</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>Description</th><th>IP</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($auditRows as $a): ?>
            <tr>
              <td class="small text-nowrap"><?= e(date('d M Y, H:i:s', strtotime($a['created_at']))) ?></td>
              <td class="small"><?= e($a['username'] ?? 'System') ?></td>
              <td><span class="badge bg-secondary"><?= e($a['action']) ?></span></td>
              <td class="small"><?= e($a['module']) ?></td>
              <td class="small text-muted"><?= e($a['record_table'] ? $a['record_table'] . ' #' . $a['record_id'] : '-') ?></td>
              <td class="small"><?= e($a['description']) ?></td>
              <td class="small text-muted"><?= e($a['ip_address']) ?></td>
              <td>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this audit log entry?')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_audit_log">
                  <input type="hidden" name="audit_id" value="<?= (int) $a['audit_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($auditRows)): ?><tr><td colspan="8" class="text-center text-muted py-4">No audit records found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="card mb-3">
    <div class="card-body">
      <form method="POST" class="d-inline" onsubmit="var days = prompt('Delete login logs older than how many days?', '90'); if(days) { this.querySelector('[name=days]').value = days; return true; } return false;">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="purge_login_logs">
        <input type="hidden" name="days" value="90">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa fa-trash me-1"></i> Purge Old Login Logs</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>When</th><th>User / Attempted</th><th>Status</th><th>IP Address</th><th>Device</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($loginRows as $l): ?>
            <tr>
              <td class="small text-nowrap"><?= e(date('d M Y, H:i:s', strtotime($l['created_at']))) ?></td>
              <td class="small"><?= e(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: $l['username_attempted']) ?></td>
              <td>
                <?php
                  $badgeClass = match ($l['status']) {
                      'success' => 'bg-success',
                      'logout'  => 'bg-secondary',
                      default   => 'bg-danger',
                  };
                ?>
                <span class="badge <?= $badgeClass ?>"><?= e(str_replace('_', ' ', $l['status'])) ?></span>
              </td>
              <td class="small text-muted"><?= e($l['ip_address']) ?></td>
              <td class="small text-muted" style="max-width:280px;"><?= e(mb_strimwidth($l['user_agent'] ?? '', 0, 60, '...')) ?></td>
              <td>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this login log entry?')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_login_log">
                  <input type="hidden" name="login_id" value="<?= (int) $l['login_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($loginRows)): ?><tr><td colspan="6" class="text-center text-muted py-4">No login activity found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>