<?php
/**
 * director/roles.php
 * Permission Matrix - Visual check/uncheck interface for managing 
 * role-based permissions. Director and System Admin can assign or 
 * revoke specific permissions for each role.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin']);

$pdo = get_db_connection();

// ====== Handle permission updates ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_permissions') {
    csrf_verify();
    
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $grantedPerms = $_POST['perms'] ?? [];
    
    // Clear existing permissions for this role
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid')->execute(['rid' => $roleId]);
    
    // Insert granted permissions
    $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)');
    foreach ($grantedPerms as $pid) {
        $insert->execute(['rid' => $roleId, 'pid' => (int) $pid]);
    }
    
    $roleName = $pdo->prepare('SELECT role_name FROM roles WHERE role_id = :rid');
    $roleName->execute(['rid' => $roleId]);
    $rn = $roleName->fetch()['role_name'];
    
    audit_log('update_permissions', 'role_management', 'role_permissions', $roleId, "Updated permissions for role: {$rn}");
    flash_set('success', "Permissions updated for role: {$rn}");
    redirect(app_url('/director/roles.php'));
}

// ====== Handle "Reset role to defaults" ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_defaults') {
    csrf_verify();
    $roleId = (int) ($_POST['role_id'] ?? 0);
    
    // Get all permission keys for this role from the seed definition
    $fullAccessRoles = ['director', 'system_admin'];
    $roleName = $pdo->prepare('SELECT role_name FROM roles WHERE role_id = :rid');
    $roleName->execute(['rid' => $roleId]);
    $roleNameVal = $roleName->fetch()['role_name'];
    
    // Grant all permissions for full-access roles
    if (in_array($roleNameVal, $fullAccessRoles)) {
        $allPerms = $pdo->query('SELECT permission_id FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid')->execute(['rid' => $roleId]);
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)');
        foreach ($allPerms as $pid) {
            $insert->execute(['rid' => $roleId, 'pid' => $pid]);
        }
        $count = count($allPerms);
    } else {
        flash_set('error', 'Default reset is only available for director and system_admin roles. Use the permission matrix to customize.');
        redirect(app_url('/director/roles.php'));
    }
    
    audit_log('reset_permissions', 'role_management', 'role_permissions', $roleId, "Reset permissions for role: {$roleNameVal} ({$count} permissions)");
    flash_set('success', "Permissions reset to full access for role: {$roleNameVal}");
    redirect(app_url('/director/roles.php'));
}

// ====== Load data ======
$roles = $pdo->query(
    "SELECT r.*, COUNT(u.user_id) AS user_count FROM roles r
     LEFT JOIN users u ON u.role_id = r.role_id
     GROUP BY r.role_id ORDER BY r.role_name"
)->fetchAll();

$permissions = $pdo->query('SELECT * FROM permissions ORDER BY permission_key')->fetchAll();

// Group permissions by module
$modules = [];
foreach ($permissions as $p) {
    $parts = explode('_', $p['permission_key']);
    $module = $parts[0];
    if ($module === 'fee' || $module === 'invoice' || $module === 'payment' || $module === 'payroll' || $module === 'budget') $module = 'finance';
    if ($module === 'marks' || $module === 'results' || $module === 'transcript' || $module === 'timetable' || $module === 'exam' || $module === 'class') $module = 'academic';
    if ($module === 'message' || $module === 'notification' || $module === 'announcement' || $module === 'communication') $module = 'communication';
    if ($module === 'backup' || $module === 'audit' || $module === 'system' || $module === 'calendar' || $module === 'reports') $module = 'system';
    if ($module === 'discipline') $module = 'discipline';
    if ($module === 'attendance') $module = 'attendance';
    if ($module === 'dashboard') $module = 'dashboard';
    $modules[$module][] = $p;
}

// Get currently granted permissions per role
$granted = [];
$stmt = $pdo->query('SELECT role_id, permission_id FROM role_permissions');
foreach ($stmt->fetchAll() as $rp) {
    $granted[$rp['role_id']][] = (int) $rp['permission_id'];
}

$pageTitle = 'Permission Matrix';
require APP_ROOT . '/includes/header.php';
?>

<style>
.permission-matrix .module-card { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 16px; }
.permission-matrix .module-header { background: #f7fafc; padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; color: #2d3748; border-radius: 8px 8px 0 0; }
.permission-matrix .module-body { padding: 8px 16px; }
.permission-matrix .perm-check { margin-right: 8px; }
.permission-matrix .perm-label { font-size: 12px; color: #4a5568; cursor: pointer; }
.permission-matrix .perm-row { padding: 4px 0; display: flex; align-items: center; }
.permission-matrix .role-header { font-weight: 600; font-size: 13px; color: #1a202c; padding: 12px 16px; background: #edf2f7; border-radius: 8px 8px 0 0; border-bottom: 2px solid #cbd5e0; }
.permission-matrix .role-col { min-width: 160px; }
.form-check-input:checked { background-color: #2B6CB0; border-color: #2B6CB0; }
.form-check-input:disabled { opacity: 0.5; cursor: not-allowed; }
.count-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #e2e8f0; color: #4a5568; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-user-shield text-gold me-2"></i>Permission Matrix</h1>
  <div>
    <button class="btn btn-sm btn-outline-secondary me-2" onclick="checkAllShown()"><i class="fa fa-check-square me-1"></i> Select All Shown</button>
    <button class="btn btn-sm btn-outline-secondary me-2" onclick="uncheckAllShown()"><i class="fa fa-square me-1"></i> Deselect All Shown</button>
  </div>
</div>

<p class="text-muted mb-4">
  <i class="fa fa-info-circle me-1"></i> 
  Check or uncheck permissions for each role. <strong>Director</strong> and <strong>System Admin</strong> have full access by default.
  Changes take effect immediately after saving.
</p>

<?php if (empty($roles)): ?>
  <div class="alert alert-warning">No roles found in the system.</div>
<?php else: ?>

  <!-- Role Selection Tabs -->
  <ul class="nav nav-tabs mb-4" id="roleTabs" role="tablist">
    <?php foreach ($roles as $i => $role): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= $i === 0 ? 'active' : '' ?>" 
                id="tab-<?= (int) $role['role_id'] ?>" 
                data-bs-toggle="tab" 
                data-bs-target="#role-<?= (int) $role['role_id'] ?>" 
                type="button" role="tab">
          <?= e(str_replace('_', ' ', ucfirst($role['role_name']))) ?>
          <span class="count-badge ms-1"><?= (int) $role['user_count'] ?></span>
        </button>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="tab-content">
    <?php foreach ($roles as $i => $role): 
      $roleId = (int) $role['role_id'];
      $rolePerms = $granted[$roleId] ?? [];
      $roleName = $role['role_name'];
      $isFullAccess = in_array($roleName, ['director', 'system_admin']);
      $permCount = count($rolePerms);
      $totalPerms = count($permissions);
    ?>
      <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="role-<?= $roleId ?>" role="tabpanel">
        <div class="card mb-3">
          <div class="card-body py-2 d-flex justify-content-between align-items-center">
            <div>
              <strong><?= e(str_replace('_', ' ', ucfirst($roleName))) ?></strong>
              <span class="text-muted ms-2 small">— <?= e($role['role_description'] ?? '') ?></span>
            </div>
            <div>
              <span class="badge bg-primary me-2" id="count-<?= $roleId ?>"><?= $permCount ?> / <?= $totalPerms ?> permissions</span>
            </div>
          </div>
        </div>

        <form method="POST" id="form-<?= $roleId ?>" onsubmit="return confirm('Update permissions for this role?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_permissions">
          <input type="hidden" name="role_id" value="<?= $roleId ?>">
          
          <div class="row">
            <?php foreach ($modules as $module => $modPerms): ?>
              <div class="col-lg-4 col-md-6">
                <div class="module-card">
                  <div class="module-header">
                    <i class="fa fa-<?= 
                      $module === 'dashboard' ? 'chart-pie' : 
                      ($module === 'student' ? 'user-graduate' : 
                      ($module === 'staff' ? 'id-badge' : 
                      ($module === 'user' ? 'users-cog' : 
                      ($module === 'finance' ? 'coins' : 
                      ($module === 'academic' ? 'book' : 
                      ($module === 'attendance' ? 'calendar-check' : 
                      ($module === 'discipline' ? 'gavel' : 
                      ($module === 'communication' ? 'comment-dots' : 'cogs'))))))))
                    ?> me-2"></i>
                    <?= e(ucfirst($module)) ?>
                    <span class="count-badge ms-2" id="mod-count-<?= $roleId ?>-<?= e($module) ?>">0</span>
                  </div>
                  <div class="module-body">
                    <?php foreach ($modPerms as $perm): 
                      $checked = in_array((int) $perm['permission_id'], $rolePerms);
                    ?>
                      <div class="perm-row">
                        <input class="form-check-input perm-check" type="checkbox" 
                               name="perms[]" value="<?= (int) $perm['permission_id'] ?>"
                               id="perm-<?= $roleId ?>-<?= (int) $perm['permission_id'] ?>"
                               <?= $checked ? 'checked' : '' ?>
                               onchange="updateCount(<?= $roleId ?>, <?= $totalPerms ?>)">
                        <label class="form-check-label perm-label" for="perm-<?= $roleId ?>-<?= (int) $perm['permission_id'] ?>">
                          <?= e($perm['description']) ?>
                          <br><small class="text-muted"><?= e($perm['permission_key']) ?></small>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          
          <div class="d-flex justify-content-between mt-3">
            <div>
              <button type="submit" class="btn btn-primary">
                <i class="fa fa-save me-1"></i> Save Permissions
              </button>
              <button type="button" class="btn btn-outline-secondary ms-2" onclick="resetForm(<?= $roleId ?>)">
                <i class="fa fa-undo me-1"></i> Reset Form
              </button>
            </div>
          </div>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
// Update permission count per module
function updateCount(roleId, total) {
    const form = document.getElementById('form-' + roleId);
    const checkboxes = form.querySelectorAll('input[name="perms[]"]');
    const checked = form.querySelectorAll('input[name="perms[]"]:checked');
    document.getElementById('count-' + roleId).textContent = checked.length + ' / ' + total + ' permissions';
    
    // Update per-module counts
    document.querySelectorAll('[id^="mod-count-' + roleId + '-"]').forEach(el => {
        const module = el.id.replace('mod-count-' + roleId + '-', '');
        const modCheckboxes = el.closest('.module-card').querySelectorAll('input[name="perms[]"]:checked');
        el.textContent = modCheckboxes.length;
    });
}

// Initialize counts on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[id^="form-"]').forEach(form => {
        const roleId = form.id.replace('form-', '');
        const total = form.querySelectorAll('input[name="perms[]"]').length;
        updateCount(parseInt(roleId), total);
    });
});

// Reset form (revert to checked state)
function resetForm(roleId) {
    const form = document.getElementById('form-' + roleId);
    form.querySelectorAll('input[name="perms[]"]').forEach(cb => {
        cb.checked = cb.dataset.original === 'checked';
    });
    updateCount(roleId, form.querySelectorAll('input[name="perms[]"]').length);
}

// Select all checkboxes in the current tab
function checkAllShown() {
    const activeTab = document.querySelector('.tab-pane.show.active');
    if (activeTab) {
        activeTab.querySelectorAll('input[name="perms[]"]').forEach(cb => cb.checked = true);
        const roleId = activeTab.id.replace('role-', '');
        const total = activeTab.querySelectorAll('input[name="perms[]"]').length;
        updateCount(parseInt(roleId), total);
    }
}

// Deselect all checkboxes in the current tab
function uncheckAllShown() {
    const activeTab = document.querySelector('.tab-pane.show.active');
    if (activeTab) {
        activeTab.querySelectorAll('input[name="perms[]"]').forEach(cb => cb.checked = false);
        const roleId = activeTab.id.replace('role-', '');
        const total = activeTab.querySelectorAll('input[name="perms[]"]').length;
        updateCount(parseInt(roleId), total);
    }
}

// Store original state for reset
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="perms[]"]').forEach(cb => {
        cb.dataset.original = cb.checked ? 'checked' : 'unchecked';
    });
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>