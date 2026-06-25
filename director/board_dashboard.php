<?php
/**
 * director/board_dashboard.php
 * Read-only governance dashboard for the School Board: strategic reports,
 * performance summaries, financial summaries, and announcements.
 * The Board has no edit rights anywhere in the system.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['school_board']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

$totalStudents = $pdo->query("SELECT COUNT(*) c FROM students WHERE status='active'")->fetch()['c'];
$totalStaff    = $pdo->query("SELECT COUNT(*) c FROM staff WHERE status='active'")->fetch()['c'];

$financeStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total_amount),0) AS billed, COALESCE(SUM(amount_paid),0) AS collected
     FROM invoices WHERE term_id = :term"
);
$financeStmt->execute(['term' => $period['term_id']]);
$finance = $financeStmt->fetch();
$collectionRate = $finance['billed'] > 0 ? round(($finance['collected'] / $finance['billed']) * 100, 1) : 0;

$avgPerformance = $pdo->query(
    "SELECT ROUND(AVG(overall_average),1) AS avg_score FROM report_cards WHERE status='published'"
)->fetch()['avg_score'];

$announcements = $pdo->query(
    "SELECT a.*, u.first_name, u.last_name FROM announcements a
     JOIN users u ON u.user_id = a.posted_by
     WHERE a.audience IN ('all','board')
     ORDER BY a.created_at DESC LIMIT 6"
)->fetchAll();

$pageTitle = 'School Board Dashboard';
require APP_ROOT . '/includes/header.php';
?>

<div class="mb-4">
  <h1 class="h3 mb-0">Governance Overview</h1>
  <p class="text-muted">Strategic summary for the School Board &middot; <?= e(date('l, d F Y')) ?></p>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card accent-navy">
      <div class="kpi-label">Active Students</div>
      <div class="kpi-value"><?= (int) $totalStudents ?></div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card">
      <div class="kpi-label">Active Staff</div>
      <div class="kpi-value"><?= (int) $totalStaff ?></div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card accent-green">
      <div class="kpi-label">Fee Collection Rate</div>
      <div class="kpi-value"><?= e($collectionRate) ?>%</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="asms-kpi-card">
      <div class="kpi-label">Avg. Published Score</div>
      <div class="kpi-value"><?= $avgPerformance !== null ? e($avgPerformance) : '—' ?></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-coins text-gold me-2"></i>Financial Summary (Current Term)</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><td>Total Billed</td><td class="text-end fw-semibold"><?= format_money($finance['billed']) ?></td></tr>
          <tr><td>Collected to Date</td><td class="text-end fw-semibold text-success"><?= format_money($finance['collected']) ?></td></tr>
        </table>
        <p class="text-muted small mt-2 mb-0">For a detailed breakdown by department and category, see Financial Summary in the menu.</p>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><i class="fa fa-bullhorn text-gold me-2"></i>Latest Announcements</div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush small">
          <?php foreach ($announcements as $a): ?>
            <li class="list-group-item">
              <div class="fw-semibold"><?= e($a['title']) ?></div>
              <div class="text-muted"><?= e(mb_strimwidth($a['body'], 0, 90, '...')) ?></div>
              <div class="text-muted" style="font-size:.75rem;">by <?= e($a['first_name'] . ' ' . $a['last_name']) ?> &middot; <?= e(format_date($a['created_at'])) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($announcements)): ?><li class="list-group-item text-muted">No announcements yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
