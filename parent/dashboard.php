<?php
/**
 * parent/dashboard.php
 * Parent/guardian dashboard: enhanced cards per child with academic,
 * attendance, and fee indicators with visual charts.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['parent']);

$pdo = get_db_connection();
$userId = current_user_id();
$period = get_current_period($pdo);

$guardianStmt = $pdo->prepare('SELECT guardian_id FROM guardians WHERE user_id = :uid');
$guardianStmt->execute(['uid' => $userId]);
$guardian = $guardianStmt->fetch();

$pageTitle = 'Parent Dashboard';
require APP_ROOT . '/includes/header.php';

if (!$guardian) {
    echo '<div class="alert alert-warning">No guardian profile is linked to your account yet. Please contact the school office.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$childrenStmt = $pdo->prepare(
    "SELECT s.student_id, u.first_name, u.last_name, s.admission_no, cl.level_name, c.stream_name, c.class_id,
        rc.overall_average, rc.overall_position, rc.class_size, rc.attendance_percent
     FROM students s
     JOIN users u ON u.user_id = s.user_id
     JOIN student_guardians sg ON sg.student_id = s.student_id
     LEFT JOIN classes c ON c.class_id = s.class_id
     LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     LEFT JOIN report_cards rc ON rc.student_id = s.student_id AND rc.term_id = :term
     WHERE sg.guardian_id = :gid"
);
$childrenStmt->execute(['term' => $period['term_id'], 'gid' => $guardian['guardian_id']]);
$children = $childrenStmt->fetchAll();
$childCount = count($children);

// ====== Unread messages ======
$msgStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = :uid AND is_read = 0");
$msgStmt->execute(['uid' => $userId]);
$unreadMessages = (int) $msgStmt->fetch()['c'];
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Welcome, <?= e($_SESSION['full_name']) ?> <span class="badge bg-gold ms-2">Parent</span></h1>
      <p class="mb-0"><?= $childCount ?> child<?= $childCount !== 1 ? 'ren' : '' ?> enrolled &middot; <?= e(date('l, d F Y')) ?></p>
    </div>
    <div class="d-flex gap-2">
      <?php if ($unreadMessages > 0): ?>
        <a href="<?= e(app_url('/communication/inbox.php')) ?>" class="btn btn-gold"><i class="fa fa-envelope me-1"></i> Messages (<?= $unreadMessages ?>)</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search children..." data-search="#childrenCards" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> My Children</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/parent/results.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-graduation-cap me-1"></i> Results</a>
    <a href="<?= e(app_url('/parent/attendance.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-check me-1"></i> Attendance</a>
    <a href="<?= e(app_url('/parent/fees.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-money-check-alt me-1"></i> Fees</a>
    <a href="<?= e(app_url('/communication/inbox.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-comments me-1"></i> Messages</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<?php if (empty($children)): ?>
  <div class="col-12"><div class="alert alert-info">No children are linked to your account yet. Please contact the school office.</div></div>
<?php endif; ?>

<?php foreach ($children as $child): ?>
  <?php
    $sid = (int) $child['student_id'];
    // Fee data
    $invStmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) AS bal, COALESCE(SUM(total_amount),0) AS total FROM invoices WHERE student_id = :sid");
    $invStmt->execute(['sid' => $sid]);
    $invData = $invStmt->fetch();
    $balance = $invData['bal'];
    $totalBilled = $invData['total'];
    $paidAmount = $totalBilled - $balance;
    $paymentRate = $totalBilled > 0 ? round(($paidAmount / $totalBilled) * 100, 1) : 0;

    // Attendance stats
    $attStmt = $pdo->prepare("SELECT SUM(status='present') AS p, SUM(status='absent') AS a, COUNT(*) AS t FROM student_attendance WHERE student_id = :sid");
    $attStmt->execute(['sid' => $sid]);
    $attData = $attStmt->fetch();
    $attRate = ($attData && $attData['t'] > 0) ? round(($attData['p'] / $attData['t']) * 100, 1) : 0;
    $absentCount = (int) ($attData['a'] ?? 0);

    // Recent results
    $resultStmt = $pdo->prepare(
        "SELECT sub.subject_name, tr.average_marks, tr.grade_letter
         FROM term_results tr
         JOIN class_subjects cs ON cs.class_subject_id = tr.class_subject_id
         JOIN subjects sub ON sub.subject_id = cs.subject_id
         WHERE tr.student_id = :sid AND tr.term_id = :term
         ORDER BY tr.average_marks DESC LIMIT 3"
    );
    $resultStmt->execute(['sid' => $sid, 'term' => $period['term_id']]);
    $topResults = $resultStmt->fetchAll();

    // Weekly attendance for chart
    $weeklyAtt = $pdo->prepare(
        "SELECT attendance_date, status FROM student_attendance
         WHERE student_id = :sid AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         ORDER BY attendance_date"
    );
    $weeklyAtt->execute(['sid' => $sid]);
    $attDates = [];
    $attStatuses = [];
    foreach ($weeklyAtt as $a) {
        $attDates[] = date('D', strtotime($a['attendance_date']));
        $attStatuses[] = $a['status'];
    }
  ?>

  <div class="card mb-4 animate-fade-in">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <i class="fa fa-user-graduate text-gold me-2"></i>
        <span class="fw-bold"><?= e($child['first_name'] . ' ' . $child['last_name']) ?></span>
        <span class="text-muted ms-2 small"><?= e($child['level_name'] ? $child['level_name'] . ' ' . $child['stream_name'] : 'Unassigned class') ?></span>
        <span class="text-muted ms-2 small">(<?= e($child['admission_no']) ?>)</span>
      </div>
      <div class="d-flex gap-1">
        <a href="<?= e(app_url('/parent/results.php')) ?>?student_id=<?= $sid ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-graduation-cap"></i></a>
        <a href="<?= e(app_url('/parent/attendance.php')) ?>?student_id=<?= $sid ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-check"></i></a>
        <a href="<?= e(app_url('/parent/fees.php')) ?>?student_id=<?= $sid ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-money-check-alt"></i></a>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <!-- KPI Mini Cards -->
        <div class="col-md-3">
          <div class="asms-kpi-card accent-navy" style="padding:0.85rem 1rem;">
            <div class="kpi-label">Term Average</div>
            <div class="kpi-value" style="font-size:1.4rem;"><?= $child['overall_average'] !== null ? e($child['overall_average']) . '%' : '—' ?></div>
            <div class="kpi-sub"><?= $child['overall_position'] ? 'Rank: ' . (int)$child['overall_position'] . '/' . (int)$child['class_size'] : '' ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="asms-kpi-card <?= $attRate >= 90 ? 'accent-green' : 'accent-orange' ?>" style="padding:0.85rem 1rem;">
            <div class="kpi-label">Attendance</div>
            <div class="kpi-value" style="font-size:1.4rem;"><?= e($attRate) ?>%</div>
            <div class="kpi-sub"><?= $absentCount ?> absent</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="asms-kpi-card <?= $balance > 0 ? 'accent-red' : 'accent-green' ?>" style="padding:0.85rem 1rem;">
            <div class="kpi-label">Fee Balance</div>
            <div class="kpi-value" style="font-size:1.1rem;"><?= format_money($balance) ?></div>
            <div class="progress mt-2" style="height:5px;">
              <div class="progress-bar bg-gold" data-width="<?= $paymentRate ?>" style="width:0%"></div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card h-100 bg-light border-0" style="padding:0.85rem 1rem;">
            <div class="small text-muted mb-1">Top Subjects</div>
            <?php if (!empty($topResults)): ?>
              <?php foreach ($topResults as $r): ?>
                <div class="d-flex justify-content-between small">
                  <span><?= e(mb_strimwidth($r['subject_name'], 0, 18, '..')) ?></span>
                  <span class="fw-semibold"><?= e($r['average_marks']) ?>%</span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-muted small">No results yet</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Mini attendance chart per child -->
      <?php if (!empty($attDates)): ?>
        <div class="mt-3">
          <div class="chart-container-sm">
            <canvas id="attChart<?= $sid ?>"></canvas>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($attDates)): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var ctx = document.getElementById('attChart<?= $sid ?>');
    if (ctx) {
      var colors = <?= json_encode(array_map(function($s) {
        return $s === 'present' ? '#1F8A55' : ($s === 'absent' ? '#C23B3B' : '#DD6B20');
      }, $attStatuses)) ?>;
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: <?= json_encode($attDates) ?>,
          datasets: [{
            label: 'Status',
            data: <?= json_encode(array_map(function($s) { return $s === 'present' ? 1 : ($s === 'absent' ? 0 : 0.5); }, $attStatuses)) ?>,
            backgroundColor: colors,
            borderRadius: 4,
            borderSkipped: false
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { var labels = ['Absent','Late','Present']; return labels[Math.round(ctx.raw * 2)] || 'Present'; } } } },
          scales: {
            y: { display: false, beginAtZero: true, max: 1 },
            x: { grid: { display: false }, ticks: { font: { size: 9 } } }
          }
        }
      });
    }
  });
  </script>
  <?php endif; ?>

<?php endforeach; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>