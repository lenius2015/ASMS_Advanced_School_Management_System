<?php
/**
 * academic/exams.php
 * Schedule and manage examinations. Supports full CRUD:
 * Create, Edit, Delete, and status updates.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();

// ---- CREATE EXAM ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_exam') {
    csrf_verify();
    $examTypeId = (int) ($_POST['exam_type_id'] ?? 0);
    $termId = (int) ($_POST['term_id'] ?? 0);
    $examName = trim($_POST['exam_name'] ?? '');
    $startDate = $_POST['start_date'] ?? null;
    $endDate = $_POST['end_date'] ?? null;
    $maxMarks = (float) ($_POST['max_marks'] ?? 100);

    if ($examTypeId <= 0 || $termId <= 0 || $examName === '') {
        flash_set('error', 'Exam type, term, and name are required.');
    } else {
        $pdo->prepare(
            'INSERT INTO exams (exam_type_id, term_id, exam_name, start_date, end_date, max_marks, status, created_by)
             VALUES (:type, :term, :name, :start, :end, :max, "scheduled", :by)'
        )->execute([
            'type' => $examTypeId, 'term' => $termId, 'name' => $examName,
            'start' => $startDate ?: null, 'end' => $endDate ?: null, 'max' => $maxMarks, 'by' => current_user_id(),
        ]);
        $examId = (int) $pdo->lastInsertId();
        audit_log('create_exam', 'academics', 'exams', $examId, "Scheduled exam: {$examName}");
        flash_set('success', 'Exam scheduled successfully.');
    }
    redirect(app_url('/academic/exams.php'));
}

// ---- EDIT EXAM ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_exam') {
    csrf_verify();
    $examId = (int) ($_POST['exam_id'] ?? 0);
    $examTypeId = (int) ($_POST['exam_type_id'] ?? 0);
    $termId = (int) ($_POST['term_id'] ?? 0);
    $examName = trim($_POST['exam_name'] ?? '');
    $startDate = $_POST['start_date'] ?? null;
    $endDate = $_POST['end_date'] ?? null;
    $maxMarks = (float) ($_POST['max_marks'] ?? 100);

    if ($examId <= 0 || $examName === '') {
        flash_set('error', 'Exam name is required.');
    } else {
        $pdo->prepare(
            'UPDATE exams SET exam_type_id = :type, term_id = :term, exam_name = :name,
             start_date = :start, end_date = :end, max_marks = :max WHERE exam_id = :id'
        )->execute([
            'type' => $examTypeId, 'term' => $termId, 'name' => $examName,
            'start' => $startDate ?: null, 'end' => $endDate ?: null, 'max' => $maxMarks, 'id' => $examId,
        ]);
        audit_log('edit_exam', 'academics', 'exams', $examId, "Edited exam: {$examName}");
        flash_set('success', 'Exam updated successfully.');
    }
    redirect(app_url('/academic/exams.php'));
}

// ---- DELETE EXAM ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_exam') {
    csrf_verify();
    $examId = (int) ($_POST['exam_id'] ?? 0);
    if ($examId > 0) {
        try {
            // Check if marks have been entered
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_marks WHERE exam_id = :id AND marks_obtained IS NOT NULL");
            $stmt->execute(['id' => $examId]);
            $markCount = (int) $stmt->fetchColumn();

            if ($markCount > 0) {
                flash_set('error', "Cannot delete exam with {$markCount} existing mark(s). Remove marks first.");
            } else {
                $pdo->prepare('DELETE FROM exam_marks WHERE exam_id = :id')->execute(['id' => $examId]);
                $pdo->prepare('DELETE FROM exams WHERE exam_id = :id')->execute(['id' => $examId]);
                audit_log('delete_exam', 'academics', 'exams', $examId, 'Deleted exam');
                flash_set('success', 'Exam deleted successfully.');
            }
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete exam. It may have related records.');
        }
    }
    redirect(app_url('/academic/exams.php'));
}

// ---- STATUS UPDATE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    csrf_verify();
    $examId = (int) ($_POST['exam_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $validStatuses = ['scheduled','ongoing','marks_pending','submitted','verified','published'];
    if (in_array($status, $validStatuses, true)) {
        $pdo->prepare('UPDATE exams SET status = :s WHERE exam_id = :id')->execute(['s' => $status, 'id' => $examId]);
        audit_log('update_exam_status', 'academics', 'exams', $examId, "Status changed to {$status}");
        flash_set('success', 'Exam status updated.');
    }
    redirect(app_url('/academic/exams.php'));
}

$exams = $pdo->query(
    "SELECT e.*, et.type_name, t.term_name, y.year_name FROM exams e
     JOIN exam_types et ON et.exam_type_id = e.exam_type_id
     JOIN terms t ON t.term_id = e.term_id
     JOIN academic_years y ON y.year_id = t.year_id
     ORDER BY e.created_at DESC LIMIT 100"
)->fetchAll();

$examTypes = $pdo->query('SELECT * FROM exam_types ORDER BY type_name')->fetchAll();
$terms = $pdo->query('SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id ORDER BY t.start_date DESC')->fetchAll();

$pageTitle = 'Examinations';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">Examinations</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newExamModal"><i class="fa fa-plus me-1"></i> Schedule Exam</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Exam Name</th><th>Type</th><th>Term</th><th>Dates</th><th>Max Marks</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($exams as $ex): ?>
          <tr>
            <td><?= e($ex['exam_name']) ?></td>
            <td><?= e($ex['type_name']) ?></td>
            <td><?= e($ex['year_name'] . ' - ' . $ex['term_name']) ?></td>
            <td class="small"><?= format_date($ex['start_date']) ?> &ndash; <?= format_date($ex['end_date']) ?></td>
            <td><?= e($ex['max_marks']) ?></td>
            <td>
              <form method="POST" class="d-flex gap-1">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="exam_id" value="<?= (int) $ex['exam_id'] ?>">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width:130px;">
                  <?php foreach (['scheduled','ongoing','marks_pending','submitted','verified','published'] as $st): ?>
                    <option value="<?= $st ?>" <?= $ex['status'] === $st ? 'selected' : '' ?>><?= e(str_replace('_',' ',ucfirst($st))) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editExamModal<?= (int) $ex['exam_id'] ?>" title="Edit"><i class="fa fa-edit"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete exam <?= e($ex['exam_name']) ?>? This cannot be undone.')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_exam">
                  <input type="hidden" name="exam_id" value="<?= (int) $ex['exam_id'] ?>">
                  <button class="btn btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>

          <!-- Edit Exam Modal -->
          <div class="modal fade" id="editExamModal<?= (int) $ex['exam_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="POST">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="edit_exam">
                  <input type="hidden" name="exam_id" value="<?= (int) $ex['exam_id'] ?>">
                  <div class="modal-header"><h5 class="modal-title">Edit Exam</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body">
                    <div class="mb-2"><label class="form-label">Exam Name <span class="required-mark">*</span></label><input type="text" name="exam_name" class="form-control" required value="<?= e($ex['exam_name']) ?>"></div>
                    <div class="row g-2 mb-2">
                      <div class="col-6"><label class="form-label">Exam Type</label>
                        <select name="exam_type_id" class="form-select" required>
                          <?php foreach ($examTypes as $t): ?>
                            <option value="<?= (int) $t['exam_type_id'] ?>" <?= $ex['exam_type_id'] == $t['exam_type_id'] ? 'selected' : '' ?>><?= e($t['type_name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-6"><label class="form-label">Term</label>
                        <select name="term_id" class="form-select" required>
                          <?php foreach ($terms as $t): ?>
                            <option value="<?= (int) $t['term_id'] ?>" <?= $ex['term_id'] == $t['term_id'] ? 'selected' : '' ?>><?= e($t['year_name'] . ' - ' . $t['term_name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <div class="row g-2 mb-2">
                      <div class="col-4"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= e($ex['start_date'] ?? '') ?>"></div>
                      <div class="col-4"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= e($ex['end_date'] ?? '') ?>"></div>
                      <div class="col-4"><label class="form-label">Max Marks</label><input type="number" name="max_marks" class="form-control" value="<?= e($ex['max_marks']) ?>"></div>
                    </div>
                  </div>
                  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($exams)): ?><tr><td colspan="7" class="text-center text-muted py-4">No exams scheduled yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create Exam Modal -->
<div class="modal fade" id="newExamModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_exam">
        <div class="modal-header"><h5 class="modal-title">Schedule New Exam</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Exam Name <span class="required-mark">*</span></label><input type="text" name="exam_name" class="form-control" required placeholder="e.g. Mid-Term 1 Examination"></div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label">Exam Type</label>
              <select name="exam_type_id" class="form-select" required><?php foreach ($examTypes as $t): ?><option value="<?= (int) $t['exam_type_id'] ?>"><?= e($t['type_name']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-6"><label class="form-label">Term</label>
              <select name="term_id" class="form-select" required><?php foreach ($terms as $t): ?><option value="<?= (int) $t['term_id'] ?>"><?= e($t['year_name'] . ' - ' . $t['term_name']) ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-4"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control"></div>
            <div class="col-4"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control"></div>
            <div class="col-4"><label class="form-label">Max Marks</label><input type="number" name="max_marks" class="form-control" value="100"></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Schedule</button></div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>