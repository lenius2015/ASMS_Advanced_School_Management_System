<?php
/**
 * bursar/dashboard.php
 * Enhanced Bursar Dashboard with comprehensive KPIs, charts,
 * and fee management overview.
 * 
 * KPIs:
 * - Total Expected Revenue
 * - Total Collected
 * - Total Outstanding Balance
 * - Today's Collections
 * - This Month Collections
 * - Overdue Payments
 * 
 * Charts:
 * - Monthly Revenue Chart (12 months)
 * - Collection Trend Chart (6 months)
 * - Outstanding Balance by Class
 * - Invoice Status Distribution
 * 
 * Tables:
 * - Recent Payments
 * - Overdue Invoices
 * - Top Defaulters
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
$period = get_current_period($pdo);
$termId = $period['term_id'];

// Load fee functions
require_once __DIR__ . '/../includes/fee_functions.php';

// ====== KPI Data ======
$kpis = get_bursar_dashboard_kpis($pdo, $termId);

// ====== Monthly Collection Trend (12 months) ======
$yearlyTrend = get_monthly_collection_trend($pdo, 12);

// ====== 6-Month Trend for chart ======
$sixMonthTrend = [
    'months'    => array_slice($yearlyTrend['months'], -6),
    'collected' => array_slice($yearlyTrend['collected'], -6),
    'counts'    => array_slice($yearlyTrend['counts'], -6),
];

// ====== Outstanding by Class ======
$outstandingByClass = get_outstanding_by_class($pdo, $termId);
$outClassLabels = [];
$outClassData = [];
foreach ($outstandingByClass as $oc) {
    $outClassLabels[] = $oc['level_name'];
    $outClassData[] = (float) $oc['outstanding'];
}

// ====== Invoice Status Distribution ======
$invStatusLabels = ['Paid', 'Partial', 'Pending', 'Overdue', 'Cancelled'];
$invStatusData = [
    $kpis['paid_count'],
    $kpis['partial_count'],
    $kpis['pending_count'],
    $kpis['overdue_count'],
    $kpis['cancelled_count'],
];
$invStatusColors = ['#1F8A55', '#C8932A', '#334E68', '#C23B3B', '#6B7280'];

// ====== Recent Payments ======
$recentPayments = $pdo->query(
    "SELECT p.*, u.first_name, u.last_name, s.admission_no, s.student_id 
     FROM payments p
     JOIN students s ON s.student_id = p.student_id
     JOIN users u ON u.user_id = s.user_id
     ORDER BY p.created_at DESC LIMIT 10"
)->fetchAll();

// ====== Overdue Invoices ======
$overdueInvoices = $pdo->prepare(
    "SELECT i.*, u.first_name, u.last_name, s.admission_no, s.student_id,
            DATEDIFF(CURDATE(), i.due_date) AS days_overdue
     FROM invoices i
     JOIN students s ON s.student_id = i.student_id
     JOIN users u ON u.user_id = s.user_id
     WHERE i.status = 'overdue' AND i.term_id = :term
     ORDER BY i.due_date ASC LIMIT 10"
);
$overdueInvoices->execute(['term' => $termId]);
$overdueList = $overdueInvoices->fetchAll();

// ====== Top Defaulters ======
$topDefaulters = get_top_defaulters($pdo, $termId, 8);

// ====== Total Students ======
$totalStudents = $pdo->query("SELECT COUNT(*) AS c FROM students WHERE status = 'active'")->fetch()['c'];

$pageTitle = 'Bursar Dashboard';
require APP_ROOT . '/includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card { break-inside: avoid; }
}
</style>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Bursar Dashboard <span class="badge bg-gold ms-2">Finance</span></h1>
      <p class="mb-0">Fee collection & financial overview &middot; <?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2 no-print">
      <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="btn btn-gold"><i class="fa fa-money-bill-wave me-1"></i> Record Payment</a>
      <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="btn btn-outline-light"><i class="fa fa-chart-line me-1"></i> Reports</a>
      <button class="btn btn-outline-light" onclick="window.print()"><i class="fa fa-print"></i></button>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1 no-print">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search students..." data-search=".data-table" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> Term: Current</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-list-ol me-1"></i> Fee Structures</a>
    <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-file-invoice me-1"></i> Invoices</a>
    <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-money-bill-wave me-1"></i> Record Payment</a>
    <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-file-alt me-1"></i> Reports</a>
  </div>
</div>

<!-- KPI Cards Row 1 -->
<div class="row g-3 mb-4">
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-file-invoice kpi-icon"></i>
      <div class="kpi-label">Expected Revenue</div>
      <div class="kpi-value"><?= format_money($kpis['total_expected']) ?></div>
      <div class="kpi-sub"><?= $kpis['billed_students'] ?> students billed</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Total Collected</div>
      <div class="kpi-value"><?= format_money($kpis['total_collected']) ?></div>
      <div class="kpi-sub"><?= $kpis['collection_rate'] ?>% collection rate</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-red">
      <i class="fa fa-exclamation-triangle kpi-icon"></i>
      <div class="kpi-label">Outstanding</div>
      <div class="kpi-value"><?= format_money($kpis['total_outstanding']) ?></div>
      <div class="kpi-sub"><?= $kpis['overdue_count'] ?> overdue invoices</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-calendar-day kpi-icon"></i>
      <div class="kpi-label">Today's Collections</div>
      <div class="kpi-value"><?= format_money($kpis['today_collected']) ?></div>
      <div class="kpi-sub"><?= $kpis['today_count'] ?> payments today</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-5">
    <div class="asms-kpi-card accent-gold">
      <i class="fa fa-calendar-alt kpi-icon"></i>
      <div class="kpi-label">This Month</div>
      <div class="kpi-value"><?= format_money($kpis['month_collected']) ?></div>
      <div class="kpi-sub"><?= $kpis['month_count'] ?> payments</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-6">
    <div class="asms-kpi-card accent-purple">
      <i class="fa fa-clock kpi-icon"></i>
      <div class="kpi-label">Overdue Amount</div>
      <div class="kpi-value"><?= format_money($kpis['overdue_amount']) ?></div>
      <div class="kpi-sub"><?= $kpis['overdue_inv_count'] ?> invoices overdue</div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-8 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-chart-line text-gold me-2"></i>Monthly Revenue Trend (12 Months)</span>
        <span class="badge bg-gold"><?= format_money(array_sum($yearlyTrend['collected'])) ?> Total</span>
      </div>
      <div class="card-body">
        <div class="chart-container" style="height:300px;">
          <canvas id="revenueChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-pie-chart text-gold me-2"></i>Invoice Status</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div class="chart-container-sm" style="width:220px;height:220px;">
          <canvas id="statusChart"></canvas>
        </div>
      </div>
      <div class="card-footer bg-white text-center small">
        <?php foreach ($invStatusLabels as $i => $label): ?>
          <span class="badge me-1" style="background:<?= $invStatusColors[$i] ?>"><?= $label ?>: <?= $invStatusData[$i] ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-chart-bar text-gold me-2"></i>Outstanding Balance by Class</span>
      </div>
      <div class="card-body">
        <div class="chart-container" style="height:250px;">
          <canvas id="outstandingByClassChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-6 animate-fade-in animate-delay-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-exclamation-triangle text-gold me-2"></i>Top Defaulters</span>
        <a href="<?= e(app_url('/bursar/invoices.php')) ?>?status=overdue" class="small">View All &rarr;</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
          <thead><tr><th>Student</th><th>Class</th><th>Balance</th><th>Invoices</th></tr></thead>
          <tbody>
            <?php foreach ($topDefaulters as $d): ?>
              <tr>
                <td><?= e($d['first_name'] . ' ' . $d['last_name']) ?> <span class="text-muted small">(<?= e($d['admission_no']) ?>)</span></td>
                <td><?= e($d['level_name'] . ' ' . $d['stream_name']) ?></td>
                <td class="text-danger fw-semibold"><?= format_money($d['total_balance']) ?></td>
                <td><?= (int) $d['invoice_count'] ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($topDefaulters)): ?><tr><td colspan="4" class="text-center text-muted py-4">No defaulters! All fees collected.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7 animate-fade-in animate-delay-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-exclamation-triangle text-gold me-2"></i>Overdue Invoices</span>
        <span class="badge bg-danger"><?= $kpis['overdue_count'] ?> overdue</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 data-table">
          <thead><tr><th>Student</th><th>Invoice</th><th>Control No.</th><th>Balance</th><th>Due Date</th><th>Overdue</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($overdueList as $inv): ?>
              <tr>
                <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?> <span class="text-muted small">(<?= e($inv['admission_no']) ?>)</span></td>
                <td><code><?= e($inv['invoice_no']) ?></code></td>
                <td><code class="small"><?= e($inv['control_number'] ?: '-') ?></code></td>
                <td class="text-danger fw-semibold"><?= format_money($inv['balance']) ?></td>
                <td class="small"><?= format_date($inv['due_date']) ?></td>
                <td><span class="badge bg-danger"><?= (int) $inv['days_overdue'] ?> days</span></td>
                <td><a href="<?= e(app_url('/bursar/invoices.php')) ?>?id=<?= (int) $inv['invoice_id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($overdueList)): ?><tr><td colspan="7" class="text-center text-muted py-4">No overdue invoices. Well done!</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white no-print">
        <a href="<?= e(app_url('/bursar/fee_reminders.php')) ?>" class="small"><i class="fa fa-bell me-1"></i>Send fee reminders &rarr;</a>
      </div>
    </div>
  </div>
  <div class="col-lg-5 animate-fade-in animate-delay-5">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-receipt text-gold me-2"></i>Recent Payments</div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($recentPayments as $p): ?>
            <li class="activity-item">
              <div class="activity-icon icon-success"><i class="fa fa-check"></i></div>
              <div class="activity-content">
                <div class="activity-title"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
                <div class="activity-text small"><?= e($p['admission_no']) ?> &middot; <?= e($p['recorded_by_name'] ?: 'Bursar') ?></div>
              </div>
              <div class="activity-time fw-semibold text-success"><?= format_money($p['amount']) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($recentPayments)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No payments recorded yet.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="card-footer bg-white no-print">
        <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="small">View all invoices &rarr;</a>
      </div>
    </div>
  </div>
</div>

<!-- Quick Actions -->
<div class="row animate-fade-in animate-delay-5 no-print">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-bolt text-gold me-2"></i>Quick Actions</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="quick-action-item"><i class="fa fa-list-ol"></i><span>Fee Structures</span></a>
          <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="quick-action-item"><i class="fa fa-file-invoice"></i><span>Invoices</span></a>
          <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="quick-action-item"><i class="fa fa-money-bill-wave"></i><span>Record Payment</span></a>
          <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="quick-action-item"><i class="fa fa-chart-pie"></i><span>Reports</span></a>
          <a href="<?= e(app_url('/bursar/fee_reminders.php')) ?>" class="quick-action-item"><i class="fa fa-bell"></i><span>Fee Reminders</span></a>
          <a href="<?= e(app_url('/director/finance_overview.php')) ?>" class="quick-action-item"><i class="fa fa-chart-line"></i><span>Finance Overview</span></a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const fmt = (v) => 'TZS ' + Number(v).toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});

  // 1. Revenue Trend Chart (12 months bar + line)
  var revCtx = document.getElementById('revenueChart');
  if (revCtx) {
    new Chart(revCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($yearlyTrend['months']) ?>,
        datasets: [
          {
            label: 'Collected',
            data: <?= json_encode($yearlyTrend['collected']) ?>,
            backgroundColor: 'rgba(31,138,85,0.7)',
            borderColor: '#1F8A55',
            borderWidth: 1,
            borderRadius: 4,
            order: 2
          },
          {
            label: 'Transactions',
            data: <?= json_encode($yearlyTrend['counts']) ?>,
            type: 'line',
            borderColor: '#C8932A',
            backgroundColor: 'rgba(200,147,42,0.1)',
            fill: true,
            tension: 0.35,
            pointBackgroundColor: '#C8932A',
            pointRadius: 4,
            pointHoverRadius: 6,
            yAxisID: 'y1',
            order: 1
          }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 } } },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12,
            callbacks: {
              label: function(ctx) {
                if (ctx.dataset.label === 'Collected') return fmt(ctx.raw);
                return ctx.raw + ' payments';
              }
            }
          }
        },
        scales: {
          y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 10 }, callback: v => fmt(v) } },
          y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { font: { size: 10 } } },
          x: { grid: { display: false }, ticks: { font: { size: 10 } } }
        }
      }
    });
  }

  // 2. Invoice Status Chart (doughnut)
  var statusCtx = document.getElementById('statusChart');
  if (statusCtx) {
    new Chart(statusCtx, {
      type: 'doughnut',
      data: {
        labels: <?= json_encode($invStatusLabels) ?>,
        datasets: [{ data: <?= json_encode($invStatusData) ?>, backgroundColor: <?= json_encode($invStatusColors) ?>, borderWidth: 0, hoverOffset: 10 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '65%',
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, padding: 10, font: { size: 10 } } },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12,
            callbacks: { label: ctx => ctx.label + ': ' + ctx.raw + ' invoices' }
          }
        }
      }
    });
  }

  // 3. Outstanding by Class Chart (horizontal bar)
  var outCtx = document.getElementById('outstandingByClassChart');
  if (outCtx) {
    new Chart(outCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($outClassLabels) ?>,
        datasets: [{
          label: 'Outstanding',
          data: <?= json_encode($outClassData) ?>,
          backgroundColor: [
            'rgba(194,59,59,0.7)', 'rgba(200,147,42,0.7)',
            'rgba(51,78,104,0.7)', 'rgba(31,138,85,0.7)',
            'rgba(107,114,128,0.7)', 'rgba(99,102,241,0.7)'
          ],
          borderColor: ['#C23B3B', '#C8932A', '#334E68', '#1F8A55', '#6B7280', '#6366F1'],
          borderWidth: 1,
          borderRadius: 4
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12, callbacks: { label: ctx => fmt(ctx.raw) } }
        },
        scales: {
          x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 10 }, callback: v => fmt(v) } },
          y: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
      }
    });
  }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>