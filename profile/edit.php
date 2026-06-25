<?php
/**
 * profile/edit.php
 * Universal profile editing page.
 * Allows users to update their email, phone, and profile picture only.
 * Sensitive data (name, role, username, etc.) is read-only.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

$pdo = get_db_connection();
$userId = current_user_id();
$user = get_user_profile($pdo, $userId);

if (!$user) {
    flash_set('error', 'User not found.');
    redirect(app_url('/index.php'));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    csrf_verify();

    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // Validate email format
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $pdo->beginTransaction();

            // Update basic info
            $stmt = $pdo->prepare(
                'UPDATE users SET email = :email, phone = :phone WHERE user_id = :uid'
            );
            $stmt->execute([
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'uid'   => $userId,
            ]);

            // Handle profile photo upload
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['photo'];
                $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $uploadError = validate_upload($file, $allowedExt, 5_242_880); // 5MB max

                if ($uploadError) {
                    throw new RuntimeException($uploadError);
                }

                $targetDir = APP_ROOT . '/uploads/photos/';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                // Delete old photo if exists
                if ($user['photo_path'] && file_exists($user['photo_path'])) {
                    @unlink($user['photo_path']);
                }

                $filePath = store_upload($file, $targetDir, 'user_' . $userId);
                $pdo->prepare('UPDATE users SET photo_path = :path WHERE user_id = :uid')
                    ->execute(['path' => $filePath, 'uid' => $userId]);

                // Update session for immediate display
                $_SESSION['photo_path'] = $filePath;
            }

            $pdo->commit();
            audit_log('update_profile', 'profile', 'users', $userId, 'User updated profile');
            flash_set('success', 'Profile updated successfully.');

            // Refresh user data
            $user = get_user_profile($pdo, $userId);
            redirect(app_url('/profile/edit.php'));
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
            error_log('[ASMS] profile update failed: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Edit Profile';
require APP_ROOT . '/includes/header.php';
?>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <a href="<?= e(app_url('/profile/view.php')) ?>" class="small mb-3 d-inline-block"><i class="fa fa-arrow-left me-1"></i> Back to Profile</a>

      <div class="card">
        <div class="card-header">
          <h4 class="mb-0"><i class="fa fa-edit me-2"></i>Edit Profile</h4>
        </div>
        <div class="card-body">
          <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_profile">

            <!-- Profile Photo -->
            <div class="text-center mb-4">
              <div class="mb-3 d-flex justify-content-center">
                <div class="position-relative" style="width:150px;height:150px;">
                  <?= render_avatar($user['photo_path'], $user['first_name'], $user['last_name'], 150, 'border border-3 border-gold') ?>
                  <label for="photoInput" class="position-absolute bottom-0 end-0 btn btn-sm btn-gold rounded-circle p-2 shadow" style="cursor:pointer;" title="Change photo">
                    <i class="fa fa-camera"></i>
                  </label>
                  <input type="file" id="photoInput" name="photo" class="d-none" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewProfilePhoto(event)">
                </div>
              </div>
              <div id="photoPreview" class="mb-2"></div>
              <div class="text-muted small">Click the camera icon to change your profile picture. JPG, PNG, GIF, WebP (max 5MB)</div>
            </div>

            <!-- Read-Only Info -->
            <h6 class="text-muted small text-uppercase border-bottom pb-2 mb-3">Account Information (Read-only)</h6>
            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">Role</label>
                <input type="text" class="form-control" value="<?= e(ucfirst(str_replace('_', ' ', $user['role_name']))) ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" value="<?= e($user['first_name']) ?>" disabled>
              </div>
              <div class="col-md-6">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" value="<?= e($user['last_name']) ?>" disabled>
              </div>
            </div>

            <!-- Editable Fields -->
            <h6 class="text-muted small text-uppercase border-bottom pb-2 mb-3">Communication Details (Editable)</h6>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Email <span class="text-muted">(Communication)</span></label>
                <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" placeholder="Enter email address">
                <div class="form-text">Used for notifications and password recovery.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Phone <span class="text-muted">(Communication)</span></label>
                <input type="text" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>" placeholder="Enter phone number">
                <div class="form-text">Used for SMS alerts and contact.</div>
              </div>
            </div>

            <div class="text-end mt-4">
              <a href="<?= e(app_url('/profile/view.php')) ?>" class="btn btn-outline-secondary me-2">Cancel</a>
              <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Save Changes</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Change Password Link -->
      <div class="card mt-4">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <h6 class="mb-1"><i class="fa fa-lock me-2"></i>Password</h6>
            <p class="text-muted small mb-0">Want to change your password?</p>
          </div>
          <a href="<?= e(app_url('/auth/change_password.php')) ?>" class="btn btn-outline-primary btn-sm">Change Password</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function previewProfilePhoto(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('photoPreview');
        preview.innerHTML = `
            <div class="alert alert-info py-2 small">
                <i class="fa fa-check-circle me-1"></i> Selected: ${file.name}
                <br><img src="${e.target.result}" class="mt-2 rounded-circle border" style="width:80px;height:80px;object-fit:cover;">
            </div>
        `;
    };
    reader.readAsDataURL(file);
}
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>