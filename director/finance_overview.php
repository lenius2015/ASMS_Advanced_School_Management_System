<?php
/**
 * director/finance_overview.php
 * Director's read access to financial summaries across fee collection,
 * payroll, and budget vs expenses — without bursar's transactional tools.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'school_board']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

$financeStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0) AS billed, COALESCE(SUM(amount_paid),0) AS collected, COALESCE(SUM(balance),0) AS outstanding
     FROM invoices WHERE term_id = :term"
);
$financeStmt->execute(['term' => $period['term_id']]);
$finance = $financeStmt->fetch();

$byCategory = $pdo->prepare(
    "SELECT fc.category_name, COALESCE(SUM(ii.amount),0) AS total
     FROM invoice_items ii
     JOIN fee_categories fc ON fc.fee_category_id = ii.fee_category_id
     JOIN invoices i ON i.invoice_id = ii.invoice_id
     WHERE i.term_id = :term
     GROUP BY fc.category_name ORDER BY total DESC"
);
$byCategory->execute(['term' => $period['term_id']]);
$categoryRows = $byCategory->fetchAll();

$monthlyCollections = $pdo->query(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount) AS total
     FROM payments
     WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY month ORDER BY month"
)->fetchAll();

$payrollTotal = $pdo->query(
    "SELECT COALESCE(SUM(net_pay),0) AS total FROM payslips ps
     JOIN payroll_runs pr ON pr.payroll_run_id = ps.payroll_run_id
     WHERE pr.pay_period_month = MONTH(CURDATE()) AND pr.pay_period_year = YEAR(CURDATE())"
)->fetch()['total'];

$budgetVsExpense = $pdo->query(
    "SELECT d.department_name, COALESCE(SUM(b.allocated_amount),0) AS allocated, COALESCE(exp.spent,0) AS spent
     FROM departments d
     LEFT JOIN budgets b ON b.department_id = d.department_id
     LEFT JOIN (
        SELECT department_id, SUM(amount) AS spent FROM expenses WHERE status IN ('approved','paid') GROUP BY department_id
     ) exp ON exp.department_id = d.department_id
     GROUP BY d.department_id"
)->fetchAll();

$pageTitle = 'Financial Overview';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Financial Overview</h1>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card accent-navy"><div class="kpi-label">Total Billed (Term)</div><div class="kpi-value"><?= format_money($finance['billed']) ?></div></div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card accent-green"><div class="kpi-label">Collected</div><div class="kpi-value"><?= format_money($finance['collected']) ?></div></div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card accent-red"><div class="kpi-label">Outstanding</div><div class="kpi-value"><?= format_money($finance['outstanding']) ?></div></div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card"><div class="kpi-label">Payroll (This Month)</div><div class="kpi-value"><?= format_money($payrollTotal) ?></div></div>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Collections by Fee Category (Current Term)</div>
      <div class="card-body"><canvas id="categoryChart" height="220"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Monthly Collections Trend</div>
      <div class="card-body"><canvas id="trendChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Budget vs. Expenditure by Department</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Department</th><th>Allocated</th><th>Spent</th><th>Remaining</th><th>Utilization</th></tr></thead>
      <tbody>
        <?php foreach ($budgetVsExpense as $b): $remaining = $b['allocated'] - $b['spent']; $pct = $b['allocated'] > 0 ? round(($b['spent']/$b['allocated'])*100,1) : 0; ?>
          <tr>
            <td><?= e($b['department_name']) ?></td>
            <td><?= format_money($b['allocated']) ?></td>
            <td><?= format_money($b['spent']) ?></td>
            <td class="<?= $remaining < 0 ? 'text-danger' : '' ?>"><?= format_money($remaining) ?></td>
            <td style="width:160px;">
              <div class="progress" style="height:18px;">
                <div class="progress-bar <?= $pct > 90 ? 'bg-danger' : 'bg-success' ?>" style="width:<?= min($pct,100) ?>%"><?= e($pct) ?>%</div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const categoryLabels = <?= json_encode(array_column($categoryRows, 'category_name')) ?>;
const categoryData = <?= json_encode(array_map('floatval', array_column($categoryRows, 'total'))) ?>;
new Chart(document.getElementById('categoryChart'), {
  type: 'doughnut',
  data: { labels: categoryLabels, datasets: [{ data: categoryData, backgroundColor: ['#102A43','#C8932A','#1F8A55','#334E68','#C23B3B','#4C7DA8'] }] },
  options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
});

const trendLabels = <?= json_encode(array_column($monthlyCollections, 'month')) ?>;
const trendData = <?= json_encode(array_map('floatval', array_column($monthlyCollections, 'total'))) ?>;
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: { labels: trendLabels, datasets: [{ label: 'Collections (TZS)', data: trendData, borderColor: '#C8932A', backgroundColor: 'rgba(200,147,42,0.15)', fill: true, tension: 0.3 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
