<?php
/**
 * profile/view.php
 * Universal profile view page accessible by all roles.
 * Displays user information and provides link to edit profile.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

$pdo = get_db_connection();
$userId = (int) ($_GET['id'] ?? current_user_id());
$currentUserId = current_user_id();
$currentRole = current_role();

// Only allow viewing own profile unless authorized
$isOwnProfile = ($userId === $currentUserId);
$canViewOthers = in_array($currentRole, ['director', 'system_admin', 'head_of_school', 'academic_officer', 'class_teacher', 'subject_teacher']);

if (!$isOwnProfile && !$canViewOthers) {
    flash_set('error', 'You do not have permission to view that profile.');
    redirect(app_url('/profile/view.php'));
}

$user = get_user_profile($pdo, $userId);
if (!$user) {
    flash_set('error', 'User not found.');
    redirect(app_url('/index.php'));
}

// Get student data if user is a student
$student = null;
if ($user['role_name'] === 'student') {
    $stmt = $pdo->prepare(
        "SELECT s.*, cl.level_name, c.stream_name
         FROM students s
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE s.user_id = :uid"
    );
    $stmt->execute(['uid' => $userId]);
    $student = $stmt->fetch();
}

// Get registration completeness for students
$regStatus = null;
if ($student) {
    $regStatus = registration_completeness($pdo, (int) $student['student_id']);
}

$pageTitle = 'My Profile';
require APP_ROOT . '/includes/header.php';
?>

<div class="container py-4">
  <?php if (!$isOwnProfile): ?>
    <a href="javascript:history.back()" class="small mb-3 d-inline-block"><i class="fa fa-arrow-left me-1"></i> Back</a>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Profile Header Card -->
    <div class="col-lg-4">
      <div class="card text-center">
        <div class="card-body">
          <div class="mb-3 d-flex justify-content-center">
            <?= render_avatar($user['photo_path'], $user['first_name'], $user['last_name'], 120, 'border border-3 border-gold') ?>
          </div>
          <h4 class="mb-1"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h4>
          <span class="badge bg-navy mb-2"><?= e(ucfirst(str_replace('_', ' ', $user['role_name']))) ?></span>
          <p class="text-muted small mb-1"><i class="fa fa-user me-1"></i> <?= e($user['username']) ?></p>
          <?php if ($student): ?>
            <p class="text-muted small mb-0"><code><?= e($student['admission_no']) ?></code></p>
            <p class="text-muted small"><?= e($student['level_name'] . ' ' . $student['stream_name'] ?? 'Unassigned') ?></p>
            <div class="mt-2"><?= $regStatus ? $regStatus['badge'] : '' ?></div>
          <?php endif; ?>
        </div>
        <?php if ($isOwnProfile): ?>
          <div class="card-footer bg-transparent">
            <a href="<?= e(app_url('/profile/edit.php')) ?>" class="btn btn-gold w-100">
              <i class="fa fa-edit me-1"></i> Edit Profile
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Profile Details -->
    <div class="col-lg-8">
      <div class="card mb-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="fa fa-info-circle me-2"></i>Personal Information</h5>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="text-muted small text-uppercase">First Name</label>
              <p class="fw-semibold mb-0"><?= e($user['first_name']) ?></p>
            </div>
            <div class="col-md-6">
              <label class="text-muted small text-uppercase">Last Name</label>
              <p class="fw-semibold mb-0"><?= e($user['last_name']) ?></p>
            </div>
            <div class="col-md-6">
              <label class="text-muted small text-uppercase">Email</label>
              <p class="fw-semibold mb-0"><?= e($user['email'] ?? '-') ?></p>
            </div>
            <div class="col-md-6">
              <label class="text-muted small text-uppercase">Phone</label>
              <p class="fw-semibold mb-0"><?= e($user['phone'] ?? '-') ?></p>
            </div>
            <div class="col-md-6">
              <label class="text-muted small text-uppercase">Gender</label>
              <p class="fw-semibold mb-0"><?= e(ucfirst($user['gender'] ?? '-')) ?></p>
            </div>
            <div class="col-md-6">
              <label class="text-muted small text-uppercase">Member Since</label>
              <p class="fw-semibold mb-0"><?= format_date($user['created_at']) ?></p>
            </div>
          </div>
        </div>
      </div>

  <?php if ($student): ?>
        <!-- Missing Documents Warning -->
        <?php if ($regStatus && $regStatus['level'] !== 'complete'): ?>
          <?php $missingDocs = get_missing_required_documents($pdo, (int) $student['student_id']); ?>
          <div class="alert alert-danger mb-4">
            <div class="d-flex align-items-start gap-2">
              <i class="fa fa-exclamation-triangle fa-2x mt-1"></i>
              <div>
                <h6 class="alert-heading mb-1">Incomplete Registration!</h6>
                <p class="mb-1 small">The following required documents are missing. Please upload them to complete the registration.</p>
                <ul class="mb-0 small">
                  <?php foreach ($missingDocs as $key => $label): ?>
                    <li><strong><?= e($label) ?></strong></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Student Details -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="mb-0"><i class="fa fa-graduation-cap me-2"></i>Student Information</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">Admission No.</label>
                <p class="fw-semibold mb-0"><code><?= e($student['admission_no']) ?></code></p>
              </div>
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">Class</label>
                <p class="fw-semibold mb-0"><?= e($student['level_name'] . ' ' . $student['stream_name'] ?? 'Unassigned') ?></p>
              </div>
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">Date of Birth</label>
                <p class="fw-semibold mb-0"><?= format_date($student['date_of_birth']) ?></p>
              </div>
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">Status</label>
                <p class="fw-semibold mb-0"><span class="badge badge-status-<?= e($student['status']) ?>"><?= e(ucfirst($student['status'])) ?></span></p>
              </div>
            </div>
          </div>
        </div>

        <!-- Identity & Legal Documents Section -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="mb-0"><i class="fa fa-id-card me-2"></i>Identity & Legal Documents</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">NIDA Number</label>
                <p class="fw-semibold mb-0"><?= e($student['nida_number'] ?? '<span class="text-muted">Not set</span>') ?></p>
              </div>
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">Passport Number</label>
                <p class="fw-semibold mb-0"><?= e($student['passport_number'] ?? '<span class="text-muted">Not set</span>') ?></p>
              </div>
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">Passport Expiry</label>
                <p class="fw-semibold mb-0"><?= format_date($student['passport_expiry'] ?? null) ?></p>
              </div>
              <div class="col-md-6">
                <label class="text-muted small text-uppercase">Passport Photo</label>
                <p class="fw-semibold mb-0">
                  <?php if (!empty($student['passport_photo_path']) && file_exists(APP_ROOT . '/' . $student['passport_photo_path'])): ?>
                    <a href="<?= e(app_url($student['passport_photo_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                      <i class="fa fa-eye me-1"></i> View Photo
                    </a>
                  <?php else: ?>
                    <span class="text-muted">Not uploaded</span>
                  <?php endif; ?>
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Medical Information -->
        <?php $medical = get_student_medical_record($pdo, (int) $student['student_id']); ?>
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="mb-0"><i class="fa fa-notes-medical me-2"></i>Medical Information</h5>
          </div>
          <div class="card-body">
            <?php if (!empty($medical)): ?>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="text-muted small text-uppercase">Blood Group</label>
                  <p class="fw-semibold mb-0"><?= e($medical['blood_group'] ?: $student['blood_group'] ?: '-') ?></p>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small text-uppercase">Allergies</label>
                  <p class="fw-semibold mb-0"><?= e($medical['allergies'] ?: 'None recorded') ?></p>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small text-uppercase">Chronic Conditions</label>
                  <p class="fw-semibold mb-0"><?= e($medical['chronic_conditions'] ?: 'None recorded') ?></p>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small text-uppercase">Current Medications</label>
                  <p class="fw-semibold mb-0"><?= e($medical['medications'] ?: 'None recorded') ?></p>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small text-uppercase">Doctor</label>
                  <p class="fw-semibold mb-0"><?= e($medical['doctor_name'] ?: '-') ?> <?= $medical['doctor_phone'] ? '(' . e($medical['doctor_phone']) . ')' : '' ?></p>
                </div>
                <div class="col-md-6">
                  <label class="text-muted small text-uppercase">Health Insurance</label>
                  <p class="fw-semibold mb-0"><?= e($medical['health_insurance_provider'] ?: '-') ?> <?= $medical['health_insurance_no'] ? '(' . e($medical['health_insurance_no']) . ')' : '' ?></p>
                </div>
              </div>
            <?php else: ?>
              <p class="text-muted mb-0"><i class="fa fa-info-circle me-1"></i> No medical records recorded yet.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Student Documents -->
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fa fa-file me-2"></i>Documents</h5>
            <?php if ($isOwnProfile || in_array($currentRole, ['director', 'system_admin', 'head_of_school', 'academic_officer'])): ?>
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                <i class="fa fa-upload me-1"></i> Upload
              </button>
            <?php endif; ?>
          </div>
          <div class="card-body p-0">
            <?php $docs = get_student_documents($pdo, (int) $student['student_id']); ?>
            <?php if (empty($docs)): ?>
              <p class="text-muted text-center py-4 mb-0">No documents uploaded yet.</p>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach ($docs as $doc): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                      <span class="badge bg-secondary me-2"><?= e(ucfirst(str_replace('_', ' ', $doc['document_type']))) ?></span>
                      <span class="fw-semibold small"><?= e($doc['document_name']) ?></span>
                      <span class="text-muted small ms-2"><?= $doc['file_size'] ? round($doc['file_size'] / 1024, 1) . ' KB' : '' ?></span>
                      <br>
                      <span class="text-muted small">Uploaded <?= format_date($doc['created_at']) ?>
                        <?php if ($doc['uploader_fn']): ?> by <?= e($doc['uploader_fn'] . ' ' . $doc['uploader_ln']) ?><?php endif; ?>
                      </span>
                    </div>
                    <?php if (file_exists($doc['file_path']) && ($isOwnProfile || in_array($currentRole, ['director', 'system_admin', 'head_of_school', 'academic_officer']))): ?>
                      <a href="<?= e(app_url('/profile/download_doc.php')) ?>?doc_id=<?= (int) $doc['document_id'] ?>" class="btn btn-sm btn-outline-success" title="Download">
                        <i class="fa fa-download"></i>
                      </a>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Upload Document Modal -->
<?php if ($student && ($isOwnProfile || in_array($currentRole, ['director', 'system_admin', 'head_of_school', 'academic_officer']))): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data" action="<?= e(app_url('/profile/upload_doc.php')) ?>">
        <?php csrf_field(); ?>
        <input type="hidden" name="student_id" value="<?= (int) $student['student_id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Upload Document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Document Type <span class="required-mark">*</span></label>
            <select name="document_type" class="form-select" required>
              <option value="birth_certificate">✅ Birth Certificate (Required)</option>
              <option value="medical_checkup">✅ Medical Checkup Form (Required)</option>
              <option value="nida_card">✅ NIDA Card (Required)</option>
              <option value="passport_copy">✅ Passport Copy (Required)</option>
              <option value="vaccination_card">✅ Vaccination Card (Required)</option>
              <option value="transfer_slip">Transfer Slip</option>
              <option value="previous_results">Previous Results</option>
              <option value="passport_photo">Passport Photo</option>
              <option value="guardian_id">Guardian ID</option>
              <option value="parent_consent">Parent Consent Form</option>
              <option value="fee_agreement">Fee Agreement</option>
              <option value="other">Other</option>
            </select>
            <div class="form-text mt-1">✅ Marked documents are required for complete registration.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Document Name <span class="required-mark">*</span></label>
            <input type="text" name="document_name" class="form-control" required placeholder="e.g. Birth Certificate 2026">
          </div>
          <div class="mb-3">
            <label class="form-label">File <span class="required-mark">*</span></label>
            <input type="file" name="document_file" class="form-control" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
            <div class="form-text">Allowed: PDF, DOC, DOCX, JPG, PNG, GIF (max 10MB)</div>
          </div>
          <div class="mb-0">
            <label class="form-label">Notes (optional)</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>