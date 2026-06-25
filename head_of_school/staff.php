<?php
/**
 * head_of_school/staff.php
 * Enhanced Staff & HR management for Head of School.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school']);

$pdo = get_db_connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_staff') {
    csrf_verify();

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $roleId    = (int) ($_POST['role_id'] ?? 0);
    $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
    $jobTitle  = trim($_POST['job_title'] ?? '');
    $employmentType = $_POST['employment_type'] ?? 'full_time';
    $dateHired = $_POST['date_hired'] ?? null;
    $basicSalary = (float) ($_POST['basic_salary'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $educationLevel = trim($_POST['education_level'] ?? '');
    $gender = $_POST['gender'] ?? null;

    if ($firstName === '' || $lastName === '' || $roleId <= 0 || $jobTitle === '') {
        $error = 'First name, last name, role, and job title are required.';
    } else {
        try {
            $pdo->beginTransaction();

            $staffNo = generate_sequential_id($pdo, 'STF', (int) date('Y'));
            $username = strtolower($firstName[0] . $lastName . random_int(10, 99));
            $tempPassword = 'Staff@' . random_int(1000, 9999);
            $hash = password_hash($tempPassword, PASSWORD_BCRYPT);

            $pdo->prepare(
                'INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, must_change_password)
                 VALUES (UUID(), :rid, :u, :email, :phone, :h, :fn, :ln, :gender, 1)'
            )->execute([
                'rid' => $roleId, 'u' => $username, 'email' => $email ?: null, 'phone' => $phone ?: null,
                'h' => $hash, 'fn' => $firstName, 'ln' => $lastName, 'gender' => $gender ?: null,
            ]);
            $staffUserId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, basic_salary, education_level)
                 VALUES (:uid, :sno, :dept, :title, :etype, :hired, :salary, :edu)'
            )->execute([
                'uid' => $staffUserId, 'sno' => $staffNo, 'dept' => $departmentId,
                'title' => $jobTitle, 'etype' => $employmentType, 'hired' => $dateHired ?: null,
                'salary' => $basicSalary, 'edu' => $educationLevel ?: null,
            ]);
            $newStaffId = (int) $pdo->lastInsertId();

            $pdo->commit();
            audit_log('create_staff', 'staff_management', 'staff', $newStaffId, "Onboarded staff {$staffNo}");
            flash_set('success', "Staff registered with number {$staffNo}. Login username: {$username}, temporary password: {$tempPassword}.");
            redirect(app_url('/head_of_school/staff.php'));
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ASMS] create_staff failed: ' . $e->getMessage());
            $error = 'Failed to register staff member. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_upload_cv') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $docName = trim($_POST['doc_name'] ?? '');
    if ($staffId <= 0 || $docName === '' || !isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] === UPLOAD_ERR_NO_FILE) {
        flash_set('error', 'Staff, document name, and file are required.');
    } else {
        $file = $_FILES['doc_file'];
        $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $uploadError = validate_upload($file, $allowedExt, 20_971_520);
        if ($uploadError) {
            flash_set('error', $uploadError);
        } else {
            try {
                $targetDir = APP_ROOT . '/uploads/staff_documents/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                $filePath = store_upload($file, $targetDir, 'doc_' . $staffId);
                $mimeType = mime_content_type($filePath) ?: $file['type'];
                $stmt = $pdo->prepare(
                    'INSERT INTO staff_documents (staff_id, document_type, document_name, file_path, file_size, mime_type, uploaded_by)
                     VALUES (:sid, :type, :name, :path, :size, :mime, :uid)'
                );
                $stmt->execute(['sid' => $staffId, 'type' => 'cv', 'name' => $docName, 'path' => $filePath, 'size' => $file['size'], 'mime' => $mimeType, 'uid' => current_user_id()]);
                flash_set('success', 'Document uploaded successfully.');
            } catch (Throwable $e) {
                error_log('[ASMS] quick upload failed: ' . $e->getMessage());
                flash_set('error', 'Failed to upload document.');
            }
        }
    }
    redirect(app_url('/head_of_school/staff.php'));
}

$search = trim($_GET['q'] ?? '');
$deptFilter = (int) ($_GET['department_id'] ?? 0);
$typeFilter = $_GET['employment_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT st.*, u.first_name, u.last_name, u.username, u.email, u.phone, u.gender, u.photo_path,
               r.role_name, d.department_name,
               (SELECT COUNT(*) FROM staff_documents WHERE staff_id = st.staff_id) AS doc_count,
               (SELECT COUNT(*) FROM staff_certificates WHERE staff_id = st.staff_id) AS cert_count,
               (SELECT COUNT(*) FROM staff_qualifications WHERE staff_id = st.staff_id) AS qual_count
        FROM staff st
        JOIN users u ON u.user_id = st.user_id
        JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN departments d ON d.department_id = st.department_id
        WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR st.staff_no LIKE :s3 OR st.job_title LIKE :s4)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
}
if ($deptFilter > 0) { $sql .= ' AND st.department_id = :dept'; $params['dept'] = $deptFilter; }
if ($typeFilter !== '') { $sql .= ' AND st.employment_type = :etype'; $params['etype'] = $typeFilter; }
if ($statusFilter !== '') { $sql .= ' AND st.status = :status'; $params['status'] = $statusFilter; }
$sql .= ' ORDER BY st.created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll();

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$roles = $pdo->query("SELECT * FROM roles WHERE role_name NOT IN ('parent','student') ORDER BY role_name")->fetchAll();

$totalActive = (int) $pdo->query("SELECT COUNT(*) FROM staff WHERE status='active'")->fetchColumn();
$totalOnLeave = (int) $pdo->query("SELECT COUNT(*) FROM staff WHERE status='on_leave'")->fetchColumn();
$deptStats = $pdo->query("SELECT d.department_name, COUNT(st.staff_id) AS cnt FROM staff st JOIN departments d ON d.department_id = st.department_id WHERE st.status='active' GROUP BY d.department_name ORDER BY cnt DESC")->fetchAll();

$pageTitle = 'Staff Management';
require APP_ROOT . '/includes/header.php';
?>
<style>
.staff-avatar-sm { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; background: #e9ecef; display: inline-flex; align-items: center; justify-content: center; }
.hr-stat-card { border-left: 4px solid; border-radius: 8px; padding: 1rem; }
.hr-stat-card.accent-blue { border-left-color: #2B6CB0; }
.hr-stat-card.accent-green { border-left-color: #1F8A55; }
.hr-stat-card.accent-orange { border-left-color: #DD6B20; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-id-badge text-gold me-2"></i>Staff Management</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newStaffModal"><i class="fa fa-user-plus me-1"></i> Onboard Staff</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="hr-stat-card accent-blue bg-white shadow-sm">
      <div class="d-flex justify-content-between align-items-center">
        <div><div class="fs-3 fw-bold"><?= $totalActive ?></div><div class="text-muted small">Active Staff</div></div>
        <i class="fa fa-user-check fa-2x text-primary opacity-25"></i>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="hr-stat-card accent-orange bg-white shadow-sm">
      <div class="d-flex justify-content-between align-items-center">
        <div><div class="fs-3 fw-bold"><?= $totalOnLeave ?></div><div class="text-muted small">On Leave</div></div>
        <i class="fa fa-clock fa-2x text-warning opacity-25"></i>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="hr-stat-card accent-green bg-white shadow-sm">
      <div class="d-flex justify-content-between align-items-center">
        <div><div class="fs-3 fw-bold"><?= count($staffList) ?></div><div class="text-muted small">Total Records</div></div>
        <i class="fa fa-users fa-2x text-success opacity-25"></i>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($deptStats)): ?>
<div class="d-flex flex-wrap gap-2 mb-4">
  <span class="text-muted small fw-bold">Departments:</span>
  <?php foreach ($deptStats as $ds): ?>
    <span class="badge bg-light text-dark border"><?= e($ds['department_name']) ?>: <?= (int) $ds['cnt'] ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, staff no, or title..." value="<?= e($search) ?>"></div>
      <div class="col-md-3">
        <select name="department_id" class="form-select form-select-sm">
          <option value="0">All Departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['department_id'] ?>" <?= $deptFilter === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="employment_type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach (['full_time','part_time','contract','volunteer'] as $t): ?>
            <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_',' ',$t))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <?php foreach (['active','on_leave','suspended','terminated','retired'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_',' ',$s))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <button class="btn btn-sm btn-outline-primary w-100"><i class="fa fa-search"></i></button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Staff</th><th>Contact</th><th>Department</th><th>Employment</th><th>Docs</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($staffList as $s): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="staff-avatar-sm"><?php if ($s['photo_path'] && file_exists($s['photo_path'])): ?><img src="<?= e(app_url($s['photo_path'])) ?>" class="staff-avatar-sm" alt=""><?php else: ?><i class="fa fa-user text-secondary"></i><?php endif; ?></div>
                <div>
                  <a href="<?= e(app_url('/director/staff_detail.php?id=' . (int) $s['staff_id'])) ?>" class="fw-semibold text-decoration-none"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></a>
                  <div class="small text-muted"><code><?= e($s['staff_no']) ?></code> &middot; <span class="badge bg-secondary"><?= e(str_replace('_', ' ', $s['role_name'])) ?></span></div>
                </div>
              </div>
            </td>
            <td class="small"><?= e($s['email'] ?: $s['phone'] ?: '-') ?></td>
            <td><span class="badge bg-info"><?= e($s['department_name'] ?: '-') ?></span><div class="small text-muted mt-1"><?= e($s['job_title']) ?></div></td>
            <td class="small"><?= e(ucfirst(str_replace('_',' ',$s['employment_type']))) ?><div class="text-muted"><?= e(format_date($s['date_hired'])) ?></div></td>
            <td class="small">
              <span class="badge bg-<?= $s['doc_count'] > 0 ? 'success' : 'secondary' ?>">📄<?= (int) $s['doc_count'] ?></span>
              <span class="badge bg-<?= $s['cert_count'] > 0 ? 'success' : 'secondary' ?>">🎓<?= (int) $s['cert_count'] ?></span>
            </td>
            <td><span class="badge bg-<?= $s['status']==='active' ? 'success' : ($s['status']==='on_leave' ? 'warning' : 'secondary') ?>"><?= e(ucfirst(str_replace('_',' ',$s['status']))) ?></span></td>
            <td class="text-center">
              <a href="<?= e(app_url('/director/staff_detail.php?id=' . (int) $s['staff_id'])) ?>" class="btn btn-sm btn-outline-primary" title="View Profile"><i class="fa fa-eye"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($staffList)): ?><tr><td colspan="7" class="text-center text-muted py-4">No staff found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Onboard Staff Modal (Enhanced) -->
<div class="modal fade" id="newStaffModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_staff">
        <div class="modal-header">
          <h5 class="modal-title">Onboard New Staff</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
          <div class="row g-2 mb-2">
            <div class="col-md-4"><label class="form-label">First Name <span class="required-mark">*</span></label><input type="text" name="first_name" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Last Name <span class="required-mark">*</span></label><input type="text" name="last_name" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Gender</label>
              <select name="gender" class="form-select"><option value="">-- Select --</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-4"><label class="form-label">System Role <span class="required-mark">*</span></label>
              <select name="role_id" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($roles as $r): ?><option value="<?= (int) $r['role_id'] ?>"><?= e(str_replace('_',' ',ucfirst($r['role_name']))) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Department</label>
              <select name="department_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Employment Type</label>
              <select name="employment_type" class="form-select">
                <option value="full_time">Full Time</option><option value="part_time">Part Time</option>
                <option value="contract">Contract</option><option value="volunteer">Volunteer</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Job Title <span class="required-mark">*</span></label><input type="text" name="job_title" class="form-control" required placeholder="e.g. Mathematics Teacher"></div>
            <div class="col-md-3"><label class="form-label">Date Hired</label><input type="date" name="date_hired" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Basic Salary (TZS)</label><input type="number" name="basic_salary" class="form-control" min="0" step="1000"></div>
          </div>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Education Level</label>
              <select name="education_level" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach (['Primary','Secondary','Certificate','Diploma',"Bachelor's Degree","Master's Degree",'PhD','Other'] as $opt): ?>
                  <option value="<?= $opt ?>"><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="text-muted small mt-3 mb-0">A staff number and login credentials will be generated automatically.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Onboard Staff</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>