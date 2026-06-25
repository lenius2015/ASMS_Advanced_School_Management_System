<?php
/**
 * bursar/record_payment.php
 * Record a payment against an invoice. Automatically updates the
 * invoice's amount_paid, balance, and status.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
$error = '';
$preselectInvoiceId = (int) ($_GET['invoice_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'cash';
    $reference = trim($_POST['reference_no'] ?? '');
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    $invStmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = :id');
    $invStmt->execute(['id' => $invoiceId]);
    $invoice = $invStmt->fetch();

    if (!$invoice) {
        $error = 'Invoice not found.';
    } elseif ($amount <= 0) {
        $error = 'Payment amount must be greater than zero.';
    } elseif ($amount > (float) $invoice['balance']) {
        $error = 'Payment amount (' . format_money($amount) . ') exceeds the outstanding balance (' . format_money($invoice['balance']) . ').';
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                'INSERT INTO payments (invoice_id, student_id, amount, payment_method, reference_no, payment_date, received_by, notes)
                 VALUES (:iid, :sid, :amt, :method, :ref, :date, :by, :notes)'
            )->execute([
                'iid' => $invoiceId, 'sid' => $invoice['student_id'], 'amt' => $amount, 'method' => $method,
                'ref' => $reference ?: null, 'date' => $paymentDate, 'by' => current_user_id(), 'notes' => $notes ?: null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();

            $newPaid = (float) $invoice['amount_paid'] + $amount;
            $newBalance = (float) $invoice['total_amount'] - $newPaid;
            $newStatus = $newBalance <= 0 ? 'paid' : 'partial';

            $pdo->prepare('UPDATE invoices SET amount_paid = :paid, balance = :bal, status = :status WHERE invoice_id = :id')
                ->execute(['paid' => $newPaid, 'bal' => max($newBalance, 0), 'status' => $newStatus, 'id' => $invoiceId]);

            $pdo->commit();
            audit_log('record_payment', 'finance', 'payments', $paymentId, "Recorded payment of " . format_money($amount) . " against invoice {$invoice['invoice_no']}");

            // Notify guardian
            $guardianStmt = $pdo->prepare(
                "SELECT g.user_id FROM guardians g JOIN student_guardians sg ON sg.guardian_id = g.guardian_id
                 WHERE sg.student_id = :sid AND g.user_id IS NOT NULL"
            );
            $guardianStmt->execute(['sid' => $invoice['student_id']]);
            foreach ($guardianStmt->fetchAll() as $g) {
                notify_user($pdo, (int) $g['user_id'], 'Payment Received', 'A payment of ' . format_money($amount) . ' was recorded against invoice ' . $invoice['invoice_no'] . '.', 'fee', app_url('/parent/fees.php'));
            }

            flash_set('success', 'Payment recorded successfully.');
            redirect(app_url('/bursar/invoices.php') . '?id=' . $invoiceId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ASMS] record_payment failed: ' . $e->getMessage());
            $error = 'Failed to record payment. Please try again.';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT i.*, u.first_name, u.last_name, s.admission_no FROM invoices i
        JOIN students s ON s.student_id = i.student_id JOIN users u ON u.user_id = s.user_id
        WHERE i.status IN ('unpaid','partial','overdue')";
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3 OR i.invoice_no LIKE :s4)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
}
$sql .= ' ORDER BY i.due_date ASC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$openInvoices = $stmt->fetchAll();

$pageTitle = 'Record Payment';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Record Payment</h1>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Payment Details</div>
      <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
        <form method="POST">
          <?php csrf_field(); ?>
          <div class="mb-2">
            <label class="form-label">Invoice <span class="required-mark">*</span></label>
            <select name="invoice_id" class="form-select" required id="invoiceSelect">
              <option value="">-- Select Invoice --</option>
              <?php foreach ($openInvoices as $inv): ?>
                <option value="<?= (int) $inv['invoice_id'] ?>" <?= $preselectInvoiceId === (int) $inv['invoice_id'] ? 'selected' : '' ?>>
                  <?= e($inv['invoice_no'] . ' — ' . $inv['first_name'] . ' ' . $inv['last_name'] . ' (Balance: ' . number_format($inv['balance'],0) . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Amount (TZS) <span class="required-mark">*</span></label>
            <input type="number" name="amount" class="form-control" min="1" step="0.01" required>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Method</label>
              <select name="payment_method" class="form-select">
                <option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option>
                <option value="mobile_money">Mobile Money</option><option value="card">Card</option>
                <option value="online_gateway">Online Gateway</option><option value="cheque">Cheque</option>
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
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <button type="submit" class="btn btn-primary w-100">Record Payment</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Outstanding Invoices</div>
      <div class="card-body">
        <form method="GET" class="mb-3"><input type="text" name="q" class="form-control" placeholder="Search..." value="<?= e($search) ?>" onkeyup="if(event.key==='Enter') this.form.submit()"></form>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Invoice</th><th>Student</th><th>Balance</th><th>Due</th></tr></thead>
          <tbody>
            <?php foreach ($openInvoices as $inv): ?>
              <tr>
                <td><code><?= e($inv['invoice_no']) ?></code></td>
                <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?></td>
                <td class="text-danger fw-semibold"><?= format_money($inv['balance']) ?></td>
                <td class="small"><?= format_date($inv['due_date']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($openInvoices)): ?><tr><td colspan="4" class="text-center text-muted py-4">No outstanding invoices.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
