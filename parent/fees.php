<?php
/**
 * parent/fees.php
 * Enhanced Parent Portal - View fee status for all linked children.
 * Control Numbers, invoices, payment history, and download/print.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['parent']);
require_once __DIR__ . '/../includes/fee_functions.php';

$pdo = get_db_connection();
$userId = current_user_id();

// Get all children linked to this guardian
$childrenStmt = $pdo->prepare(
    "SELECT s.student_id, s.admission_no, u.first_name, u.last_name, 
            cl.level_name, c.stream_name, s.status
     FROM students s
     JOIN student_guardians sg ON sg.student_id = s.student_id
     JOIN guardians g ON g.guardian_id = sg.guardian_id
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN classes c ON c.class_id = s.class_id
     LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE g.user_id = :uid AND s.status = 'active'
     ORDER BY u.first_name"
);
$childrenStmt->execute(['uid' => $userId]);
$children = $childrenStmt->fetchAll();

// Get selected child
$studentId = (int) ($_GET['student_id'] ?? 0);
$verifiedStudentId = $studentId ? verify_guardian_owns_student($pdo, $userId, $studentId) : null;

if (!$verifiedStudentId && !empty($children)) {
    $verifiedStudentId = (int) $children[0]['student_id'];
}

$pageTitle = 'Fee Status';
require APP_ROOT . '/includes/header.php';

if (empty($children)): ?>
  <div class="alert alert-warning">
    <i class="fa fa-exclamation-circle me-1"></i> No children are linked to your account. Please contact the school to link your children.
  </div>
<?php require APP_ROOT . '/includes/footer.php'; exit; endif;

if (!$verifiedStudentId): ?>
  <div class="alert alert-warning">
    <i class="fa fa-exclamation-circle me-1"></i> Student not found or access denied.
  </div>
<?php require APP_ROOT . '/includes/footer.php'; exit; endif;

// Get fee summary
$summary = get_student_fee_summary($pdo, $verifiedStudentId);
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
      <h1 class="h3 mb-1">Fee Status</h1>
      <p class="mb-0">View and manage your children's fee accounts</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fa fa-print"></i></button>
    </div>
  </div>
</div>

<!-- Child Selector -->
<?php if (count($children) > 1): ?>
  <div class="card mb-4 animate-fade-in animate-delay-1">
    <div class="card-body">
      <form method="GET" class="row g-2 align-items-center">
        <div class="col-auto"><label class="form-label mb-0 fw-semibold">Select Child:</label></div>
        <div class="col-md-4">
          <select name="student_id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($children as $child): ?>
              <option value="<?= (int) $child['student_id'] ?>" <?= $verifiedStudentId === (int) $child['student_id'] ? 'selected' : '' ?>>
                <?= e($child['first_name'] . ' ' . $child['last_name'] . ' (' . $child['admission_no'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- Student Info -->
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

<!-- Invoices -->
<div class="card mb-4 animate-fade-in animate-delay-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fa fa-file-invoice text-gold me-1"></i> Invoices</span>
    <span class="badge bg-navy"><?= count($invoices) ?> invoices</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr><th>Invoice No.</th><th>Control Number</th><th>Term</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><code><?= e($inv['invoice_no']) ?></code></td>
            <td>
              <?php if ($inv['control_number']): ?>
                <code class="fw-bold"><?= e($inv['control_number']) ?></code>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($inv['year_name'] . ' - ' . $inv['term_name']) ?></td>
            <td><?= format_money($inv['total_amount']) ?></td>
            <td class="text-success"><?= format_money($inv['amount_paid']) ?></td>
            <td class="text-danger"><?= format_money($inv['balance']) ?></td>
            <td class="small"><?= format_date($inv['due_date']) ?></td>
            <td>
              <span class="badge badge-status-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span>
            </td>
            <td>
              <?php if ((float) $inv['balance'] > 0 && in_array($inv['status'], ['unpaid', 'partial', 'overdue'])): ?>
                <button class="btn btn-sm btn-gold pay-now-btn" 
                  data-student-id="<?= (int) $verifiedStudentId ?>"
                  data-invoice-id="<?= (int) $inv['invoice_id'] ?>"
                  data-invoice-no="<?= e($inv['invoice_no']) ?>"
                  data-balance="<?= (float) $inv['balance'] ?>"
                  data-bs-toggle="modal" data-bs-target="#payNowModal">
                  <i class="fa fa-credit-card me-1"></i> Pay Now
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?><tr><td colspan="9" class="text-center text-muted py-4">No invoices generated yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Payment History -->
<div class="card mb-4 animate-fade-in animate-delay-4">
  <div class="card-header"><span><i class="fa fa-receipt text-gold me-1"></i> Payment History</span></div>
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

<!-- All Children Overview -->
<?php if (count($children) > 1): ?>
<div class="card animate-fade-in animate-delay-5">
  <div class="card-header"><span><i class="fa fa-users text-gold me-1"></i> All Children - Fee Overview</span></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Child</th><th>Admission</th><th>Class</th><th>Total Fees</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($children as $child):
          $childSummary = get_student_fee_summary($pdo, (int) $child['student_id']);
          $childAcct = $childSummary['account'];
        ?>
          <tr class="<?= $verifiedStudentId === (int) $child['student_id'] ? 'table-active' : '' ?>">
            <td><a href="?student_id=<?= (int) $child['student_id'] ?>"><?= e($child['first_name'] . ' ' . $child['last_name']) ?></a></td>
            <td class="small"><?= e($child['admission_no']) ?></td>
            <td class="small"><?= e($child['level_name'] ?? '') ?> <?= e($child['stream_name'] ?? '') ?></td>
            <td><?= format_money((float) ($childAcct['total_fees'] ?? 0)) ?></td>
            <td class="text-success"><?= format_money((float) ($childAcct['total_paid'] ?? 0)) ?></td>
            <td class="text-danger"><?= format_money((float) ($childAcct['balance'] ?? 0)) ?></td>
            <td><span class="badge bg-<?= $statusColors[$childAcct['payment_status'] ?? 'pending'] ?>"><?= e(ucfirst($childAcct['payment_status'] ?? 'pending')) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Pay Now Modal -->
<div class="modal fade" id="payNowModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-credit-card text-gold me-2"></i>Make Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="payNowForm">
        <div class="modal-body">
          <input type="hidden" name="student_id" id="payStudentId" value="">
          <input type="hidden" name="invoice_id" id="payInvoiceId" value="">
          <div class="mb-3">
            <label class="form-label">Invoice</label>
            <p class="fw-bold mb-0" id="payInvoiceNo"></p>
          </div>
          <div class="mb-3">
            <label class="form-label">Outstanding Balance</label>
            <p class="fw-bold text-danger" id="payBalance"></p>
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Amount (TZS) <span class="required-mark">*</span></label>
            <input type="number" name="amount" id="payAmount" class="form-control" min="1" step="1000" required>
            <small class="text-muted">Enter the amount you wish to pay (cannot exceed balance).</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <select name="payment_method" id="payMethod" class="form-select">
              <option value="mobile_money">Mobile Money (M-Pesa/Airtel/Tigo)</option>
              <option value="online_gateway">Online Payment Gateway</option>
              <option value="bank_transfer">Bank Transfer</option>
            </select>
          </div>
          <div class="mb-3" id="phoneNumberGroup">
            <label class="form-label">Phone Number (for Mobile Money)</label>
            <input type="text" name="phone_number" id="payPhone" class="form-control" placeholder="e.g. 0712345678">
          </div>
          <div id="payResult" class="alert d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold" id="paySubmitBtn"><i class="fa fa-credit-card me-1"></i> Process Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="mt-3 text-muted small">
  <i class="fa fa-info-circle me-1"></i> For payment options or to dispute a charge, please contact the school's finance office (Bursar).
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set up Pay Now buttons
    document.querySelectorAll('.pay-now-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('payStudentId').value = this.dataset.studentId;
            document.getElementById('payInvoiceId').value = this.dataset.invoiceId;
            document.getElementById('payInvoiceNo').textContent = this.dataset.invoiceNo;
            document.getElementById('payBalance').textContent = 'TZS ' + Number(this.dataset.balance).toLocaleString();
            document.getElementById('payAmount').max = this.dataset.balance;
            document.getElementById('payAmount').value = this.dataset.balance;
            document.getElementById('payResult').classList.add('d-none');
            document.getElementById('paySubmitBtn').disabled = false;
        });
    });

    // Toggle phone field visibility based on payment method
    document.getElementById('payMethod').addEventListener('change', function() {
        document.getElementById('phoneNumberGroup').style.display = 
            this.value === 'mobile_money' ? 'block' : 'none';
    });
    document.getElementById('phoneNumberGroup').style.display = 'none';

    // Handle payment submission
    document.getElementById('payNowForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.getElementById('paySubmitBtn');
        var resultDiv = document.getElementById('payResult');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Processing...';
        resultDiv.classList.add('d-none');

        var data = {
            student_id: parseInt(document.getElementById('payStudentId').value),
            invoice_id: parseInt(document.getElementById('payInvoiceId').value),
            amount: parseFloat(document.getElementById('payAmount').value),
            payment_method: document.getElementById('payMethod').value,
            phone_number: document.getElementById('payPhone').value
        };

        fetch('<?= e(app_url('/api/payments/initiate.php')) ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            resultDiv.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
            if (result.success) {
                resultDiv.classList.add('alert-success');
                resultDiv.innerHTML = '<strong>Payment initiated!</strong><br>' + 
                    result.data.instructions +
                    '<br><br><strong>Reference:</strong> ' + result.data.transaction_id;
                btn.innerHTML = '<i class="fa fa-check me-1"></i> Done';
                btn.disabled = true;
            } else {
                resultDiv.classList.add('alert-danger');
                resultDiv.textContent = result.message;
                btn.innerHTML = '<i class="fa fa-credit-card me-1"></i> Process Payment';
                btn.disabled = false;
            }
        })
        .catch(function(err) {
            resultDiv.classList.remove('d-none');
            resultDiv.classList.add('alert-danger');
            resultDiv.textContent = 'Network error. Please try again.';
            btn.innerHTML = '<i class="fa fa-credit-card me-1"></i> Process Payment';
            btn.disabled = false;
        });
    });
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
