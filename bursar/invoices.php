<?php
/**
 * bursar/invoices.php
 * Enhanced Invoice Management with Control Number display,
 * print functionality, invoice cancellation, and full payment history.
 *
 * Features:
 * - Listing with search, filter, pagination
 * - Detail view with invoice items, payment history, recorded by
 * - Control Number display
 * - Print Invoice
 * - Download Fee Statement
 * - Invoice Cancellation
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
require_once __DIR__ . '/../includes/fee_functions.php';

$invoiceId = (int) ($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

// Handle cancel action
if ($invoiceId > 0 && $action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $reason = trim($_POST['cancel_reason'] ?? '');
    $cancelled = cancel_invoice($pdo, $invoiceId, current_user_id(), $reason);
    if ($cancelled) {
        audit_log('cancel_invoice', 'finance', 'invoices', $invoiceId, 'Cancelled invoice');
        flash_set('success', 'Invoice has been cancelled.');
    } else {
        flash_set('error', 'Invoice could not be cancelled. Only pending or partial invoices can be cancelled.');
    }
    redirect(app_url('/bursar/invoices.php') . '?id=' . $invoiceId);
}

// Handle print action
if ($invoiceId > 0 && $action === 'print') {
    // Load invoice data and render printable version
    $stmt = $pdo->prepare(
        "SELECT i.*, u.first_name, u.last_name, s.admission_no, 
                t.term_name, y.year_name,
                cl.level_name, c.stream_name
         FROM invoices i 
         JOIN students s ON s.student_id = i.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN terms t ON t.term_id = i.term_id
         JOIN academic_years y ON y.year_id = t.year_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE i.invoice_id = :id"
    );
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        flash_set('error', 'Invoice not found.');
        redirect(app_url('/bursar/invoices.php'));
    }

    $items = $pdo->prepare(
        "SELECT ii.*, fc.category_name FROM invoice_items ii
         JOIN fee_categories fc ON fc.fee_category_id = ii.fee_category_id WHERE ii.invoice_id = :id"
    );
    $items->execute(['id' => $invoiceId]);
    $invoiceItems = $items->fetchAll();

    $payments = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = :id ORDER BY payment_date DESC');
    $payments->execute(['id' => $invoiceId]);
    $invoicePayments = $payments->fetchAll();

    $schoolName = get_setting($pdo, 'school_name', 'School Name');
    $schoolMotto = get_setting($pdo, 'school_motto', '');
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>Invoice <?= e($invoice['invoice_no']) ?></title>
    <style>
        body { font-family: 'Courier New', monospace; font-size: 12px; margin: 20px; color: #000; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 15px; }
        .header h1 { font-size: 20px; margin: 0; }
        .header p { margin: 3px 0; font-size: 11px; }
        .invoice-title { font-size: 16px; font-weight: bold; text-align: center; margin: 20px 0; }
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { padding: 3px 5px; }
        .info-table .label { font-weight: bold; width: 150px; }
        table.items { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table.items th { background: #000; color: #fff; padding: 6px 8px; text-align: left; font-size: 11px; }
        table.items td { padding: 5px 8px; border-bottom: 1px solid #ccc; }
        table.items tfoot td { font-weight: bold; border-top: 2px solid #000; padding-top: 8px; }
        .text-end { text-align: right; }
        .status-badge { display: inline-block; padding: 3px 10px; border: 1px solid #000; font-weight: bold; font-size: 11px; }
        .footer { text-align: center; margin-top: 40px; font-size: 10px; border-top: 1px solid #ccc; padding-top: 10px; }
        .control-number { font-size: 14px; font-weight: bold; letter-spacing: 1px; }
        @media print { body { margin: 0; } .no-print { display: none; } }
    </style>
    </head><body>
    <div class="no-print" style="text-align:center;margin-bottom:15px;">
        <button onclick="window.print()" style="padding:8px 20px;cursor:pointer;">Print Invoice</button>
        <button onclick="window.close()" style="padding:8px 20px;cursor:pointer;">Close</button>
    </div>
    <div class="header">
        <h1><?= e($schoolName) ?></h1>
        <p><?= e($schoolMotto) ?></p>
        <p>Invoice Statement</p>
    </div>
    <div class="invoice-title">INVOICE</div>
    <div style="text-align:center;margin:10px 0;">
        <span class="control-number">Control No: <?= e($invoice['control_number'] ?: 'N/A') ?></span><br>
        <span>Invoice No: <?= e($invoice['invoice_no']) ?></span>
    </div>
    <table class="info-table">
        <tr><td class="label">Student:</td><td><?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?></td>
            <td class="label">Class:</td><td><?= e($invoice['level_name'] ?? '') ?> <?= e($invoice['stream_name'] ?? '') ?></td></tr>
        <tr><td class="label">Admission No:</td><td><?= e($invoice['admission_no']) ?></td>
            <td class="label">Term:</td><td><?= e($invoice['year_name'] . ' - ' . $invoice['term_name']) ?></td></tr>
        <tr><td class="label">Due Date:</td><td><?= format_date($invoice['due_date']) ?></td>
            <td class="label">Status:</td><td><span class="status-badge"><?= e(ucfirst($invoice['status'])) ?></span></td></tr>
    </table>
    <table class="items">
        <thead><tr><th>#</th><th>Fee Category</th><th class="text-end">Amount (TZS)</th></tr></thead>
        <tbody>
            <?php $i = 1; foreach ($invoiceItems as $it): ?>
                <tr><td><?= $i++ ?></td><td><?= e($it['category_name']) ?></td><td class="text-end"><?= number_format((float) $it['amount'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">Total</td><td class="text-end"><?= number_format((float) $invoice['total_amount'], 2) ?></td></tr>
        <tr><td colspan="2">Amount Paid</td><td class="text-end"><?= number_format((float) $invoice['amount_paid'], 2) ?></td></tr>
        <tr><td colspan="2">Balance</td><td class="text-end"><?= number_format((float) $invoice['balance'], 2) ?></td></tr></tfoot>
    </table>
    <?php if (!empty($invoicePayments)): ?>
        <h4 style="margin-top:20px;">Payment History</h4>
        <table class="items">
            <thead><tr><th>Date</th><th>Reference</th><th>Method</th><th class="text-end">Amount</th><th>Recorded By</th></tr></thead>
            <tbody>
                <?php foreach ($invoicePayments as $p): ?>
                    <tr><td><?= format_date($p['payment_date']) ?></td>
                        <td><?= e($p['reference_no'] ?: '-') ?></td>
                        <td><?= e(str_replace('_', ' ', ucfirst($p['payment_method']))) ?></td>
                        <td class="text-end"><?= number_format((float) $p['amount'], 2) ?></td>
                        <td><?= e($p['recorded_by_name'] ?: '-') ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <div class="footer">
        <p><?= e($schoolName) ?> | Generated: <?= date('d M Y H:i') ?></p>
        <p>This is a computer-generated document. No signature required.</p>
    </div>
    <script>window.print();</script>
    </body></html>
    <?php
    exit;
}

// Handle fee statement download
if ($invoiceId > 0 && $action === 'statement') {
    $stmt = $pdo->prepare(
        "SELECT i.*, u.first_name, u.last_name, s.admission_no,
                t.term_name, y.year_name, cl.level_name, c.stream_name
         FROM invoices i
         JOIN students s ON s.student_id = i.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN terms t ON t.term_id = i.term_id
         JOIN academic_years y ON y.year_id = t.year_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE i.invoice_id = :id"
    );
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();

    if (!$invoice) { flash_set('error', 'Invoice not found.'); redirect(app_url('/bursar/invoices.php')); }

    $summary = get_student_fee_summary($pdo, (int) $invoice['student_id']);
    $headers = ['Date', 'Reference', 'Description', 'Amount', 'Balance', 'Recorded By'];
    $data = [];

    // Opening balance
    $data[] = ['Date' => '-', 'Reference' => '-', 'Description' => 'Opening Balance (Invoice)', 'Amount' => number_format((float) $invoice['total_amount'], 2), 'Balance' => number_format((float) $invoice['total_amount'], 2), 'Recorded By' => '-'];

    // Payments
    $runningBalance = (float) $invoice['total_amount'];
    foreach ($summary['payments'] as $p) {
        if ((int) $p['invoice_id'] !== $invoiceId) continue;
        $runningBalance -= (float) $p['amount'];
        $data[] = [
            'Date' => format_date($p['payment_date']),
            'Reference' => $p['reference_no'] ?: '-',
            'Description' => 'Payment via ' . str_replace('_', ' ', ucfirst($p['payment_method'])),
            'Amount' => number_format((float) $p['amount'], 2),
            'Balance' => number_format(max($runningBalance, 0), 2),
            'Recorded By' => $p['recorded_by_name'] ?: '-',
        ];
    }

    $html = render_printable_report('Fee Statement - ' . e($invoice['first_name'] . ' ' . $invoice['last_name']), $headers, $data, [
        'Student' => e($invoice['first_name'] . ' ' . $invoice['last_name']),
        'Admission No' => e($invoice['admission_no']),
        'Invoice No' => e($invoice['invoice_no']),
        'Control Number' => e($invoice['control_number'] ?: 'N/A'),
        'Total Fees' => number_format((float) $invoice['total_amount'], 2),
        'Total Paid' => number_format((float) $invoice['amount_paid'], 2),
        'Balance' => number_format((float) $invoice['balance'], 2),
        'Status' => ucfirst($invoice['status']),
    ]);
    output_pdf($html, 'fee_statement_' . $invoice['invoice_no'] . '.pdf');
    exit;
}

// ---- Single Invoice Detail View ----
if ($invoiceId > 0 && $action === '') {
    $stmt = $pdo->prepare(
        "SELECT i.*, u.first_name, u.last_name, s.admission_no, s.student_id,
                t.term_name, y.year_name, cl.level_name, c.stream_name
         FROM invoices i 
         JOIN students s ON s.student_id = i.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN terms t ON t.term_id = i.term_id
         JOIN academic_years y ON y.year_id = t.year_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE i.invoice_id = :id"
    );
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        flash_set('error', 'Invoice not found.');
        redirect(app_url('/bursar/invoices.php'));
    }

    $items = $pdo->prepare(
        "SELECT ii.*, fc.category_name FROM invoice_items ii
         JOIN fee_categories fc ON fc.fee_category_id = ii.fee_category_id WHERE ii.invoice_id = :id"
    );
    $items->execute(['id' => $invoiceId]);
    $invoiceItems = $items->fetchAll();

    $paymentsStmt = $pdo->prepare(
        "SELECT p.*, u.first_name AS recorder_fn, u.last_name AS recorder_ln 
         FROM payments p 
         LEFT JOIN users u ON u.user_id = p.received_by
         WHERE p.invoice_id = :id ORDER BY p.payment_date DESC"
    );
    $paymentsStmt->execute(['id' => $invoiceId]);
    $invoicePayments = $paymentsStmt->fetchAll();

    $pageTitle = 'Invoice ' . $invoice['invoice_no'];
    require APP_ROOT . '/includes/header.php';
    ?>
    <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="small mb-3 d-inline-block"><i class="fa fa-arrow-left me-1"></i> Back to all invoices</a>

    <div class="card mb-4">
      <div class="card-body d-flex flex-wrap justify-content-between gap-3">
        <div>
          <h2 class="h4 mb-1">
            <?= e($invoice['invoice_no']) ?>
            <?php if ($invoice['control_number']): ?>
              <span class="badge bg-dark ms-2" style="font-size:0.8rem;letter-spacing:1px;">
                <i class="fa fa-qrcode me-1"></i><?= e($invoice['control_number']) ?>
              </span>
            <?php endif; ?>
          </h2>
          <p class="text-muted mb-0">
            <?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?> (<?= e($invoice['admission_no']) ?>) 
            &middot; <?= e($invoice['level_name'] ?? '') ?> <?= e($invoice['stream_name'] ?? '') ?>
            &middot; <?= e($invoice['year_name'] . ' - ' . $invoice['term_name']) ?>
          </p>
        </div>
        <div class="text-end">
          <span class="badge badge-status-<?= e($invoice['status']) ?> fs-6"><?= e(ucfirst($invoice['status'])) ?></span>
          <?php if ($invoice['cancelled_at']): ?>
            <div class="small text-muted mt-1">Cancelled: <?= format_date($invoice['cancelled_at']) ?></div>
          <?php endif; ?>
          <div class="mt-2 d-flex gap-1">
            <a href="<?= e(app_url('/bursar/record_payment.php')) ?>?invoice_id=<?= (int) $invoiceId ?>" class="btn btn-sm btn-gold">Record Payment</a>
            <a href="?id=<?= (int) $invoiceId ?>&action=print" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fa fa-print"></i></a>
            <a href="?id=<?= (int) $invoiceId ?>&action=statement" class="btn btn-sm btn-outline-info"><i class="fa fa-download"></i> Statement</a>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card mb-4">
          <div class="card-header">Invoice Items</div>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Category</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
                <?php foreach ($invoiceItems as $it): ?>
                  <tr><td><?= e($it['category_name']) ?></td><td class="small text-muted"><?= e($it['description'] ?: '-') ?></td><td class="text-end"><?= format_money($it['amount']) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="fw-bold"><td colspan="2">Total Fees</td><td class="text-end"><?= format_money($invoice['total_amount']) ?></td></tr>
              </tfoot>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-header">Payment History</div>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Date</th><th>Reference</th><th>Amount</th><th>Method</th><th>Recorded By</th></tr></thead>
              <tbody>
                <?php foreach ($invoicePayments as $p): ?>
                  <tr>
                    <td class="small"><?= format_date($p['payment_date']) ?></td>
                    <td class="small"><code><?= e($p['reference_no'] ?: '-') ?></code></td>
                    <td class="fw-semibold text-success"><?= format_money($p['amount']) ?></td>
                    <td class="small"><?= e(str_replace('_', ' ', ucfirst($p['payment_method']))) ?></td>
                    <td class="small"><?= e($p['recorded_by_name'] ?: ($p['recorder_fn'] ? $p['recorder_fn'] . ' ' . $p['recorder_ln'] : '-')) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($invoicePayments)): ?>
                  <tr><td colspan="5" class="text-center text-muted py-3">No payments recorded yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card mb-4">
          <div class="card-header">Payment Summary</div>
          <div class="card-body">
            <table class="table table-sm mb-3">
              <tr><td>Total Due</td><td class="text-end fw-semibold"><?= format_money($invoice['total_amount']) ?></td></tr>
              <tr><td>Amount Paid</td><td class="text-end fw-semibold text-success"><?= format_money($invoice['amount_paid']) ?></td></tr>
              <tr><td>Balance</td><td class="text-end fw-semibold text-danger"><?= format_money($invoice['balance']) ?></td></tr>
              <tr><td>Due Date</td><td class="text-end"><?= format_date($invoice['due_date']) ?></td></tr>
              <tr><td>Control Number</td><td class="text-end"><code class="fw-bold"><?= e($invoice['control_number'] ?: 'N/A') ?></code></td></tr>
            </table>

            <?php if ($invoice['status'] === 'cancelled' && $invoice['cancel_reason']): ?>
              <div class="alert alert-danger small py-2 mb-3">
                <strong>Cancelled:</strong> <?= e($invoice['cancel_reason']) ?>
              </div>
            <?php endif; ?>

            <?php if (in_array($invoice['status'], ['pending', 'partial'])): ?>
              <form method="POST" action="?id=<?= (int) $invoiceId ?>&action=cancel" onsubmit="return confirm('Are you sure you want to cancel this invoice?')">
                <?php csrf_field(); ?>
                <div class="mb-2">
                  <label class="form-label small">Cancel Reason</label>
                  <input type="text" name="cancel_reason" class="form-control form-control-sm" placeholder="Reason for cancellation" required>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="fa fa-ban me-1"></i> Cancel Invoice</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ---- Listing View ----
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$classFilter = (int) ($_GET['class_level_id'] ?? 0);

$sql = "SELECT i.*, u.first_name, u.last_name, s.admission_no, t.term_name, cl.level_name
        FROM invoices i
        JOIN students s ON s.student_id = i.student_id
        JOIN users u ON u.user_id = s.user_id
        JOIN terms t ON t.term_id = i.term_id
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
        WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3 OR i.invoice_no LIKE :s4 OR i.control_number LIKE :s5)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = $params['s5'] = "%{$search}%";
}
if ($statusFilter !== '') {
    $sql .= ' AND i.status = :status';
    $params['status'] = $statusFilter;
}
if ($classFilter > 0) {
    $sql .= ' AND c.class_level_id = :cl';
    $params['cl'] = $classFilter;
}
$sql .= ' ORDER BY i.created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$classLevels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();

$pageTitle = 'Invoices';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Invoices</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Search name, admission, invoice, control no..." value="<?= e($search) ?>">
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['pending','partial','paid','overdue','cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="class_level_id" class="form-select">
          <option value="">All Classes</option>
          <?php foreach ($classLevels as $cl): ?>
            <option value="<?= (int) $cl['class_level_id'] ?>" <?= $classFilter === (int) $cl['class_level_id'] ? 'selected' : '' ?>><?= e($cl['level_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary w-100"><i class="fa fa-search"></i> Search</button>
      </div>
      <div class="col-md-2">
        <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="btn btn-gold w-100"><i class="fa fa-plus"></i> Generate</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 data-table">
      <thead>
        <tr>
          <th>Invoice No.</th>
          <th>Control No.</th>
          <th>Student</th>
          <th>Class</th>
          <th>Term</th>
          <th>Total</th>
          <th>Paid</th>
          <th>Balance</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><code><?= e($inv['invoice_no']) ?></code></td>
            <td><code class="small"><?= e($inv['control_number'] ?: '-') ?></code></td>
            <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?> <span class="text-muted small">(<?= e($inv['admission_no']) ?>)</span></td>
            <td class="small"><?= e($inv['level_name'] ?? '-') ?></td>
            <td class="small"><?= e($inv['term_name']) ?></td>
            <td><?= format_money($inv['total_amount']) ?></td>
            <td class="text-success"><?= format_money($inv['amount_paid']) ?></td>
            <td class="text-danger"><?= format_money($inv['balance']) ?></td>
            <td><span class="badge badge-status-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
            <td>
              <a href="?id=<?= (int) $inv['invoice_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-eye"></i></a>
              <a href="?id=<?= (int) $inv['invoice_id'] ?>&action=print" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fa fa-print"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No invoices found. Generate invoices from Fee Structures.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>