<?php
/**
 * head_of_school/departments.php
 * Department Management for Head of School.
 * Manage school departments, assign department heads, and view staff distribution.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school']);

$pdo = get_db_connection();
$error = '';

// ---- Create Department -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_department') {
    csrf_verify();
    $deptName = trim($_POST['department_name'] ?? '');
    $deptDesc = trim($_POST['description'] ?? '');
    $headId = (int) ($_POST['department_head_id'] ?? 0) ?: null;

    if ($deptName === '') {
        flash_set('error', 'Department name is required.');
    } else {
        try {
            $pdo->prepare('INSERT INTO departments (department_name, description, department_head_id) VALUES (:name, :desc, :head)')
                ->execute(['name' => $deptName, 'desc' => $deptDesc ?: null, 'head' => $headId]);
            audit_log('create_department', 'department_management', 'departments', (int) $pdo->lastInsertId(), "Created department {$deptName}");
            flash_set('success', "Department '{$deptName}' created successfully.");
        } catch (Throwable $e) {
            flash_set('error', 'Failed to create department. Name may already exist.');
            error_log('[ASMS] create_department: ' . $e->getMessage());
        }
    }
    redirect(app_url('/head_of_school/departments.php'));
}

// ---- Edit Department ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_department') {
    csrf_verify();
    $deptId = (int) ($_POST['department_id'] ?? 0);
    $deptName = trim($_POST['department_name'] ?? '');
    $deptDesc = trim($_POST['description'] ?? '');
    $headId = (int) ($_POST['department_head_id'] ?? 0) ?: null;

    if ($deptId <= 0 || $deptName === '') {
        flash_set('error', 'Department ID and name are required.');
    } else {
        try {
            $pdo->prepare('UPDATE departments SET department_name = :name, description = :desc, department_head_id = :head WHERE department_id = :id')
                ->execute(['name' => $deptName, 'desc' => $deptDesc ?: null, 'head' => $headId, 'id' => $deptId]);
            audit_log('edit_department', 'department_management', 'departments', $deptId, "Edited department {$deptName}");
            flash_set('success', "Department '{$deptName}' updated successfully.");
        } catch (Throwable $e) {
            flash_set('error', 'Failed to update department.');
            error_log('[ASMS] edit_department: ' . $e->getMessage());
        }
    }
    redirect(app_url('/head_of_school/departments.php'));
}

// ---- Delete Department -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_department') {
    csrf_verify();
    $deptId = (int) ($_POST['department_id'] ?? 0);
    if ($deptId <= 0) {
        flash_set('error', 'Invalid department.');
    } else {
        try {
            $pdo->prepare('DELETE FROM departments WHERE department_id = :id')->execute(['id' => $deptId]);
            audit_log('delete_department', 'department_management', 'departments', $deptId, "Deleted department");
            flash_set('success', 'Department deleted successfully.');
        } catch (Throwable $e) {
            flash_set('error', 'Cannot delete department. It may have staff assigned to it.');
            error_log('[ASMS] delete_department: ' . $e->getMessage());
        }
    }
    redirect(app_url('/head_of_school/departments.php'));
}

// ---- Get Data ----------------------------------------------------------
$departments = $pdo->query(
    "SELECT d.*, 
            (SELECT COUNT(*) FROM staff WHERE department_id = d.department_id AND status = 'active') AS staff_count,
            CONCAT(u.first_name, ' ', u.last_name) AS head_name
     FROM departments d
     LEFT JOIN users u ON u.user_id = d.department_head_id
     ORDER BY d.department_name"
)->fetchAll();

// Get potential department heads (staff who can lead departments)
$potentialHeads = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name, r.role_name, st.job_title
     FROM staff st
     JOIN users u ON u.user_id = st.user_id
     JOIN roles r ON r.role_id = u.role_id
     WHERE st.status = 'active'
     ORDER BY u.first_name"
)->fetchAll();

$pageTitle = 'Department Management';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-building text-gold me-2"></i>Department Management</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#createDeptModal"><i class="fa fa-plus me-1"></i> New Department</button>
</div>

<div class="alert alert-info small">
  <i class="fa fa-info-circle me-1"></i>
  The school has 5 mandatory departments: <strong>Academic</strong>, <strong>Hostels</strong>, <strong>Health</strong>, <strong>Social & Welfare</strong>, and <strong>Environment & Maintenance</strong>.
  Use this page to assign department heads and manage departments.
</div>

<div class="row g-4">
  <?php foreach ($departments as $d): ?>
    <div class="col-md-6 col-lg-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="card-title mb-0"><?= e($d['department_name']) ?></h5>
            <div class="btn-group btn-group-sm">
              <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editDeptModal<?= (int) $d['department_id'] ?>" title="Edit"><i class="fa fa-edit"></i></button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete department <?= e($d['department_name']) ?>?')">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete_department">
                <input type="hidden" name="department_id" value="<?= (int) $d['department_id'] ?>">
                <button class="btn btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
              </form>
            </div>
          </div>
          <?php if ($d['description']): ?>
            <p class="text-muted small mb-2"><?= e($d['description']) ?></p>
          <?php endif; ?>
          <div class="d-flex justify-content-between align-items-center mt-3">
            <div>
              <span class="badge bg-primary"><?= (int) $d['staff_count'] ?> Staff</span>
            </div>
            <div class="small text-muted">
              <?php if ($d['head_name']): ?>
                Head: <strong><?= e($d['head_name']) ?></strong>
              <?php else: ?>
                <em class="text-warning">No head assigned</em>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Department Modal -->
    <div class="modal fade" id="editDeptModal<?= (int) $d['department_id'] ?>" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="edit_department">
            <input type="hidden" name="department_id" value="<?= (int) $d['department_id'] ?>">
            <div class="modal-header">
              <h5 class="modal-title">Edit: <?= e($d['department_name']) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-2">
                <label class="form-label">Department Name <span class="required-mark">*</span></label>
                <input type="text" name="department_name" class="form-control" required value="<?= e($d['department_name']) ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2"><?= e($d['description'] ?? '') ?></textarea>
              </div>
              <div class="mb-2">
                <label class="form-label">Department Head</label>
                <select name="department_head_id" class="form-select">
                  <option value="">-- None --</option>
                  <?php foreach ($potentialHeads as $ph): ?>
                    <option value="<?= (int) $ph['user_id'] ?>" <?= (int) $d['department_head_id'] === (int) $ph['user_id'] ? 'selected' : '' ?>>
                      <?= e($ph['first_name'] . ' ' . $ph['last_name'] . ' (' . $ph['job_title'] . ')') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Create Department Modal -->
<div class="modal fade" id="createDeptModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_department">
        <div class="modal-header">
          <h5 class="modal-title">Create New Department</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Department Name <span class="required-mark">*</span></label>
            <input type="text" name="department_name" class="form-control" required placeholder="e.g. Sports Department">
          </div>
          <div class="mb-2">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Describe this department's function"></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Department Head</label>
            <select name="department_head_id" class="form-select">
              <option value="">-- None --</option>
              <?php foreach ($potentialHeads as $ph): ?>
                <option value="<?= (int) $ph['user_id'] ?>"><?= e($ph['first_name'] . ' ' . $ph['last_name'] . ' (' . $ph['job_title'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Department</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>