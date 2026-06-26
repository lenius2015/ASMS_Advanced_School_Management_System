<?php
/**
 * academic/parent_accounts.php
 * Parent Account Management.
 * Create user accounts for guardians/parents and link them to students.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school', 'academic_officer']);

$pdo = get_db_connection();

// ---- Create Parent User Account ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_parent_user') {
    csrf_verify();
    $guardianId = (int) ($_POST['guardian_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($guardianId <= 0) {
        flash_set('error', 'Please select a guardian.');
    } else {
        try {
            $pdo->beginTransaction();

            // Get guardian details
            $stmt = $pdo->prepare('SELECT * FROM guardians WHERE guardian_id = :gid');
            $stmt->execute(['gid' => $guardianId]);
            $guardian = $stmt->fetch();

            if (!$guardian) {
                throw new Exception('Guardian not found.');
            }

            if (!empty($guardian['user_id'])) {
                throw new Exception('This guardian already has a user account.');
            }

            // Find parent role ID
            $parentRoleId = $pdo->query("SELECT role_id FROM roles WHERE role_name='parent'")->fetch()['role_id'];

            // Create user account
            $username = generate_username($pdo, $guardian['first_name'], $guardian['last_name'], $email ?: $guardian['email'] ?? '');
            $tempPassword = bin2hex(random_bytes(4));
            $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

            $pdo->prepare(
                'INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, must_change_password)
                 VALUES (UUID(), :rid, :u, :email, :phone, :h, :fn, :ln, 1)'
            )->execute([
                'rid' => $parentRoleId, 'u' => $username,
                'email' => ($email ?: $guardian['email']) ?: null,
                'phone' => ($phone ?: $guardian['phone']) ?: null,
                'h' => $hash,
                'fn' => $guardian['first_name'], 'ln' => $guardian['last_name'],
            ]);
            $newUserId = (int) $pdo->lastInsertId();

            // Link to guardian record
            $pdo->prepare('UPDATE guardians SET user_id = :uid WHERE guardian_id = :gid')
                ->execute(['uid' => $newUserId, 'gid' => $guardianId]);

            $pdo->commit();
            audit_log('create_parent_user', 'user_management', 'guardians', $guardianId, "Created parent user account for {$guardian['first_name']} {$guardian['last_name']}");
            flash_set('success', "Parent account created for {$guardian['first_name']} {$guardian['last_name']}.<br><strong>Username:</strong> {$username}<br><strong>Password:</strong> {$tempPassword}");
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ASMS] create_parent_user failed: ' . $e->getMessage());
            flash_set('error', $e->getMessage() ?: 'Failed to create parent account.');
        }
    }
    redirect(app_url('/academic/parent_accounts.php'));
}

// ---- Remove Parent User Account Link -----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlink_parent_user') {
    csrf_verify();
    $guardianId = (int) ($_POST['guardian_id'] ?? 0);
    if ($guardianId > 0) {
        $pdo->prepare('UPDATE guardians SET user_id = NULL WHERE guardian_id = :gid')
            ->execute(['gid' => $guardianId]);
        flash_set('success', 'Parent user account unlinked.');
    }
    redirect(app_url('/academic/parent_accounts.php'));
}

// Get all guardians with their linked students info
$guardians = $pdo->query(
    "SELECT g.*, 
            CONCAT(u.first_name, ' ', u.last_name, ' (', u.username, ')') AS user_info,
            u.is_active AS user_active,
            (SELECT GROUP_CONCAT(CONCAT(s2.first_name, ' ', s2.last_name, ' (', st.admission_no, ')') SEPARATOR ', ')
             FROM student_guardians sg
             JOIN students st ON st.student_id = sg.student_id
             JOIN users s2 ON s2.user_id = st.user_id
             WHERE sg.guardian_id = g.guardian_id) AS linked_students,
            (SELECT COUNT(*) FROM student_guardians WHERE guardian_id = g.guardian_id) AS student_count
     FROM guardians g
     LEFT JOIN users u ON u.user_id = g.user_id
     ORDER BY g.last_name, g.first_name"
)->fetchAll();

$pageTitle = 'Parent Account Management';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-users text-gold me-2"></i>Parent Account Management</h1>
</div>

<div class="alert alert-info small">
  <i class="fa fa-info-circle me-1"></i>
  Create user accounts for parents/guardians so they can log in to the <strong>Parent Portal</strong> to view their children's results, fee status, and attendance.
  Each parent account is automatically linked to their children's records.
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Parent/Guardian</th>
          <th>Contact</th>
          <th>Linked Students</th>
          <th>Account Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($guardians as $g): ?>
          <tr>
            <td>
              <strong><?= e($g['first_name'] . ' ' . $g['last_name']) ?></strong>
              <div class="small text-muted"><?= e(ucfirst($g['relationship'])) ?></div>
            </td>
            <td class="small">
              <?php if ($g['phone']): ?><i class="fa fa-phone me-1"></i><?= e($g['phone']) ?><br><?php endif; ?>
              <?php if ($g['email']): ?><i class="fa fa-envelope me-1"></i><?= e($g['email']) ?><?php endif; ?>
            </td>
            <td class="small">
              <?php if ($g['linked_students']): ?>
                <span class="badge bg-info me-1"><?= (int) $g['student_count'] ?> student(s)</span>
                <span class="text-muted"><?= e($g['linked_students']) ?></span>
              <?php else: ?>
                <span class="text-muted">No students linked</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($g['user_id']): ?>
                <span class="badge bg-<?= $g['user_active'] ? 'success' : 'danger' ?>">
                  <i class="fa fa-user me-1"></i><?= $g['user_active'] ? 'Has Account' : 'Inactive' ?>
                </span>
                <div class="small text-muted mt-1"><?= e($g['user_info']) ?></div>
              <?php else: ?>
                <span class="badge bg-secondary"><i class="fa fa-times me-1"></i>No Account</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($g['user_id']): ?>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary" onclick="showCredentials(<?= (int) $g['guardian_id'] ?>, '<?= e($g['first_name'] . ' ' . $g['last_name']) ?>')" title="View/Reset Password">
                    <i class="fa fa-key"></i> Reset
                  </button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Unlink user account from this parent?')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="unlink_parent_user">
                    <input type="hidden" name="guardian_id" value="<?= (int) $g['guardian_id'] ?>">
                    <button class="btn btn-outline-danger" title="Unlink Account"><i class="fa fa-unlink"></i></button>
                  </form>
                </div>
              <?php else: ?>
                <button class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#createParentModal<?= (int) $g['guardian_id'] ?>">
                  <i class="fa fa-user-plus me-1"></i> Create Account
                </button>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Create Parent Account Modal -->
          <div class="modal fade" id="createParentModal<?= (int) $g['guardian_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="POST">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="create_parent_user">
                  <input type="hidden" name="guardian_id" value="<?= (int) $g['guardian_id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Create Account for <?= e($g['first_name'] . ' ' . $g['last_name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p class="text-muted small">A parent portal account will be created. The user will be able to view results and fee status for their linked children.</p>
                    <div class="mb-2">
                      <label class="form-label">Email (optional)</label>
                      <input type="email" name="email" class="form-control" value="<?= e($g['email'] ?? '') ?>" placeholder="For account recovery">
                    </div>
                    <div class="mb-2">
                      <label class="form-label">Phone (optional)</label>
                      <input type="text" name="phone" class="form-control" value="<?= e($g['phone'] ?? '') ?>" placeholder="For SMS notifications">
                    </div>
                    <div class="alert alert-success small mb-0">
                      <strong>Linked Students:</strong><br>
                      <?php if ($g['linked_students']): ?>
                        <?= e($g['linked_students']) ?>
                      <?php else: ?>
                        <em>None linked</em>
                      <?php endif; ?>
                    </div>
                    <p class="text-muted small mt-2 mb-0">A username and temporary password will be generated automatically.</p>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Account</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($guardians)): ?>
          <tr><td colspan="5" class="text-center text-muted py-5">
            <i class="fa fa-users fa-3x mb-3 d-block text-muted"></i>
            No parent/guardian records found. Parents are created when you register students with guardian information.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Reset Password Modal (placeholder) -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="<?= e(app_url('/director/users.php')) ?>">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="force_reset">
        <input type="hidden" name="user_id" id="resetUserId" value="0">
        <div class="modal-header">
          <h5 class="modal-title">Reset Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>This will reset the password for <strong id="resetUserName"></strong>. A new temporary password will be generated.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showCredentials(guardianId, name) {
    alert('Account created successfully!\n\nUsername and password will be shown after creation.\n\nPlease note down the credentials for: ' + name);
}
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>