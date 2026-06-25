<?php
/**
 * academic/publish_results.php
 * Step 5 of the workflow: compute each student's overall average/GPA/
 * position from verified term_results, generate (or refresh) their
 * report_cards row, and publish to parents/students when ready.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$period = get_current_period($pdo);
$termId = (int) ($_GET['term_id'] ?? $period['term_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'compute_class') {
    csrf_verify();
    $classId = (int) ($_POST['class_id'] ?? 0);
    $termIdPost = (int) ($_POST['term_id'] ?? 0);

    $studentsStmt = $pdo->prepare("SELECT student_id FROM students WHERE class_id = :cid AND status = 'active'");
    $studentsStmt->execute(['cid' => $classId]);
    $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

    $averages = [];
    foreach ($studentIds as $sid) {
        $resStmt = $pdo->prepare(
            "SELECT AVG(average_marks) AS avg_score, AVG(gpa) AS avg_gpa FROM term_results
             WHERE student_id = :sid AND term_id = :term"
        );
        $resStmt->execute(['sid' => $sid, 'term' => $termIdPost]);
        $r = $resStmt->fetch();

        $attStmt = $pdo->prepare(
            "SELECT SUM(status='present') AS present_days, COUNT(*) AS total_days FROM student_attendance
             WHERE student_id = :sid"
        );
        $attStmt->execute(['sid' => $sid]);
        $att = $attStmt->fetch();
        $attendancePct = ($att && $att['total_days'] > 0) ? round(($att['present_days'] / $att['total_days']) * 100, 1) : null;

        $averages[$sid] = [
            'avg' => $r['avg_score'] !== null ? round((float) $r['avg_score'], 2) : null,
            'gpa' => $r['avg_gpa'] !== null ? round((float) $r['avg_gpa'], 2) : null,
            'attendance' => $attendancePct,
        ];
    }

    // Rank by average to compute class position
    $ranked = $averages;
    uasort($ranked, fn($a, $b) => ($b['avg'] ?? -1) <=> ($a['avg'] ?? -1));
    $position = 0;
    $classSize = count($studentIds);
    $positions = [];
    foreach ($ranked as $sid => $data) {
        $position++;
        $positions[$sid] = $position;
    }

    foreach ($averages as $sid => $data) {
        $pdo->prepare(
            'INSERT INTO report_cards (student_id, term_id, overall_average, overall_gpa, overall_position, class_size, attendance_percent, status)
             VALUES (:sid, :term, :avg, :gpa, :pos, :size, :att, "draft")
             ON DUPLICATE KEY UPDATE overall_average = :avg2, overall_gpa = :gpa2, overall_position = :pos2, class_size = :size2, attendance_percent = :att2'
        )->execute([
            'sid' => $sid, 'term' => $termIdPost, 'avg' => $data['avg'], 'gpa' => $data['gpa'],
            'pos' => $positions[$sid], 'size' => $classSize, 'att' => $data['attendance'],
            'avg2' => $data['avg'], 'gpa2' => $data['gpa'], 'pos2' => $positions[$sid], 'size2' => $classSize, 'att2' => $data['attendance'],
        ]);
    }

    audit_log('compute_report_cards', 'academics', 'report_cards', $classId, "Computed report cards for class #{$classId}, term #{$termIdPost}");
    flash_set('success', "Computed report cards for {$classSize} student(s). Review below, then publish when ready.");
    redirect(app_url('/academic/publish_results.php') . '?term_id=' . $termIdPost);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish') {
    csrf_verify();
    $classId = (int) ($_POST['class_id'] ?? 0);
    $termIdPost = (int) ($_POST['term_id'] ?? 0);

    $stmt = $pdo->prepare(
        "UPDATE report_cards rc
         JOIN students s ON s.student_id = rc.student_id
         SET rc.status = 'published', rc.published_by = :by, rc.published_at = NOW()
         WHERE s.class_id = :cid AND rc.term_id = :term AND rc.status = 'draft'"
    );
    $stmt->execute(['by' => current_user_id(), 'cid' => $classId, 'term' => $termIdPost]);
    $count = $stmt->rowCount();

    // Notify guardians and students
    $studentsStmt = $pdo->prepare("SELECT s.student_id, s.user_id FROM students s WHERE s.class_id = :cid");
    $studentsStmt->execute(['cid' => $classId]);
    foreach ($studentsStmt->fetchAll() as $s) {
        if ($s['user_id']) {
            notify_user($pdo, (int) $s['user_id'], 'Results Published', 'Your report card for this term has been published.', 'result', app_url('/student/results.php'));
        }
        $gStmt = $pdo->prepare("SELECT g.user_id FROM guardians g JOIN student_guardians sg ON sg.guardian_id = g.guardian_id WHERE sg.student_id = :sid AND g.user_id IS NOT NULL");
        $gStmt->execute(['sid' => $s['student_id']]);
        foreach ($gStmt->fetchAll() as $g) {
            notify_user($pdo, (int) $g['user_id'], 'Results Published', "Your child's report card for this term has been published.", 'result', app_url('/parent/results.php'));
        }
    }

    audit_log('publish_results', 'academics', 'report_cards', $classId, "Published {$count} report card(s)");
    flash_set('success', "Published {$count} report card(s) for this class. Parents and students have been notified.");
    redirect(app_url('/academic/publish_results.php') . '?term_id=' . $termIdPost);
}

$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count,
        (SELECT COUNT(*) FROM report_cards rc JOIN students s2 ON s2.student_id = rc.student_id WHERE s2.class_id = c.class_id AND rc.term_id = " . (int) $termId . " AND rc.status='draft') AS draft_count,
        (SELECT COUNT(*) FROM report_cards rc JOIN students s3 ON s3.student_id = rc.student_id WHERE s3.class_id = c.class_id AND rc.term_id = " . (int) $termId . " AND rc.status='published') AS published_count
     FROM classes c JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$terms = $pdo->query('SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id ORDER BY t.start_date DESC')->fetchAll();

$pageTitle = 'Publish Results';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Compute &amp; Publish Results</h1>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4">
        <label class="form-label">Term</label>
        <select name="term_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($terms as $t): ?><option value="<?= (int) $t['term_id'] ?>" <?= $termId === (int) $t['term_id'] ? 'selected' : '' ?>><?= e($t['year_name'] . ' - ' . $t['term_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Class</th><th>Students</th><th>Draft Reports</th><th>Published Reports</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($classes as $c): ?>
          <tr>
            <td><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></td>
            <td><?= (int) $c['student_count'] ?></td>
            <td><span class="badge bg-secondary"><?= (int) $c['draft_count'] ?></span></td>
            <td><span class="badge bg-success"><?= (int) $c['published_count'] ?></span></td>
            <td class="text-nowrap">
              <form method="POST" class="d-inline" data-confirm="Compute/refresh report cards for this class?">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="compute_class">
                <input type="hidden" name="class_id" value="<?= (int) $c['class_id'] ?>">
                <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                <button class="btn btn-sm btn-outline-primary"><i class="fa fa-calculator"></i> Compute</button>
              </form>
              <form method="POST" class="d-inline" data-confirm="Publish all draft report cards for this class? Parents and students will be notified immediately.">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="publish">
                <input type="hidden" name="class_id" value="<?= (int) $c['class_id'] ?>">
                <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                <button class="btn btn-sm btn-gold" <?= $c['draft_count'] == 0 ? 'disabled' : '' ?>><i class="fa fa-bullhorn"></i> Publish</button>
              </form>
              <a href="<?= e(app_url('/academic/class_results.php')) ?>?class_id=<?= (int) $c['class_id'] ?>&term_id=<?= (int) $termId ?>" class="btn btn-sm btn-outline-secondary">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
