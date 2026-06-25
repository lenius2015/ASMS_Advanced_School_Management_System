<?php
/**
 * bursar/dashboard.php
 * Bursar's operational finance dashboard: collection status, overdue
 * invoices, payment trends, and budget tracking with interactive charts.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

// ====== Finance KPIs ======
$financeStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0) AS billed, COALESCE(SUM(amount_paid),0) AS collected, COALESCE(SUM(balance),0) AS outstanding,
            SUM(status='overdue') AS overdue_count, SUM(status='unpaid') AS unpaid_count,
            SUM(status='partial') AS partial_count, SUM(status='paid') AS paid_count
     FROM invoices WHERE term_id = :term"
);
$financeStmt->execute(['term' => $period['term_id']]);
$finance = $financeStmt->fetch();

$collectionRate = $finance['billed'] > 0 ? round(($finance['collected'] / $finance['billed']) * 100, 1) : 0;
$totalInvoices = (int) $finance['paid_count'] + (int) $finance['unpaid_count'] + (int) $finance['partial_count'] + (int) $finance['overdue_count'];

// ====== Monthly collection trend (last 6 months) ======
$monthlyTrend = $pdo->query(
    "SELECT DATE_FORMAT(p.created_at, '%b') AS month,
            COALESCE(SUM(p.amount),0) AS collected
     FROM payments p
     WHERE p.created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY DATE_FORMAT(p.created_at, '%Y-%m'), DATE_FORMAT(p.created_at, '%b')
     ORDER BY MIN(p.created_at)"
)->fetchAll();
$trendMonths = [];
$trendCollected = [];
foreach ($monthlyTrend as $mt) {
    $trendMonths[] = $mt['month'];
    $trendCollected[] = (float) $mt['collected'];
}

// ====== Invoice status distribution ======
$invStatusLabels = ['Paid', 'Partial', 'Unpaid', 'Overdue'];
$invStatusData = [
    (int) $finance['paid_count'],
    (int) $finance['partial_count'],
    (int) $finance['unpaid_count'],
    (int) $finance['overdue_count']
];
$invStatusColors = ['#1F8A55', '#C8932A', '#334E68', '#C23B3B'];

// ====== Recent payments ======
$recentPayments = $pdo->query(
    "SELECT p.*, u.first_name, u.last_name, s.admission_no FROM payments p
     JOIN students s ON s.student_id = p.student_id
     JOIN users u ON u.user_id = s.user_id
     ORDER BY p.created_at DESC LIMIT 8"
)->fetchAll();

// ====== Overdue invoices ======
$overdueInvoices = $pdo->query(
    "SELECT i.*, u.first_name, u.last_name, s.admission_no FROM invoices i
     JOIN students s ON s.student_id = i.student_id
     JOIN users u ON u.user_id = s.user_id
     WHERE i.status IN ('overdue','unpaid','partial') AND i.due_date < CURDATE()
     ORDER BY i.due_date ASC LIMIT 8"
)->fetchAll();

// ====== Total students with invoices ======
$totalStudentsBilled = $pdo->prepare(
    "SELECT COUNT(DISTINCT student_id) AS c FROM invoices WHERE term_id = :term"
);
$totalStudentsBilled->execute(['term' => $period['term_id']]);
$billedStudents = (int) $totalStudentsBilled->fetch()['c'];

// ====== Payroll summary ======
$payrollStmt = $pdo->query(
    "SELECT COALESCE(SUM(basic_salary),0) AS total_payroll FROM staff WHERE status='active'"
);
$payrollTotal = (float) $payrollStmt->fetch()['total_payroll'];

$pageTitle = 'Finance Dashboard';
require APP_ROOT . '/includes/header.php';
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Finance Dashboard <span class="badge bg-gold ms-2">Bursar</span></h1>
      <p class="mb-0">Fee collection & financial overview &middot; <?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="btn btn-gold"><i class="fa fa-money-bill-wave me-1"></i> Record Payment</a>
      <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="btn btn-outline-light"><i class="fa fa-chart-line me-1"></i> Reports</a>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search..." data-search="#financeTables" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> Finance Overview</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-list-ol me-1"></i> Fee Structures</a>
    <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-file-invoice me-1"></i> Invoices</a>
    <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-money-bill-wave me-1"></i> Record Payment</a>
    <a href="<?= e(app_url('/bursar/payroll.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-wallet me-1"></i> Payroll</a>
    <a href="<?= e(app_url('/bursar/budget.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-piggy-bank me-1"></i> Budget</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-file-invoice kpi-icon"></i>
      <div class="kpi-label">Total Billed</div>
      <div class="kpi-value" style="font-size:1.3rem;"><?= format_money($finance['billed']) ?></div>
      <div class="kpi-sub"><?= $billedStudents ?> students billed</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Collected (<?= e($collectionRate) ?>%)</div>
      <div class="kpi-value" style="font-size:1.3rem;"><?= format_money($finance['collected']) ?></div>
      <div class="kpi-sub"><?= (int) $finance['paid_count'] ?> invoices paid in full</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-red">
      <i class="fa fa-exclamation-triangle kpi-icon"></i>
      <div class="kpi-label">Outstanding</div>
      <div class="kpi-value" style="font-size:1.3rem;"><?= format_money($finance['outstanding']) ?></div>
      <div class="kpi-sub"><?= (int) $finance['overdue_count'] ?> overdue invoices</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-wallet kpi-icon"></i>
      <div class="kpi-label">Monthly Payroll</div>
      <div class="kpi-value" style="font-size:1.3rem;"><?= format_money($payrollTotal) ?></div>
      <div class="kpi-sub">Active staff salaries</div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-7 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-chart-line text-gold me-2"></i>Collection Trend (Last 6 Months)</div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="trendChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-pie-chart text-gold me-2"></i>Invoice Status Distribution</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div class="chart-container-sm" style="width:220px;height:220px;">
          <canvas id="statusChart"></canvas>
        </div>
      </div>
      <div class="card-footer bg-white text-center">
        <?php foreach ($invStatusLabels as $i => $label): ?>
          <span class="badge" style="background:<?= $invStatusColors[$i] ?>"><?= $label ?>: <?= $invStatusData[$i] ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-exclamation-triangle text-gold me-2"></i>Overdue / Outstanding Invoices</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Student</th><th>Invoice</th><th>Balance</th><th>Due Date</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($overdueInvoices as $inv): ?>
              <tr>
                <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?> <span class="text-muted small">(<?= e($inv['admission_no']) ?>)</span></td>
                <td><code><?= e($inv['invoice_no']) ?></code></td>
                <td class="text-danger fw-semibold"><?= format_money($inv['balance']) ?></td>
                <td class="small"><?= format_date($inv['due_date']) ?></td>
                <td><span class="badge bg-<?= $inv['status'] === 'overdue' ? 'danger' : ($inv['status'] === 'partial' ? 'warning' : 'secondary') ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
                <td><a href="<?= e(app_url('/bursar/invoices.php')) ?>?id=<?= (int) $inv['invoice_id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($overdueInvoices)): ?><tr><td colspan="6" class="text-center text-muted py-4">No overdue invoices. Well done!</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white">
        <a href="<?= e(app_url('/bursar/fee_reminders.php')) ?>" class="small">Send fee reminders &rarr;</a>
      </div>
    </div>
  </div>

  <div class="col-lg-5 animate-fade-in animate-delay-4">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-receipt text-gold me-2"></i>Recent Payments</div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($recentPayments as $p): ?>
            <li class="activity-item">
              <div class="activity-icon icon-success"><i class="fa fa-check"></i></div>
              <div class="activity-content">
                <div class="activity-title"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
                <div class="activity-text"><?= e($p['admission_no']) ?></div>
              </div>
              <div class="activity-time fw-semibold text-success"><?= format_money($p['amount']) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($recentPayments)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No payments recorded yet.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="card-footer bg-white">
        <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="small">View all invoices &rarr;</a>
      </div>
    </div>
  </div>
</div>

<!-- Quick Actions -->
<div class="row animate-fade-in animate-delay-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-bolt text-gold me-2"></i>Quick Actions</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="quick-action-item"><i class="fa fa-list-ol"></i><span>Fee Structures</span></a>
          <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="quick-action-item"><i class="fa fa-file-invoice"></i><span>Invoices</span></a>
          <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="quick-action-item"><i class="fa fa-money-bill-wave"></i><span>Record Payment</span></a>
          <a href="<?= e(app_url('/bursar/fee_reminders.php')) ?>" class="quick-action-item"><i class="fa fa-bell"></i><span>Fee Reminders</span></a>
          <a href="<?= e(app_url('/bursar/payroll.php')) ?>" class="quick-action-item"><i class="fa fa-wallet"></i><span>Payroll</span></a>
          <a href="<?= e(app_url('/bursar/budget.php')) ?>" class="quick-action-item"><i class="fa fa-piggy-bank"></i><span>Budget</span></a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Collection Trend Chart
  var trendCtx = document.getElementById('trendChart');
  if (trendCtx) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: <?= json_encode($trendMonths) ?>,
        datasets: [{
          label: 'Collected',
          data: <?= json_encode($trendCollected) ?>,
          borderColor: '#1F8A55',
          backgroundColor: 'rgba(31,138,85,0.1)',
          fill: true,
          tension: 0.35,
          pointBackgroundColor: '#1F8A55',
          pointRadius: 5,
          pointHoverRadius: 7
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12, callbacks: { label: function(ctx) { return 'TZS ' + Number(ctx.raw).toLocaleString(); } } }
        },
        scales: {
          y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 }, callback: function(v) { return 'TZS ' + (v/1000).toFixed(0) + 'k'; } } },
          x: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
      }
    });
  }

  // Invoice Status Chart
  var statusCtx = document.getElementById('statusChart');
  if (statusCtx) {
    new Chart(statusCtx, {
      type: 'doughnut',
      data: {
        labels: <?= json_encode($invStatusLabels) ?>,
        datasets: [{ data: <?= json_encode($invStatusData) ?>, backgroundColor: <?= json_encode($invStatusColors) ?>, borderWidth: 0, hoverOffset: 8 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '65%',
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, font: { size: 10 } } },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12 }
        }
      }
    });
  }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>