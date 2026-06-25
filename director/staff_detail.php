<?php
/**
 * director/staff_detail.php
 * Full staff profile view with all personal, employment, document,
 * qualification, certificate, and HR information.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school', 'academic_officer']);

$pdo = get_db_connection();
$staffId = (int) ($_GET['id'] ?? 0);

// Check if this is a document download/delete action
$action = $_GET['action'] ?? '';
if ($action === 'download_doc' && $staffId > 0) {
    $docId = (int) ($_GET['doc_id'] ?? 0);
    $doc = $pdo->prepare("SELECT * FROM staff_documents WHERE document_id = :id AND staff_id = :sid");
    $doc->execute(['id' => $docId, 'sid' => $staffId]);
    $docData = $doc->fetch();
    if ($docData && file_exists($docData['file_path'])) {
        header('Content-Type: ' . ($docData['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . basename($docData['file_path']) . '"');
        header('Content-Length: ' . filesize($docData['file_path']));
        readfile($docData['file_path']);
        exit;
    }
    flash_set('error', 'File not found.');
    redirect(app_url('/director/staff_detail.php?id=' . $staffId));
}

if ($action === 'download_cert' && $staffId > 0) {
    $certId = (int) ($_GET['cert_id'] ?? 0);
    $cert = $pdo->prepare("SELECT * FROM staff_certificates WHERE certificate_id = :id AND staff_id = :sid");
    $cert->execute(['id' => $certId, 'sid' => $staffId]);
    $certData = $cert->fetch();
    if ($certData && $certData['file_path'] && file_exists($certData['file_path'])) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($certData['file_path']) . '"');
        readfile($certData['file_path']);
        exit;
    }
    flash_set('error', 'File not found.');
    redirect(app_url('/director/staff_detail.php?id=' . $staffId));
}

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $docType = $_POST['document_type'] ?? 'other';
    $docName = trim($_POST['document_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($staffId <= 0 || $docName === '') {
        flash_set('error', 'Staff ID and document name are required.');
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
        flash_set('error', 'Please select a file to upload.');
    } else {
        $file = $_FILES['document_file'];
        $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        $error = validate_upload($file, $allowedExt, 20_971_520); // 20MB max
        if ($error) {
            flash_set('error', $error);
        } else {
            try {
                $targetDir = APP_ROOT . '/uploads/staff_documents/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                $filePath = store_upload($file, $targetDir, 'doc_' . $staffId);
                $mimeType = mime_content_type($filePath) ?: $file['type'];

                $stmt = $pdo->prepare(
                    'INSERT INTO staff_documents (staff_id, document_type, document_name, file_path, file_size, mime_type, notes, uploaded_by)
                     VALUES (:sid, :type, :name, :path, :size, :mime, :notes, :uid)'
                );
                $stmt->execute([
                    'sid' => $staffId, 'type' => $docType, 'name' => $docName,
                    'path' => $filePath, 'size' => $file['size'], 'mime' => $mimeType,
                    'notes' => $notes ?: null, 'uid' => current_user_id(),
                ]);
                audit_log('upload_staff_document', 'staff_management', 'staff_documents', (int) $pdo->lastInsertId(), "Uploaded {$docType}: {$docName}");
                flash_set('success', 'Document uploaded successfully.');
            } catch (Throwable $e) {
                error_log('[ASMS] document upload failed: ' . $e->getMessage());
                flash_set('error', 'Failed to upload document.');
            }
        }
    }
    redirect(app_url('/director/staff_detail.php?id=' . $staffId));
}

// Handle certificate upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_certificate') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $certName = trim($_POST['certificate_name'] ?? '');
    $authority = trim($_POST['issuing_authority'] ?? '');
    $certNo = trim($_POST['certificate_number'] ?? '');
    $issueDate = $_POST['issue_date'] ?: null;
    $expiryDate = $_POST['expiry_date'] ?: null;
    $certNotes = trim($_POST['notes'] ?? '');

    if ($staffId <= 0 || $certName === '' || $authority === '') {
        flash_set('error', 'Certificate name and issuing authority are required.');
    } else {
        $filePath = null;
        if (isset($_FILES['cert_file']) && $_FILES['cert_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['cert_file'];
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
            $error = validate_upload($file, $allowedExt, 10_485_760);
            if ($error) {
                flash_set('error', $error);
                redirect(app_url('/director/staff_detail.php?id=' . $staffId));
            }
            $targetDir = APP_ROOT . '/uploads/staff_documents/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $filePath = store_upload($file, $targetDir, 'cert_' . $staffId);
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO staff_certificates (staff_id, certificate_name, issuing_authority, certificate_number, issue_date, expiry_date, file_path, notes)
                 VALUES (:sid, :name, :auth, :no, :issue, :expiry, :file, :notes)'
            );
            $stmt->execute([
                'sid' => $staffId, 'name' => $certName, 'auth' => $authority,
                'no' => $certNo ?: null, 'issue' => $issueDate, 'expiry' => $expiryDate,
                'file' => $filePath, 'notes' => $certNotes ?: null,
            ]);
            audit_log('add_staff_certificate', 'staff_management', 'staff_certificates', (int) $pdo->lastInsertId(), "Added cert: {$certName}");
            flash_set('success', 'Certificate added successfully.');
        } catch (Throwable $e) {
            error_log('[ASMS] add certificate failed: ' . $e->getMessage());
            flash_set('error', 'Failed to add certificate.');
        }
    }
    redirect(app_url('/director/staff_detail.php?id=' . $staffId));
}

// Handle qualification upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_qualification') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $qualName = trim($_POST['qualification_name'] ?? '');
    $institution = trim($_POST['institution'] ?? '');
    $fieldStudy = trim($_POST['field_of_study'] ?? '');
    $yearObtained = $_POST['year_obtained'] ? (int) $_POST['year_obtained'] : null;
    $grade = trim($_POST['grade'] ?? '');

    if ($staffId <= 0 || $qualName === '' || $institution === '') {
        flash_set('error', 'Qualification name and institution are required.');
    } else {
        $filePath = null;
        if (isset($_FILES['qual_file']) && $_FILES['qual_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['qual_file'];
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
            $error = validate_upload($file, $allowedExt, 10_485_760);
            if ($error) {
                flash_set('error', $error);
                redirect(app_url('/director/staff_detail.php?id=' . $staffId));
            }
            $targetDir = APP_ROOT . '/uploads/staff_documents/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $filePath = store_upload($file, $targetDir, 'qual_' . $staffId);
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO staff_qualifications (staff_id, qualification_name, institution, field_of_study, year_obtained, grade, file_path)
                 VALUES (:sid, :name, :inst, :field, :year, :grade, :file)'
            );
            $stmt->execute([
                'sid' => $staffId, 'name' => $qualName, 'inst' => $institution,
                'field' => $fieldStudy ?: null, 'year' => $yearObtained, 'grade' => $grade ?: null,
                'file' => $filePath,
            ]);
            audit_log('add_staff_qualification', 'staff_management', 'staff_qualifications', (int) $pdo->lastInsertId(), "Added qual: {$qualName}");
            flash_set('success', 'Qualification added successfully.');
        } catch (Throwable $e) {
            error_log('[ASMS] add qualification failed: ' . $e->getMessage());
            flash_set('error', 'Failed to add qualification.');
        }
    }
    redirect(app_url('/director/staff_detail.php?id=' . $staffId));
}

// Handle staff edit (general info update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_staff_info') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);

    $updates = [
        'job_title' => trim($_POST['job_title'] ?? ''),
        'department_id' => (int) ($_POST['department_id'] ?? 0) ?: null,
        'employment_type' => $_POST['employment_type'] ?? 'full_time',
        'basic_salary' => (float) ($_POST['basic_salary'] ?? 0),
        'date_hired' => $_POST['date_hired'] ?: null,
        'education_level' => trim($_POST['education_level'] ?? ''),
        'years_of_experience' => (int) ($_POST['years_of_experience'] ?? 0),
        'marital_status' => $_POST['marital_status'] ?: null,
        'religion' => trim($_POST['religion'] ?? ''),
        'bank_name' => trim($_POST['bank_name'] ?? ''),
        'bank_account_no' => trim($_POST['bank_account_no'] ?? ''),
        'bank_branch' => trim($_POST['bank_branch'] ?? ''),
        'tin_number' => trim($_POST['tin_number'] ?? ''),
        'nssf_number' => trim($_POST['nssf_number'] ?? ''),
        'next_of_kin_name' => trim($_POST['next_of_kin_name'] ?? ''),
        'next_of_kin_phone' => trim($_POST['next_of_kin_phone'] ?? ''),
        'next_of_kin_relationship' => trim($_POST['next_of_kin_relationship'] ?? ''),
        'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => trim($_POST['emergency_contact_phone'] ?? ''),
    ];

    $setClauses = [];
    $params = [];
    foreach ($updates as $col => $val) {
        $setClauses[] = "{$col} = :{$col}";
        $params[$col] = $val;
    }
    $params['sid'] = $staffId;

    try {
        $pdo->prepare('UPDATE staff SET ' . implode(', ', $setClauses) . ' WHERE staff_id = :sid')->execute($params);

        // Update user basic info
        $stmt = $pdo->prepare("SELECT user_id FROM staff WHERE staff_id = :sid");
        $stmt->execute(['sid' => $staffId]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
        if ($userId) {
            $userUpdates = [];
            if (!empty($_POST['email'])) {
                $userUpdates['email'] = trim($_POST['email']);
            }
            if (!empty($_POST['phone'])) {
                $userUpdates['phone'] = trim($_POST['phone']);
            }
            if (!empty($_POST['gender'])) {
                $userUpdates['gender'] = $_POST['gender'];
            }
            if ($userUpdates) {
                $setStr = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($userUpdates)));
                $userUpdates['uid'] = $userId;
                $pdo->prepare("UPDATE users SET {$setStr} WHERE user_id = :uid")->execute($userUpdates);
            }
        }

        audit_log('update_staff', 'staff_management', 'staff', $staffId, "Updated staff profile");
        flash_set('success', 'Staff information updated successfully.');
    } catch (Throwable $e) {
        error_log('[ASMS] update staff failed: ' . $e->getMessage());
        flash_set('error', 'Failed to update staff information.');
    }
    redirect(app_url('/director/staff_detail.php?id=' . $staffId));
}

// Fetch staff data
$stmt = $pdo->prepare(
    "SELECT st.*, u.first_name, u.last_name, u.username, u.email, u.phone, u.gender, u.photo_path,
            u.is_active, r.role_name, r.role_id, d.department_name
     FROM staff st
     JOIN users u ON u.user_id = st.user_id
     JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN departments d ON d.department_id = st.department_id
     WHERE st.staff_id = :id"
);
$stmt->execute(['id' => $staffId]);
$staff = $stmt->fetch();

if (!$staff) {
    flash_set('error', 'Staff member not found.');
    redirect(app_url('/director/staff.php'));
}

// Fetch related data
$documents = $pdo->prepare("SELECT * FROM staff_documents WHERE staff_id = :sid ORDER BY created_at DESC");
$documents->execute(['sid' => $staffId]);
$documents = $documents->fetchAll();

$certificates = $pdo->prepare("SELECT * FROM staff_certificates WHERE staff_id = :sid ORDER BY created_at DESC");
$certificates->execute(['sid' => $staffId]);
$certificates = $certificates->fetchAll();

$qualifications = $pdo->prepare("SELECT * FROM staff_qualifications WHERE staff_id = :sid ORDER BY year_obtained DESC");
$qualifications->execute(['sid' => $staffId]);
$qualifications = $qualifications->fetchAll();

$emergencyContacts = $pdo->prepare("SELECT * FROM staff_emergency_contacts WHERE staff_id = :sid");
$emergencyContacts->execute(['sid' => $staffId]);
$emergencyContacts = $emergencyContacts->fetchAll();

$leaveRecords = $pdo->prepare("SELECT * FROM staff_leave WHERE staff_id = :sid ORDER BY start_date DESC LIMIT 10");
$leaveRecords->execute(['sid' => $staffId]);
$leaveRecords = $leaveRecords->fetchAll();

$trainingRecords = $pdo->prepare("SELECT * FROM staff_trainings WHERE staff_id = :sid ORDER BY start_date DESC LIMIT 10");
$trainingRecords->execute(['sid' => $staffId]);
$trainingRecords = $trainingRecords->fetchAll();

$performanceRecords = $pdo->prepare("SELECT * FROM staff_performance WHERE staff_id = :sid ORDER BY review_date DESC LIMIT 10");
$performanceRecords->execute(['sid' => $staffId]);
$performanceRecords = $performanceRecords->fetchAll();

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

$pageTitle = 'Staff Profile - ' . $staff['first_name'] . ' ' . $staff['last_name'];
$activeTab = $_GET['tab'] ?? 'profile';
require APP_ROOT . '/includes/header.php';

// Helper to format badge
$badgeStatus = function($status): string {
    $map = ['active'=>'success','on_leave'=>'warning','suspended'=>'danger','terminated'=>'dark','retired'=>'secondary'];
    return '<span class="badge bg-' . ($map[$status] ?? 'secondary') . '">' . ucfirst(str_replace('_',' ',$status)) . '</span>';
};
?>
<style>
.profile-header { background: linear-gradient(135deg, #102A43 0%, #2B6CB0 100%); border-radius: 12px; padding: 2rem; color: #fff; margin-bottom: 1.5rem; }
.profile-avatar { width: 120px; height: 120px; border-radius: 50%; border: 4px solid rgba(255,255,255,0.3); object-fit: cover; background: #334E68; display: flex; align-items: center; justify-content: center; font-size: 3rem; }
.staff-detail-section { margin-bottom: 1.5rem; }
.staff-detail-section .card-header { font-weight: 600; }
.detail-label { color: #6B7A8D; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
.detail-value { font-weight: 500; }
.document-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0; }
.document-item:last-child { border-bottom: none; }
.document-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <a href="<?= e(app_url('/director/staff.php')) ?>" class="btn btn-sm btn-outline-secondary me-2"><i class="fa fa-arrow-left"></i> Back to Staff</a>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#editStaffModal"><i class="fa fa-edit me-1"></i> Edit Profile</button>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal"><i class="fa fa-upload me-1"></i> Upload Document</button>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addCertModal"><i class="fa fa-certificate me-1"></i> Add Certificate</button>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addQualModal"><i class="fa fa-graduation-cap me-1"></i> Add Qualification</button>
  </div>
</div>

<!-- Profile Header -->
<div class="profile-header d-flex align-items-center gap-4 flex-wrap">
  <div class="profile-avatar">
    <?php if ($staff['photo_path'] && file_exists($staff['photo_path'])): ?>
      <img src="<?= e(app_url($staff['photo_path'])) ?>" class="profile-avatar" alt="Photo">
    <?php else: ?>
      <i class="fa fa-user"></i>
    <?php endif; ?>
  </div>
  <div class="flex-grow-1">
    <h2 class="h3 mb-1"><?= e($staff['first_name'] . ' ' . $staff['last_name']) ?></h2>
    <p class="mb-1">
      <span class="badge bg-light text-dark me-2"><i class="fa fa-id-badge me-1"></i><?= e($staff['staff_no']) ?></span>
      <?= $badgeStatus($staff['status']) ?>
      <span class="ms-2 text-white-50"><i class="fa fa-briefcase me-1"></i><?= e($staff['job_title'] ?: 'N/A') ?></span>
    </p>
    <p class="mb-0 text-white-50">
      <i class="fa fa-building me-1"></i><?= e($staff['department_name'] ?? 'No Department') ?>
      &middot; <i class="fa fa-user-tag ms-2 me-1"></i><?= e(str_replace('_', ' ', $staff['role_name'])) ?>
      &middot; <i class="fa fa-clock ms-2 me-1"></i>Hired: <?= e(format_date($staff['date_hired'])) ?>
    </p>
  </div>
  <div class="text-end">
    <div class="text-white-50 small">Contact</div>
    <div class="text-light"><?= e($staff['email'] ?: '-') ?></div>
    <div class="text-light"><?= e($staff['phone'] ?: '-') ?></div>
  </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-3" id="staffTabs">
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'profile' ? 'active' : '' ?>" href="?id=<?= $staffId ?>&tab=profile"><i class="fa fa-user me-1"></i>Profile</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'employment' ? 'active' : '' ?>" href="?id=<?= $staffId ?>&tab=employment"><i class="fa fa-briefcase me-1"></i>Employment</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'documents' ? 'active' : '' ?>" href="?id=<?= $staffId ?>&tab=documents"><i class="fa fa-file me-1"></i>Documents (<?= count($documents) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'certificates' ? 'active' : '' ?>" href="?id=<?= $staffId ?>&tab=certificates"><i class="fa fa-certificate me-1"></i>Certificates (<?= count($certificates) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'qualifications' ? 'active' : '' ?>" href="?id=<?= $staffId ?>&tab=qualifications"><i class="fa fa-graduation-cap me-1"></i>Qualifications (<?= count($qualifications) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $activeTab === 'hr' ? 'active' : '' ?>" href="?id=<?= $staffId ?>&tab=hr"><i class="fa fa-chart-line me-1"></i>HR Records</a></li>
</ul>

<?php if ($activeTab === 'profile'): ?>
<!-- PROFILE TAB -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-user-circle text-gold me-2"></i>Personal Information</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="detail-label" style="width:140px;">Full Name</td><td class="detail-value"><?= e($staff['first_name'] . ' ' . $staff['last_name']) ?></td></tr>
          <tr><td class="detail-label">Gender</td><td class="detail-value"><?= e(ucfirst($staff['gender'] ?? 'Not set')) ?></td></tr>
          <tr><td class="detail-label">Marital Status</td><td class="detail-value"><?= e(ucfirst($staff['marital_status'] ?? 'Not set')) ?></td></tr>
          <tr><td class="detail-label">Religion</td><td class="detail-value"><?= e($staff['religion'] ?: 'Not set') ?></td></tr>
          <tr><td class="detail-label">Education Level</td><td class="detail-value"><?= e($staff['education_level'] ?: 'Not set') ?></td></tr>
          <tr><td class="detail-label">Years of Experience</td><td class="detail-value"><?= (int) $staff['years_of_experience'] ?> years</td></tr>
        </table>
      </div>
    </div>
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-phone text-gold me-2"></i>Contact Information</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="detail-label" style="width:140px;">Email</td><td class="detail-value"><?= e($staff['email'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">Phone</td><td class="detail-value"><?= e($staff['phone'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">Username</td><td class="detail-value"><code><?= e($staff['username']) ?></code></td></tr>
          <tr><td class="detail-label">Account Status</td><td class="detail-value"><?= $staff['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Disabled</span>' ?></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-users text-gold me-2"></i>Next of Kin / Emergency</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="detail-label" style="width:140px;">Next of Kin</td><td class="detail-value"><?= e($staff['next_of_kin_name'] ?: 'Not set') ?></td></tr>
          <tr><td class="detail-label">Relationship</td><td class="detail-value"><?= e($staff['next_of_kin_relationship'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">Kin Phone</td><td class="detail-value"><?= e($staff['next_of_kin_phone'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">Emergency Contact</td><td class="detail-value"><?= e($staff['emergency_contact_name'] ?: 'Not set') ?></td></tr>
          <tr><td class="detail-label">Emergency Phone</td><td class="detail-value"><?= e($staff['emergency_contact_phone'] ?: '-') ?></td></tr>
        </table>
      </div>
    </div>
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-university text-gold me-2"></i>Bank & Tax Info</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="detail-label" style="width:140px;">Bank Name</td><td class="detail-value"><?= e($staff['bank_name'] ?: 'Not set') ?></td></tr>
          <tr><td class="detail-label">Account No</td><td class="detail-value"><?= e($staff['bank_account_no'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">Branch</td><td class="detail-value"><?= e($staff['bank_branch'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">TIN Number</td><td class="detail-value"><?= e($staff['tin_number'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">NSSF Number</td><td class="detail-value"><?= e($staff['nssf_number'] ?: '-') ?></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($activeTab === 'employment'): ?>
<!-- EMPLOYMENT TAB -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-briefcase text-gold me-2"></i>Employment Details</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="detail-label" style="width:150px;">Staff Number</td><td class="detail-value"><code><?= e($staff['staff_no']) ?></code></td></tr>
          <tr><td class="detail-label">Job Title</td><td class="detail-value"><?= e($staff['job_title'] ?: '-') ?></td></tr>
          <tr><td class="detail-label">Department</td><td class="detail-value"><?= e($staff['department_name'] ?? '-') ?></td></tr>
          <tr><td class="detail-label">Employment Type</td><td class="detail-value"><span class="badge bg-info"><?= e(ucfirst(str_replace('_',' ',$staff['employment_type']))) ?></span></td></tr>
          <tr><td class="detail-label">Date Hired</td><td class="detail-value"><?= e(format_date($staff['date_hired'])) ?></td></tr>
          <tr><td class="detail-label">Status</td><td class="detail-value"><?= $badgeStatus($staff['status']) ?></td></tr>
          <tr><td class="detail-label">System Role</td><td class="detail-value"><span class="badge bg-secondary"><?= e(str_replace('_',' ',$staff['role_name'])) ?></span></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-money-bill text-gold me-2"></i>Salary & Compensation</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="detail-label" style="width:150px;">Basic Salary</td><td class="detail-value"><?= format_money($staff['basic_salary']) ?></td></tr>
          <tr><td class="detail-label">National ID</td><td class="detail-value"><?= e($staff['national_id'] ?: '-') ?></td></tr>
        </table>
      </div>
    </div>
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-history text-gold me-2"></i>Timeline</div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="detail-label" style="width:150px;">Created</td><td class="detail-value"><?= e(format_date($staff['created_at'])) ?></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($activeTab === 'documents'): ?>
<!-- DOCUMENTS TAB -->
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><i class="fa fa-file text-gold me-2"></i>Staff Documents</span>
    <button class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#uploadDocModal"><i class="fa fa-upload me-1"></i> Upload New</button>
  </div>
  <div class="card-body p-0">
    <?php if (empty($documents)): ?>
      <div class="text-center text-muted py-4"><i class="fa fa-folder-open fa-2x mb-2"></i><p>No documents uploaded yet.</p></div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Type</th><th>Name</th><th>File</th><th>Size</th><th>Uploaded</th><th>Notes</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($documents as $d): ?>
              <tr>
                <td><span class="badge bg-secondary"><?= e(str_replace('_',' ',ucfirst($d['document_type']))) ?></span></td>
                <td><?= e($d['document_name']) ?></td>
                <td>
                  <?php if (file_exists($d['file_path'])): ?>
                    <a href="?id=<?= $staffId ?>&action=download_doc&doc_id=<?= (int) $d['document_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-download"></i></a>
                  <?php else: ?>
                    <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Missing</span>
                  <?php endif; ?>
                </td>
                <td class="small"><?= $d['file_size'] ? round($d['file_size'] / 1024, 1) . ' KB' : '-' ?></td>
                <td class="small"><?= e(format_date($d['created_at'])) ?></td>
                <td class="small text-muted"><?= e($d['notes'] ?: '-') ?></td>
                <td>
                  <a href="?id=<?= $staffId ?>&action=download_doc&doc_id=<?= (int) $d['document_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download"><i class="fa fa-download"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($activeTab === 'certificates'): ?>
<!-- CERTIFICATES TAB -->
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><i class="fa fa-certificate text-gold me-2"></i>Certificates & Licenses</span>
    <button class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#addCertModal"><i class="fa fa-plus me-1"></i> Add Certificate</button>
  </div>
  <div class="card-body p-0">
    <?php if (empty($certificates)): ?>
      <div class="text-center text-muted py-4"><i class="fa fa-certificate fa-2x mb-2"></i><p>No certificates recorded.</p></div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Certificate</th><th>Issuing Authority</th><th>Number</th><th>Issue Date</th><th>Expiry</th><th>Verified</th><th>File</th></tr></thead>
          <tbody>
            <?php foreach ($certificates as $c): ?>
              <tr>
                <td><?= e($c['certificate_name']) ?></td>
                <td><?= e($c['issuing_authority']) ?></td>
                <td class="small"><code><?= e($c['certificate_number'] ?? '-') ?></code></td>
                <td class="small"><?= e(format_date($c['issue_date'])) ?></td>
                <td class="small"><?= e(format_date($c['expiry_date'])) ?></td>
                <td><?= $c['verified'] ? '<span class="badge bg-success">Verified</span>' : '<span class="badge bg-warning">Pending</span>' ?></td>
                <td>
                  <?php if ($c['file_path'] && file_exists($c['file_path'])): ?>
                    <a href="?id=<?= $staffId ?>&action=download_cert&cert_id=<?= (int) $c['certificate_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-download"></i></a>
                  <?php else: ?>
                    <span class="text-muted">No file</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($activeTab === 'qualifications'): ?>
<!-- QUALIFICATIONS TAB -->
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><i class="fa fa-graduation-cap text-gold me-2"></i>Academic & Professional Qualifications</span>
    <button class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#addQualModal"><i class="fa fa-plus me-1"></i> Add Qualification</button>
  </div>
  <div class="card-body p-0">
    <?php if (empty($qualifications)): ?>
      <div class="text-center text-muted py-4"><i class="fa fa-graduation-cap fa-2x mb-2"></i><p>No qualifications recorded.</p></div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Qualification</th><th>Institution</th><th>Field of Study</th><th>Year</th><th>Grade</th><th>File</th></tr></thead>
          <tbody>
            <?php foreach ($qualifications as $q): ?>
              <tr>
                <td><?= e($q['qualification_name']) ?></td>
                <td><?= e($q['institution']) ?></td>
                <td><?= e($q['field_of_study'] ?? '-') ?></td>
                <td><?= e($q['year_obtained'] ?? '-') ?></td>
                <td><?= e($q['grade'] ?? '-') ?></td>
                <td>
                  <?php if ($q['file_path'] && file_exists($q['file_path'])): ?>
                    <a href="<?= e(app_url($q['file_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa fa-eye"></i></a>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($activeTab === 'hr'): ?>
<!-- HR RECORDS TAB -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-calendar-alt text-gold me-2"></i>Leave Records</div>
      <div class="card-body p-0">
        <?php if (empty($leaveRecords)): ?>
          <div class="text-center text-muted py-3"><p class="mb-0">No leave records.</p></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Type</th><th>Period</th><th>Days</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($leaveRecords as $l): ?>
                  <tr>
                    <td><?= e(ucfirst($l['leave_type'])) ?></td>
                    <td class="small"><?= e(format_date($l['start_date'])) ?> - <?= e(format_date($l['end_date'])) ?></td>
                    <td><?= (int) $l['total_days'] ?></td>
                    <td>
                      <?php $map = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary']; ?>
                      <span class="badge bg-<?= $map[$l['status']] ?? 'secondary' ?>"><?= e(ucfirst($l['status'])) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-chalkboard-teacher text-gold me-2"></i>Training & Development</div>
      <div class="card-body p-0">
        <?php if (empty($trainingRecords)): ?>
          <div class="text-center text-muted py-3"><p class="mb-0">No training records.</p></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Training</th><th>Provider</th><th>Date</th><th>Cert.</th></tr></thead>
              <tbody>
                <?php foreach ($trainingRecords as $t): ?>
                  <tr>
                    <td><?= e($t['training_name']) ?></td>
                    <td class="small"><?= e($t['provider'] ?? '-') ?></td>
                    <td class="small"><?= e(format_date($t['start_date'])) ?></td>
                    <td><?= $t['certificate_obtained'] ? '<i class="fa fa-check text-success"></i>' : '-' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card staff-detail-section">
      <div class="card-header"><i class="fa fa-star text-gold me-2"></i>Performance Reviews</div>
      <div class="card-body p-0">
        <?php if (empty($performanceRecords)): ?>
          <div class="text-center text-muted py-3"><p class="mb-0">No performance reviews yet.</p></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Period</th><th>Date</th><th>Rating</th><th>Score</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($performanceRecords as $p): ?>
                  <tr>
                    <td><?= e($p['review_period']) ?></td>
                    <td class="small"><?= e(format_date($p['review_date'])) ?></td>
                    <td><span class="badge bg-<?= $p['rating'] === 'excellent' ? 'success' : ($p['rating'] === 'good' ? 'info' : ($p['rating'] === 'satisfactory' ? 'primary' : 'warning')) ?>"><?= e(ucfirst(str_replace('_',' ',$p['rating'] ?? 'N/A'))) ?></span></td>
                    <td><?= $p['overall_score'] ? number_format($p['overall_score'], 2) : '-' ?></td>
                    <td><span class="badge bg-secondary"><?= e(ucfirst($p['status'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===================== MODALS ===================== -->

<!-- Edit Staff Modal -->
<div class="modal fade" id="editStaffModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="update_staff_info">
        <input type="hidden" name="staff_id" value="<?= $staffId ?>">
        <div class="modal-header">
          <h5 class="modal-title">Edit Staff Profile</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-tabs mb-3" id="editTabs">
            <li class="nav-item"><a class="nav-link active" href="#editPersonal" data-bs-toggle="tab">Personal</a></li>
            <li class="nav-item"><a class="nav-link" href="#editEmployment" data-bs-toggle="tab">Employment</a></li>
            <li class="nav-item"><a class="nav-link" href="#editContact" data-bs-toggle="tab">Contact</a></li>
            <li class="nav-item"><a class="nav-link" href="#editBank" data-bs-toggle="tab">Bank & Tax</a></li>
            <li class="nav-item"><a class="nav-link" href="#editEmergency" data-bs-toggle="tab">Emergency</a></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="editPersonal">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label">Marital Status</label>
                  <select name="marital_status" class="form-select">
                    <option value="">-- Select --</option>
                    <?php foreach (['single','married','divorced','widowed'] as $opt): ?>
                      <option value="<?= $opt ?>" <?= $staff['marital_status'] === $opt ? 'selected' : '' ?>><?= e(ucfirst($opt)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6"><label class="form-label">Religion</label><input type="text" name="religion" class="form-control" value="<?= e($staff['religion'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Education Level</label>
                  <select name="education_level" class="form-select">
                    <option value="">-- Select --</option>
                    <?php foreach (['Primary','Secondary','Certificate','Diploma','Bachelor\'s Degree','Master\'s Degree','PhD','Other'] as $opt): ?>
                      <option value="<?= $opt ?>" <?= $staff['education_level'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6"><label class="form-label">Years of Experience</label><input type="number" name="years_of_experience" class="form-control" min="0" value="<?= (int) $staff['years_of_experience'] ?>"></div>
                <div class="col-md-6"><label class="form-label">Gender</label>
                  <select name="gender" class="form-select">
                    <option value="">-- Select --</option>
                    <?php foreach (['male','female','other'] as $opt): ?>
                      <option value="<?= $opt ?>" <?= $staff['gender'] === $opt ? 'selected' : '' ?>><?= e(ucfirst($opt)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="tab-pane fade" id="editEmployment">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label">Job Title</label><input type="text" name="job_title" class="form-control" value="<?= e($staff['job_title'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Department</label>
                  <select name="department_id" class="form-select">
                    <option value="">-- None --</option>
                    <?php foreach ($departments as $d): ?>
                      <option value="<?= (int) $d['department_id'] ?>" <?= (int) $staff['department_id'] === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4"><label class="form-label">Employment Type</label>
                  <select name="employment_type" class="form-select">
                    <?php foreach (['full_time','part_time','contract','volunteer'] as $opt): ?>
                      <option value="<?= $opt ?>" <?= $staff['employment_type'] === $opt ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_',' ',$opt))) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4"><label class="form-label">Date Hired</label><input type="date" name="date_hired" class="form-control" value="<?= e($staff['date_hired'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label">Basic Salary (TZS)</label><input type="number" name="basic_salary" class="form-control" min="0" step="1000" value="<?= (float) $staff['basic_salary'] ?>"></div>
              </div>
            </div>
            <div class="tab-pane fade" id="editContact">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($staff['email'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($staff['phone'] ?? '') ?>"></div>
              </div>
            </div>
            <div class="tab-pane fade" id="editBank">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= e($staff['bank_name'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Account No</label><input type="text" name="bank_account_no" class="form-control" value="<?= e($staff['bank_account_no'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Bank Branch</label><input type="text" name="bank_branch" class="form-control" value="<?= e($staff['bank_branch'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">TIN Number</label><input type="text" name="tin_number" class="form-control" value="<?= e($staff['tin_number'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">NSSF Number</label><input type="text" name="nssf_number" class="form-control" value="<?= e($staff['nssf_number'] ?? '') ?>"></div>
              </div>
            </div>
            <div class="tab-pane fade" id="editEmergency">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label">Next of Kin Name</label><input type="text" name="next_of_kin_name" class="form-control" value="<?= e($staff['next_of_kin_name'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Next of Kin Phone</label><input type="text" name="next_of_kin_phone" class="form-control" value="<?= e($staff['next_of_kin_phone'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Relationship</label><input type="text" name="next_of_kin_relationship" class="form-control" value="<?= e($staff['next_of_kin_relationship'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_contact_name" class="form-control" value="<?= e($staff['emergency_contact_name'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Emergency Contact Phone</label><input type="text" name="emergency_contact_phone" class="form-control" value="<?= e($staff['emergency_contact_phone'] ?? '') ?>"></div>
              </div>
            </div>
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

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="upload_document">
        <input type="hidden" name="staff_id" value="<?= $staffId ?>">
        <div class="modal-header">
          <h5 class="modal-title">Upload Document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Document Type</label>
            <select name="document_type" class="form-select">
              <?php foreach (['cv'=>'CV / Resume','certificate'=>'Certificate','degree'=>'Degree','transcript'=>'Transcript','professional_cert'=>'Professional Cert','id_copy'=>'ID Copy','recommendation'=>'Recommendation Letter','appointment_letter'=>'Appointment Letter','contract'=>'Contract','other'=>'Other'] as $val => $label): ?>
                <option value="<?= $val ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Document Name <span class="required-mark">*</span></label><input type="text" name="document_name" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">File (PDF, DOC, JPG, PNG - max 20MB)</label><input type="file" name="document_file" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Certificate Modal -->
<div class="modal fade" id="addCertModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="add_certificate">
        <input type="hidden" name="staff_id" value="<?= $staffId ?>">
        <div class="modal-header">
          <h5 class="modal-title">Add Certificate</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Certificate Name <span class="required-mark">*</span></label><input type="text" name="certificate_name" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Issuing Authority <span class="required-mark">*</span></label><input type="text" name="issuing_authority" class="form-control" required></div>
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Certificate Number</label><input type="text" name="certificate_number" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Issue Date</label><input type="date" name="issue_date" class="form-control"></div>
          </div>
          <div class="mb-2"><label class="form-label">Expiry Date</label><input type="date" name="expiry_date" class="form-control"></div>
          <div class="mb-2"><label class="form-label">Upload File (PDF, JPG, PNG)</label><input type="file" name="cert_file" class="form-control"></div>
          <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Certificate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Qualification Modal -->
<div class="modal fade" id="addQualModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="add_qualification">
        <input type="hidden" name="staff_id" value="<?= $staffId ?>">
        <div class="modal-header">
          <h5 class="modal-title">Add Qualification</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Qualification Name <span class="required-mark">*</span></label><input type="text" name="qualification_name" class="form-control" required placeholder="e.g. Bachelor of Education"></div>
          <div class="mb-2"><label class="form-label">Institution <span class="required-mark">*</span></label><input type="text" name="institution" class="form-control" required></div>
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Field of Study</label><input type="text" name="field_of_study" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Year Obtained</label><input type="number" name="year_obtained" class="form-control" min="1950" max="2099"></div>
            <div class="col-md-3"><label class="form-label">Grade</label><input type="text" name="grade" class="form-control"></div>
          </div>
          <div class="mb-2"><label class="form-label">Upload Document</label><input type="file" name="qual_file" class="form-control"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Qualification</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>