<?php
/**
 * class_teacher/attendance.php
 * The official daily attendance register for the homeroom class.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();
$selectedDate = $_GET['date'] ?? date('Y-m-d');

$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Daily Attendance';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {
    csrf_verify();
    $date = $_POST['attendance_date'] ?? date('Y-m-d');
    $statuses = $_POST['status'] ?? [];
    $remarksAll = $_POST['remarks'] ?? [];

    foreach ($statuses as $studentId => $status) {
        $remark = trim($remarksAll[$studentId] ?? '');
        $pdo->prepare(
            'INSERT INTO student_attendance (student_id, class_id, attendance_date, status, recorded_by, remarks)
             VALUES (:sid, :cid, :date, :status, :by, :remarks)
             ON DUPLICATE KEY UPDATE status = :status2, recorded_by = :by2, remarks = :remarks2'
        )->execute([
            'sid' => (int) $studentId, 'cid' => $myClass['class_id'], 'date' => $date, 'status' => $status,
            'by' => $teacherId, 'remarks' => $remark ?: null, 'status2' => $status, 'by2' => $teacherId, 'remarks2' => $remark ?: null,
        ]);
    }

    audit_log('record_attendance', 'attendance', 'student_attendance', null, "Recorded attendance for class #{$myClass['class_id']} on {$date}");
    flash_set('success', 'Attendance saved for ' . format_date($date) . '.');
    redirect(app_url('/class_teacher/attendance.php') . '?date=' . $date);
}

$students = $pdo->prepare(
    "SELECT s.student_id, u.first_name, u.last_name, s.admission_no FROM students s
     JOIN users u ON u.user_id = s.user_id WHERE s.class_id = :cid AND s.status='active' ORDER BY u.first_name"
);
$students->execute(['cid' => $myClass['class_id']]);
$studentList = $students->fetchAll();

$existing = $pdo->prepare('SELECT * FROM student_attendance WHERE class_id = :cid AND attendance_date = :date');
$existing->execute(['cid' => $myClass['class_id'], 'date' => $selectedDate]);
$existingMap = [];
foreach ($existing->fetchAll() as $row) {
    $existingMap[$row['student_id']] = $row;
}
?>

<h1 class="h3 mb-4">Daily Attendance &mdash; <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-3"><input type="date" name="date" class="form-control" value="<?= e($selectedDate) ?>" onchange="this.form.submit()"></div>
    </form>
  </div>
</div>

<form method="POST">
  <?php csrf_field(); ?>
  <input type="hidden" name="action" value="save_attendance">
  <input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span>Register for <?= e(format_date($selectedDate)) ?></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.status-select').forEach(s => s.value='present')">Mark All Present</button>
    </div>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>Admission No.</th><th>Student</th><th style="width:140px;">Status</th><th>Remarks</th></tr></thead>
        <tbody>
          <?php foreach ($studentList as $s): $current = $existingMap[$s['student_id']]['status'] ?? 'present'; $remark = $existingMap[$s['student_id']]['remarks'] ?? ''; ?>
            <tr>
              <td><code><?= e($s['admission_no']) ?></code></td>
              <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
              <td>
                <select name="status[<?= (int) $s['student_id'] ?>]" class="form-select form-select-sm status-select">
                  <?php foreach (['present','absent','late','excused'] as $st): ?>
                    <option value="<?= $st ?>" <?= $current === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="remarks[<?= (int) $s['student_id'] ?>]" class="form-control form-control-sm" value="<?= e($remark) ?>"></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($studentList)): ?><tr><td colspan="4" class="text-center text-muted py-4">No students in this class.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer bg-white"><button class="btn btn-primary">Save Attendance</button></div>
  </div>
</form>

<?php require APP_ROOT . '/includes/footer.php'; ?>
