<?php
/**
 * student/fees.php
 * The student's own fee/invoice status (read-only).
 */
require_once __DIR__ . '/../config/config.php';
require_role(['student']);

$pdo = get_db_connection();
$userId = current_user_id();

$studentStmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :uid');
$studentStmt->execute(['uid' => $userId]);
$student = $studentStmt->fetch();

$pageTitle = 'Fee Status';
require APP_ROOT . '/includes/header.php';

if (!$student) {
    echo '<div class="alert alert-warning">Your student profile could not be found.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}
$studentId = (int) $student['student_id'];

$invoices = $pdo->prepare(
    "SELECT i.*, t.term_name FROM invoices i JOIN terms t ON t.term_id = i.term_id
     WHERE i.student_id = :id ORDER BY i.created_at DESC"
);
$invoices->execute(['id' => $studentId]);
$invoiceList = $invoices->fetchAll();
$totalBalance = array_sum(array_column($invoiceList, 'balance'));
?>

<h1 class="h3 mb-4">Fee Status</h1>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="asms-kpi-card <?= $totalBalance > 0 ? 'accent-red' : 'accent-green' ?>"><div class="kpi-label">Total Outstanding</div><div class="kpi-value"><?= format_money($totalBalance) ?></div></div></div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Invoice No.</th><th>Term</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($invoiceList as $inv): ?>
          <tr>
            <td><code><?= e($inv['invoice_no']) ?></code></td>
            <td><?= e($inv['term_name']) ?></td>
            <td><?= format_money($inv['total_amount']) ?></td>
            <td class="text-success"><?= format_money($inv['amount_paid']) ?></td>
            <td class="text-danger"><?= format_money($inv['balance']) ?></td>
            <td><span class="badge badge-status-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($invoiceList)): ?><tr><td colspan="6" class="text-center text-muted py-4">No invoices yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
