<?php
/**
 * bursar/reports.php
 * Financial reports: collections within a date range, breakdown by
 * payment method, and outstanding balances by class.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();

$fromDate = $_GET['from'] ?? (new DateTime())->modify('-30 days')->format('Y-m-d');
$toDate = $_GET['to'] ?? date('Y-m-d');

$collectionsStmt = $pdo->prepare(
    "SELECT payment_method, COUNT(*) AS txn_count, SUM(amount) AS total
     FROM payments WHERE payment_date BETWEEN :from AND :to GROUP BY payment_method ORDER BY total DESC"
);
$collectionsStmt->execute(['from' => $fromDate, 'to' => $toDate]);
$byMethod = $collectionsStmt->fetchAll();
$totalCollected = array_sum(array_column($byMethod, 'total'));

$outstandingByClass = $pdo->query(
    "SELECT cl.level_name, c.stream_name, SUM(i.balance) AS outstanding
     FROM invoices i
     JOIN students s ON s.student_id = i.student_id
     JOIN classes c ON c.class_id = s.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE i.balance > 0
     GROUP BY c.class_id ORDER BY outstanding DESC"
)->fetchAll();

$pageTitle = 'Financial Reports';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Financial Reports</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($fromDate) ?>"></div>
      <div class="col-md-3"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($toDate) ?>"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100">Apply</button></div>
    </form>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Collections by Payment Method (<?= e(format_date($fromDate)) ?> &ndash; <?= e(format_date($toDate)) ?>)</div>
      <div class="card-body">
        <canvas id="methodChart" height="220"></canvas>
        <table class="table table-sm mt-3 mb-0">
          <thead><tr><th>Method</th><th>Transactions</th><th>Total</th></tr></thead>
          <tbody>
            <?php foreach ($byMethod as $m): ?>
              <tr><td><?= e(str_replace('_',' ',ucfirst($m['payment_method']))) ?></td><td><?= (int) $m['txn_count'] ?></td><td><?= format_money($m['total']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="fw-bold"><td colspan="2">Total</td><td><?= format_money($totalCollected) ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Outstanding Balances by Class</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Class</th><th>Outstanding</th></tr></thead>
          <tbody>
            <?php foreach ($outstandingByClass as $o): ?>
              <tr><td><?= e($o['level_name'] . ' ' . $o['stream_name']) ?></td><td class="text-danger fw-semibold"><?= format_money($o['outstanding']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($outstandingByClass)): ?><tr><td colspan="2" class="text-center text-muted py-3">No outstanding balances. Excellent!</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('methodChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($m) => str_replace('_',' ',ucfirst($m['payment_method'])), $byMethod)) ?>,
    datasets: [{ label: 'Total Collected', data: <?= json_encode(array_map('floatval', array_column($byMethod, 'total'))) ?>, backgroundColor: '#1F8A55' }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
