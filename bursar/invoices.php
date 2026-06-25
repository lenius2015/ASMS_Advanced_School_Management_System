<?php
/**
 * bursar/invoices.php
 * Browse all invoices, with a detail view showing items and payment
 * history for a single invoice when ?id= is passed.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
$invoiceId = (int) ($_GET['id'] ?? 0);

if ($invoiceId > 0) {
    $stmt = $pdo->prepare(
        "SELECT i.*, u.first_name, u.last_name, s.admission_no, t.term_name, y.year_name
         FROM invoices i JOIN students s ON s.student_id = i.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN terms t ON t.term_id = i.term_id
         JOIN academic_years y ON y.year_id = t.year_id
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

    $paymentsStmt = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = :id ORDER BY payment_date DESC');
    $paymentsStmt->execute(['id' => $invoiceId]);
    $invoicePayments = $paymentsStmt->fetchAll();

    $pageTitle = 'Invoice ' . $invoice['invoice_no'];
    require APP_ROOT . '/includes/header.php';
    ?>
    <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="small mb-3 d-inline-block"><i class="fa fa-arrow-left me-1"></i> Back to all invoices</a>

    <div class="card mb-4">
      <div class="card-body d-flex flex-wrap justify-content-between gap-3">
        <div>
          <h2 class="h4 mb-1"><?= e($invoice['invoice_no']) ?></h2>
          <p class="text-muted mb-0"><?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?> (<?= e($invoice['admission_no']) ?>) &middot; <?= e($invoice['year_name'] . ' - ' . $invoice['term_name']) ?></p>
        </div>
        <div class="text-end">
          <span class="badge badge-status-<?= e($invoice['status']) ?> fs-6"><?= e(ucfirst($invoice['status'])) ?></span>
          <div class="mt-2"><a href="<?= e(app_url('/bursar/record_payment.php')) ?>?invoice_id=<?= (int) $invoiceId ?>" class="btn btn-sm btn-gold">Record Payment</a></div>
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
                <tr class="fw-bold"><td colspan="2">Total</td><td class="text-end"><?= format_money($invoice['total_amount']) ?></td></tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header">Payment Summary</div>
          <div class="card-body">
            <table class="table table-sm mb-3">
              <tr><td>Total Due</td><td class="text-end fw-semibold"><?= format_money($invoice['total_amount']) ?></td></tr>
              <tr><td>Amount Paid</td><td class="text-end fw-semibold text-success"><?= format_money($invoice['amount_paid']) ?></td></tr>
              <tr><td>Balance</td><td class="text-end fw-semibold text-danger"><?= format_money($invoice['balance']) ?></td></tr>
            </table>
            <h6 class="text-muted small text-uppercase">Payment History</h6>
            <ul class="list-group list-group-flush small">
              <?php foreach ($invoicePayments as $p): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <span><?= format_date($p['payment_date']) ?> &middot; <?= e(str_replace('_', ' ', ucfirst($p['payment_method']))) ?></span>
                  <span class="fw-semibold"><?= format_money($p['amount']) ?></span>
                </li>
              <?php endforeach; ?>
              <?php if (empty($invoicePayments)): ?><li class="list-group-item text-muted">No payments recorded yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ---- Listing view ----
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT i.*, u.first_name, u.last_name, s.admission_no, t.term_name FROM invoices i
        JOIN students s ON s.student_id = i.student_id
        JOIN users u ON u.user_id = s.user_id
        JOIN terms t ON t.term_id = i.term_id WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3 OR i.invoice_no LIKE :s4)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
}
if ($statusFilter !== '') {
    $sql .= ' AND i.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY i.created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$pageTitle = 'Invoices';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Invoices</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-5"><input type="text" name="q" class="form-control" placeholder="Search student, admission no., or invoice no." value="<?= e($search) ?>"></div>
      <div class="col-md-3">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['unpaid','partial','paid','overdue','waived'] as $st): ?>
            <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="fa fa-search"></i></button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Invoice No.</th><th>Student</th><th>Term</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><code><?= e($inv['invoice_no']) ?></code></td>
            <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?> <span class="text-muted small">(<?= e($inv['admission_no']) ?>)</span></td>
            <td><?= e($inv['term_name']) ?></td>
            <td><?= format_money($inv['total_amount']) ?></td>
            <td class="text-success"><?= format_money($inv['amount_paid']) ?></td>
            <td class="text-danger"><?= format_money($inv['balance']) ?></td>
            <td><span class="badge badge-status-<?= e($inv['status']) ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
            <td><a href="?id=<?= (int) $inv['invoice_id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?><tr><td colspan="8" class="text-center text-muted py-4">No invoices found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
