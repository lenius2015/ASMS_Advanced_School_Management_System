<?php
/**
 * parent/attendance.php
 * Attendance history and summary for the selected child.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['parent']);

$pdo = get_db_connection();
$userId = current_user_id();
$studentId = (int) ($_GET['student_id'] ?? 0) ?: get_default_child($pdo, $userId);
$verifiedStudentId = $studentId ? verify_guardian_owns_student($pdo, $userId, $studentId) : null;

$pageTitle = 'Attendance';
require APP_ROOT . '/includes/header.php';

if (!$verifiedStudentId) {
    echo '<div class="alert alert-warning">No child found or you do not have access to view this record.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$studentInfo = $pdo->prepare("SELECT u.first_name, u.last_name FROM students s JOIN users u ON u.user_id = s.user_id WHERE s.student_id = :id");
$studentInfo->execute(['id' => $verifiedStudentId]);
$student = $studentInfo->fetch();

$summary = $pdo->prepare(
    "SELECT SUM(status='present') AS present_days, SUM(status='absent') AS absent_days,
        SUM(status='late') AS late_days, COUNT(*) AS total_days
     FROM student_attendance WHERE student_id = :id"
);
$summary->execute(['id' => $verifiedStudentId]);
$stats = $summary->fetch();
$rate = ($stats && $stats['total_days'] > 0) ? round(($stats['present_days'] / $stats['total_days']) * 100, 1) : null;

$recent = $pdo->prepare('SELECT * FROM student_attendance WHERE student_id = :id ORDER BY attendance_date DESC LIMIT 30');
$recent->execute(['id' => $verifiedStudentId]);
$records = $recent->fetchAll();
?>

<h1 class="h3 mb-1">Attendance</h1>
<p class="text-muted mb-4"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></p>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card accent-green"><div class="kpi-label">Attendance Rate</div><div class="kpi-value"><?= $rate !== null ? e($rate) . '%' : '—' ?></div></div></div>
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card"><div class="kpi-label">Present Days</div><div class="kpi-value"><?= (int) ($stats['present_days'] ?? 0) ?></div></div></div>
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card accent-red"><div class="kpi-label">Absent Days</div><div class="kpi-value"><?= (int) ($stats['absent_days'] ?? 0) ?></div></div></div>
  <div class="col-md-3 col-sm-6"><div class="asms-kpi-card"><div class="kpi-label">Late Days</div><div class="kpi-value"><?= (int) ($stats['late_days'] ?? 0) ?></div></div></div>
</div>

<div class="card">
  <div class="card-header">Recent Attendance (Last 30 Records)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Date</th><th>Status</th><th>Remarks</th></tr></thead>
      <tbody>
        <?php foreach ($records as $r): ?>
          <tr>
            <td><?= format_date($r['attendance_date']) ?></td>
            <td><span class="badge badge-status-<?= $r['status']==='present' ? 'active' : ($r['status']==='absent' ? 'overdue' : 'pending') ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td class="small text-muted"><?= e($r['remarks'] ?: '-') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?><tr><td colspan="3" class="text-center text-muted py-4">No attendance records yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
