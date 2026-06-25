<?php
/**
 * director/finance_overview.php
 * Director-level financial overview with cross-year comparisons
 * and comprehensive reporting access.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school']);
require_once __DIR__ . '/../includes/fee_functions.php';

$pdo = get_db_connection();
$period = get_current_period($pdo);

// Get all academic years for selection
$years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll();
$selectedYearId = (int) ($_GET['year_id'] ?? $period['year_id']);
$selectedTermId = (int) ($_GET['term_id'] ?? $period['term_id']);

// Get KPIs for selected term
$kpis = get_bursar_dashboard_kpis($pdo, $selectedTermId);
$yearlyTrend = get_monthly_collection_trend($pdo, 12);
$outstandingByClass = get_outstanding_by_class($pdo, $selectedTermId);

// City/region breakdowns
$classLevels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();

// Get year-over-year comparison
$yearComparison = [];
foreach ($years as $y) {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(i.total_amount),0) AS total_billed,
                COALESCE(SUM(i.amount_paid),0) AS total_collected,
                COALESCE(SUM(i.balance),0) AS total_outstanding
         FROM invoices i
         JOIN terms t ON t.term_id = i.term_id
         WHERE t.year_id = :year"
    );
    $stmt->execute(['year' => $y['year_id']]);
    $yearComparison[$y['year_name']] = $stmt->fetch();
}

$pageTitle = 'Finance Overview';
require APP_ROOT . '/includes/header.php';
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Finance Overview <span class="badge bg-gold ms-2">Executive</span></h1>
      <p class="mb-0">Complete financial oversight &middot; <?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="btn btn-outline-light btn-sm"><i class="fa fa-file-alt me-1"></i> Reports</a>
      <button class="btn btn-outline-light btn-sm" onclick="window.print()"><i class="fa fa-print"></i></button>
    </div>
  </div>
</div>

<!-- Period Selector -->
<div class="card mb-4 animate-fade-in animate-delay-1">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-auto"><label class="form-label mb-0 fw-semibold">Period:</label></div>
      <div class="col-md-3">
        <select name="year_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int) $y['year_id'] ?>" <?= $selectedYearId === (int) $y['year_id'] ? 'selected' : '' ?>>
              <?= e($y['year_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="term_id" class="form-select" onchange="this.form.submit()">
          <?php 
          $terms = $pdo->prepare("SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id WHERE t.year_id = :yid ORDER BY t.start_date DESC");
          $terms->execute(['yid' => $selectedYearId]);
          foreach ($terms as $t): ?>
            <option value="<?= (int) $t['term_id'] ?>" <?= $selectedTermId === (int) $t['term_id'] ? 'selected' : '' ?>>
              <?= e($t['year_name'] . ' - ' . $t['term_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<!-- Executive KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-file-invoice kpi-icon"></i>
      <div class="kpi-label">Total Billed</div>
      <div class="kpi-value"><?= format_money($kpis['total_expected']) ?></div>
      <div class="kpi-sub"><?= $kpis['billed_students'] ?> students</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-check-circle kpi-icon"></i>
      <div class="kpi-label">Collected</div>
      <div class="kpi-value"><?= format_money($kpis['total_collected']) ?></div>
      <div class="kpi-sub"><?= $kpis['collection_rate'] ?>% rate</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-red">
      <i class="fa fa-exclamation-triangle kpi-icon"></i>
      <div class="kpi-label">Outstanding</div>
      <div class="kpi-value"><?= format_money($kpis['total_outstanding']) ?></div>
      <div class="kpi-sub"><?= $kpis['overdue_count'] ?> overdue</div>
    </div>
  </div>
  <div class="col-xl-2 col-md-4 col-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-calendar-day kpi-icon"></i>
      <div class="kpi-label">Today</div>
      <div class="kpi-value"><?= format_money($kpis['today_collected']) ?></div>
      <div class="kpi-sub"><?= $kpis['today_count'] ?> payments</div>
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
      <div class="kpi-label">Overdue</div>
      <div class="kpi-value"><?= format_money($kpis['overdue_amount']) ?></div>
      <div class="kpi-sub"><?= $kpis['overdue_inv_count'] ?> invoices</div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-lg-8 animate-fade-in animate-delay-2">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-chart-line text-gold me-2"></i>Revenue Trend (12 Months)</div>
      <div class="card-body">
        <div class="chart-container" style="height:300px;">
          <canvas id="revenueChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4 animate-fade-in animate-delay-3">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-chart-bar text-gold me-2"></i>Outstanding by Class</div>
      <div class="card-body">
        <div class="chart-container" style="height:300px;">
          <canvas id="outstandingChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Year-over-Year Comparison -->
<div class="card mb-4 animate-fade-in animate-delay-4">
  <div class="card-header"><i class="fa fa-chart-simple text-gold me-2"></i>Year-over-Year Comparison</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Year</th><th>Total Billed</th><th>Total Collected</th><th>Outstanding</th><th>Collection Rate</th></tr></thead>
      <tbody>
        <?php foreach ($yearComparison as $yearName => $data): 
          $billed = (float) $data['total_billed'];
          $collected = (float) $data['total_collected'];
          $outstanding = (float) $data['total_outstanding'];
          $rate = $billed > 0 ? round(($collected / $billed) * 100, 1) : 0;
        ?>
          <tr>
            <td class="fw-semibold"><?= e($yearName) ?></td>
            <td><?= format_money($billed) ?></td>
            <td class="text-success"><?= format_money($collected) ?></td>
            <td class="text-danger"><?= format_money($outstanding) ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:8px;">
                  <div class="progress-bar bg-success" style="width:<?= $rate ?>%"></div>
                </div>
                <span class="small fw-semibold"><?= $rate ?>%</span>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Invoice Status Summary -->
<div class="card animate-fade-in animate-delay-5">
  <div class="card-header"><i class="fa fa-list text-gold me-2"></i>Invoice Status Summary</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3 col-6">
        <div class="text-center p-3 bg-success bg-opacity-10 rounded">
          <div class="display-6 text-success"><?= $kpis['paid_count'] ?></div>
          <div class="small text-muted">Paid</div>
        </div>
      </div>
      <div class="col-md-3 col-6">
        <div class="text-center p-3 bg-warning bg-opacity-10 rounded">
          <div class="display-6 text-warning"><?= $kpis['partial_count'] ?></div>
          <div class="small text-muted">Partial</div>
        </div>
      </div>
      <div class="col-md-3 col-6">
        <div class="text-center p-3 bg-secondary bg-opacity-10 rounded">
          <div class="display-6 text-secondary"><?= $kpis['pending_count'] ?></div>
          <div class="small text-muted">Pending</div>
        </div>
      </div>
      <div class="col-md-3 col-6">
        <div class="text-center p-3 bg-danger bg-opacity-10 rounded">
          <div class="display-6 text-danger"><?= $kpis['overdue_count'] ?></div>
          <div class="small text-muted">Overdue</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fmt = (v) => 'TZS ' + Number(v).toLocaleString(undefined, {minimumFractionDigits:0});

    // Revenue Chart
    const revCtx = document.getElementById('revenueChart');
    if (revCtx) {
        new Chart(revCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($yearlyTrend['months']) ?>,
                datasets: [{
                    label: 'Collected',
                    data: <?= json_encode($yearlyTrend['collected']) ?>,
                    backgroundColor: 'rgba(31,138,85,0.7)',
                    borderColor: '#1F8A55',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12,
                        callbacks: { label: ctx => fmt(ctx.raw) }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 10 }, callback: v => fmt(v) } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }

    // Outstanding by Class Chart
    const outCtx = document.getElementById('outstandingChart');
    if (outCtx) {
        const labels = <?= json_encode(array_column($outstandingByClass, 'level_name')) ?>;
        const data = <?= json_encode(array_map(function($o) { return (float) $o['outstanding']; }, $outstandingByClass)) ?>;
        new Chart(outCtx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: ['#C23B3B', '#C8932A', '#334E68', '#1F8A55', '#6B7280', '#6366F1', '#EC4899'],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 8, font: { size: 10 } } },
                    tooltip: { backgroundColor: '#0A1C2E', cornerRadius: 8, padding: 12,
                        callbacks: { label: ctx => ctx.label + ': ' + fmt(ctx.raw) }
                    }
                }
            }
        });
    }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>