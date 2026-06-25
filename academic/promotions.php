<?php
/**
 * academic/promotions.php
 * End-of-year promotion workflow: move students to the next class level
 * (or mark as repeated/graduated/transferred) for the new academic year.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'promote_batch') {
    csrf_verify();
    $fromClassId = (int) ($_POST['from_class_id'] ?? 0);
    $toClassId = (int) ($_POST['to_class_id'] ?? 0) ?: null;
    $fromYearId = (int) ($_POST['from_year_id'] ?? 0);
    $toYearId = (int) ($_POST['to_year_id'] ?? 0);
    $decision = $_POST['decision'] ?? 'promoted';

    $studentsStmt = $pdo->prepare("SELECT student_id FROM students WHERE class_id = :cid AND status = 'active'");
    $studentsStmt->execute(['cid' => $fromClassId]);
    $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    foreach ($studentIds as $sid) {
        $pdo->prepare(
            'INSERT INTO student_promotions (student_id, from_class_id, to_class_id, from_year_id, to_year_id, decision, decided_by)
             VALUES (:sid, :from_c, :to_c, :from_y, :to_y, :dec, :by)'
        )->execute(['sid' => $sid, 'from_c' => $fromClassId, 'to_c' => $toClassId, 'from_y' => $fromYearId, 'to_y' => $toYearId, 'dec' => $decision, 'by' => current_user_id()]);

        if ($decision === 'promoted' && $toClassId) {
            $pdo->prepare('UPDATE students SET class_id = :cid WHERE student_id = :sid')->execute(['cid' => $toClassId, 'sid' => $sid]);
        } elseif ($decision === 'graduated') {
            $pdo->prepare("UPDATE students SET status = 'graduated' WHERE student_id = :sid")->execute(['sid' => $sid]);
        } elseif ($decision === 'transferred') {
            $pdo->prepare("UPDATE students SET status = 'transferred' WHERE student_id = :sid")->execute(['sid' => $sid]);
        }
        // 'repeated' keeps the student in the same class_id deliberately (no change)
        $count++;
    }

    audit_log('promote_batch', 'academics', 'student_promotions', null, "Processed {$decision} for {$count} student(s)");
    flash_set('success', "Processed '{$decision}' for {$count} student(s).");
    redirect(app_url('/academic/promotions.php'));
}

$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name, c.year_id, y.year_name,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count
     FROM classes c JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     JOIN academic_years y ON y.year_id = c.year_id
     ORDER BY y.year_name DESC, cl.sort_order"
)->fetchAll();

$years = $pdo->query('SELECT * FROM academic_years ORDER BY start_date DESC')->fetchAll();

$recentPromotions = $pdo->query(
    "SELECT sp.*, u.first_name, u.last_name, fc.stream_name AS from_stream, fcl.level_name AS from_level,
        tc.stream_name AS to_stream, tcl.level_name AS to_level
     FROM student_promotions sp
     JOIN students s ON s.student_id = sp.student_id
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN classes fc ON fc.class_id = sp.from_class_id
     LEFT JOIN class_levels fcl ON fcl.class_level_id = fc.class_level_id
     LEFT JOIN classes tc ON tc.class_id = sp.to_class_id
     LEFT JOIN class_levels tcl ON tcl.class_level_id = tc.class_level_id
     ORDER BY sp.decided_at DESC LIMIT 20"
)->fetchAll();

$pageTitle = 'Promotions';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Student Promotions</h1>

<div class="card mb-4">
  <div class="card-header">Promote / Process a Class in Bulk</div>
  <div class="card-body">
    <form method="POST" data-confirm="This will apply the selected decision to every active student in the source class. Continue?">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="promote_batch">
      <div class="row g-2 mb-2">
        <div class="col-md-4">
          <label class="form-label">From Class</label>
          <select name="from_class_id" class="form-select" required id="fromClass">
            <option value="">-- Select --</option>
            <?php foreach ($classes as $c): ?>
              <option value="<?= (int) $c['class_id'] ?>" data-year="<?= (int) $c['year_id'] ?>"><?= e($c['year_name'] . ' - ' . $c['level_name'] . ' ' . $c['stream_name'] . ' (' . $c['student_count'] . ' students)') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">To Class (if promoting)</label>
          <select name="to_class_id" class="form-select">
            <option value="">-- None / Not Applicable --</option>
            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['class_id'] ?>"><?= e($c['year_name'] . ' - ' . $c['level_name'] . ' ' . $c['stream_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Decision</label>
          <select name="decision" class="form-select" required>
            <option value="promoted">Promoted</option>
            <option value="repeated">Repeated (stays in class)</option>
            <option value="graduated">Graduated</option>
            <option value="transferred">Transferred Out</option>
          </select>
        </div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="form-label">From Academic Year</label>
          <select name="from_year_id" class="form-select" required>
            <?php foreach ($years as $y): ?><option value="<?= (int) $y['year_id'] ?>"><?= e($y['year_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">To Academic Year</label>
          <select name="to_year_id" class="form-select" required>
            <?php foreach ($years as $y): ?><option value="<?= (int) $y['year_id'] ?>"><?= e($y['year_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-gold">Process Promotion</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Recent Promotion Records</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Student</th><th>From</th><th>To</th><th>Decision</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($recentPromotions as $p): ?>
          <tr>
            <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
            <td><?= e($p['from_level'] ? $p['from_level'] . ' ' . $p['from_stream'] : '-') ?></td>
            <td><?= e($p['to_level'] ? $p['to_level'] . ' ' . $p['to_stream'] : '-') ?></td>
            <td><span class="badge bg-secondary"><?= e(ucfirst($p['decision'])) ?></span></td>
            <td class="small text-muted"><?= e(date('d M Y', strtotime($p['decided_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recentPromotions)): ?><tr><td colspan="5" class="text-center text-muted py-4">No promotion records yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
