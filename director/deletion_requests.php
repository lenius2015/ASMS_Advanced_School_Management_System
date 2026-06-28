<?php
/**
 * director/deletion_requests.php
 * View and manage pending student deletion requests.
 * Director and System Admin can approve or reject requests.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school']);

$pdo = get_db_connection();

// Handle approve/reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Only Director and System Admin can approve/reject requests
    $currentRole = current_role();
    if (!in_array($currentRole, ['director', 'system_admin'], true)) {
        flash_set('error', 'You do not have permission to approve or reject deletion requests.');
        redirect(app_url('/director/deletion_requests.php'));
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'approve' or 'reject'
    $reviewerRemarks = trim($_POST['reviewer_remarks'] ?? '');

    if ($requestId <= 0 || !in_array($action, ['approve', 'reject'])) {
        flash_set('error', 'Invalid request.');
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT dr.*, s.admission_no, u2.first_name AS student_fn, u2.last_name AS student_ln,
                        u3.first_name AS requester_fn, u3.last_name AS requester_ln
                 FROM deletion_requests dr
                 JOIN students s ON s.student_id = dr.student_id
                 JOIN users u2 ON u2.user_id = s.user_id
                 JOIN users u3 ON u3.user_id = dr.requested_by
                 WHERE dr.request_id = :rid"
            );
            $stmt->execute(['rid' => $requestId]);
            $req = $stmt->fetch();

            if (!$req) {
                throw new Exception('Request not found.');
            }

            if ($action === 'approve') {
                // Delete the student (cascading deletes will clean related tables)
                $pdo->prepare('DELETE FROM students WHERE student_id = :sid')
                    ->execute(['sid' => (int) $req['student_id']]);

                // Mark the request as approved
                $pdo->prepare(
                    'UPDATE deletion_requests SET status = :status, reviewed_by = :uid, reviewed_at = NOW(), reviewer_remarks = :remarks
                     WHERE request_id = :rid'
                )->execute([
                    'status' => 'approved', 'uid' => current_user_id(),
                    'remarks' => $reviewerRemarks ?: null, 'rid' => $requestId,
                ]);

                $studentName = $req['student_fn'] . ' ' . $req['student_ln'];

                // Notify the requester
                notify_user(
                    $pdo, (int) $req['requested_by'],
                    'Deletion Request Approved',
                    "The deletion request for student {$studentName} ({$req['admission_no']}) has been approved and processed.",
                    'system'
                );

                audit_log('approve_deletion', 'student_management', 'deletion_requests', $requestId,
                    "Approved deletion of student {$studentName} ({$req['admission_no']})");
                flash_set('success', "Student {$studentName} has been permanently deleted.");
            } else {
                // Mark as rejected
                $pdo->prepare(
                    'UPDATE deletion_requests SET status = :status, reviewed_by = :uid, reviewed_at = NOW(), reviewer_remarks = :remarks
                     WHERE request_id = :rid'
                )->execute([
                    'status' => 'rejected', 'uid' => current_user_id(),
                    'remarks' => $reviewerRemarks ?: null, 'rid' => $requestId,
                ]);

                $studentName = $req['student_fn'] . ' ' . $req['student_ln'];

                // Notify the requester
                notify_user(
                    $pdo, (int) $req['requested_by'],
                    'Deletion Request Rejected',
                    "The deletion request for student {$studentName} ({$req['admission_no']}) has been rejected." .
                        ($reviewerRemarks ? " Reason: {$reviewerRemarks}" : ''),
                    'system'
                );

                audit_log('reject_deletion', 'student_management', 'deletion_requests', $requestId,
                    "Rejected deletion of student {$studentName} ({$req['admission_no']})");
                flash_set('success', "Deletion request for {$studentName} has been rejected.");
            }
        } catch (Throwable $e) {
            error_log('[ASMS] deletion request processing failed: ' . $e->getMessage());
            flash_set('error', 'Failed to process request. ' . $e->getMessage());
        }
    }
    redirect(app_url('/director/deletion_requests.php'));
}

// Fetch all deletion requests
$requests = $pdo->query(
    "SELECT dr.*,
            s.admission_no, s.status AS student_status,
            u2.first_name AS student_fn, u2.last_name AS student_ln, u2.photo_path,
            u3.first_name AS requester_fn, u3.last_name AS requester_ln, u3.username AS requester_username,
            u4.first_name AS reviewer_fn, u4.last_name AS reviewer_ln
     FROM deletion_requests dr
     JOIN students s ON s.student_id = dr.student_id
     JOIN users u2 ON u2.user_id = s.user_id
     JOIN users u3 ON u3.user_id = dr.requested_by
     LEFT JOIN users u4 ON u4.user_id = dr.reviewed_by
     ORDER BY dr.created_at DESC"
)->fetchAll();

$pageTitle = 'Deletion Requests';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-trash-alt text-danger me-2"></i>Student Deletion Requests</h1>
</div>

<?php if (empty($requests)): ?>
  <div class="card">
    <div class="card-body text-center py-5">
      <i class="fa fa-inbox fa-4x text-muted mb-3"></i>
      <h5 class="text-muted">No deletion requests</h5>
      <p class="text-muted small">There are no student deletion requests to review.</p>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Student</th>
            <th>Admission No.</th>
            <th>Requested By</th>
            <th>Reason</th>
            <th>Date</th>
            <th>Status</th>
            <th>Reviewer</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r): ?>
            <tr class="<?= $r['status'] === 'pending' ? 'table-warning' : '' ?>">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?= render_avatar($r['photo_path'] ?? null, $r['student_fn'], $r['student_ln'], 32) ?>
                  <span><?= e($r['student_fn'] . ' ' . $r['student_ln']) ?></span>
                </div>
              </td>
              <td><code><?= e($r['admission_no']) ?></code></td>
              <td class="small"><?= e($r['requester_fn'] . ' ' . $r['requester_ln']) ?></td>
              <td style="max-width:250px;">
                <div class="text-truncate small" title="<?= e($r['reason']) ?>"><?= e($r['reason']) ?></div>
              </td>
              <td class="small text-muted"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></td>
              <td>
                <?php
                  $statusBadge = match ($r['status']) {
                    'pending' => 'bg-warning text-dark',
                    'approved' => 'bg-danger',
                    'rejected' => 'bg-secondary',
                  };
                ?>
                <span class="badge <?= $statusBadge ?>"><?= e(ucfirst($r['status'])) ?></span>
              </td>
              <td class="small text-muted">
                <?= $r['reviewer_fn'] ? e($r['reviewer_fn'] . ' ' . $r['reviewer_ln']) : '-' ?>
                <?php if ($r['reviewed_at']): ?>
                  <br><span class="smaller"><?= e(date('d M Y', strtotime($r['reviewed_at']))) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['status'] === 'pending'): ?>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                    data-bs-target="#reviewModal<?= (int) $r['request_id'] ?>">
                    <i class="fa fa-gavel me-1"></i> Review
                  </button>
                <?php elseif ($r['reviewer_remarks']): ?>
                  <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal"
                    data-bs-target="#remarksModal<?= (int) $r['request_id'] ?>">
                    <i class="fa fa-comment"></i>
                  </button>
                <?php endif; ?>
              </td>
            </tr>

            <!-- Review Modal -->
            <?php if ($r['status'] === 'pending'): ?>
            <div class="modal fade" id="reviewModal<?= (int) $r['request_id'] ?>" tabindex="-1">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Review Deletion Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <strong>Student:</strong> <?= e($r['student_fn'] . ' ' . $r['student_ln']) ?> (<code><?= e($r['admission_no']) ?></code>)
                    </div>
                    <div class="mb-3">
                      <strong>Requested by:</strong> <?= e($r['requester_fn'] . ' ' . $r['requester_ln']) ?>
                    </div>
                    <div class="mb-3">
                      <strong>Reason:</strong>
                      <p class="text-muted small mb-0 p-2 bg-light rounded"><?= e($r['reason']) ?></p>
                    </div>
                    <div class="alert alert-danger small">
                      <i class="fa fa-exclamation-triangle me-1"></i>
                      <strong>Warning:</strong> Approving will permanently delete this student and ALL related records (attendance, marks, fees, discipline). This action cannot be undone.
                    </div>



                    <?php if (in_array(current_role(), ['director', 'system_admin'], true)): ?>
                    <div class="d-flex gap-2">
                      <form method="POST" class="flex-grow-1" onsubmit="return confirm('Are you sure you want to permanently delete this student?')">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                        <div class="mb-2">
                          <label class="form-label small">Remarks (optional)</label>
                          <textarea name="reviewer_remarks" class="form-control form-control-sm" rows="2" placeholder="Optional remarks about this decision"></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger w-100">
                          <i class="fa fa-check me-1"></i> Approve & Delete Permanently
                        </button>
                      </form>

                      <form method="POST" class="flex-grow-1">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                        <div class="mb-2">
                          <label class="form-label small">Remarks (optional)</label>
                          <textarea name="reviewer_remarks" class="form-control form-control-sm" rows="2" placeholder="Why is this being rejected?"></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary w-100">
                          <i class="fa fa-times me-1"></i> Reject
                        </button>
                      </form>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info small mb-0">
                      <i class="fa fa-info-circle me-1"></i>
                      This request is pending review by the Director or System Admin. You do not have permission to approve or reject requests.
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Remarks Modal -->
            <?php if ($r['reviewer_remarks']): ?>
            <div class="modal fade" id="remarksModal<?= (int) $r['request_id'] ?>" tabindex="-1">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Reviewer Remarks</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p class="mb-1"><strong>Reviewer:</strong> <?= e($r['reviewer_fn'] . ' ' . $r['reviewer_ln']) ?></p>
                    <p class="mb-3"><strong>Decision:</strong>
                      <span class="badge <?= $r['status'] === 'approved' ? 'bg-danger' : 'bg-secondary' ?>">
                        <?= e(ucfirst($r['status'])) ?>
                      </span>
                    </p>
                    <p class="bg-light rounded p-3 mb-0"><?= e($r['reviewer_remarks']) ?></p>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
