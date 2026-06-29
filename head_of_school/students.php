<?php
/**
 * head_of_school/students.php
 * Student management: list, search/filter, register, edit, and delete students.
 * Generates sequential admission numbers (STU-YYYY-NNNN).
 * Head of School has full CRUD — this is a school management function.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school']);

$pdo = get_db_connection();
$error = '';

// ---- CREATE STUDENT ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_student') {
    csrf_verify();

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $classId   = (int) ($_POST['class_id'] ?? 0);
    $dob       = $_POST['date_of_birth'] ?? null;
    $gender    = $_POST['gender'] ?? null;
    $address   = trim($_POST['address'] ?? '');
    $guardianName  = trim($_POST['guardian_name'] ?? '');
    $guardianPhone = trim($_POST['guardian_phone'] ?? '');
    $guardianRel   = $_POST['guardian_relationship'] ?? 'guardian';

    if ($firstName === '' || $lastName === '' || $classId <= 0) {
        $error = 'First name, last name, and class are required.';
    } else {
        try {
            $pdo->beginTransaction();

            $admissionNo = generate_sequential_id($pdo, 'STU', (int) date('Y'));
            // Use admission number as username for students — it's unique and meaningful
            $username = strtolower($admissionNo);
            $tempPassword = bin2hex(random_bytes(4));
            $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
            $studentRoleId = $pdo->query("SELECT role_id FROM roles WHERE role_name='student'")->fetch()['role_id'];

            $pdo->prepare(
                'INSERT INTO users (uuid, role_id, username, password_hash, first_name, last_name, gender, must_change_password)
                 VALUES (UUID(), :rid, :u, :h, :fn, :ln, :g, 1)'
            )->execute(['rid' => $studentRoleId, 'u' => $username, 'h' => $hash, 'fn' => $firstName, 'ln' => $lastName, 'g' => $gender ?: null]);
            $studentUserId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO students (user_id, admission_no, class_id, date_of_birth, gender, admission_date, address)
                 VALUES (:uid, :adm, :cls, :dob, :g, CURDATE(), :addr)'
            )->execute([
                'uid' => $studentUserId, 'adm' => $admissionNo, 'cls' => $classId,
                'dob' => $dob ?: null, 'g' => $gender ?: null, 'addr' => $address ?: null,
            ]);
            $newStudentId = (int) $pdo->lastInsertId();

            if ($guardianName !== '') {
                [$gFirst, $gLast] = array_pad(explode(' ', $guardianName, 2), 2, '');
                $pdo->prepare(
                    'INSERT INTO guardians (first_name, last_name, relationship, phone) VALUES (:fn, :ln, :rel, :phone)'
                )->execute(['fn' => $gFirst, 'ln' => $gLast ?: $gFirst, 'rel' => $guardianRel, 'phone' => $guardianPhone ?: null]);
                $guardianId = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO student_guardians (student_id, guardian_id, is_primary_contact) VALUES (:sid, :gid, 1)')
                    ->execute(['sid' => $newStudentId, 'gid' => $guardianId]);
            }

            $pdo->commit();

            // Handle document uploads AFTER commit
            $uploadedDocs = 0;
            $targetDir = APP_ROOT . '/uploads/documents/student/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $docTypes = ['birth_certificate', 'medical_checkup', 'nida_card', 'passport_copy', 'vaccination_card'];
            foreach ($docTypes as $docType) {
                $fileKey = 'doc_' . $docType;
                if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES[$fileKey];
                    $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
                    $docError = validate_upload($file, $allowedExt, 10_485_760);
                    if (!$docError) {
                        try {
                            $filePath = store_upload($file, $targetDir, 'doc_' . $newStudentId);
                            $mimeType = mime_content_type($filePath) ?: $file['type'];
                            $docName = ucfirst(str_replace('_', ' ', $docType));
                            $pdo->prepare(
                                'INSERT INTO student_documents (student_id, document_type, document_name, file_path, file_size, mime_type, uploaded_by)
                                 VALUES (:sid, :type, :name, :path, :size, :mime, :uid)'
                            )->execute([
                                'sid' => $newStudentId, 'type' => $docType, 'name' => $docName,
                                'path' => $filePath, 'size' => $file['size'], 'mime' => $mimeType,
                                'uid' => current_user_id(),
                            ]);
                            $uploadedDocs++;
                        } catch (Throwable $docE) {
                            error_log('[ASMS] Student document upload failed: ' . $docE->getMessage());
                        }
                    }
                }
            }
            // Auto-update registration completeness
            if ($uploadedDocs >= 5) {
                try {
                    $pdo->prepare('UPDATE students SET registration_complete = 1 WHERE student_id = :sid')
                        ->execute(['sid' => $newStudentId]);
                } catch (Throwable $updE) {
                    error_log('[ASMS] Student registration_complete update failed: ' . $updE->getMessage());
                }
            }

            audit_log('create_student', 'student_management', 'students', $newStudentId, "Registered student {$admissionNo}");
            flash_set('success', "Student registered with admission number {$admissionNo}. Login username: {$username}, temporary password: {$tempPassword}.");
            redirect(app_url('/head_of_school/students.php'));
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $rb) {}
            $errMsg = $e->getMessage();
            if (str_contains($errMsg, '1062 Duplicate') && str_contains($errMsg, 'admission_no')) {
                $error = 'A system error occurred while generating the admission number. Please try again.';
            } elseif (str_contains($errMsg, '1062 Duplicate')) {
                $error = 'A duplicate record was detected. The username or admission number may already exist.';
            } else {
                $error = 'Failed to register student. Please try again and ensure all fields are correct.';
            }
            error_log('[ASMS] create_student failed: ' . $errMsg);
        }
    }
}

// ---- UPLOAD DOCUMENTS (for existing students) --------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_documents') {
    csrf_verify();
    $studentId = (int) ($_POST['student_id'] ?? 0);

    if ($studentId > 0) {
        $uploadedDocs = 0;
        $targetDir = APP_ROOT . '/uploads/documents/student/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $docTypes = ['birth_certificate', 'medical_checkup', 'nida_card', 'passport_copy', 'vaccination_card'];
        foreach ($docTypes as $docType) {
            $fileKey = 'doc_' . $docType;
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES[$fileKey];
                $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
                $docError = validate_upload($file, $allowedExt, 10_485_760);
                if (!$docError) {
                    try {
                        $filePath = store_upload($file, $targetDir, 'doc_' . $studentId);
                        $mimeType = mime_content_type($filePath) ?: $file['type'];
                        $docName = ucfirst(str_replace('_', ' ', $docType));
                        $pdo->prepare(
                            'INSERT INTO student_documents (student_id, document_type, document_name, file_path, file_size, mime_type, uploaded_by)
                             VALUES (:sid, :type, :name, :path, :size, :mime, :uid)'
                        )->execute([
                            'sid' => $studentId, 'type' => $docType, 'name' => $docName,
                            'path' => $filePath, 'size' => $file['size'], 'mime' => $mimeType,
                            'uid' => current_user_id(),
                        ]);
                        $uploadedDocs++;
                    } catch (Throwable $docE) {
                        error_log('[ASMS] Student document upload failed: ' . $docE->getMessage());
                    }
                }
            }
        }

        // Check if registration is now complete
        if ($uploadedDocs > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM student_documents WHERE student_id = :sid");
            $stmt->execute(['sid' => $studentId]);
            $totalDocs = (int) $stmt->fetchColumn();
            if ($totalDocs >= 5) {
                $pdo->prepare('UPDATE students SET registration_complete = 1 WHERE student_id = :sid')
                    ->execute(['sid' => $studentId]);
            }
            audit_log('upload_documents', 'student_management', 'students', $studentId, "Uploaded {$uploadedDocs} document(s) for student #{$studentId}");
            flash_set('success', "{$uploadedDocs} document(s) uploaded successfully.");
        } else {
            flash_set('info', 'No new documents were selected for upload.');
        }
    }
    redirect(app_url('/head_of_school/students.php'));
}

// ---- UPDATE STUDENT ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_student') {
    csrf_verify();
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $classId = (int) ($_POST['class_id'] ?? 0) ?: null;
    $dob = $_POST['date_of_birth'] ?: null;
    $gender = $_POST['gender'] ?? null;
    $address = trim($_POST['address'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $nida = trim($_POST['nida_number'] ?? '');
    $passportNo = trim($_POST['passport_number'] ?? '');
    $passportExpiry = $_POST['passport_expiry'] ?: null;
    $bloodGroup = trim($_POST['blood_group'] ?? '');
    $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');

    if ($studentId > 0) {
        try {
            $pdo->prepare(
                'UPDATE students SET class_id = :cls, date_of_birth = :dob, gender = :g, address = :addr,
                 status = :status, nida_number = :nida, passport_number = :passport, passport_expiry = :pexp,
                 blood_group = :bg, emergency_contact_name = :ecn, emergency_contact_phone = :ecp
                 WHERE student_id = :sid'
            )->execute([
                'cls' => $classId, 'dob' => $dob, 'g' => $gender, 'addr' => $address ?: null,
                'status' => $status, 'nida' => $nida ?: null, 'passport' => $passportNo ?: null,
                'pexp' => $passportExpiry, 'bg' => $bloodGroup ?: null,
                'ecn' => $emergencyName ?: null, 'ecp' => $emergencyPhone ?: null,
                'sid' => $studentId,
            ]);
            audit_log('update_student', 'student_management', 'students', $studentId, "Updated student #{$studentId}");
            flash_set('success', 'Student information updated successfully.');
        } catch (Throwable $e) {
            error_log('[ASMS] update_student failed: ' . $e->getMessage());
            flash_set('error', 'Failed to update student.');
        }
    }
    redirect(app_url('/head_of_school/students.php'));
}

// ---- DELETE STUDENT ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_student') {
    csrf_verify();
    $studentId = (int) ($_POST['student_id'] ?? 0);
    if ($studentId > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT user_id FROM students WHERE student_id = :sid");
            $stmt->execute(['sid' => $studentId]);
            $userId = (int) ($stmt->fetchColumn() ?: 0);

            $docs = $pdo->prepare("SELECT file_path FROM student_documents WHERE student_id = :sid");
            $docs->execute(['sid' => $studentId]);
            foreach ($docs->fetchAll() as $d) {
                if ($d['file_path'] && file_exists($d['file_path'])) @unlink($d['file_path']);
            }

            $pdo->prepare("DELETE FROM students WHERE student_id = :sid")->execute(['sid' => $studentId]);
            if ($userId > 0) {
                $pdo->prepare("DELETE FROM users WHERE user_id = :uid")->execute(['uid' => $userId]);
            }

            $pdo->commit();
            audit_log('delete_student', 'student_management', 'students', $studentId, "Deleted student #{$studentId}");
            flash_set('success', 'Student has been permanently deleted.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ASMS] delete_student failed: ' . $e->getMessage());
            flash_set('error', 'Failed to delete student. They may have existing records that prevent deletion.');
        }
    }
    redirect(app_url('/head_of_school/students.php'));
}

// ---- FETCH STUDENTS ---------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$classFilter = (int) ($_GET['class_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$regFilter = $_GET['reg'] ?? '';

$sql = "SELECT s.*, u.first_name, u.last_name, u.username, u.photo_path, u.email, u.phone,
               cl.level_name, c.stream_name
        FROM students s
        LEFT JOIN users u ON u.user_id = s.user_id
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
        WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3)';
    $params['s1'] = $params['s2'] = $params['s3'] = "%{$search}%";
}
if ($classFilter > 0) {
    $sql .= ' AND s.class_id = :cls';
    $params['cls'] = $classFilter;
}
if ($statusFilter !== '') {
    $sql .= ' AND s.status = :status';
    $params['status'] = $statusFilter;
}
if ($regFilter === 'incomplete') {
    $sql .= ' AND s.registration_complete = 0';
} elseif ($regFilter === 'complete') {
    $sql .= ' AND s.registration_complete = 1';
}
$sql .= ' ORDER BY s.created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$classesJson = [];
$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$pageTitle = 'Student Management';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-user-graduate text-gold me-2"></i>Student Management</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newStudentModal"><i class="fa fa-user-plus me-1"></i> Register Student</button>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-3"><input type="text" name="q" class="form-control" placeholder="Search name or admission no." value="<?= e($search) ?>"></div>
      <div class="col-md-2">
        <select name="class_id" class="form-select">
          <option value="0">All Classes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['class_id'] ?>" <?= $classFilter === (int) $c['class_id'] ? 'selected' : '' ?>><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['active','graduated','transferred','suspended','expelled','inactive'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="reg" class="form-select">
          <option value="">All Registrations</option>
          <option value="incomplete" <?= $regFilter === 'incomplete' ? 'selected' : '' ?>>Incomplete</option>
          <option value="complete" <?= $regFilter === 'complete' ? 'selected' : '' ?>>Complete</option>
        </select>
      </div>
      <div class="col-md-1"><button class="btn btn-outline-primary w-100"><i class="fa fa-search"></i></button></div>
      <div class="col-md-1"><a href="<?= e(app_url('/head_of_school/students.php')) ?>" class="btn btn-outline-secondary w-100"><i class="fa fa-sync-alt"></i></a></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Admission No.</th>
        <th>Name</th>
        <th>Class</th>
        <th>Gender</th>
        <th>Status</th>
        <th>Reg.</th>
        <th>NIDA</th>
        <th>Passport</th>
        <th>Admitted</th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($students as $s):
          $sid = (int) $s['student_id'];
          $isComplete = (int) ($s['registration_complete'] ?? 0) === 1;
        ?>
          <tr class="<?= !$isComplete ? 'table-warning' : '' ?>">
            <td><code><?= e($s['admission_no']) ?></code></td>
            <td>
              <?= render_avatar($s['photo_path'] ?? null, $s['first_name'] ?? '', $s['last_name'] ?? '', 28, 'me-1') ?>
              <?= e(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?>
            </td>
            <td><?= e($s['level_name'] ? $s['level_name'] . ' ' . $s['stream_name'] : 'Unassigned') ?></td>
            <td><?= e(ucfirst($s['gender'] ?? '-')) ?></td>
            <td><span class="badge badge-status-<?= e($s['status']) ?>"><?= e(ucfirst($s['status'])) ?></span></td>
            <td>
              <?php if ($isComplete): ?>
                <span class="badge bg-success" title="Registration complete"><i class="fa fa-check-circle"></i></span>
              <?php else: ?>
                <span class="badge bg-danger" title="Registration incomplete - missing required documents"><i class="fa fa-exclamation-circle"></i></span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($s['nida_number'] ?: '-') ?></td>
            <td class="small"><?= e($s['passport_number'] ?: '-') ?></td>
            <td class="small text-muted"><?= format_date($s['admission_date']) ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= e(app_url('/director/student_profile.php')) ?>?id=<?= $sid ?>" class="btn btn-outline-primary" title="View Profile"><i class="fa fa-eye"></i></a>
                <button class="btn btn-outline-warning" title="Edit"
                  data-student-id="<?= $sid ?>"
                  data-first-name="<?= e($s['first_name'] ?? '') ?>"
                  data-last-name="<?= e($s['last_name'] ?? '') ?>"
                  data-class-id="<?= (int) ($s['class_id'] ?? 0) ?>"
                  data-dob="<?= e($s['date_of_birth'] ?? '') ?>"
                  data-gender="<?= e($s['gender'] ?? '') ?>"
                  data-status="<?= e($s['status'] ?? 'active') ?>"
                  data-address="<?= e($s['address'] ?? '') ?>"
                  data-nida="<?= e($s['nida_number'] ?? '') ?>"
                  data-passport="<?= e($s['passport_number'] ?? '') ?>"
                  data-passport-expiry="<?= e($s['passport_expiry'] ?? '') ?>"
                  data-blood-group="<?= e($s['blood_group'] ?? '') ?>"
                  data-emergency-name="<?= e($s['emergency_contact_name'] ?? '') ?>"
                  data-emergency-phone="<?= e($s['emergency_contact_phone'] ?? '') ?>"
                  onclick="openEditModal(this)"><i class="fa fa-edit"></i></button>
                <button class="btn btn-outline-info" title="Upload Documents"
                  data-student-id="<?= $sid ?>"
                  data-first-name="<?= e($s['first_name'] ?? '') ?>"
                  data-last-name="<?= e($s['last_name'] ?? '') ?>"
                  onclick="openUploadModal(this)"><i class="fa fa-upload"></i></button>
                <button class="btn btn-outline-danger" onclick="confirmDeleteStudent(<?= $sid ?>, '<?= e(addslashes($s['first_name'] . ' ' . $s['last_name'])) ?>')" title="Delete"><i class="fa fa-trash"></i></button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="10" class="text-center text-muted py-4">No students found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="delete_student">
        <input type="hidden" name="student_id" id="deleteStudentId" value="0">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><i class="fa fa-exclamation-triangle me-1"></i> Delete Student</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2"><strong>Are you sure you want to permanently delete this student?</strong></p>
          <p class="text-danger small mb-0"><i class="fa fa-info-circle"></i> This will also delete all documents, attendance records, results, invoices, and the user account. This cannot be undone.</p>
          <p class="mt-2 mb-0">Student: <strong id="deleteStudentName"></strong></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="fa fa-trash me-1"></i> Permanently Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- New Student Modal -->
<div class="modal fade" id="newStudentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_student">
        <div class="modal-header">
          <h5 class="modal-title">Register New Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
          <h6 class="text-muted small text-uppercase">Student Details</h6>
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">First Name <span class="required-mark">*</span></label><input type="text" name="first_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Last Name <span class="required-mark">*</span></label><input type="text" name="last_name" class="form-control" required></div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-4"><label class="form-label">Class <span class="required-mark">*</span></label>
              <select name="class_id" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($classes as $c): ?>
                  <option value="<?= (int) $c['class_id'] ?>"><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Gender</label>
              <select name="gender" class="form-select"><option value="">--</option><option value="male">Male</option><option value="female">Female</option></select>
            </div>
          </div>
          <div class="mb-3"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>

          <h6 class="text-muted small text-uppercase">Primary Guardian (optional, can be added later)</h6>
          <div class="row g-2 mb-3">
            <div class="col-md-5"><label class="form-label">Full Name</label><input type="text" name="guardian_name" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="guardian_phone" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Relationship</label>
              <select name="guardian_relationship" class="form-select">
                <option value="father">Father</option><option value="mother">Mother</option><option value="guardian">Guardian</option><option value="other">Other</option>
              </select>
            </div>
          </div>

          <!-- Documents Section -->
          <h6 class="text-muted small text-uppercase border-top pt-3">Required Documents (can be uploaded later)</h6>
          <div class="text-muted small mb-2">
            <i class="fa fa-info-circle me-1"></i>Upload all 5 required documents to mark registration as <strong>Complete</strong>.
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-file text-warning me-1"></i> Birth Certificate</label>
              <input type="file" name="doc_birth_certificate" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-notes-medical text-danger me-1"></i> Medical Checkup Form</label>
              <input type="file" name="doc_medical_checkup" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-id-card text-primary me-1"></i> NIDA Card</label>
              <input type="file" name="doc_nida_card" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-passport text-info me-1"></i> Passport Copy</label>
              <input type="file" name="doc_passport_copy" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-syringe text-success me-1"></i> Vaccination Card</label>
              <input type="file" name="doc_vaccination_card" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
            </div>
          </div>

          <p class="text-muted small mt-3 mb-0">An admission number and student login will be generated automatically.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Register Student</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Single Shared Document Upload Modal (populated dynamically via JS) -->
<div class="modal fade" id="uploadDocModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data" id="uploadDocForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="upload_documents">
        <input type="hidden" name="student_id" id="uploadStudentId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="uploadModalTitle"><i class="fa fa-upload me-2"></i>Upload Documents</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            <i class="fa fa-info-circle me-1"></i>Upload missing documents for this student. Upload all 5 required documents to mark registration as <strong>Complete</strong>.
          </p>

          <!-- Existing Documents Preview -->
          <div id="existingDocsContainer" class="mb-3 d-none">
            <h6 class="text-muted small text-uppercase border-bottom pb-2">Already Uploaded Documents</h6>
            <div id="existingDocsList" class="row g-2"></div>
          </div>

          <div id="uploadFileError" class="alert alert-danger small d-none"><i class="fa fa-exclamation-circle me-1"></i><span id="uploadFileErrorMsg"></span></div>

          <h6 class="text-muted small text-uppercase">Upload New Documents</h6>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-file text-warning me-1"></i> Birth Certificate</label>
              <input type="file" name="doc_birth_certificate" class="form-control form-control-sm doc-upload-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" data-doc-name="Birth Certificate">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-notes-medical text-danger me-1"></i> Medical Checkup Form</label>
              <input type="file" name="doc_medical_checkup" class="form-control form-control-sm doc-upload-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" data-doc-name="Medical Checkup Form">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-id-card text-primary me-1"></i> NIDA Card</label>
              <input type="file" name="doc_nida_card" class="form-control form-control-sm doc-upload-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" data-doc-name="NIDA Card">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-passport text-info me-1"></i> Passport Copy</label>
              <input type="file" name="doc_passport_copy" class="form-control form-control-sm doc-upload-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" data-doc-name="Passport Copy">
            </div>
            <div class="col-md-6">
              <label class="form-label"><i class="fa fa-syringe text-success me-1"></i> Vaccination Card</label>
              <input type="file" name="doc_vaccination_card" class="form-control form-control-sm doc-upload-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif" data-doc-name="Vaccination Card">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="uploadDocSubmitBtn"><i class="fa fa-upload me-1"></i> Upload Documents</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Single Shared Edit Student Modal (populated dynamically via JS) -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="update_student">
        <input type="hidden" name="student_id" id="editStudentId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="editModalTitle"><i class="fa fa-edit me-2"></i>Edit Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-tabs mb-3" id="editTabs">
            <li class="nav-item"><a class="nav-link active" href="#editBasic" data-bs-toggle="tab">Basic Info</a></li>
            <li class="nav-item"><a class="nav-link" href="#editIdentity" data-bs-toggle="tab">Identity & Legal</a></li>
            <li class="nav-item"><a class="nav-link" href="#editMedical" data-bs-toggle="tab">Medical</a></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="editBasic">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Class</label>
                  <select name="class_id" id="editClassId" class="form-select">
                    <option value="">-- Unassigned --</option>
                    <?php foreach ($classes as $c): ?>
                      <option value="<?= (int) $c['class_id'] ?>"><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Date of Birth</label>
                  <input type="date" name="date_of_birth" id="editDob" class="form-control">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Gender</label>
                  <select name="gender" id="editGender" class="form-select">
                    <option value="">--</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Status</label>
                  <select name="status" id="editStatus" class="form-select">
                    <?php foreach (['active','graduated','transferred','suspended','expelled','inactive'] as $st): ?>
                      <option value="<?= $st ?>"><?= e(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Address</label>
                  <textarea name="address" id="editAddress" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Emergency Contact Name</label>
                  <input type="text" name="emergency_contact_name" id="editEmergencyName" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Emergency Contact Phone</label>
                  <input type="text" name="emergency_contact_phone" id="editEmergencyPhone" class="form-control">
                </div>
              </div>
            </div>
            <div class="tab-pane fade" id="editIdentity">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">NIDA Number</label>
                  <input type="text" name="nida_number" id="editNida" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Passport Number</label>
                  <input type="text" name="passport_number" id="editPassport" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Passport Expiry Date</label>
                  <input type="date" name="passport_expiry" id="editPassportExpiry" class="form-control">
                </div>
              </div>
            </div>
            <div class="tab-pane fade" id="editMedical">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Blood Group</label>
                  <input type="text" name="blood_group" id="editBloodGroup" class="form-control" placeholder="e.g. A+, O-">
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Allowed file extensions for document upload
var ALLOWED_DOC_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
var ALLOWED_DOC_EXTENSIONS_MSG = 'PDF, DOC, DOCX, JPG, JPEG, PNG, GIF';

function confirmDeleteStudent(studentId, studentName) {
  document.getElementById('deleteStudentId').value = studentId;
  document.getElementById('deleteStudentName').textContent = studentName;
  new bootstrap.Modal(document.getElementById('deleteStudentModal')).show();
}

// Validate file input on change
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('doc-upload-input')) {
    var file = e.target.files[0];
    var errorDiv = document.getElementById('uploadFileError');
    var errorMsg = document.getElementById('uploadFileErrorMsg');
    var submitBtn = document.getElementById('uploadDocSubmitBtn');

    if (file) {
      var ext = file.name.split('.').pop().toLowerCase();
      if (ALLOWED_DOC_EXTENSIONS.indexOf(ext) === -1) {
        errorDiv.classList.remove('d-none');
        errorMsg.textContent = 'Unsupported file type "' + ext.toUpperCase() + '". Allowed: ' + ALLOWED_DOC_EXTENSIONS_MSG + '. Please select a valid file.';
        e.target.value = '';
        submitBtn.disabled = true;
        return;
      }
      // Check file size (10MB max)
      if (file.size > 10 * 1024 * 1024) {
        errorDiv.classList.remove('d-none');
        errorMsg.textContent = 'File "' + file.name + '" exceeds the 10MB size limit. Please choose a smaller file.';
        e.target.value = '';
        submitBtn.disabled = true;
        return;
      }
    }
    errorDiv.classList.add('d-none');
    submitBtn.disabled = false;
  }
});

function openUploadModal(btn) {
  var data = btn.dataset;
  var studentId = data.studentId;
  document.getElementById('uploadStudentId').value = studentId;
  document.getElementById('uploadModalTitle').innerHTML = '<i class="fa fa-upload me-2"></i>Upload Documents: ' + data.firstName + ' ' + data.lastName;
  document.getElementById('uploadFileError').classList.add('d-none');
  document.getElementById('uploadDocSubmitBtn').disabled = false;

  // Fetch existing documents via AJAX
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '<?= e(app_url('/api/students/documents.php')) ?>?student_id=' + studentId, true);
  xhr.onload = function() {
    var container = document.getElementById('existingDocsContainer');
    var list = document.getElementById('existingDocsList');
    if (xhr.status === 200) {
      try {
        var docs = JSON.parse(xhr.responseText);
        if (docs.length > 0) {
          list.innerHTML = '';
          docs.forEach(function(doc) {
            var icon = 'fa-file';
            var color = 'text-secondary';
            var ext = doc.file_path.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif'].indexOf(ext) !== -1) {
              icon = 'fa-file-image';
              color = 'text-success';
            } else if (ext === 'pdf') {
              icon = 'fa-file-pdf';
              color = 'text-danger';
            } else if (['doc', 'docx'].indexOf(ext) !== -1) {
              icon = 'fa-file-word';
              color = 'text-primary';
            }
            var previewUrl = '<?= e(app_url('/')) ?>' + doc.file_path;
            list.innerHTML += '<div class="col-md-6 mb-1">' +
              '<a href="' + previewUrl + '" target="_blank" class="text-decoration-none small">' +
              '<i class="fa ' + icon + ' ' + color + ' me-1"></i> ' + doc.document_name +
              '</a> <span class="text-muted small">(' + doc.file_size_formatted + ')</span>' +
              '</div>';
          });
          container.classList.remove('d-none');
        } else {
          container.classList.add('d-none');
        }
      } catch (e) {
        container.classList.add('d-none');
      }
    } else {
      container.classList.add('d-none');
    }
  };
  xhr.onerror = function() {
    document.getElementById('existingDocsContainer').classList.add('d-none');
  };
  xhr.send();

  new bootstrap.Modal(document.getElementById('uploadDocModal')).show();
}

function openEditModal(btn) {
  var data = btn.dataset;
  document.getElementById('editStudentId').value = data.studentId;
  document.getElementById('editModalTitle').innerHTML = '<i class="fa fa-edit me-2"></i>Edit Student: ' + data.firstName + ' ' + data.lastName;
  document.getElementById('editClassId').value = data.classId;
  document.getElementById('editDob').value = data.dob;
  document.getElementById('editGender').value = data.gender;
  document.getElementById('editStatus').value = data.status;
  document.getElementById('editAddress').value = data.address;
  document.getElementById('editNida').value = data.nida;
  document.getElementById('editPassport').value = data.passport;
  document.getElementById('editPassportExpiry').value = data.passportExpiry;
  document.getElementById('editBloodGroup').value = data.bloodGroup;
  document.getElementById('editEmergencyName').value = data.emergencyName;
  document.getElementById('editEmergencyPhone').value = data.emergencyPhone;

  // Reset to first tab
  var tabEl = document.querySelector('#editTabs .nav-link');
  if (tabEl) {
    var tab = new bootstrap.Tab(tabEl);
    tab.show();
  }

  new bootstrap.Modal(document.getElementById('editStudentModal')).show();
}
</script>

<?php if ($error): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var modal = new bootstrap.Modal(document.getElementById('newStudentModal'));
  modal.show();
});
</script>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>