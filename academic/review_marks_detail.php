<?php
/**
 * academic/review_marks_detail.php
 * Shows the individual student marks within a single submitted batch,
 * so the Academic Department can spot-check before verifying/rejecting.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$examId = (int) ($_GET['exam_id'] ?? 0);
$classSubjectId = (int) ($_GET['class_subject_id'] ?? 0);

$infoStmt = $pdo->prepare(
    "SELECT e.exam_name, e.max_marks, sub.subject_name, cl.level_name, c.stream_name
     FROM exams e JOIN class_subjects cs ON cs.class_subject_id = :cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE e.exam_id = :exam"
);
$infoStmt->execute(['cs' => $classSubjectId, 'exam' => $examId]);
$info = $infoStmt->fetch();

if (!$info) {
    flash_set('error', 'Submission not found.');
    redirect(app_url('/academic/verify_marks.php'));
}

$marksStmt = $pdo->prepare(
    "SELECT em.*, u.first_name, u.last_name, s.admission_no FROM exam_marks em
     JOIN students s ON s.student_id = em.student_id
     JOIN users u ON u.user_id = s.user_id
     WHERE em.exam_id = :exam AND em.class_subject_id = :cs
     ORDER BY u.first_name"
);
$marksStmt->execute(['exam' => $examId, 'cs' => $classSubjectId]);
$marks = $marksStmt->fetchAll();

$pageTitle = 'Review Marks';
require APP_ROOT . '/includes/header.php';
?>

<a href="<?= e(app_url('/academic/verify_marks.php')) ?>" class="small mb-3 d-inline-block"><i class="fa fa-arrow-left me-1"></i> Back to pending list</a>

<h1 class="h4 mb-1"><?= e($info['exam_name']) ?> &mdash; <?= e($info['subject_name']) ?></h1>
<p class="text-muted mb-4"><?= e($info['level_name'] . ' ' . $info['stream_name']) ?> &middot; Max Marks: <?= e($info['max_marks']) ?></p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Admission No.</th><th>Student</th><th>Marks Obtained</th><th>Percentage</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($marks as $m): $pct = ($m['marks_obtained'] !== null && $info['max_marks'] > 0) ? round(($m['marks_obtained']/$info['max_marks'])*100,1) : null; ?>
          <tr>
            <td><code><?= e($m['admission_no']) ?></code></td>
            <td><?= e($m['first_name'] . ' ' . $m['last_name']) ?></td>
            <td><?= $m['is_absent'] ? '<span class="text-muted">Absent</span>' : e($m['marks_obtained']) ?></td>
            <td><?= $pct !== null ? e($pct) . '%' : '-' ?></td>
            <td><span class="badge badge-status-<?= $m['verification_status']==='verified' ? 'verified' : ($m['verification_status']==='rejected' ? 'rejected' : 'pending') ?>"><?= e(ucfirst($m['verification_status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($marks)): ?><tr><td colspan="5" class="text-center text-muted py-4">No marks found for this submission.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
