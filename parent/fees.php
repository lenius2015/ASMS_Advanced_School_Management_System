<?php
/**
 * parent/fees.php
 * Fee status, invoices, and payment history for the selected child.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['parent']);

$pdo = get_db_connection();
$userId = current_user_id();
$studentId = (int) ($_GET['student_id'] ?? 0) ?: get_default_child($pdo, $userId);
$verifiedStudentId = $studentId ? verify_guardian_owns_student($pdo, $userId, $studentId) : null;

$pageTitle = 'Fee Status';
require APP_ROOT . '/includes/header.php';

if (!$verifiedStudentId) {
    echo '<div class="alert alert-warning">No child found or you do not have access to view this record.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$studentInfo = $pdo->prepare("SELECT u.first_name, u.last_name FROM students s JOIN users u ON u.user_id = s.user_id WHERE s.student_id = :id");
$studentInfo->execute(['id' => $verifiedStudentId]);
$student = $studentInfo->fetch();

$invoices = $pdo->prepare(
    "SELECT i.*, t.term_name FROM invoices i JOIN terms t ON t.term_id = i.term_id
     WHERE i.student_id = :id ORDER BY i.created_at DESC"
);
$invoices->execute(['id' => $verifiedStudentId]);
$invoiceList = $invoices->fetchAll();

$totalBalance = array_sum(array_column($invoiceList, 'balance'));
?>

<h1 class="h3 mb-1">Fee Status</h1>
<p class="text-muted mb-4"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></p>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="asms-kpi-card <?= $totalBalance > 0 ? 'accent-red' : 'accent-green' ?>"><div class="kpi-label">Total Outstanding</div><div class="kpi-value"><?= format_money($totalBalance) ?></div></div></div>
</div>

<div class="card">
  <div class="card-header">Invoices</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Invoice No.</th><th>Term</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due Date</th></tr></thead>
      <tbody>
        <?php foreach ($invoiceList as $inv): ?>
          <tr>
            <td><code><?= e($inv['invoice_no']) ?></code></td>
            <td><?= e($inv['term_name']) ?></td>
            <td><?= format_money($inv['total_amount']) ?></td>
            <td class="text-success"><?= format_money($inv['amount_paid']) ?></td>
            <td class="text-danger"><?= format_money($inv['balance']) ?></td>
            <td><span class="badge badge-status-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
            <td class="small"><?= format_date($inv['due_date']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($invoiceList)): ?><tr><td colspan="7" class="text-center text-muted py-4">No invoices yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="text-muted small mt-3">For payment options or to dispute a charge, please contact the school's finance office (Bursar).</p>

<?php require APP_ROOT . '/includes/footer.php'; ?>
