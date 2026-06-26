<?php
/**
 * academic/class_levels.php
 * Manage class levels (Form 1, Form 2, Form 3, Form 4, Grade 7, etc.)
 * Create, edit, delete class levels and reorder them.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'head_of_school', 'director']);

$pdo = get_db_connection();

// ---- Create Class Level ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_level') {
    csrf_verify();
    $levelName = trim($_POST['level_name'] ?? '');
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($levelName === '') {
        flash_set('error', 'Level name is required.');
    } else {
        try {
            $pdo->prepare('INSERT INTO class_levels (level_name, sort_order) VALUES (:name, :sort)')
                ->execute(['name' => $levelName, 'sort' => $sortOrder]);
            audit_log('create_class_level', 'academics', 'class_levels', (int) $pdo->lastInsertId(), "Created class level: {$levelName}");
            flash_set('success', "Class level '{$levelName}' created.");
        } catch (PDOException $e) {
            flash_set('error', 'That class level name already exists.');
        }
    }
    redirect(app_url('/academic/class_levels.php'));
}

// ---- Edit Class Level ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_level') {
    csrf_verify();
    $levelId = (int) ($_POST['level_id'] ?? 0);
    $levelName = trim($_POST['level_name'] ?? '');
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($levelId <= 0 || $levelName === '') {
        flash_set('error', 'Level ID and name are required.');
    } else {
        try {
            $pdo->prepare('UPDATE class_levels SET level_name = :name, sort_order = :sort WHERE class_level_id = :id')
                ->execute(['name' => $levelName, 'sort' => $sortOrder, 'id' => $levelId]);
            audit_log('edit_class_level', 'academics', 'class_levels', $levelId, "Edited class level: {$levelName}");
            flash_set('success', 'Class level updated.');
        } catch (PDOException $e) {
            flash_set('error', 'Failed to update class level.');
        }
    }
    redirect(app_url('/academic/class_levels.php'));
}

// ---- Delete Class Level ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_level') {
    csrf_verify();
    $levelId = (int) ($_POST['level_id'] ?? 0);
    if ($levelId > 0) {
        try {
            // Check if any classes use this level
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE class_level_id = :id");
            $stmt->execute(['id' => $levelId]);
            $classCount = (int) $stmt->fetchColumn();

            if ($classCount > 0) {
                flash_set('error', "Cannot delete: {$classCount} class(es) use this level. Delete or reassign them first.");
            } else {
                $pdo->prepare('DELETE FROM class_levels WHERE class_level_id = :id')->execute(['id' => $levelId]);
                audit_log('delete_class_level', 'academics', 'class_levels', $levelId, 'Deleted class level');
                flash_set('success', 'Class level deleted.');
            }
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete class level. It may have related records.');
        }
    }
    redirect(app_url('/academic/class_levels.php'));
}

$levels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();

$pageTitle = 'Class Levels';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="fa fa-layer-group text-gold me-2"></i>Class Levels</h1>
    <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#createLevelModal"><i class="fa fa-plus me-1"></i> New Level</button>
</div>

<div class="alert alert-info small">
    <i class="fa fa-info-circle me-1"></i>
    Class levels define the grade structure (e.g., Form 1, Form 2, Form 3, Form 4, Grade 7, etc.).
    Use <strong>sort order</strong> to control the display sequence (lower numbers appear first).
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Level Name</th>
                    <th>Sort Order</th>
                    <th>Classes Using This Level</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($levels as $l):
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE class_level_id = :id");
                    $stmt->execute(['id' => $l['class_level_id']]);
                    $usage = (int) $stmt->fetchColumn();
                ?>
                    <tr>
                        <td class="fw-semibold"><?= e($l['level_name']) ?></td>
                        <td><span class="badge bg-secondary"><?= (int) $l['sort_order'] ?></span></td>
                        <td><span class="badge bg-info"><?= $usage ?> classes</span></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editLevelModal<?= (int) $l['class_level_id'] ?>" title="Edit"><i class="fa fa-edit"></i></button>
                                <?php if ($usage === 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete class level <?= e($l['level_name']) ?>?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_level">
                                        <input type="hidden" name="level_id" value="<?= (int) $l['class_level_id'] ?>">
                                        <button class="btn btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-outline-danger" disabled title="In use by <?= $usage ?> classes"><i class="fa fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                    <!-- Edit Modal -->
                    <div class="modal fade" id="editLevelModal<?= (int) $l['class_level_id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="edit_level">
                                    <input type="hidden" name="level_id" value="<?= (int) $l['class_level_id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit: <?= e($l['level_name']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label">Level Name <span class="required-mark">*</span></label>
                                            <input type="text" name="level_name" class="form-control" required value="<?= e($l['level_name']) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Sort Order</label>
                                            <input type="number" name="sort_order" class="form-control" value="<?= (int) $l['sort_order'] ?>" min="0">
                                            <small class="text-muted">Lower numbers appear first in dropdowns.</small>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($levels)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No class levels defined. Create one using the "New Level" button.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Level Modal -->
<div class="modal fade" id="createLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_level">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Class Level</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Level Name <span class="required-mark">*</span></label>
                        <input type="text" name="level_name" class="form-control" required placeholder="e.g. Form 5, Grade 8, A-Level">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                        <small class="text-muted">Controls display order (e.g., Form 1 = 1, Form 2 = 2, etc.)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>