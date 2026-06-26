<?php
/**
 * director/students.php
 * Student management: list, search/filter, and register new students.
 * Generates sequential admission numbers (STU-YYYY-NNNN).
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school', 'academic_officer']);

$pdo = get_db_connection();
$error = '';

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
            $username = generate_username($pdo, $firstName, $lastName);
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
            audit_log('create_student', 'student_management', 'students', $newStudentId, "Registered student {$admissionNo}");
            flash_set('success', "Student registered with admission number {$admissionNo}. Login username: {$username}, temporary password: {$tempPassword}.");
            redirect(app_url('/director/students.php'));
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ASMS] create_student failed: ' . $e->getMessage());
            $error = 'Failed to register student. Please try again.';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$classFilter = (int) ($_GET['class_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT s.*, u.first_name, u.last_name, u.username, u.photo_path, cl.level_name, c.stream_name
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
$sql .= ' ORDER BY s.created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$pageTitle = 'Student Management';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">Student Management</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newStudentModal"><i class="fa fa-user-plus me-1"></i> Register Student</button>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Search name or admission no." value="<?= e($search) ?>"></div>
      <div class="col-md-3">
        <select name="class_id" class="form-select">
          <option value="0">All Classes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['class_id'] ?>" <?= $classFilter === (int) $c['class_id'] ? 'selected' : '' ?>><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['active','graduated','transferred','suspended','expelled','inactive'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="fa fa-search"></i> Filter</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Admission No.</th><th>Name</th><th>Class</th><th>Gender</th><th>Status</th><th>Admitted</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td><code><?= e($s['admission_no']) ?></code></td>
            <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <td><?= e($s['level_name'] ? $s['level_name'] . ' ' . $s['stream_name'] : 'Unassigned') ?></td>
            <td><?= e(ucfirst($s['gender'] ?? '-')) ?></td>
            <td><span class="badge badge-status-<?= e($s['status']) ?>"><?= e(ucfirst($s['status'])) ?></span></td>
            <td class="small text-muted"><?= format_date($s['admission_date']) ?></td>
            <td><a href="<?= e(app_url('/director/student_profile.php')) ?>?id=<?= (int) $s['student_id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="7" class="text-center text-muted py-4">No students found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

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
          <h6 class="text-muted small text-uppercase border-top pt-3">Supporting Documents (optional, can be added later)</h6>
          <div class="text-muted small mb-2">
            <i class="fa fa-info-circle me-1"></i>Uploading birth certificate and medical checkup will mark registration as <strong>Complete</strong>.
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Birth Certificate</label>
              <input type="file" name="doc_birth_certificate" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
              <div class="form-text">PDF, DOC, JPG, PNG (max 10MB)</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Medical Checkup Form</label>
              <input type="file" name="doc_medical_checkup" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
              <div class="form-text">PDF, DOC, JPG, PNG (max 10MB)</div>
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

<?php require APP_ROOT . '/includes/footer.php'; ?>
