<?php
/**
 * director/student_profile.php
 * 360-degree view of a single student: bio data, guardians, attendance
 * summary, latest results, fee status, and discipline history.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school', 'academic_officer', 'class_teacher']);

$pdo = get_db_connection();
$studentId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT s.*, u.first_name, u.last_name, u.username, u.email, u.phone, cl.level_name, c.stream_name
     FROM students s
     LEFT JOIN users u ON u.user_id = s.user_id
     LEFT JOIN classes c ON c.class_id = s.class_id
     LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE s.student_id = :id"
);
$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch();

if (!$student) {
    flash_set('error', 'Student not found.');
    redirect(app_url('/director/students.php'));
}

$gStmt = $pdo->prepare(
    "SELECT g.*, sg.is_primary_contact FROM guardians g
     JOIN student_guardians sg ON sg.guardian_id = g.guardian_id
     WHERE sg.student_id = :id"
);
$gStmt->execute(['id' => $studentId]);
$guardianList = $gStmt->fetchAll();

$attStmt = $pdo->prepare(
    "SELECT
        SUM(status='present') AS present_days,
        SUM(status='absent') AS absent_days,
        SUM(status='late') AS late_days,
        COUNT(*) AS total_days
     FROM student_attendance WHERE student_id = :id"
);
$attStmt->execute(['id' => $studentId]);
$attendance = $attStmt->fetch();
$attendanceRate = ($attendance && $attendance['total_days'] > 0)
    ? round(($attendance['present_days'] / $attendance['total_days']) * 100, 1) : null;

$resultsStmt = $pdo->prepare(
    "SELECT rc.*, t.term_name, y.year_name FROM report_cards rc
     JOIN terms t ON t.term_id = rc.term_id
     JOIN academic_years y ON y.year_id = t.year_id
     WHERE rc.student_id = :id ORDER BY t.start_date DESC LIMIT 5"
);
$resultsStmt->execute(['id' => $studentId]);
$reportCards = $resultsStmt->fetchAll();

$invoiceStmt = $pdo->prepare(
    "SELECT i.*, t.term_name FROM invoices i JOIN terms t ON t.term_id = i.term_id
     WHERE i.student_id = :id ORDER BY i.created_at DESC LIMIT 5"
);
$invoiceStmt->execute(['id' => $studentId]);
$invoices = $invoiceStmt->fetchAll();

$disciplineStmt = $pdo->prepare(
    "SELECT d.*, u.first_name AS reporter_fn, u.last_name AS reporter_ln FROM discipline_records d
     LEFT JOIN users u ON u.user_id = d.reported_by
     WHERE d.student_id = :id ORDER BY d.incident_date DESC LIMIT 5"
);
$disciplineStmt->execute(['id' => $studentId]);
$disciplineRecords = $disciplineStmt->fetchAll();

// Check deletion request status
$delReqStmt = $pdo->prepare("SELECT * FROM deletion_requests WHERE student_id = :sid ORDER BY created_at DESC LIMIT 1");
$delReqStmt->execute(['sid' => $studentId]);
$deletionRequest = $delReqStmt->fetch();

// Get medical record and missing docs
$medical = get_student_medical_record($pdo, $studentId);
$regStatus = registration_completeness($pdo, $studentId);
$missingDocs = get_missing_required_documents($pdo, $studentId);

$pageTitle = 'Student Profile';
require APP_ROOT . '/includes/header.php';
?>

<a href="javascript:history.back()" class="small mb-3 d-inline-block"><i class="fa fa-arrow-left me-1"></i> Back</a>

<?php if ($regStatus['level'] !== 'complete' && !empty($missingDocs)): ?>
<div class="alert alert-danger alert-dismissible fade show">
  <div class="d-flex align-items-start gap-2">
    <i class="fa fa-exclamation-triangle fa-2x mt-1"></i>
    <div>
      <h6 class="alert-heading mb-1"><i class="fa fa-times-circle me-1"></i> Incomplete Registration!</h6>
      <p class="mb-1 small">This student is missing the following required documents. Registration will remain incomplete until all are uploaded.</p>
      <ul class="mb-0 small">
        <?php foreach ($missingDocs as $key => $label): ?>
          <li><strong><?= e($label) ?></strong> <a href="#" class="text-white" onclick="document.querySelector('[data-bs-target=\'#uploadDocModal\']')?.click();return false;">(upload now)</a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body d-flex flex-wrap gap-4 align-items-center">
    <div class="bg-navy text-white rounded-circle d-flex align-items-center justify-content-center" style="width:72px;height:72px;font-size:1.6rem;">
      <?= e(mb_substr($student['first_name'],0,1) . mb_substr($student['last_name'],0,1)) ?>
    </div>
    <div>
      <h2 class="h4 mb-1"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></h2>
      <p class="text-muted mb-0">
        <code><?= e($student['admission_no']) ?></code> &middot;
        <?= e($student['level_name'] ? $student['level_name'] . ' ' . $student['stream_name'] : 'Unassigned class') ?> &middot;
        <span class="badge badge-status-<?= e($student['status']) ?>"><?= e(ucfirst($student['status'])) ?></span>
      </p>
    </div>
    <div class="ms-auto text-end">
      <div class="small text-muted">Attendance Rate</div>
      <div class="h4 mb-0 <?= $attendanceRate !== null && $attendanceRate < 80 ? 'text-danger' : 'text-success' ?>">
        <?= $attendanceRate !== null ? e($attendanceRate) . '%' : 'No data' ?>
      </div>
      <div class="mt-2 small">
        <?php $regStatus = registration_completeness($pdo, $studentId); ?>
        <?= $regStatus['badge'] ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-header">Biodata</div>
      <div class="card-body small">
        <p class="mb-1"><strong>Date of Birth:</strong> <?= format_date($student['date_of_birth']) ?></p>
        <p class="mb-1"><strong>Gender:</strong> <?= e(ucfirst($student['gender'] ?? '-')) ?></p>
        <p class="mb-1"><strong>Admission Date:</strong> <?= format_date($student['admission_date']) ?></p>
        <p class="mb-1"><strong>Blood Group:</strong> <?= e($student['blood_group'] ?: '-') ?></p>
        <p class="mb-1"><strong>Address:</strong> <?= e($student['address'] ?: '-') ?></p>
        <p class="mb-0"><strong>Emergency Contact:</strong> <?= e($student['emergency_contact_name'] ?: '-') ?> <?= $student['emergency_contact_phone'] ? '(' . e($student['emergency_contact_phone']) . ')' : '' ?></p>
      </div>
    </div>

    <?php if (in_array(current_role(), ['director', 'system_admin', 'head_of_school'])): ?>
    <div class="card mb-4">
      <div class="card-header bg-danger text-white">Danger Zone</div>
      <div class="card-body text-center">
        <?php if ($deletionRequest && $deletionRequest['status'] === 'pending'): ?>
          <span class="badge bg-warning text-dark mb-2 d-block">
            <i class="fa fa-clock me-1"></i> Deletion Pending Review
          </span>
          <div class="small text-muted">
            Requested: <?= e(date('d M Y, H:i', strtotime($deletionRequest['created_at']))) ?><br>
            Reason: <?= e($deletionRequest['reason']) ?>
          </div>
        <?php elseif ($deletionRequest && $deletionRequest['status'] === 'approved'): ?>
          <span class="badge bg-danger mb-2 d-block">
            <i class="fa fa-check-circle me-1"></i> Deletion Approved
          </span>
        <?php elseif ($deletionRequest && $deletionRequest['status'] === 'rejected'): ?>
          <span class="badge bg-secondary mb-2 d-block">
            <i class="fa fa-times-circle me-1"></i> Deletion Rejected
          </span>
          <?php if ($deletionRequest['reviewer_remarks']): ?>
            <div class="small text-muted">Remarks: <?= e($deletionRequest['reviewer_remarks']) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <p class="small text-muted mb-2">Permanently remove this student from the system.</p>
          <a href="<?= e(app_url('/director/delete_student_request.php?id=' . $studentId)) ?>" class="btn btn-outline-danger btn-sm">
            <i class="fa fa-trash me-1"></i> Request Deletion
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Guardians</div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush small">
          <?php foreach ($guardianList as $g): ?>
            <li class="list-group-item">
              <div class="fw-semibold"><?= e($g['first_name'] . ' ' . $g['last_name']) ?> <?= $g['is_primary_contact'] ? '<span class="badge bg-secondary">Primary</span>' : '' ?></div>
              <div class="text-muted"><?= e(ucfirst($g['relationship'])) ?> &middot; <?= e($g['phone'] ?: 'No phone') ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($guardianList)): ?><li class="list-group-item text-muted">No guardians recorded.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-4">
      <div class="card-header">Recent Report Cards</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Term</th><th>Average</th><th>GPA</th><th>Position</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($reportCards as $rc): ?>
              <tr>
                <td><?= e($rc['year_name'] . ' - ' . $rc['term_name']) ?></td>
                <td><?= $rc['overall_average'] !== null ? e($rc['overall_average']) . '%' : '-' ?></td>
                <td><?= $rc['overall_gpa'] !== null ? e($rc['overall_gpa']) : '-' ?></td>
                <td><?= $rc['overall_position'] ? '#' . e($rc['overall_position']) . ' of ' . e($rc['class_size']) : '-' ?></td>
                <td><span class="badge badge-status-<?= e($rc['status']) ?>"><?= e(ucfirst($rc['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($reportCards)): ?><tr><td colspan="5" class="text-center text-muted py-3">No report cards yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Fee Status (Recent Invoices)</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Invoice</th><th>Term</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($invoices as $inv): ?>
              <tr>
                <td><code><?= e($inv['invoice_no']) ?></code></td>
                <td><?= e($inv['term_name']) ?></td>
                <td><?= format_money($inv['total_amount']) ?></td>
                <td class="text-success"><?= format_money($inv['amount_paid']) ?></td>
                <td class="text-danger"><?= format_money($inv['balance']) ?></td>
                <td><span class="badge badge-status-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($invoices)): ?><tr><td colspan="6" class="text-center text-muted py-3">No invoices yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Discipline History</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Action Taken</th><th>Reported By</th></tr></thead>
          <tbody>
            <?php foreach ($disciplineRecords as $d): ?>
              <tr>
                <td class="small"><?= format_date($d['incident_date']) ?></td>
                <td><span class="badge badge-status-<?= $d['category']==='severe' ? 'overdue' : ($d['category']==='moderate' ? 'pending' : 'active') ?>"><?= e(ucfirst($d['category'])) ?></span></td>
                <td class="small"><?= e($d['description']) ?></td>
                <td class="small"><?= e($d['action_taken'] ?: '-') ?></td>
                <td class="small text-muted"><?= e(trim(($d['reporter_fn'] ?? '') . ' ' . ($d['reporter_ln'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($disciplineRecords)): ?><tr><td colspan="5" class="text-center text-muted py-3">No discipline records. Clean record.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
