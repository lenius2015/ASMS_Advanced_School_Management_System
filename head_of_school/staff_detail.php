<?php
/**
 * head_of_school/staff_detail.php
 * Full staff profile view for Head of School.
 * Head of School can view, edit, manage documents, and change status.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school', 'academic_officer']);

$pdo = get_db_connection();
$staffId = (int) ($_GET['id'] ?? 0);

if ($staffId <= 0) {
    flash_set('error', 'Invalid staff ID.');
    redirect(app_url('/head_of_school/staff.php'));
}

// Fetch staff record
$stmt = $pdo->prepare(
    "SELECT st.*, u.first_name, u.last_name, u.username, u.email, u.phone, u.gender, u.photo_path, u.is_active,
            r.role_name, r.role_id, d.department_name, d.department_id AS dept_id
     FROM staff st
     JOIN users u ON u.user_id = st.user_id
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN departments d ON d.department_id = st.department_id
     WHERE st.staff_id = :sid"
);
$stmt->execute(['sid' => $staffId]);
$staff = $stmt->fetch();

if (!$staff) {
    flash_set('error', 'Staff record not found.');
    redirect(app_url('/head_of_school/staff.php'));
}

// ---- Update Staff Details ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_details') {
    csrf_verify();
    $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
    $jobTitle = trim($_POST['job_title'] ?? '');
    $employmentType = $_POST['employment_type'] ?? 'full_time';
    $dateHired = $_POST['date_hired'] ?? null;
    $basicSalary = (float) ($_POST['basic_salary'] ?? 0);
    $educationLevel = trim($_POST['education_level'] ?? '');
    $status = $_POST['status'] ?? 'active';

    $nidaNumber = trim($_POST['nida_number'] ?? '');
    $passportNumber = trim($_POST['passport_number'] ?? '');
    $passportExpiry = $_POST['passport_expiry'] ?? null;
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccount = trim($_POST['bank_account_no'] ?? '');
    $bankBranch = trim($_POST['bank_branch'] ?? '');
    $tinNumber = trim($_POST['tin_number'] ?? '');
    $nssfNumber = trim($_POST['nssf_number'] ?? '');

    if ($jobTitle === '') {
        flash_set('error', 'Job title is required.');
    } else {
        try {
            $pdo->prepare(
                'UPDATE staff SET department_id = :dept, job_title = :title, employment_type = :etype,
                 date_hired = :hired, basic_salary = :salary, education_level = :edu, status = :status,
                 nida_number = :nida, passport_number = :passport, passport_expiry = :passport_expiry,
                 bank_name = :bank, bank_account_no = :bank_acct, bank_branch = :bank_branch,
                 tin_number = :tin, nssf_number = :nssf
                 WHERE staff_id = :sid'
            )->execute([
                'dept' => $departmentId, 'title' => $jobTitle, 'etype' => $employmentType,
                'hired' => $dateHired ?: null, 'salary' => $basicSalary, 'edu' => $educationLevel ?: null,
                'status' => $status, 'sid' => $staffId,
                'nida' => $nidaNumber ?: null, 'passport' => $passportNumber ?: null,
                'passport_expiry' => $passportExpiry, 'bank' => $bankName ?: null,
                'bank_acct' => $bankAccount ?: null, 'bank_branch' => $bankBranch ?: null,
                'tin' => $tinNumber ?: null, 'nssf' => $nssfNumber ?: null,
            ]);
            audit_log('edit_staff', 'staff_management', 'staff', $staffId, "Updated staff details from profile page");
            flash_set('success', 'Staff details updated successfully.');
        } catch (Throwable $e) {
            error_log('[ASMS] update staff details failed: ' . $e->getMessage());
            flash_set('error', 'Failed to update staff details.');
        }
    }
    redirect(app_url('/head_of_school/staff_detail.php?id=' . $staffId));
}

// ---- Deactivate User Account -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_user_active') {
    csrf_verify();

    // Prevent non-director users from deactivating a director
    $currentRole = current_role();
    if ($staff['role_name'] === 'director' && !in_array($currentRole, ['director', 'system_admin'], true)) {
        flash_set('error', 'You do not have permission to deactivate the School Director account.');
        redirect(app_url('/head_of_school/staff_detail.php?id=' . $staffId));
    }

    $userId = (int) $staff['user_id'];
    $newActive = $staff['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE users SET is_active = :a WHERE user_id = :id')
        ->execute(['a' => $newActive, 'id' => $userId]);
    audit_log($newActive ? 'activate_user' : 'deactivate_user', 'user_management', 'users', $userId, "User toggled from staff profile");
    flash_set('success', 'User account ' . ($newActive ? 'activated' : 'deactivated') . '.');
    redirect(app_url('/head_of_school/staff_detail.php?id=' . $staffId));
}

// ---- Delete Staff -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_staff') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);

    if ($staffId <= 0) {
        flash_set('error', 'Invalid staff ID.');
        redirect(app_url('/head_of_school/staff_detail.php?id=' . $staffId));
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT st.*, u.user_id, u.username, r.role_name FROM staff st JOIN users u ON u.user_id = st.user_id JOIN roles r ON r.role_id = u.role_id WHERE st.staff_id = :id");
        $stmt->execute(['id' => $staffId]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new Exception('Staff record not found.');
        }


        // Prevent non-director users from deleting a director
        $currentRole = current_role();
        if ($record['role_name'] === 'director' && !in_array($currentRole, ['director', 'system_admin'], true)) {
            $pdo->rollBack();
            flash_set('error', 'You do not have permission to delete the School Director.');
            redirect(app_url('/head_of_school/staff_detail.php?id=' . $staffId));
        }

        // Check for dependencies
        $deps = [];
        $check = $pdo->prepare("SELECT COUNT(*) FROM class_subjects WHERE teacher_id = :uid");
        $check->execute(['uid' => $record['user_id']]);
        if ((int) $check->fetchColumn() > 0) $deps[] = 'class subject assignments';

        $check = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE class_teacher_id = :uid");
        $check->execute(['uid' => $record['user_id']]);
        if ((int) $check->fetchColumn() > 0) $deps[] = 'class teacher assignments';

        $check = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_head_id = :uid");
        $check->execute(['uid' => $record['user_id']]);
        if ((int) $check->fetchColumn() > 0) $deps[] = 'department head assignments';

        if (!empty($deps)) {
            $pdo->rollBack();
            flash_set('error', 'Cannot delete staff: This staff member has ' . implode(', ', $deps) . '. Please reassign or terminate them first.');
            redirect(app_url('/head_of_school/staff_detail.php?id=' . $staffId));
        }

        // Delete associated files
        foreach (['staff_documents', 'staff_certificates', 'staff_qualifications'] as $table) {
            $fileCol = $table === 'staff_documents' || $table === 'staff_certificates' || $table === 'staff_qualifications' ? 'file_path' : 'file_path';
            $files = $pdo->prepare("SELECT file_path FROM {$table} WHERE staff_id = :sid");
            $files->execute(['sid' => $staffId]);
            foreach ($files->fetchAll() as $f) {
                if ($f['file_path'] && file_exists($f['file_path'])) {
                    @unlink($f['file_path']);
                }
            }
        }

        $staffNo = $record['staff_no'];
        $staffName = $record['first_name'] . ' ' . $record['last_name'];
        $pdo->prepare("DELETE FROM staff WHERE staff_id = :id")->execute(['id' => $staffId]);

        $pdo->commit();
        audit_log('delete_staff', 'staff_management', 'staff', $staffId, "Deleted staff {$staffNo} - {$staffName}");
        flash_set('success', "Staff ({$staffNo}) {$staffName} has been permanently deleted.");
        redirect(app_url('/head_of_school/staff.php'));
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[ASMS] delete_staff failed: ' . $e->getMessage());
        flash_set('error', 'Failed to delete staff: ' . $e->getMessage());
        redirect(app_url('/head_of_school/staff_detail.php?id=' . $staffId));
    }
}

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

$pageTitle = 'Staff Profile: ' . $staff['first_name'] . ' ' . $staff['last_name'];
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-id-badge text-gold me-2"></i>Staff Profile</h1>
  <div class="d-flex gap-2">
    <a href="<?= e(app_url('/head_of_school/staff.php')) ?>" class="btn btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i> Back to Staff</a>
    <?php $currentRole = current_role(); ?>
    <?php if (!($staff['role_name'] === 'director' && !in_array($currentRole, ['director', 'system_admin'], true))): ?>
    <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteStaff(<?= (int) $staffId ?>, '<?= e($staff['first_name'] . ' ' . $staff['last_name']) ?>')">
      <i class="fa fa-trash me-1"></i> Delete Staff
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4">
  <!-- Profile Card -->
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body">
        <div class="mb-3">
          <?php if ($staff['photo_path'] && file_exists($staff['photo_path'])): ?>
            <img src="<?= e(app_url($staff['photo_path'])) ?>" class="rounded-circle" width="120" height="120" style="object-fit:cover;" alt="">
          <?php else: ?>
            <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center" style="width:120px;height:120px;">
              <i class="fa fa-user fa-4x text-secondary"></i>
            </div>
          <?php endif; ?>
        </div>
        <h5 class="mb-1"><?= e($staff['first_name'] . ' ' . $staff['last_name']) ?></h5>
        <p class="text-muted small mb-2"><code><?= e($staff['staff_no']) ?></code></p>
        <span class="badge bg-secondary"><?= e(str_replace('_', ' ', $staff['role_name'])) ?></span>
        <span class="badge bg-<?= $staff['status']==='active'?'success':($staff['status']==='on_leave'?'warning':'secondary') ?>">
          <?= e(ucfirst(str_replace('_',' ',$staff['status']))) ?>
        </span>
        <hr>
        <div class="text-start small">
          <div class="mb-1"><strong>Username:</strong> <code><?= e($staff['username']) ?></code></div>
          <div class="mb-1"><strong>Email:</strong> <?= e($staff['email'] ?: '-') ?></div>
          <div class="mb-1"><strong>Phone:</strong> <?= e($staff['phone'] ?: '-') ?></div>
        </div>
        <?php if (!($staff['role_name'] === 'director' && !in_array($currentRole, ['director', 'system_admin'], true))): ?>
        <form method="POST" class="mt-2" onsubmit="return confirm('Toggle user account active status?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="toggle_user_active">
          <button type="submit" class="btn btn-sm btn-outline-<?= $staff['is_active']?'danger':'success' ?> w-100">
            <i class="fa fa-<?= $staff['is_active']?'ban':'check' ?> me-1"></i>
            <?= $staff['is_active'] ? 'Deactivate User Account' : 'Activate User Account' ?>
          </button>
        </form>
        <?php endif; ?>
      </div>
      </div>
    </div>
  </div>

  <!-- Details & Edit Form -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Employment Details</h5></div>
      <div class="card-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_details">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Department</label>
              <select name="department_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= (int) $d['department_id'] ?>" <?= (int) $staff['dept_id'] === (int) $d['department_id'] ? 'selected' : '' ?>>
                    <?= e($d['department_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Job Title <span class="required-mark">*</span></label>
              <input type="text" name="job_title" class="form-control" required value="<?= e($staff['job_title']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Employment Type</label>
              <select name="employment_type" class="form-select">
                <option value="full_time" <?= $staff['employment_type']==='full_time'?'selected':''?>>Full Time</option>
                <option value="part_time" <?= $staff['employment_type']==='part_time'?'selected':''?>>Part Time</option>
                <option value="contract" <?= $staff['employment_type']==='contract'?'selected':''?>>Contract</option>
                <option value="volunteer" <?= $staff['employment_type']==='volunteer'?'selected':''?>>Volunteer</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="active" <?= $staff['status']==='active'?'selected':''?>>Active</option>
                <option value="on_leave" <?= $staff['status']==='on_leave'?'selected':''?>>On Leave</option>
                <option value="suspended" <?= $staff['status']==='suspended'?'selected':''?>>Suspended</option>
                <option value="terminated" <?= $staff['status']==='terminated'?'selected':''?>>Terminated</option>
                <option value="retired" <?= $staff['status']==='retired'?'selected':''?>>Retired</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date Hired</label>
              <input type="date" name="date_hired" class="form-control" value="<?= e($staff['date_hired'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Basic Salary (TZS)</label>
              <input type="number" name="basic_salary" class="form-control" min="0" step="1000" value="<?= e($staff['basic_salary'] ?: '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Education Level</label>
              <select name="education_level" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach (['Primary','Secondary','Certificate','Diploma',"Bachelor's Degree","Master's Degree",'PhD','Other'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= $staff['education_level'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <hr>
          <h6 class="text-muted small text-uppercase mb-2">Identity & Bank Details</h6>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">NIDA Number</label>
              <input type="text" name="nida_number" class="form-control" value="<?= e($staff['nida_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Passport Number</label>
              <input type="text" name="passport_number" class="form-control" value="<?= e($staff['passport_number'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Passport Expiry</label>
              <input type="date" name="passport_expiry" class="form-control" value="<?= e($staff['passport_expiry'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Bank Name</label>
              <input type="text" name="bank_name" class="form-control" value="<?= e($staff['bank_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Account No</label>
              <input type="text" name="bank_account_no" class="form-control" value="<?= e($staff['bank_account_no'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Bank Branch</label>
              <input type="text" name="bank_branch" class="form-control" value="<?= e($staff['bank_branch'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">TIN Number</label>
              <input type="text" name="tin_number" class="form-control" value="<?= e($staff['tin_number'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">NSSF Number</label>
              <input type="text" name="nssf_number" class="form-control" value="<?= e($staff['nssf_number'] ?? '') ?>">
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Delete Staff Confirmation Modal -->
<div class="modal fade" id="deleteStaffModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST" id="deleteStaffForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="delete_staff">
        <input type="hidden" name="staff_id" id="deleteStaffId" value="0">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fa fa-exclamation-triangle me-1"></i> Delete Staff</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
<img src="...">  </div>
          <p class="mb-2"><strong>Are you sure you want to permanently delete this staff member?</strong></p>
          <p class="text-danger small mb-0"><i class="fa fa-info-circle"></i> This action will also delete all associated documents, certificates, qualifications, leave records, and the user account. This cannot be undone.</p>
          <p class="mt-2 mb-0">Staff: <strong id="deleteStaffName"></strong></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fa fa-trash me-1"></i> Permanently Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function confirmDeleteStaff(staffId, staffName) {
  document.getElementById('deleteStaffId').value = staffId;
  document.getElementById('deleteStaffName').textContent = staffName;
  new bootstrap.Modal(document.getElementById('deleteStaffModal')).show();
}
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
