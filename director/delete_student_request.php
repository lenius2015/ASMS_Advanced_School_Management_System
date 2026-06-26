<?php
/**
 * director/delete_student_request.php
 * Allows Director and Head of School to request student deletion.
 * Pending requests can be reviewed and approved/rejected by the Director.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school']);

$pdo = get_db_connection();
$studentId = (int) ($_GET['id'] ?? 0);

if ($studentId <= 0) {
    flash_set('error', 'Invalid student ID.');
    redirect(app_url('/director/students.php'));
}

// Get student info
$stmt = $pdo->prepare(
    "SELECT s.*, u.first_name, u.last_name, u.photo_path, cl.level_name, c.stream_name
     FROM students s
     LEFT JOIN users u ON u.user_id = s.user_id
     LEFT JOIN classes c ON c.class_id = s.class_id
     LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE s.student_id = :sid"
);
$stmt->execute(['sid' => $studentId]);
$student = $stmt->fetch();

if (!$student) {
    flash_set('error', 'Student not found.');
    redirect(app_url('/director/students.php'));
}

// Check if there's already a pending request
$checkStmt = $pdo->prepare(
    "SELECT * FROM deletion_requests WHERE student_id = :sid AND status = 'pending'"
);
$checkStmt->execute(['sid' => $studentId]);
$existingRequest = $checkStmt->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_deletion_request') {
    csrf_verify();
    $reason = trim($_POST['reason'] ?? '');

    if ($reason === '') {
        flash_set('error', 'You must provide a reason for the deletion request.');
    } elseif ($existingRequest) {
        flash_set('error', 'A pending deletion request already exists for this student.');
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO deletion_requests (student_id, requested_by, reason, status)
                 VALUES (:sid, :uid, :reason, :status)'
            )->execute([
                'sid'    => $studentId,
                'uid'    => current_user_id(),
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $studentName = $student['first_name'] . ' ' . $student['last_name'];

            // Notify director and system admins about the request
            $admins = $pdo->query(
                "SELECT user_id FROM users u
                 JOIN roles r ON r.role_id = u.role_id
                 WHERE r.role_name IN ('director', 'system_admin') AND u.is_active = 1"
            )->fetchAll();

            foreach ($admins as $admin) {
                notify_user(
                    $pdo,
                    (int) $admin['user_id'],
                    'Student Deletion Request',
                    "A deletion request has been submitted for student {$studentName} ({$student['admission_no']}). Please review.",
                    'system',
                    app_url('/director/deletion_requests.php')
                );
            }

            audit_log('submit_deletion_request', 'student_management', 'students', $studentId,
                "Submitted deletion request for student {$student['admission_no']}: {$reason}");

            flash_set('success', "Deletion request submitted for {$studentName}. It will be reviewed by the Director.");
            redirect(app_url('/director/student_profile.php?id=' . $studentId));
        } catch (Throwable $e) {
            error_log('[ASMS] deletion request failed: ' . $e->getMessage());
            flash_set('error', 'Failed to submit deletion request. Please try again.');
        }
    }
}

$pageTitle = 'Request Student Deletion';
require APP_ROOT . '/includes/header.php';
?>

<div class="container py-4">
  <nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= e(app_url('/director/students.php')) ?>">Students</a></li>
      <li class="breadcrumb-item"><a href="<?= e(app_url('/director/student_profile.php?id=' . $studentId)) ?>"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></a></li>
      <li class="breadcrumb-item active">Request Deletion</li>
    </ol>
  </nav>

  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card">
        <div class="card-header bg-danger text-white">
          <h5 class="mb-0"><i class="fa fa-exclamation-triangle me-2"></i>Request Student Deletion</h5>
        </div>
        <div class="card-body">
          <div class="alert alert-warning">
            <i class="fa fa-info-circle me-1"></i>
            <strong>Important:</strong> This will submit a deletion request for review by the Director.
            The student will only be permanently removed from the system after approval.
            All related records (attendance, marks, discipline, invoices) will also be deleted.
          </div>

          <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-light rounded">
            <?= render_avatar($student['photo_path'] ?? null, $student['first_name'], $student['last_name'], 60) ?>
            <div>
              <h6 class="mb-1"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></h6>
              <div class="small text-muted">
                <code><?= e($student['admission_no']) ?></code> &middot;
                <?= e($student['level_name'] ? $student['level_name'] . ' ' . $student['stream_name'] : 'Unassigned') ?> &middot;
                <span class="badge badge-status-<?= e($student['status']) ?>"><?= e(ucfirst($student['status'])) ?></span>
              </div>
            </div>
          </div>

          <?php if ($existingRequest): ?>
            <div class="alert alert-info">
              <i class="fa fa-clock me-1"></i>
              A pending deletion request already exists for this student (submitted <?= e(date('d M Y, H:i', strtotime($existingRequest['created_at']))) ?>).
              Please wait for the Director to review it.
            </div>
            <a href="<?= e(app_url('/director/student_profile.php?id=' . $studentId)) ?>" class="btn btn-outline-secondary">
              <i class="fa fa-arrow-left me-1"></i> Back to Profile
            </a>
          <?php else: ?>
            <form method="POST">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="submit_deletion_request">

              <div class="mb-3">
                <label class="form-label">Reason for Deletion <span class="required-mark">*</span></label>
                <textarea name="reason" class="form-control" rows="5" required
                  placeholder="Explain why this student should be removed from the system. This will be reviewed by the Director."></textarea>
              </div>

              <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" id="confirmCheck" required>
                <label class="form-check-label" for="confirmCheck">
                  I confirm that I have reviewed this decision and understand that this will permanently remove the student
                  and all associated records upon approval.
                </label>
              </div>

              <div class="d-flex gap-2">
                <a href="<?= e(app_url('/director/student_profile.php?id=' . $studentId)) ?>" class="btn btn-outline-secondary">
                  <i class="fa fa-arrow-left me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-danger">
                  <i class="fa fa-paper-plane me-1"></i> Submit Deletion Request
                </button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>