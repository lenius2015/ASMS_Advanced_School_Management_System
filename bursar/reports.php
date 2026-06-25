<?php
/**
 * bursar/reports.php
 * Complete Reports System for the School Fee Management Module.
 *
 * Generates 7 types of reports:
 * 1. Fee Collection Report
 * 2. Outstanding Balance Report
 * 3. Student Statement Report
 * 4. Class Collection Report
 * 5. Daily Collection Report
 * 6. Monthly Collection Report
 * 7. Annual Collection Report
 *
 * Export formats: PDF (print), Excel (CSV), Print
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
require_once __DIR__ . '/../includes/fee_functions.php';

$period = get_current_period($pdo);
$action = $_GET['action'] ?? 'list';
$type = $_GET['type'] ?? '';

// Get data for dropdowns
$terms = $pdo->query(
    "SELECT t.*, y.year_name FROM terms t 
     JOIN academic_years y ON y.year_id = t.year_id 
     ORDER BY t.start_date DESC"
)->fetchAll();
$classLevels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();
$years = $pdo->query('SELECT DISTINCT YEAR(payment_date) AS yr FROM payments ORDER BY yr DESC')->fetchAll();
if (empty($years)) {
    $years = [['yr' => date('Y')]];
}

// =========================================================================
// HANDLE EXPORT / PRINT
// =========================================================================
$export = $_GET['export'] ?? '';

if ($action === 'generate' && $type && $export) {
    $reportData = generate_report_data($pdo, $type, $_GET);
    
    if ($export === 'csv') {
        export_csv($reportData['data'], $reportData['headers'], $reportData['filename'] . '.csv');
    } elseif ($export === 'print') {
        $html = render_printable_report(
            $reportData['title'],
            $reportData['headers'],
            $reportData['data'],
            $reportData['totals'] ?? []
        );
        output_pdf($html, $reportData['filename'] . '.pdf');
        exit;
    }
}

// =========================================================================
// HANDLE REPORT GENERATION (display on screen)
// =========================================================================
$reportTitle = '';
$reportHeaders = [];
$reportData = [];
$reportTotals = [];

if ($action === 'generate' && $type) {
    $result = generate_report_data($pdo, $type, $_GET);
    $reportTitle = $result['title'];
    $reportHeaders = $result['headers'];
    $reportData = $result['data'];
    $reportTotals = $result['totals'] ?? [];
}

/**
 * Generate report data based on type.
 */
function generate_report_data(PDO $pdo, string $type, array $params): array
{
    $termId = (int) ($params['term_id'] ?? 0);
    $classLevelId = (int) ($params['class_level_id'] ?? 0);
    $studentId = (int) ($params['student_id'] ?? 0);
    $dateFrom = $params['date_from'] ?? date('Y-m-01');
    $dateTo = $params['date_to'] ?? date('Y-m-d');
    $month = (int) ($params['month'] ?? date('m'));
    $year = (int) ($params['year'] ?? date('Y'));

    switch ($type) {
        case 'collection':
            $data = get_collection_report($pdo, $termId, $classLevelId);
            $headers = ['Class', 'Stream', 'Students', 'Invoices', 'Total Billed', 'Total Collected', 'Outstanding', 'Collection %'];
            $formatted = [];
            $totalBilled = 0; $totalCollected = 0; $totalOutstanding = 0;
            foreach ($data as $row) {
                $rate = $row['total_billed'] > 0 ? round(($row['total_collected'] / $row['total_billed']) * 100, 1) : 0;
                $formatted[] = [
                    'Class' => e($row['level_name']),
                    'Stream' => e($row['stream_name']),
                    'Students' => (int) $row['student_count'],
                    'Invoices' => (int) $row['invoice_count'],
                    'Total Billed' => number_format((float) $row['total_billed'], 2),
                    'Total Collected' => number_format((float) $row['total_collected'], 2),
                    'Outstanding' => number_format((float) $row['total_outstanding'], 2),
                    'Collection %' => $rate . '%',
                ];
                $totalBilled += (float) $row['total_billed'];
                $totalCollected += (float) $row['total_collected'];
                $totalOutstanding += (float) $row['total_outstanding'];
            }
            $overallRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 1) : 0;
            return [
                'title' => 'Fee Collection Report',
                'headers' => $headers,
                'data' => $formatted,
                'filename' => 'fee_collection_report',
                'totals' => [
                    'Total Billed' => number_format($totalBilled, 2),
                    'Total Collected' => number_format($totalCollected, 2),
                    'Total Outstanding' => number_format($totalOutstanding, 2),
                    'Overall Collection Rate' => $overallRate . '%',
                ],
            ];

        case 'outstanding':
            $data = get_outstanding_report($pdo, $termId, $classLevelId);
            $headers = ['Student', 'Admission No', 'Class', 'Invoice', 'Control No', 'Total', 'Paid', 'Balance', 'Due Date', 'Days Overdue'];
            $formatted = [];
            $totalBalance = 0;
            foreach ($data as $row) {
                $formatted[] = [
                    'Student' => e($row['first_name'] . ' ' . $row['last_name']),
                    'Admission No' => e($row['admission_no']),
                    'Class' => e($row['level_name'] . ' ' . $row['stream_name']),
                    'Invoice' => e($row['invoice_no']),
                    'Control No' => e($row['control_number'] ?: '-'),
                    'Total' => number_format((float) $row['total_amount'], 2),
                    'Paid' => number_format((float) $row['amount_paid'], 2),
                    'Balance' => number_format((float) $row['balance'], 2),
                    'Due Date' => format_date($row['due_date']),
                    'Days Overdue' => max((int) $row['days_overdue'], 0) . ' days',
                ];
                $totalBalance += (float) $row['balance'];
            }
            return [
                'title' => 'Outstanding Balance Report',
                'headers' => $headers,
                'data' => $formatted,
                'filename' => 'outstanding_balance_report',
                'totals' => [
                    'Total Outstanding' => number_format($totalBalance, 2),
                    'Total Students with Balances' => count($data),
                ],
            ];

        case 'student_statement':
            if (!$studentId) {
                return ['title' => 'Student Statement', 'headers' => [], 'data' => [], 'filename' => 'student_statement'];
            }
            $summary = get_student_fee_summary($pdo, $studentId);
            $headers = ['Date', 'Reference', 'Description', 'Amount', 'Balance'];
            $formatted = [];
            $runningBalance = 0;

            // Invoices as opening
            foreach ($summary['invoices'] as $inv) {
                $runningBalance += (float) $inv['total_amount'];
                $formatted[] = [
                    'Date' => format_date($inv['created_at']),
                    'Reference' => e($inv['invoice_no']),
                    'Description' => 'Invoice - ' . e($inv['term_name']) . ' (Control: ' . e($inv['control_number'] ?: 'N/A') . ')',
                    'Amount' => number_format((float) $inv['total_amount'], 2),
                    'Balance' => number_format($runningBalance, 2),
                ];
            }

            // Payments
            foreach ($summary['payments'] as $p) {
                $runningBalance -= (float) $p['amount'];
                $formatted[] = [
                    'Date' => format_date($p['payment_date']),
                    'Reference' => e($p['reference_no'] ?: '-'),
                    'Description' => 'Payment via ' . e(str_replace('_', ' ', ucfirst($p['payment_method']))),
                    'Amount' => '- ' . number_format((float) $p['amount'], 2),
                    'Balance' => number_format(max($runningBalance, 0), 2),
                ];
            }

            $studentInfo = $summary['student'];
            return [
                'title' => 'Fee Statement - ' . e(($studentInfo['first_name'] ?? '') . ' ' . ($studentInfo['last_name'] ?? '')),
                'headers' => $headers,
                'data' => $formatted,
                'filename' => 'student_statement_' . ($studentInfo['admission_no'] ?? 'unknown'),
                'totals' => [
                    'Student' => e(($studentInfo['first_name'] ?? '') . ' ' . ($studentInfo['last_name'] ?? '')),
                    'Admission No' => e($studentInfo['admission_no'] ?? 'N/A'),
                    'Class' => e(($studentInfo['level_name'] ?? '') . ' ' . ($studentInfo['stream_name'] ?? '')),
                    'Total Fees' => number_format((float) ($summary['account']['total_fees'] ?? 0), 2),
                    'Total Paid' => number_format((float) ($summary['account']['total_paid'] ?? 0), 2),
                    'Balance' => number_format((float) ($summary['account']['balance'] ?? 0), 2),
                    'Status' => ucfirst($summary['account']['payment_status'] ?? 'N/A'),
                ],
            ];

        case 'class_collection':
            $classLevelId = $classLevelId ?: 0;
            $data = get_collection_report($pdo, $termId, $classLevelId);
            $headers = ['Class', 'Stream', 'Students', 'Invoices', 'Total Billed', 'Collected', 'Outstanding', 'Paid %'];
            $formatted = [];
            $classBilled = 0; $classCollected = 0; $classOutstanding = 0;
            foreach ($data as $row) {
                $rate = $row['total_billed'] > 0 ? round(($row['total_collected'] / $row['total_billed']) * 100, 1) : 0;
                $formatted[] = [
                    'Class' => e($row['level_name'] . ' ' . $row['stream_name']),
                    'Stream' => e($row['stream_name']),
                    'Students' => (int) $row['student_count'],
                    'Invoices' => (int) $row['invoice_count'],
                    'Total Billed' => number_format((float) $row['total_billed'], 2),
                    'Collected' => number_format((float) $row['total_collected'], 2),
                    'Outstanding' => number_format((float) $row['total_outstanding'], 2),
                    'Paid %' => $rate . '%',
                ];
                $classBilled += (float) $row['total_billed'];
                $classCollected += (float) $row['total_collected'];
                $classOutstanding += (float) $row['total_outstanding'];
            }
            $overall = $classBilled > 0 ? round(($classCollected / $classBilled) * 100, 1) : 0;
            return [
                'title' => 'Class Collection Report',
                'headers' => $headers,
                'data' => $formatted,
                'filename' => 'class_collection_report',
                'totals' => [
                    'Total Billed' => number_format($classBilled, 2),
                    'Total Collected' => number_format($classCollected, 2),
                    'Total Outstanding' => number_format($classOutstanding, 2),
                    'Overall Collection Rate' => $overall . '%',
                ],
            ];

        case 'daily_collection':
            $data = get_daily_collection_report($pdo, $dateFrom, $dateTo);
            $headers = ['Date', 'Student', 'Admission', 'Invoice', 'Control No', 'Reference', 'Amount', 'Method', 'Recorded By'];
            $formatted = [];
            $dailyTotal = 0;
            foreach ($data as $row) {
                $formatted[] = [
                    'Date' => $row['payment_date'],
                    'Student' => e($row['student_fn'] . ' ' . $row['student_ln']),
                    'Admission' => e($row['admission_no']),
                    'Invoice' => e($row['invoice_no']),
                    'Control No' => e($row['control_number'] ?: '-'),
                    'Reference' => e($row['reference_no'] ?: '-'),
                    'Amount' => number_format((float) $row['amount'], 2),
                    'Method' => e(str_replace('_', ' ', ucfirst($row['payment_method']))),
                    'Recorded By' => e($row['recorded_by_name'] ?: '-'),
                ];
                $dailyTotal += (float) $row['amount'];
            }
            return [
                'title' => 'Daily Collection Report (' . $dateFrom . ' to ' . $dateTo . ')',
                'headers' => $headers,
                'data' => $formatted,
                'filename' => 'daily_collection_' . $dateFrom . '_to_' . $dateTo,
                'totals' => [
                    'Date Range' => $dateFrom . ' to ' . $dateTo,
                    'Total Collected' => number_format($dailyTotal, 2),
                    'Total Transactions' => count($data),
                ],
            ];

        case 'monthly_collection':
            $data = get_monthly_collection_report($pdo, $month, $year);
            $headers = ['Date', 'Student', 'Admission', 'Class', 'Invoice', 'Control No', 'Reference', 'Amount', 'Method', 'Recorded By'];
            $formatted = [];
            $monthlyTotal = 0;
            foreach ($data as $row) {
                $formatted[] = [
                    'Date' => $row['payment_date'],
                    'Student' => e($row['student_fn'] . ' ' . $row['student_ln']),
                    'Admission' => e($row['admission_no']),
                    'Class' => e($row['level_name'] ?? ''),
                    'Invoice' => e($row['invoice_no']),
                    'Control No' => e($row['control_number'] ?: '-'),
                    'Reference' => e($row['reference_no'] ?: '-'),
                    'Amount' => number_format((float) $row['amount'], 2),
                    'Method' => e(str_replace('_', ' ', ucfirst($row['payment_method']))),
                    'Recorded By' => e($row['recorded_by_name'] ?: '-'),
                ];
                $monthlyTotal += (float) $row['amount'];
            }
            $monthName = date('F', mktime(0, 0, 0, $month, 1));
            return [
                'title' => 'Monthly Collection Report - ' . $monthName . ' ' . $year,
                'headers' => $headers,
                'data' => $formatted,
                'filename' => 'monthly_collection_' . $month . '_' . $year,
                'totals' => [
                    'Month' => $monthName . ' ' . $year,
                    'Total Collected' => number_format($monthlyTotal, 2),
                    'Total Transactions' => count($data),
                ],
            ];

        case 'annual_collection':
            $data = get_annual_collection_report($pdo, $year);
            $headers = ['Month', 'Total Collected', 'Transactions', 'Unique Students'];
            $formatted = [];
            $annualTotal = 0; $annualTxns = 0;
            foreach ($data as $row) {
                $formatted[] = [
                    'Month' => $row['month_name'],
                    'Total Collected' => number_format((float) $row['total_collected'], 2),
                    'Transactions' => (int) $row['payment_count'],
                    'Unique Students' => (int) $row['student_count'],
                ];
                $annualTotal += (float) $row['total_collected'];
                $annualTxns += (int) $row['payment_count'];
            }
            return [
                'title' => 'Annual Collection Report - ' . $year,
                'headers' => $headers,
                'data' => $formatted,
                'filename' => 'annual_collection_' . $year,
                'totals' => [
                    'Year' => (string) $year,
                    'Total Collected' => number_format($annualTotal, 2),
                    'Total Transactions' => $annualTxns,
                ],
            ];

        default:
            return ['title' => 'Unknown Report', 'headers' => [], 'data' => [], 'filename' => 'report'];
    }
}

$pageTitle = 'Fee Reports';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Fee Collection Reports</h1>

<!-- Report Selection -->
<div class="row g-4 mb-4">
  <div class="col-lg-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-chart-pie text-gold me-2"></i>Generate Report</div>
      <div class="card-body">
        <form method="GET" class="row g-3" id="reportForm">
          <input type="hidden" name="action" value="generate">
          
          <!-- Report Type -->
          <div class="col-md-3">
            <label class="form-label">Report Type <span class="required-mark">*</span></label>
            <select name="type" class="form-select" required onchange="updateReportForm()">
              <option value="">-- Select Report --</option>
              <option value="collection" <?= $type === 'collection' ? 'selected' : '' ?>>Fee Collection Report</option>
              <option value="outstanding" <?= $type === 'outstanding' ? 'selected' : '' ?>>Outstanding Balance Report</option>
              <option value="student_statement" <?= $type === 'student_statement' ? 'selected' : '' ?>>Student Statement Report</option>
              <option value="class_collection" <?= $type === 'class_collection' ? 'selected' : '' ?>>Class Collection Report</option>
              <option value="daily_collection" <?= $type === 'daily_collection' ? 'selected' : '' ?>>Daily Collection Report</option>
              <option value="monthly_collection" <?= $type === 'monthly_collection' ? 'selected' : '' ?>>Monthly Collection Report</option>
              <option value="annual_collection" <?= $type === 'annual_collection' ? 'selected' : '' ?>>Annual Collection Report</option>
            </select>
          </div>

          <!-- Term (for collection/outstanding/class reports) -->
          <div class="col-md-2 report-filter" data-reports="collection,outstanding,class_collection">
            <label class="form-label">Term</label>
            <select name="term_id" class="form-select">
              <?php foreach ($terms as $t): ?>
                <option value="<?= (int) $t['term_id'] ?>" <?= ($termId ?? $period['term_id']) === (int) $t['term_id'] ? 'selected' : '' ?>>
                  <?= e($t['year_name'] . ' - ' . $t['term_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Class Level (for collection/outstanding/class reports) -->
          <div class="col-md-2 report-filter" data-reports="collection,outstanding,class_collection">
            <label class="form-label">Class Level</label>
            <select name="class_level_id" class="form-select">
              <option value="0">All Classes</option>
              <?php foreach ($classLevels as $cl): ?>
                <option value="<?= (int) $cl['class_level_id'] ?>" <?= ($classLevelId ?? 0) === (int) $cl['class_level_id'] ? 'selected' : '' ?>>
                  <?= e($cl['level_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Student (for student statement) -->
          <div class="col-md-3 report-filter" data-reports="student_statement" style="display:none;">
            <label class="form-label">Student</label>
            <select name="student_id" class="form-select">
              <option value="0">-- Select Student --</option>
              <?php
              $students = $pdo->query(
                  "SELECT s.student_id, u.first_name, u.last_name, s.admission_no 
                   FROM students s JOIN users u ON u.user_id = s.user_id 
                   WHERE s.status = 'active' ORDER BY u.first_name"
              )->fetchAll();
              foreach ($students as $st): ?>
                <option value="<?= (int) $st['student_id'] ?>" <?= ($studentId ?? 0) === (int) $st['student_id'] ? 'selected' : '' ?>>
                  <?= e($st['first_name'] . ' ' . $st['last_name'] . ' (' . $st['admission_no'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Date Range (for daily collection) -->
          <div class="col-md-2 report-filter" data-reports="daily_collection" style="display:none;">
            <label class="form-label">From Date</label>
            <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?? date('Y-m-01') ?>">
          </div>
          <div class="col-md-2 report-filter" data-reports="daily_collection" style="display:none;">
            <label class="form-label">To Date</label>
            <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?? date('Y-m-d') ?>">
          </div>

          <!-- Month/Year (for monthly collection) -->
          <div class="col-md-1 report-filter" data-reports="monthly_collection" style="display:none;">
            <label class="form-label">Month</label>
            <select name="month" class="form-select">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= ($month ?? date('m')) == $m ? 'selected' : '' ?>><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-1 report-filter" data-reports="monthly_collection" style="display:none;">
            <label class="form-label">Year</label>
            <select name="year" class="form-select">
              <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= ($year ?? date('Y')) == $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <!-- Year (for annual collection) -->
          <div class="col-md-1 report-filter" data-reports="annual_collection" style="display:none;">
            <label class="form-label">Year</label>
            <select name="year" class="form-select">
              <?php foreach ($years as $y): ?>
                <option value="<?= (int) $y['yr'] ?>" <?= (int) ($_GET['year'] ?? date('Y')) === (int) $y['yr'] ? 'selected' : '' ?>><?= $y['yr'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Generate Button -->
          <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100"><i class="fa fa-search me-1"></i> Generate</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Report Results -->
<?php if ($action === 'generate' && !empty($reportHeaders)): ?>
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="fa fa-file-alt text-gold me-2"></i><?= e($reportTitle) ?></span>
      <div class="d-flex gap-1">
        <a href="?action=generate&type=<?= e($type) ?>&export=print&<?= e(http_build_query($_GET)) ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fa fa-print me-1"></i> Print</a>
        <a href="?action=generate&type=<?= e($type) ?>&export=csv&<?= e(http_build_query($_GET)) ?>" class="btn btn-sm btn-outline-success"><i class="fa fa-file-excel me-1"></i> Excel</a>
        <button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fa fa-print me-1"></i> Print View</button>
      </div>
    </div>

    <?php if (!empty($reportTotals)): ?>
      <div class="card-body bg-light">
        <div class="row g-2">
          <?php foreach ($reportTotals as $label => $value): ?>
            <div class="col-md-3 col-6">
              <div class="small text-muted"><?= e($label) ?></div>
              <div class="fw-bold"><?= e($value) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0 data-table" id="reportTable">
        <thead>
          <tr>
            <th>#</th>
            <?php foreach ($reportHeaders as $h): ?>
              <th><?= e($h) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1; foreach ($reportData as $row): ?>
            <tr>
              <td><?= $i++ ?></td>
              <?php foreach ($reportHeaders as $h): ?>
                <?php 
                  $key = strtolower(str_replace([' ', '.', '/'], '_', $h));
                  $val = $row[$key] ?? $row[$h] ?? '';
                  $isMoney = str_contains(strtolower($h), 'amount') || str_contains(strtolower($h), 'total') || str_contains(strtolower($h), 'collected') || str_contains(strtolower($h), 'outstanding') || str_contains(strtolower($h), 'balance') || str_contains(strtolower($h), 'billed') || str_contains(strtolower($h), 'paid');
                ?>
                <td class="<?= $isMoney ? 'text-end fw-semibold' : '' ?>"><?= e((string) $val) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($reportData)): ?>
            <tr><td colspan="<?= count($reportHeaders) + 1 ?>" class="text-center text-muted py-4">No data available for this report.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php elseif ($action === 'generate'): ?>
  <div class="alert alert-info">Please select a report type and filters to generate data.</div>
<?php endif; ?>

<!-- Quick Links -->
<div class="row animate-fade-in">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-link text-gold me-2"></i>Quick Links</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="<?= e(app_url('/bursar/dashboard.php')) ?>" class="quick-action-item"><i class="fa fa-tachometer-alt"></i><span>Dashboard</span></a>
          <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="quick-action-item"><i class="fa fa-file-invoice"></i><span>Invoices</span></a>
          <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="quick-action-item"><i class="fa fa-money-bill-wave"></i><span>Record Payment</span></a>
          <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="quick-action-item"><i class="fa fa-list-ol"></i><span>Fee Structures</span></a>
          <a href="<?= e(app_url('/director/finance_overview.php')) ?>" class="quick-action-item"><i class="fa fa-chart-line"></i><span>Finance Overview</span></a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function updateReportForm() {
    const type = document.querySelector('[name="type"]').value;
    document.querySelectorAll('.report-filter').forEach(el => {
        const reports = el.dataset.reports.split(',');
        if (reports.includes(type)) {
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    });
}
// Initialize on load
updateReportForm();
</script>

<style>
@media print {
    .action-bar, .quick-actions, .no-print, .card-header .btn { display: none !important; }
    .card { break-inside: avoid; border: 1px solid #ccc !important; }
    body { font-size: 10px; }
}
</style>

<?php require APP_ROOT . '/includes/footer.php'; ?>