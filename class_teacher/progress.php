<?php
/**
 * class_teacher/progress.php
 * Academic progress snapshot for every student in the homeroom class:
 * latest term average, attendance rate, and discipline flag.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();
$period = get_current_period($pdo);

$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Student Progress';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT s.student_id, u.first_name, u.last_name, s.admission_no,
        rc.overall_average, rc.overall_position, rc.attendance_percent,
        (SELECT COUNT(*) FROM discipline_records dr WHERE dr.student_id = s.student_id AND dr.incident_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) AS recent_discipline
     FROM students s
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN report_cards rc ON rc.student_id = s.student_id AND rc.term_id = :term
     WHERE s.class_id = :cid AND s.status = 'active'
     ORDER BY u.first_name"
);
$stmt->execute(['term' => $period['term_id'], 'cid' => $myClass['class_id']]);
$rows = $stmt->fetchAll();
?>

<h1 class="h3 mb-4">Student Progress &mdash; <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Student</th><th>Admission No.</th><th>Term Average</th><th>Position</th><th>Attendance</th><th>Discipline (90 days)</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
            <td><code><?= e($r['admission_no']) ?></code></td>
            <td><?= $r['overall_average'] !== null ? e($r['overall_average']) . '%' : '<span class="text-muted">Pending</span>' ?></td>
            <td><?= $r['overall_position'] ? '#' . e($r['overall_position']) : '-' ?></td>
            <td><?= $r['attendance_percent'] !== null ? e($r['attendance_percent']) . '%' : '-' ?></td>
            <td><?= $r['recent_discipline'] > 0 ? '<span class="badge bg-danger">' . (int) $r['recent_discipline'] . '</span>' : '<span class="badge bg-success">0</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="6" class="text-center text-muted py-4">No students found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
