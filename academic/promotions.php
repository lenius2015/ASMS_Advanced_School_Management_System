<?php
/**
 * academic/promotions.php
 * Student promotion workflow: promote individual students or batch,
 * move to next class level, repeat, graduate, or transfer.
 * Fixed: proper year handling, individual promotions, validation.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'head_of_school', 'director']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

// ----- BATCH PROMOTION -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'promote_batch') {
    csrf_verify();
    $fromClassId = (int) ($_POST['from_class_id'] ?? 0);
    $toClassId = (int) ($_POST['to_class_id'] ?? 0) ?: null;
    $decision = $_POST['decision'] ?? 'promoted';
    $allowedDecisions = ['promoted', 'repeated', 'graduated', 'transferred'];

    if ($fromClassId <= 0 || !in_array($decision, $allowedDecisions)) {
        flash_set('error', 'Please select a valid class and decision.');
        redirect(app_url('/academic/promotions.php'));
    }

    // Get current year for "from" and "to"
    $yearInfo = $pdo->query("SELECT year_id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch();
    $currentYearId = $yearInfo ? (int) $yearInfo['year_id'] : 0;

    // Get next academic year for promotions
    $nextYear = $pdo->query(
        "SELECT year_id FROM academic_years WHERE start_date > (SELECT end_date FROM academic_years WHERE year_id = :yid) ORDER BY start_date ASC LIMIT 1"
    );
    $nextYear->execute(['yid' => $currentYearId]);
    $nextYearId = $nextYear->fetchColumn();
    $nextYearId = $nextYearId ? (int) $nextYearId : $currentYearId;

    // Get students from source class
    $stmt = $pdo->prepare("SELECT student_id, admission_no FROM students WHERE class_id = :cid AND status = 'active'");
    $stmt->execute(['cid' => $fromClassId]);
    $students = $stmt->fetchAll();

    if (empty($students)) {
        flash_set('error', 'No active students found in the selected class.');
        redirect(app_url('/academic/promotions.php'));
    }

    $count = 0;
    try {
        $pdo->beginTransaction();

        foreach ($students as $s) {
            $sid = (int) $s['student_id'];

            // Insert promotion record
            $pdo->prepare(
                'INSERT INTO student_promotions (student_id, from_class_id, to_class_id, from_year_id, to_year_id, decision, decided_by)
                 VALUES (:sid, :from_c, :to_c, :from_y, :to_y, :dec, :by)'
            )->execute([
                'sid' => $sid,
                'from_c' => $fromClassId,
                'to_c' => $decision === 'promoted' ? $toClassId : null,
                'from_y' => $currentYearId,
                'to_y' => $decision === 'promoted' ? $nextYearId : $currentYearId,
                'dec' => $decision,
                'by' => current_user_id(),
            ]);

            // Update student record based on decision
            if ($decision === 'promoted' && $toClassId > 0) {
                $pdo->prepare('UPDATE students SET class_id = :cid WHERE student_id = :sid')
                    ->execute(['cid' => $toClassId, 'sid' => $sid]);
            } elseif ($decision === 'graduated') {
                $pdo->prepare("UPDATE students SET status = 'graduated' WHERE student_id = :sid")
                    ->execute(['sid' => $sid]);
            } elseif ($decision === 'transferred') {
                $pdo->prepare("UPDATE students SET status = 'transferred' WHERE student_id = :sid")
                    ->execute(['sid' => $sid]);
            }
            // 'repeated' keeps student in same class
            $count++;
        }

        $pdo->commit();
        audit_log('promote_batch', 'academics', 'student_promotions', null, "Processed {$decision} for {$count} student(s)");
        flash_set('success', "Processed '{$decision}' for {$count} student(s).");
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[ASMS] promote_batch failed: ' . $e->getMessage());
        flash_set('error', 'Promotion failed. Please try again.');
    }
    redirect(app_url('/academic/promotions.php'));
}

// ----- INDIVIDUAL STUDENT PROMOTION -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'promote_individual') {
    csrf_verify();
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $toClassId = (int) ($_POST['to_class_id'] ?? 0) ?: null;
    $decision = $_POST['decision'] ?? 'promoted';
    $allowedDecisions = ['promoted', 'repeated', 'graduated', 'transferred'];

    if ($studentId <= 0 || !in_array($decision, $allowedDecisions)) {
        flash_set('error', 'Invalid student or decision.');
        redirect(app_url('/academic/promotions.php'));
    }

    // Get student current class and year
    $stuStmt = $pdo->prepare("SELECT s.class_id, s.status FROM students s WHERE s.student_id = :id");
    $stuStmt->execute(['id' => $studentId]);
    $student = $stuStmt->fetch();

    if (!$student || $student['status'] !== 'active') {
        flash_set('error', 'Student not found or not active.');
        redirect(app_url('/academic/promotions.php'));
    }

    $fromClassId = (int) $student['class_id'];
    $yearInfo = $pdo->query("SELECT year_id FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch();
    $currentYearId = $yearInfo ? (int) $yearInfo['year_id'] : 0;

    $nextYear = $pdo->query(
        "SELECT year_id FROM academic_years WHERE start_date > (SELECT end_date FROM academic_years WHERE year_id = :yid) ORDER BY start_date ASC LIMIT 1"
    );
    $nextYear->execute(['yid' => $currentYearId]);
    $nextYearId = $nextYear->fetchColumn();
    $nextYearId = $nextYearId ? (int) $nextYearId : $currentYearId;

    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO student_promotions (student_id, from_class_id, to_class_id, from_year_id, to_year_id, decision, decided_by)
             VALUES (:sid, :from_c, :to_c, :from_y, :to_y, :dec, :by)'
        )->execute([
            'sid' => $studentId,
            'from_c' => $fromClassId,
            'to_c' => $decision === 'promoted' ? $toClassId : null,
            'from_y' => $currentYearId,
            'to_y' => $decision === 'promoted' ? $nextYearId : $currentYearId,
            'dec' => $decision,
            'by' => current_user_id(),
        ]);

        if ($decision === 'promoted' && $toClassId > 0) {
            $pdo->prepare('UPDATE students SET class_id = :cid WHERE student_id = :sid')
                ->execute(['cid' => $toClassId, 'sid' => $studentId]);
        } elseif ($decision === 'graduated') {
            $pdo->prepare("UPDATE students SET status = 'graduated' WHERE student_id = :sid")
                ->execute(['sid' => $studentId]);
        } elseif ($decision === 'transferred') {
            $pdo->prepare("UPDATE students SET status = 'transferred' WHERE student_id = :sid")
                ->execute(['sid' => $studentId]);
        }

        $pdo->commit();
        audit_log('promote_individual', 'academics', 'student_promotions', $studentId, "Processed individual {$decision}");
        flash_set('success', "Student processed as '{$decision}'.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[ASMS] promote_individual failed: ' . $e->getMessage());
        flash_set('error', 'Failed to process student promotion.');
    }
    redirect(app_url('/academic/promotions.php'));
}

// ----- REVERT PROMOTION -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revert_promotion') {
    csrf_verify();
    $promotionId = (int) ($_POST['promotion_id'] ?? 0);
    if ($promotionId > 0) {
        try {
            $pdo->beginTransaction();

            // Get promotion details
            $promStmt = $pdo->prepare("SELECT * FROM student_promotions WHERE promotion_id = :id");
            $promStmt->execute(['id' => $promotionId]);
            $prom = $promStmt->fetch();

            if ($prom) {
                $studentId = (int) $prom['student_id'];
                $fromClassId = (int) $prom['from_class_id'];
                $decision = $prom['decision'];

                // Revert student back
                if ($decision === 'promoted' && $fromClassId > 0) {
                    $pdo->prepare('UPDATE students SET class_id = :cid WHERE student_id = :sid')
                        ->execute(['cid' => $fromClassId, 'sid' => $studentId]);
                } elseif (in_array($decision, ['graduated', 'transferred'])) {
                    $pdo->prepare("UPDATE students SET status = 'active' WHERE student_id = :sid")
                        ->execute(['sid' => $studentId]);
                }

                // Delete promotion record
                $pdo->prepare("DELETE FROM student_promotions WHERE promotion_id = :id")
                    ->execute(['id' => $promotionId]);

                $pdo->commit();
                audit_log('revert_promotion', 'academics', 'student_promotions', $promotionId, 'Reverted promotion');
                flash_set('success', 'Promotion reverted successfully.');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[ASMS] revert_promotion failed: ' . $e->getMessage());
            flash_set('error', 'Failed to revert promotion.');
        }
    }
    redirect(app_url('/academic/promotions.php'));
}

// ----- DATA FETCHING -----
$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name, c.year_id, y.year_name,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count
     FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     JOIN academic_years y ON y.year_id = c.year_id
     ORDER BY y.year_name DESC, cl.sort_order"
)->fetchAll();

$years = $pdo->query('SELECT * FROM academic_years ORDER BY start_date DESC')->fetchAll();

// Get promotions with student details
$recentPromotions = $pdo->query(
    "SELECT sp.*, u.first_name, u.last_name, s.admission_no,
        fc.stream_name AS from_stream, fcl.level_name AS from_level,
        tc.stream_name AS to_stream, tcl.level_name AS to_level,
        decider.first_name AS decider_fn, decider.last_name AS decider_ln
     FROM student_promotions sp
     JOIN students s ON s.student_id = sp.student_id
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN classes fc ON fc.class_id = sp.from_class_id
     LEFT JOIN class_levels fcl ON fcl.class_level_id = fc.class_level_id
     LEFT JOIN classes tc ON tc.class_id = sp.to_class_id
     LEFT JOIN class_levels tcl ON tcl.class_level_id = tc.class_level_id
     LEFT JOIN users decider ON decider.user_id = sp.decided_by
     ORDER BY sp.decided_at DESC LIMIT 50"
)->fetchAll();

// Get all active students for individual promotion
$individualSearch = trim($_GET['student_search'] ?? '');
$individualStudents = [];
if ($individualSearch !== '') {
    $indStmt = $pdo->prepare(
        "SELECT s.student_id, s.admission_no, u.first_name, u.last_name, cl.level_name, c.stream_name, c.class_id
         FROM students s
         JOIN users u ON u.user_id = s.user_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE s.status = 'active' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3)
         ORDER BY u.first_name LIMIT 20"
    );
    $searchTerm = "%{$individualSearch}%";
    $indStmt->execute(['s1' => $searchTerm, 's2' => $searchTerm, 's3' => $searchTerm]);
    $individualStudents = $indStmt->fetchAll();
}

// Get all classes for destination dropdown
$allClasses = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name, y.year_name
     FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     JOIN academic_years y ON y.year_id = c.year_id
     ORDER BY y.year_name DESC, cl.sort_order, c.stream_name"
)->fetchAll();

$pageTitle = 'Student Promotions';
require APP_ROOT . '/includes/header.php';
?>
<style>
.promotion-badge { font-size: 0.75rem; padding: 0.2rem 0.5rem; }
.search-result-item { cursor: pointer; transition: background 0.2s; }
.search-result-item:hover { background: #f0f4f8; }
.search-result-item.selected { background: #e2e8f0; border-left: 3px solid #C8932A; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="fa fa-level-up-alt text-gold me-2"></i>Student Promotions</h1>
    <div>
        <span class="text-muted small"><?= count($recentPromotions) ?> promotion records</span>
    </div>
</div>

<!-- Batch Promotion Card -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-users text-gold me-2"></i>Batch Promotion (Process Entire Class)</div>
    <div class="card-body">
        <form method="POST" data-confirm="This will apply the selected decision to EVERY active student in the source class. Continue?">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="promote_batch">
            <div class="row g-2 mb-2">
                <div class="col-md-4">
                    <label class="form-label">From Class <span class="required-mark">*</span></label>
                    <select name="from_class_id" class="form-select" required>
                        <option value="">-- Select Source Class --</option>
                        <?php foreach ($classes as $c): ?>
                            <?php if ((int) $c['student_count'] > 0): ?>
                                <option value="<?= (int) $c['class_id'] ?>">
                                    <?= e($c['year_name'] . ' - ' . $c['level_name'] . ' ' . $c['stream_name']) ?>
                                    (<?= (int) $c['student_count'] ?> students)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Decision <span class="required-mark">*</span></label>
                    <select name="decision" class="form-select" required id="batchDecision">
                        <option value="promoted">Promoted to Next Class</option>
                        <option value="repeated">Repeated (Stays in Same Class)</option>
                        <option value="graduated">Graduated</option>
                        <option value="transferred">Transferred Out</option>
                    </select>
                </div>
                <div class="col-md-4" id="batchTargetClass">
                    <label class="form-label">To Class (if promoting)</label>
                    <select name="to_class_id" class="form-select">
                        <option value="">-- Select Destination Class --</option>
                        <?php foreach ($allClasses as $c): ?>
                            <option value="<?= (int) $c['class_id'] ?>"><?= e($c['year_name'] . ' - ' . $c['level_name'] . ' ' . $c['stream_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-gold"><i class="fa fa-arrow-right me-1"></i> Process Batch Promotion</button>
        </form>
    </div>
</div>

<!-- Individual Promotion Card -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-user text-gold me-2"></i>Individual Student Promotion</div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-6">
                <div class="input-group">
                    <input type="text" name="student_search" class="form-control" placeholder="Search student by name or admission no..." value="<?= e($individualSearch) ?>">
                    <button class="btn btn-outline-primary"><i class="fa fa-search"></i></button>
                </div>
            </div>
            <div class="col-md-2">
                <a href="<?= e(app_url('/academic/promotions.php')) ?>" class="btn btn-outline-secondary"><i class="fa fa-times"></i> Clear</a>
            </div>
        </form>

        <?php if ($individualSearch !== ''): ?>
            <?php if (!empty($individualStudents)): ?>
                <form method="POST" data-confirm="Process this student?">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="promote_individual">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th>Current Class</th>
                                    <th>To Class</th>
                                    <th>Decision</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($individualStudents as $stu): ?>
                                    <tr>
                                        <td><code><?= e($stu['admission_no']) ?></code></td>
                                        <td><?= e($stu['first_name'] . ' ' . $stu['last_name']) ?></td>
                                        <td><?= e($stu['level_name'] ? $stu['level_name'] . ' ' . $stu['stream_name'] : 'Unassigned') ?></td>
                                        <td>
                                            <select name="to_class_id" class="form-select form-select-sm" style="min-width:150px;">
                                                <option value="">-- Same Class --</option>
                                                <?php foreach ($allClasses as $c): ?>
                                                    <option value="<?= (int) $c['class_id'] ?>" <?= (int) $c['class_id'] === (int) $stu['class_id'] ? 'disabled' : '' ?>>
                                                        <?= e($c['year_name'] . ' - ' . $c['level_name'] . ' ' . $c['stream_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="decision" class="form-select form-select-sm">
                                                <option value="promoted">Promoted</option>
                                                <option value="repeated">Repeated</option>
                                                <option value="graduated">Graduated</option>
                                                <option value="transferred">Transferred</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="hidden" name="student_id" value="<?= (int) $stu['student_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-arrow-right"></i> Process</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php else: ?>
                <div class="text-center text-muted py-3">
                    <i class="fa fa-user fa-2x mb-2"></i>
                    <p class="mb-0">No active students found matching "<?= e($individualSearch) ?>".</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center text-muted py-3">
                <i class="fa fa-search fa-2x mb-2"></i>
                <p class="mb-0">Search for a student above to process an individual promotion.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Promotion Records -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-history text-gold me-2"></i>Promotion Records</span>
        <span class="badge bg-primary"><?= count($recentPromotions) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Admission No.</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Decision</th>
                    <th>Processed By</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentPromotions as $p): ?>
                    <tr>
                        <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                        <td><code><?= e($p['admission_no']) ?></code></td>
                        <td class="small"><?= e($p['from_level'] ? $p['from_level'] . ' ' . $p['from_stream'] : '-') ?></td>
                        <td class="small"><?= e($p['to_level'] ? $p['to_level'] . ' ' . $p['to_stream'] : '-') ?></td>
                        <td>
                            <?php
                                $decisionColors = ['promoted'=>'success', 'repeated'=>'warning', 'graduated'=>'info', 'transferred'=>'secondary'];
                                $dColor = $decisionColors[$p['decision']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $dColor ?>"><?= e(ucfirst($p['decision'])) ?></span>
                        </td>
                        <td class="small text-muted"><?= e($p['decider_fn'] ? $p['decider_fn'] . ' ' . $p['decider_ln'] : '-') ?></td>
                        <td class="small text-muted"><?= e(date('d M Y', strtotime($p['decided_at']))) ?></td>
                        <td>
                            <form method="POST" class="d-inline" data-confirm="Revert this promotion? The student will be returned to their original class/status.">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="revert_promotion">
                                <input type="hidden" name="promotion_id" value="<?= (int) $p['promotion_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Revert Promotion"><i class="fa fa-undo"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentPromotions)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No promotion records yet. Use the forms above to promote students.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle target class visibility based on decision
    var decisionSelect = document.getElementById('batchDecision');
    var targetClassDiv = document.getElementById('batchTargetClass');

    function toggleTargetClass() {
        if (decisionSelect.value === 'promoted') {
            targetClassDiv.style.display = 'block';
        } else {
            targetClassDiv.style.display = 'none';
        }
    }

    if (decisionSelect) {
        decisionSelect.addEventListener('change', toggleTargetClass);
        toggleTargetClass();
    }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>