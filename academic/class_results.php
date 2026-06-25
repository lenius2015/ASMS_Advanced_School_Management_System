<?php
/**
 * academic/class_results.php
 * View the computed report card summary for every student in a class
 * for a given term — used to sanity-check before publishing.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'class_teacher', 'head_of_school', 'director', 'system_admin']);

$pdo = get_db_connection();
$classId = (int) ($_GET['class_id'] ?? 0);
$termId = (int) ($_GET['term_id'] ?? 0);

$classInfo = $pdo->prepare("SELECT c.*, cl.level_name FROM classes c JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_id = :id");
$classInfo->execute(['id' => $classId]);
$class = $classInfo->fetch();

if (!$class) {
    flash_set('error', 'Class not found.');
    redirect(app_url('/academic/publish_results.php'));
}

$stmt = $pdo->prepare(
    "SELECT rc.*, u.first_name, u.last_name, s.admission_no FROM report_cards rc
     JOIN students s ON s.student_id = rc.student_id
     JOIN users u ON u.user_id = s.user_id
     WHERE s.class_id = :cid AND rc.term_id = :term
     ORDER BY rc.overall_position ASC"
);
$stmt->execute(['cid' => $classId, 'term' => $termId]);
$reportCards = $stmt->fetchAll();

$pageTitle = 'Class Results';
require APP_ROOT . '/includes/header.php';
?>

<a href="javascript:history.back()" class="small mb-3 d-inline-block"><i class="fa fa-arrow-left me-1"></i> Back</a>

<h1 class="h4 mb-4"><?= e($class['level_name'] . ' ' . $class['stream_name']) ?> &mdash; Term Results</h1>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Position</th><th>Student</th><th>Admission No.</th><th>Average</th><th>GPA</th><th>Attendance</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($reportCards as $rc): ?>
          <tr>
            <td><?= $rc['overall_position'] ? '#' . e($rc['overall_position']) : '-' ?></td>
            <td><?= e($rc['first_name'] . ' ' . $rc['last_name']) ?></td>
            <td><code><?= e($rc['admission_no']) ?></code></td>
            <td class="fw-semibold"><?= $rc['overall_average'] !== null ? e($rc['overall_average']) . '%' : '-' ?></td>
            <td><?= $rc['overall_gpa'] !== null ? e($rc['overall_gpa']) : '-' ?></td>
            <td><?= $rc['attendance_percent'] !== null ? e($rc['attendance_percent']) . '%' : '-' ?></td>
            <td><span class="badge badge-status-<?= e($rc['status']) ?>"><?= e(ucfirst($rc['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($reportCards)): ?><tr><td colspan="7" class="text-center text-muted py-4">No results computed for this class/term yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
