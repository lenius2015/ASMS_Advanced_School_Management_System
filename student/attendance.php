<?php
/**
 * student/attendance.php
 * The student's own attendance summary and history.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['student']);

$pdo = get_db_connection();
$userId = current_user_id();

$studentStmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :uid');
$studentStmt->execute(['uid' => $userId]);
$student = $studentStmt->fetch();

$pageTitle = 'My Attendance';
require APP_ROOT . '/includes/header.php';

if (!$student) {
    echo '<div class="alert alert-warning">Your student profile could not be found.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}
$studentId = (int) $student['student_id'];

$summary = $pdo->prepare(
    "SELECT SUM(status='present') AS present_days, SUM(status='absent') AS absent_days,
        SUM(status='late') AS late_days, COUNT(*) AS total_days
     FROM student_attendance WHERE student_id = :id"
);
$summary->execute(['id' => $studentId]);
$stats = $summary->fetch();
$rate = ($stats && $stats['total_days'] > 0) ? round(($stats['present_days'] / $stats['total_days']) * 100, 1) : null;

$recent = $pdo->prepare('SELECT * FROM student_attendance WHERE student_id = :id ORDER BY attendance_date DESC LIMIT 30');
$recent->execute(['id' => $studentId]);
$records = $recent->fetchAll();
?>

<h1 class="h3 mb-4">My Attendance</h1>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card accent-green"><div class="kpi-label">Attendance Rate</div><div class="kpi-value"><?= $rate !== null ? e($rate) . '%' : '—' ?></div></div></div>
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card"><div class="kpi-label">Present Days</div><div class="kpi-value"><?= (int) ($stats['present_days'] ?? 0) ?></div></div></div>
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card accent-red"><div class="kpi-label">Absent Days</div><div class="kpi-value"><?= (int) ($stats['absent_days'] ?? 0) ?></div></div></div>
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card"><div class="kpi-label">Late Days</div><div class="kpi-value"><?= (int) ($stats['late_days'] ?? 0) ?></div></div></div>
</div>

<div class="card">
  <div class="card-header">Recent Attendance</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Date</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($records as $r): ?>
          <tr><td><?= format_date($r['attendance_date']) ?></td><td><span class="badge badge-status-<?= $r['status']==='present' ? 'active' : ($r['status']==='absent' ? 'overdue' : 'pending') ?>"><?= e(ucfirst($r['status'])) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?><tr><td colspan="2" class="text-center text-muted py-4">No attendance records yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
