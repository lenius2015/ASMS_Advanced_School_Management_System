<?php
/**
 * student/fees.php
 * Enhanced Student Fee Portal - View fees, invoices, control numbers,
 * payment history, and download/print statements.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['student']);
require_once __DIR__ . '/../includes/fee_functions.php';

$pdo = get_db_connection();
$userId = current_user_id();

$studentStmt = $pdo->prepare('SELECT s.student_id, s.admission_no FROM students s WHERE s.user_id = :uid');
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

$summary = get_student_fee_summary($pdo, $studentId);
$account = $summary['account'];
$studentInfo = $summary['student'];
$invoices = $summary['invoices'];
$payments = $summary['payments'];

$totalFees = (float) ($account['total_fees'] ?? 0);
$totalPaid = (float) ($account['total_paid'] ?? 0);
$balance = (float) ($account['balance'] ?? 0);
$paymentStatus = $account['payment_status'] ?? 'pending';
$statusColors = ['paid' => 'success', 'partial' => 'warning', 'pending' => 'secondary', 'overdue' => 'danger'];
$statusColor = $statusColors[$paymentStatus] ?? 'secondary';
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Fee Account</h1>
      <p class="mb-0"><?= e($studentInfo['first_name'] ?? '') ?> <?= e($studentInfo['last_name'] ?? '') ?> &middot; <?= e($studentInfo['admission_no'] ?? '') ?></p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
    </div>
  </div>
</div>

<!-- Student Info & Fee Summary -->
<div class="row g-3 mb-4">
  <div class="col-md-8 animate-fade-in animate-delay-1">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-user-graduate text-gold me-1"></i> Student Information</div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-4 mb-2">
            <div class="small text-muted">Student Name</div>
            <div class="fw-semibold"><?= e($studentInfo['first_name'] ?? '') ?> <?= e($studentInfo['last_name'] ?? '') ?></div>
          </div>
          <div class="col-md-3 mb-2">
            <div class="small text-muted">Admission No</div>
            <div class="fw-semibold"><?= e($studentInfo['admission_no'] ?? 'N/A') ?></div>
          </div>
          <div class="col-md-3 mb-2">
            <div class="small text-muted">Class</div>
            <div class="fw-semibold"><?= e($studentInfo['level_name'] ?? '') ?> <?= e($studentInfo['stream_name'] ?? '') ?></div>
          </div>
          <div class="col-md-2 mb-2">
            <div class="small text-muted">Status</div>
            <span class="badge bg-<?= $statusColor ?>"><?= e(ucfirst($paymentStatus)) ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4 animate-fade-in animate-delay-2">
    <div class="card h-100 <?= $balance > 0 ? 'border-danger' : 'border-success' ?>">
      <div class="card-body text-center">
        <div class="small text-muted text-uppercase">Fee Balance</div>
        <div class="display-6 fw-bold mt-2 <?= $balance > 0 ? 'text-danger' : 'text-success' ?>"><?= format_money($balance) ?></div>
        <div class="mt-2"><span class="badge bg-<?= $statusColor ?>" style="font-size:0.9rem;"><?= e(ucfirst($paymentStatus)) ?></span></div>
      </div>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-file-invoice kpi-icon"></i>
      <div class="kpi-label">Total Fees</div>
      <div class="kpi-value"><?= format_money($totalFees) ?></div>
    </div>
  </div>
  <div class="col-md-4 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Amount Paid</div>
      <div class="kpi-value"><?= format_money($totalPaid) ?></div>
    </div>
  </div>
  <div class="col-md-4 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card <?= $balance > 0 ? 'accent-red' : 'accent-green' ?>">
      <i class="fa fa-<?= $balance > 0 ? 'exclamation-triangle' : 'check-circle' ?> kpi-icon"></i>
      <div class="kpi-label">Balance</div>
      <div class="kpi-value"><?= format_money($balance) ?></div>
    </div>
  </div>
</div>

<!-- Invoices Table -->
<div class="card mb-4 animate-fade-in animate-delay-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fa fa-file-invoice text-gold me-1"></i> My Invoices</span>
    <span class="badge bg-navy"><?= count($invoices) ?> invoices</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Invoice No.</th><th>Control Number</th><th>Term</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><code><?= e($inv['invoice_no']) ?></code></td>
            <td>
              <?php if ($inv['control_number']): ?>
                <code class="fw-bold" style="cursor:pointer;" onclick="copyToClipboard('<?= e($inv['control_number']) ?>')" title="Click to copy">
                  <?= e($inv['control_number']) ?>
                </code>
                <i class="fa fa-copy text-muted small ms-1" style="cursor:pointer;" onclick="copyToClipboard('<?= e($inv['control_number']) ?>')"></i>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($inv['year_name'] . ' - ' . $inv['term_name']) ?></td>
            <td><?= format_money($inv['total_amount']) ?></td>
            <td class="text-success"><?= format_money($inv['amount_paid']) ?></td>
            <td class="text-danger"><?= format_money($inv['balance']) ?></td>
            <td class="small"><?= format_date($inv['due_date']) ?></td>
            <td><span class="badge badge-status-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
            <td><a href="?print_invoice=<?= (int) $inv['invoice_id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fa fa-print"></i></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?><tr><td colspan="9" class="text-center text-muted py-4">No invoices generated yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Payment History -->
<div class="card mb-4 animate-fade-in animate-delay-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fa fa-receipt text-gold me-1"></i> Payment History</span>
    <span class="badge bg-navy"><?= count($payments) ?> payments</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th class="text-end">Amount</th><th>Method</th></tr></thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td class="small"><?= format_date($p['payment_date']) ?></td>
            <td class="small"><code><?= e($p['reference_no'] ?: '-') ?></code></td>
            <td class="small"><?= e($p['notes'] ?: 'Payment against invoice') ?></td>
            <td class="text-end fw-semibold text-success"><?= format_money($p['amount']) ?></td>
            <td class="small"><?= e(str_replace('_', ' ', ucfirst($p['payment_method']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($payments)): ?><tr><td colspan="5" class="text-center text-muted py-4">No payments recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Print Invoice Modal -->
<?php
$printInvoiceId = (int) ($_GET['print_invoice'] ?? 0);
if ($printInvoiceId > 0):
    $invStmt = $pdo->prepare(
        "SELECT i.*, t.term_name, y.year_name FROM invoices i
         JOIN terms t ON t.term_id = i.term_id
         JOIN academic_years y ON y.year_id = t.year_id
         WHERE i.invoice_id = :id AND i.student_id = :sid"
    );
    $invStmt->execute(['id' => $printInvoiceId, 'sid' => $studentId]);
    $printInv = $invStmt->fetch();
    if ($printInv):
        $items = $pdo->prepare("SELECT ii.*, fc.category_name FROM invoice_items ii JOIN fee_categories fc ON fc.fee_category_id = ii.fee_category_id WHERE ii.invoice_id = :id");
        $items->execute(['id' => $printInvoiceId]);
        $printItems = $items->fetchAll();
        $schoolName = get_setting($pdo, 'school_name', 'School');
        ?>
        <div class="modal fade" id="printModal" tabindex="-1">
          <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Invoice: <?= e($printInv['invoice_no']) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="printArea">
              <div style="text-align:center;margin-bottom:20px;border-bottom:2px solid #000;padding-bottom:15px;">
                <h3><?= e($schoolName) ?></h3>
                <h4>INVOICE</h4>
                <p><strong>Control No: <?= e($printInv['control_number'] ?: 'N/A') ?></strong></p>
                <p>Invoice: <?= e($printInv['invoice_no']) ?></p>
              </div>
              <table style="width:100%;margin-bottom:15px;">
                <tr><td><strong>Student:</strong> <?= e($studentInfo['first_name'] ?? '') ?> <?= e($studentInfo['last_name'] ?? '') ?></td>
                    <td><strong>Term:</strong> <?= e($printInv['year_name'] . ' - ' . $printInv['term_name']) ?></td></tr>
                <tr><td><strong>Admission:</strong> <?= e($studentInfo['admission_no'] ?? '') ?></td>
                    <td><strong>Due Date:</strong> <?= format_date($printInv['due_date']) ?></td></tr>
              </table>
              <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#000;color:#fff;"><th style="padding:6px;">#</th><th style="padding:6px;">Fee Category</th><th style="padding:6px;text-align:right;">Amount</th></tr></thead>
                <tbody>
                  <?php $i=1; foreach ($printItems as $it): ?>
                    <tr><td style="padding:4px;border-bottom:1px solid #ccc;"><?= $i++ ?></td>
                        <td style="padding:4px;border-bottom:1px solid #ccc;"><?= e($it['category_name']) ?></td>
                        <td style="padding:4px;border-bottom:1px solid #ccc;text-align:right;"><?= number_format((float) $it['amount'], 2) ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr style="font-weight:bold;"><td colspan="2" style="padding:4px;border-top:2px solid #000;">Total</td>
                      <td style="padding:4px;border-top:2px solid #000;text-align:right;"><?= number_format((float) $printInv['total_amount'], 2) ?></td></tr>
                  <tr><td colspan="2" style="padding:4px;">Amount Paid</td><td style="padding:4px;text-align:right;"><?= number_format((float) $printInv['amount_paid'], 2) ?></td></tr>
                  <tr style="font-weight:bold;"><td colspan="2" style="padding:4px;">Balance</td><td style="padding:4px;text-align:right;"><?= number_format((float) $printInv['balance'], 2) ?></td></tr>
                </tfoot>
              </table>
            </div>
            <div class="modal-footer">
              <button class="btn btn-primary" onclick="printElement('printArea')"><i class="fa fa-print"></i> Print</button>
              <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div></div>
        </div>
        <script>document.addEventListener('DOMContentLoaded', function() { new bootstrap.Modal(document.getElementById('printModal')).show(); });</script>
    <?php endif;
endif;
?>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.createElement('div');
        toast.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3 fade show';
        toast.style.zIndex = '9999';
        toast.innerHTML = '<i class="fa fa-check-circle me-1"></i> Control Number copied: ' + text;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    });
}
function printElement(id) {
    const content = document.getElementById(id).innerHTML;
    const win = window.open('', '_blank');
    win.document.write('<html><head><title>Print Invoice</title><style>body{font-family:Arial,sans-serif;font-size:12px;padding:20px;}table{width:100%;border-collapse:collapse;}th{background:#000;color:#fff;padding:6px;}td{padding:4px;border-bottom:1px solid #ccc;}.text-end{text-align:right;}</style></head><body>');
    win.document.write(content);
    win.document.write('</body></html>');
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); win.close(); }, 500);
}
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>