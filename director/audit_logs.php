<?php
/**
 * director/audit_logs.php
 * Security module: view audit trail and login activity for the whole system.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'system_admin']);

$pdo = get_db_connection();

$moduleFilter = $_GET['module'] ?? '';
$tab = $_GET['tab'] ?? 'audit';

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

<h1 class="h3 mb-4">Audit &amp; Security Logs</h1>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="?tab=audit">Audit Trail</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'logins' ? 'active' : '' ?>" href="?tab=logins">Login Activity</a></li>
</ul>

<?php if ($tab === 'audit'): ?>
  <div class="card mb-3">
    <div class="card-body">
      <form method="GET" class="row g-2">
        <input type="hidden" name="tab" value="audit">
        <div class="col-md-4">
          <select name="module" class="form-select" onchange="this.form.submit()">
            <option value="">All Modules</option>
            <?php foreach ($modules as $m): ?>
              <option value="<?= e($m) ?>" <?= $moduleFilter === $m ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>Description</th><th>IP</th></tr></thead>
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
            </tr>
          <?php endforeach; ?>
          <?php if (empty($auditRows)): ?><tr><td colspan="7" class="text-center text-muted py-4">No audit records found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>When</th><th>User / Attempted</th><th>Status</th><th>IP Address</th><th>Device</th></tr></thead>
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
            </tr>
          <?php endforeach; ?>
          <?php if (empty($loginRows)): ?><tr><td colspan="5" class="text-center text-muted py-4">No login activity found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
