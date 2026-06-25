<?php
/**
 * bursar/record_payment.php
 * Enhanced Payment Recording with Control Number display,
 * payment validation, and automatic invoice/fee account updates.
 *
 * Payment Methods: Cash, Bank, Mobile Money, Other
 * When payment is recorded:
 * - Updates Paid Amount automatically
 * - Recalculates Balance automatically
 * - Updates Payment Status automatically
 * - Notifies student and guardians
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
require_once __DIR__ . '/../includes/fee_functions.php';

$error = '';
$success = '';
$preselectInvoiceId = (int) ($_GET['invoice_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'cash';
    $reference = trim($_POST['reference_no'] ?? '');
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    $result = record_payment($pdo, $invoiceId, $amount, $method, $reference, $paymentDate, current_user_id(), $notes);

    if ($result['success']) {
        audit_log('record_payment', 'finance', 'payments', $result['payment_id'], "Recorded payment of " . format_money($amount));
        flash_set('success', 'Payment recorded successfully.');
        redirect(app_url('/bursar/invoices.php') . '?id=' . $invoiceId);
    } else {
        $error = $result['message'];
    }
}

// Search for invoices
$search = trim($_GET['q'] ?? '');
$sql = "SELECT i.*, u.first_name, u.last_name, s.admission_no, cl.level_name, c.stream_name
        FROM invoices i
        JOIN students s ON s.student_id = i.student_id
        JOIN users u ON u.user_id = s.user_id
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
        WHERE i.status IN ('pending','partial','overdue')";
$params = [];

if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3 OR i.invoice_no LIKE :s4 OR i.control_number LIKE :s5)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = $params['s5'] = "%{$search}%";
}
$sql .= ' ORDER BY i.due_date ASC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$openInvoices = $stmt->fetchAll();

// Get selected invoice details for pre-fill
$selectedInvoice = null;
if ($preselectInvoiceId > 0) {
    $invStmt = $pdo->prepare(
        "SELECT i.*, u.first_name, u.last_name, s.admission_no, cl.level_name, c.stream_name
         FROM invoices i
         JOIN students s ON s.student_id = i.student_id
         JOIN users u ON u.user_id = s.user_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE i.invoice_id = :id"
    );
    $invStmt->execute(['id' => $preselectInvoiceId]);
    $selectedInvoice = $invStmt->fetch();
}

$pageTitle = 'Record Payment';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Record Payment</h1>

<?php if ($error): ?>
  <div class="alert alert-danger alert-dismissible fade show">
    <i class="fa fa-exclamation-circle me-1"></i> <?= e($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="fa fa-money-bill-wave text-gold me-1"></i> Payment Details</div>
      <div class="card-body">
        <form method="POST" id="paymentForm">
          <?php csrf_field(); ?>
          
          <div class="mb-2">
            <label class="form-label">Invoice <span class="required-mark">*</span></label>
            <select name="invoice_id" class="form-select" required id="invoiceSelect" onchange="updateInvoiceDetails()">
              <option value="">-- Select Invoice --</option>
              <?php foreach ($openInvoices as $inv): ?>
                <option value="<?= (int) $inv['invoice_id'] ?>" 
                  <?= $preselectInvoiceId === (int) $inv['invoice_id'] ? 'selected' : ''
                  ?> data-balance="<?= (float) $inv['balance'] ?>"
                  data-student="<?= e($inv['first_name'] . ' ' . $inv['last_name']) ?>"
                  data-control="<?= e($inv['control_number'] ?: '') ?>"
                  data-invoice="<?= e($inv['invoice_no']) ?>">
                  <?= e($inv['invoice_no'] . ' — ' . $inv['first_name'] . ' ' . $inv['last_name'] . ' (Balance: ' . number_format($inv['balance'], 0) . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($selectedInvoice): ?>
            <div class="alert alert-info small py-2 mb-2" id="invoiceInfo">
              <strong><?= e($selectedInvoice['invoice_no']) ?></strong><br>
              Student: <?= e($selectedInvoice['first_name'] . ' ' . $selectedInvoice['last_name']) ?><br>
              Class: <?= e($selectedInvoice['level_name'] ?? '') ?> <?= e($selectedInvoice['stream_name'] ?? '') ?><br>
              Control No: <code><?= e($selectedInvoice['control_number'] ?: 'N/A') ?></code><br>
              Balance: <strong class="text-danger"><?= format_money($selectedInvoice['balance']) ?></strong>
            </div>
          <?php else: ?>
            <div class="alert alert-info small py-2 mb-2" id="invoiceInfo" style="display:none;"></div>
          <?php endif; ?>

          <div class="mb-2">
            <label class="form-label">Amount (TZS) <span class="required-mark">*</span></label>
            <input type="number" name="amount" id="amount" class="form-control" min="1" step="0.01" required 
                   max="<?= $selectedInvoice ? (float) $selectedInvoice['balance'] : 999999999 ?>"
                   placeholder="Enter payment amount">
            <small class="text-muted" id="balanceHint">Max: <?= $selectedInvoice ? format_money($selectedInvoice['balance']) : '—' ?></small>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Method</label>
              <select name="payment_method" class="form-select">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Payment Date</label>
              <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label">Reference No.</label>
            <input type="text" name="reference_no" class="form-control" placeholder="Receipt/transaction reference">
          </div>

          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes about this payment"></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100" id="submitBtn">
            <i class="fa fa-check-circle me-1"></i> Record Payment
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-file-invoice text-gold me-1"></i> Outstanding Invoices</span>
        <span class="badge bg-danger"><?= count($openInvoices) ?> pending</span>
      </div>
      <div class="card-body">
        <form method="GET" class="mb-3">
          <div class="input-group">
            <input type="text" name="q" class="form-control" placeholder="Search by name, admission, invoice, control no..." value="<?= e($search) ?>">
            <button class="btn btn-outline-primary" type="submit"><i class="fa fa-search"></i></button>
            <?php if ($search): ?>
              <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="btn btn-outline-secondary"><i class="fa fa-times"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 data-table">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Control No.</th>
              <th>Student</th>
              <th>Class</th>
              <th>Balance</th>
              <th>Due</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($openInvoices as $inv): ?>
              <tr>
                <td><code><?= e($inv['invoice_no']) ?></code></td>
                <td><code class="small"><?= e($inv['control_number'] ?: '-') ?></code></td>
                <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?></td>
                <td class="small"><?= e($inv['level_name'] ?? '') ?> <?= e($inv['stream_name'] ?? '') ?></td>
                <td class="text-danger fw-semibold"><?= format_money($inv['balance']) ?></td>
                <td class="small"><?= format_date($inv['due_date']) ?></td>
                <td>
                  <a href="?invoice_id=<?= (int) $inv['invoice_id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-arrow-right"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($openInvoices)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No outstanding invoices. All fees collected!</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function updateInvoiceDetails() {
    const select = document.getElementById('invoiceSelect');
    const info = document.getElementById('invoiceInfo');
    const amount = document.getElementById('amount');
    const balanceHint = document.getElementById('balanceHint');
    const submitBtn = document.getElementById('submitBtn');
    
    const selected = select.options[select.selectedIndex];
    
    if (selected && selected.value) {
        const balance = parseFloat(selected.dataset.balance) || 0;
        info.style.display = 'block';
        info.innerHTML = '<strong>' + selected.dataset.invoice + '</strong><br>' +
            'Student: ' + selected.dataset.student + '<br>' +
            'Control No: <code>' + (selected.dataset.control || 'N/A') + '</code><br>' +
            'Balance: <strong class="text-danger">TZS ' + Number(balance).toLocaleString(undefined, {minimumFractionDigits:2}) + '</strong>';
        amount.max = balance;
        balanceHint.textContent = 'Max: TZS ' + Number(balance).toLocaleString(undefined, {minimumFractionDigits:2});
        submitBtn.disabled = false;
    } else {
        info.style.display = 'none';
        amount.max = 999999999;
        balanceHint.textContent = 'Max: —';
        submitBtn.disabled = true;
    }
}

// Validate amount on input
document.getElementById('amount')?.addEventListener('input', function() {
    const select = document.getElementById('invoiceSelect');
    const selected = select.options[select.selectedIndex];
    if (selected && selected.value) {
        const balance = parseFloat(selected.dataset.balance) || 0;
        const amount = parseFloat(this.value) || 0;
        if (amount > balance) {
            this.setCustomValidity('Amount exceeds outstanding balance of ' + Number(balance).toLocaleString());
        } else {
            this.setCustomValidity('');
        }
    }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>