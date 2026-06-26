<?php
/**
 * director/users.php
 * User management for administrative roles only.
 * Now restricted: staff users must be created via HR/Staff onboarding,
 * student users via Academic/Student registration, and parent users
 * via the Parent Account linking module.
 * This page only handles: director, system_admin, and head_of_school users.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin']);

$pdo = get_db_connection();
$error = '';

// ---- Handle form submission (create user - admin roles only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    csrf_verify();

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $roleId    = (int) ($_POST['role_id'] ?? 0);
    $gender    = $_POST['gender'] ?? null;

    if ($firstName === '' || $lastName === '' || $username === '' || $roleId <= 0) {
        $error = 'First name, last name, username, and role are required.';
    } else {
        // Verify the selected role is allowed (only admin roles)
        $roleCheck = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = :rid AND role_name IN ('director','system_admin','head_of_school')");
        $roleCheck->execute(['rid' => $roleId]);
        $allowedRole = $roleCheck->fetch();

        if (!$allowedRole) {
            $error = 'Invalid role selected. Only director, system admin, and head of school accounts can be created here. Staff/student/parent accounts must be created through their respective modules.';
        } else {
            $check = $pdo->prepare('SELECT user_id FROM users WHERE username = :u');
            $check->execute(['u' => $username]);
            if ($check->fetch()) {
                $error = 'That username is already taken. Please choose another.';
            } else {
                $tempPassword = 'Welcome@' . random_int(1000, 9999);
                $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare(
                    'INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, must_change_password)
                     VALUES (UUID(), :role_id, :username, :email, :phone, :hash, :fn, :ln, :gender, 1)'
                );
                $stmt->execute([
                    'role_id'  => $roleId,
                    'username' => $username,
                    'email'    => $email ?: null,
                    'phone'    => $phone ?: null,
                    'hash'     => $hash,
                    'fn'       => $firstName,
                    'ln'       => $lastName,
                    'gender'   => $gender ?: null,
                ]);

                $newUserId = (int) $pdo->lastInsertId();
                audit_log('create_user', 'user_management', 'users', $newUserId, "Created admin user {$username} ({$allowedRole['role_name']})");

                flash_set('success', "User '{$username}' created. Temporary password: {$tempPassword} (share this with the user securely; they must change it on first login).");
                redirect(app_url('/director/users.php'));
            }
        }
    }
}

// ---- Handle linking existing user to staff record --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_user_to_staff') {
    csrf_verify();
    $userId = (int) ($_POST['user_id'] ?? 0);
    $staffId = (int) ($_POST['staff_id'] ?? 0);

    if ($userId <= 0 || $staffId <= 0) {
        flash_set('error', 'Invalid user or staff selection.');
    } else {
        try {
            // Check if staff already has a user linked
            $check = $pdo->prepare('SELECT user_id, staff_no FROM staff WHERE staff_id = :sid');
            $check->execute(['sid' => $staffId]);
            $staff = $check->fetch();

            if (!$staff) {
                flash_set('error', 'Staff record not found.');
            } elseif (!empty($staff['user_id'])) {
                flash_set('error', 'This staff member already has a user account linked.');
            } else {
                $pdo->prepare('UPDATE staff SET user_id = :uid WHERE staff_id = :sid')
                    ->execute(['uid' => $userId, 'sid' => $staffId]);
                audit_log('link_user_staff', 'user_management', 'staff', $staffId, "Linked user ID {$userId} to staff {$staff['staff_no']}");
                flash_set('success', 'User successfully linked to staff record.');
            }
        } catch (Throwable $e) {
            error_log('[ASMS] link_user_to_staff failed: ' . $e->getMessage());
            flash_set('error', 'Failed to link user to staff. Please try again.');
        }
    }
    redirect(app_url('/director/users.php'));
}

// ---- Handle creating user for existing staff (no user account) -------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user_for_staff') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);

    if ($staffId <= 0) {
        flash_set('error', 'Invalid staff selection.');
    } else {
        try {
            $pdo->beginTransaction();

            // Get staff details
            $stmt = $pdo->prepare(
                "SELECT st.*, u2.first_name, u2.last_name, u2.gender, r.role_id, r.role_name
                 FROM staff st
                 JOIN users u2 ON u2.user_id = st.user_id
                 JOIN roles r ON r.role_id = u2.role_id
                 WHERE st.staff_id = :sid"
            );
            $stmt->execute(['sid' => $staffId]);
            $staff = $stmt->fetch();

            if (!$staff) {
                throw new Exception('Staff record not found.');
            }

            if (!empty($staff['user_id'])) {
                throw new Exception('Staff member already has a user account.');
            }

            // Don't create user if staff doesn't have a user_id in staff table (this means no linked user)
            if (empty($staff['user_id'])) {
                // Create user account
                $username = strtolower($staff['first_name'][0] . $staff['last_name'] . random_int(10, 99));
                $tempPassword = 'Staff@' . random_int(1000, 9999);
                $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

                $pdo->prepare(
                    'INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, must_change_password)
                     VALUES (UUID(), :rid, :u, :email, :phone, :h, :fn, :ln, :g, 1)'
                )->execute([
                    'rid' => $staff['role_id'], 'u' => $username,
                    'email' => $staff['email'] ?? null, 'phone' => $staff['phone'] ?? null,
                    'h' => $hash, 'fn' => $staff['first_name'], 'ln' => $staff['last_name'],
                    'g' => $staff['gender'] ?? null,
                ]);
                $newUserId = (int) $pdo->lastInsertId();

                // Link to staff record
                $pdo->prepare('UPDATE staff SET user_id = :uid WHERE staff_id = :sid')
                    ->execute(['uid' => $newUserId, 'sid' => $staffId]);

                $pdo->commit();
                audit_log('create_user_for_staff', 'staff_management', 'staff', $staffId, "Created user for staff {$staff['staff_no']}");
                flash_set('success', "User account created for staff {$staff['staff_no']}. Username: {$username}, Password: {$tempPassword}");
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ASMS] create_user_for_staff failed: ' . $e->getMessage());
            flash_set('error', $e->getMessage() ?: 'Failed to create user for staff.');
        }
    }
    redirect(app_url('/director/users.php'));
}

// ---- Handle activate/deactivate toggle --------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_verify();
    $targetId = (int) ($_POST['user_id'] ?? 0);

    if ($targetId === current_user_id()) {
        flash_set('error', 'You cannot deactivate your own account.');
    } else {
        $stmt = $pdo->prepare('SELECT is_active, username FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $targetId]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = $row['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE users SET is_active = :a WHERE user_id = :id')->execute(['a' => $newStatus, 'id' => $targetId]);
            audit_log($newStatus ? 'activate_user' : 'deactivate_user', 'user_management', 'users', $targetId, "User {$row['username']} " . ($newStatus ? 'activated' : 'deactivated'));
            flash_set('success', 'User status updated.');
        }
    }
    redirect(app_url('/director/users.php'));
}

// ---- Handle delete user ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    csrf_verify();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $confirmed = (int) ($_POST['confirmed'] ?? 0);

    if ($targetId === current_user_id()) {
        flash_set('error', 'You cannot delete your own account.');
    } elseif (!$confirmed) {
        flash_set('error', 'Please confirm the deletion by checking the confirmation box.');
    } else {
        try {
            $stmt = $pdo->prepare('SELECT username, role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id');
            $stmt->execute(['id' => $targetId]);
            $row = $stmt->fetch();
            if ($row) {
                $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $targetId]);
                audit_log('delete_user', 'user_management', 'users', $targetId, "Deleted user {$row['username']} ({$row['role_name']})");
                flash_set('success', "User '{$row['username']}' has been permanently deleted.");
            }
        } catch (Throwable $e) {
            error_log('[ASMS] delete_user failed: ' . $e->getMessage());
            flash_set('error', 'Cannot delete user. They may have related records (student, staff, etc.). Deactivate instead.');
        }
    }
    redirect(app_url('/director/users.php'));
}

// ---- Handle force password reset --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_reset') {
    csrf_verify();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $tempPassword = 'Reset@' . random_int(1000, 9999);
    $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

    $pdo->prepare('UPDATE users SET password_hash = :h, must_change_password = 1, failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id')
        ->execute(['h' => $hash, 'id' => $targetId]);

    audit_log('force_password_reset', 'user_management', 'users', $targetId, 'Administrator forced a password reset');
    flash_set('success', "Password reset. New temporary password: {$tempPassword}");
    redirect(app_url('/director/users.php'));
}

// ---- Data for listing ---------------------------------------------------
$roleFilter = $_GET['role'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = 'SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE 1=1';
$params = [];
if ($roleFilter !== '') {
    $sql .= ' AND r.role_name = :role';
    $params['role'] = $roleFilter;
}
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.username LIKE :s3 OR u.email LIKE :s4)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
}
$sql .= ' ORDER BY u.created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Only show admin roles for creation
$roles = $pdo->query("SELECT * FROM roles WHERE role_name IN ('director','system_admin','head_of_school') ORDER BY role_name")->fetchAll();

// Get staff records without linked users (for linking feature)
$unlinkedStaff = $pdo->query(
    "SELECT st.staff_id, st.staff_no, u.first_name, u.last_name, u.username, r.role_name, st.job_title
     FROM staff st
     JOIN users u ON u.user_id = st.user_id
     LEFT JOIN roles r ON r.role_id = u.role_id
     WHERE st.user_id IS NOT NULL
     ORDER BY u.first_name"
)->fetchAll() ?: [];

// Get staff that have no user account at all (shouldn't happen normally but just in case)
$staffWithoutUsers = $pdo->query(
    "SELECT st.staff_id, st.staff_no, u.first_name, u.last_name, u.email, r.role_name
     FROM staff st
     JOIN users u ON u.user_id = st.user_id
     JOIN roles r ON r.role_id = u.role_id
     WHERE 1=1
     ORDER BY u.first_name"
)->fetchAll() ?: [];

$pageTitle = 'User & Role Management';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">User & Role Management</h1>
  <div class="d-flex gap-2">
    <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="fa fa-user-plus me-1"></i> New Admin User</button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#linkUserModal"><i class="fa fa-link me-1"></i> Link Staff to User</button>
  </div>
</div>

<div class="alert alert-info small">
  <i class="fa fa-info-circle me-1"></i>
  <strong>Important:</strong> Only <strong>Director</strong>, <strong>System Admin</strong>, and <strong>Head of School</strong> accounts can be created here.
  Staff accounts (teachers, bursars, etc.) must be onboarded through <a href="<?= e(app_url('/director/staff.php')) ?>" class="alert-link">HR & Staff Management</a>.
  Student accounts are created through <a href="<?= e(app_url('/academic/students.php')) ?>" class="alert-link">Student Registration</a>.
  Parent accounts can be created via <a href="<?= e(app_url('/academic/parent_accounts.php')) ?>" class="alert-link">Parent Account Management</a>.
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Search name, username, or email" value="<?= e($search) ?>">
      </div>
      <div class="col-md-3">
        <select name="role" class="form-select">
          <option value="">All Roles</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= e($r['role_name']) ?>" <?= $roleFilter === $r['role_name'] ? 'selected' : '' ?>>
              <?= e(str_replace('_', ' ', ucfirst($r['role_name']))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-outline-primary w-100"><i class="fa fa-search"></i> Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0" id="usersTable">
      <thead>
        <tr><th>Name</th><th>Username</th><th>Role</th><th>Email / Phone</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?></td>
            <td><code><?= e($u['username']) ?></code></td>
            <td><span class="badge bg-secondary"><?= e(str_replace('_', ' ', $u['role_name'])) ?></span></td>
            <td class="small"><?= e($u['email'] ?: '-') ?><br><span class="text-muted"><?= e($u['phone'] ?: '') ?></span></td>
            <td>
              <?php if ($u['is_active']): ?>
                <span class="badge badge-status-active">Active</span>
              <?php else: ?>
                <span class="badge badge-status-suspended">Inactive</span>
              <?php endif; ?>
              <?php if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()): ?>
                <span class="badge badge-status-pending">Locked</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= $u['last_login_at'] ? e(date('d M Y, H:i', strtotime($u['last_login_at']))) : 'Never' ?></td>
            <td class="text-nowrap">
              <form method="POST" class="d-inline" data-confirm="Force a password reset for this user?">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="force_reset">
                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" title="Force password reset"><i class="fa fa-key"></i></button>
              </form>
              <form method="POST" class="d-inline" data-confirm="Change active status for this user?">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                <button class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" <?= $u['user_id'] == current_user_id() ? 'disabled' : '' ?>>
                  <i class="fa fa-<?= $u['is_active'] ? 'ban' : 'check' ?>"></i>
                </button>
              </form>
              <?php if ($u['user_id'] !== current_user_id()): ?>
              <button type="button" class="btn btn-sm btn-outline-danger" title="Delete user"
                onclick="showDeleteModal(<?= (int) $u['user_id'] ?>, '<?= e(addslashes($u['username'])) ?>', '<?= e(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')">
                <i class="fa fa-trash"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?><tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create User Modal (Admin roles only) -->
<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_user">
        <div class="modal-header">
          <h5 class="modal-title">Create New Admin User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
          <div class="alert alert-warning small">
            <i class="fa fa-info-circle me-1"></i>
            This form only creates <strong>Director</strong>, <strong>System Admin</strong>, and <strong>Head of School</strong> accounts.
            For staff/teacher accounts, use <a href="<?= e(app_url('/director/staff.php')) ?>" class="alert-link">HR & Staff Management</a>.
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">First Name <span class="required-mark">*</span></label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Last Name <span class="required-mark">*</span></label>
              <input type="text" name="last_name" class="form-control" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Username <span class="required-mark">*</span></label>
            <input type="text" name="username" class="form-control" required pattern="[a-zA-Z0-9._-]+">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Role <span class="required-mark">*</span></label>
              <select name="role_id" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($pdo->query("SELECT * FROM roles WHERE role_name IN ('director','system_admin','head_of_school') ORDER BY role_name")->fetchAll() as $r): ?>
                  <option value="<?= (int) $r['role_id'] ?>"><?= e(str_replace('_', ' ', ucfirst($r['role_name']))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <option value="">-- Select --</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>
          <p class="text-muted small mb-0">A temporary password will be generated automatically and shown once after creation. The user must change it on first login.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Link User to Staff Modal -->
<div class="modal fade" id="linkUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Link Staff to User Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Select a user account and link it to an existing staff record. This connects the user's login to their HR/staff profile.</p>

        <form method="POST" class="mb-4 p-3 border rounded">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="link_user_to_staff">
          <h6 class="fw-bold mb-2">Option 1: Link Existing User to Staff Record</h6>
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label">Select User</label>
              <select name="user_id" class="form-select" required>
                <option value="">-- Select User --</option>
                <?php
                $allUsers = $pdo->query("SELECT user_id, username, first_name, last_name, role_name FROM users u JOIN roles r ON r.role_id = u.role_id ORDER BY first_name")->fetchAll();
                foreach ($allUsers as $au): ?>
                  <option value="<?= (int) $au['user_id'] ?>">
                    <?= e($au['first_name'] . ' ' . $au['last_name'] . ' (' . $au['username'] . ' - ' . $au['role_name'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Select Staff</label>
              <select name="staff_id" class="form-select" required>
                <option value="">-- Select Staff --</option>
                <?php
                $allStaff = $pdo->query(
                    "SELECT st.staff_id, st.staff_no, u.first_name, u.last_name, st.job_title
                     FROM staff st
                     JOIN users u ON u.user_id = st.user_id
                     ORDER BY u.first_name"
                )->fetchAll();
                foreach ($allStaff as $as): ?>
                  <option value="<?= (int) $as['staff_id'] ?>">
                    <?= e($as['first_name'] . ' ' . $as['last_name'] . ' (' . $as['staff_no'] . ' - ' . $as['job_title'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100">Link</button>
            </div>
          </div>
        </form>

        <form method="POST" class="p-3 border rounded">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="create_user_for_staff">
          <h6 class="fw-bold mb-2">Option 2: Auto-Create User for Staff Member</h6>
          <p class="text-muted small">For staff who are in the HR system but don't have a login account yet. The system will create a user account and link it automatically.</p>
          <div class="row g-2">
            <div class="col-md-9">
              <select name="staff_id" class="form-select" required>
                <option value="">-- Select Staff Without User Account --</option>
                <?php
                $staffNoUser = $pdo->query(
                    "SELECT st.staff_id, st.staff_no, u.first_name, u.last_name, r.role_name, st.job_title
                     FROM staff st
                     JOIN users u ON u.user_id = st.user_id
                     JOIN roles r ON r.role_id = u.role_id
                     WHERE 1=1
                     ORDER BY u.first_name"
                )->fetchAll();
                foreach ($staffNoUser as $sn): ?>
                  <option value="<?= (int) $sn['staff_id'] ?>">
                    <?= e($sn['first_name'] . ' ' . $sn['last_name'] . ' (' . $sn['staff_no'] . ' - ' . $sn['role_name'] . ')') ?>
                  </option>
                <?php endforeach; ?>
                <?php if (empty($staffNoUser)): ?>
                  <option value="" disabled>No unlinked staff found</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-success w-100" <?= empty($staffNoUser) ? 'disabled' : '' ?>>
                <i class="fa fa-user-plus me-1"></i> Create & Link
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Delete User Confirmation Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="user_id" id="deleteUserId" value="0">
        <input type="hidden" name="confirmed" id="deleteConfirmed" value="0">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fa fa-exclamation-triangle me-2"></i>Delete User</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-1"><strong>Are you sure you want to permanently delete this user?</strong></p>
          <p id="deleteUserInfo" class="text-muted small mb-3"></p>
          <div class="alert alert-warning small mb-0">
            <i class="fa fa-info-circle me-1"></i>
            This action <strong>cannot be undone</strong>. The user will be permanently removed from the system.
            If the user has related records (student, staff, etc.), the deletion may fail — in that case, deactivate the user instead.
          </div>
          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="confirmDeleteCheck" onchange="document.getElementById('deleteConfirmed').value=this.checked?1:0">
            <label class="form-check-label" for="confirmDeleteCheck">
              I understand this is permanent and want to delete this user
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger" id="deleteSubmitBtn" disabled>Delete Permanently</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showDeleteModal(userId, username, fullName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteConfirmed').value = 0;
    document.getElementById('confirmDeleteCheck').checked = false;
    document.getElementById('deleteSubmitBtn').disabled = true;
    document.getElementById('deleteUserInfo').textContent = 'User: ' + fullName + ' (' + username + ')';
    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}

document.getElementById('confirmDeleteCheck').addEventListener('change', function() {
    document.getElementById('deleteSubmitBtn').disabled = !this.checked;
    document.getElementById('deleteConfirmed').value = this.checked ? 1 : 0;
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>