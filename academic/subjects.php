<?php
/**
 * academic/subjects.php
 * Subject Management for Academic Department.
 * Full CRUD: Add, Edit, Delete, Activate/Deactivate subjects.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'head_of_school', 'director']);

$pdo = get_db_connection();

// ----- CREATE SUBJECT -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_subject') {
    csrf_verify();
    $subjectName = trim($_POST['subject_name'] ?? '');
    $subjectCode = trim($_POST['subject_code'] ?? '');

    if ($subjectName === '' || $subjectCode === '') {
        flash_set('error', 'Subject name and code are required.');
    } else {
        try {
            $pdo->prepare('INSERT INTO subjects (subject_name, subject_code) VALUES (:name, :code)')
                ->execute(['name' => $subjectName, 'code' => strtoupper($subjectCode)]);
            audit_log('create_subject', 'academics', 'subjects', (int) $pdo->lastInsertId(), "Created subject {$subjectName}");
            flash_set('success', "Subject '{$subjectName}' created.");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                flash_set('error', "Subject code '{$subjectCode}' already exists.");
            } else {
                flash_set('error', 'Failed to create subject.');
            }
        }
    }
    redirect(app_url('/academic/subjects.php'));
}

// ----- UPDATE SUBJECT -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_subject') {
    csrf_verify();
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $subjectName = trim($_POST['subject_name'] ?? '');
    $subjectCode = trim($_POST['subject_code'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($subjectId <= 0 || $subjectName === '' || $subjectCode === '') {
        flash_set('error', 'Subject name and code are required.');
    } else {
        try {
            $pdo->prepare('UPDATE subjects SET subject_name = :name, subject_code = :code, is_active = :active WHERE subject_id = :id')
                ->execute(['name' => $subjectName, 'code' => strtoupper($subjectCode), 'active' => $isActive, 'id' => $subjectId]);
            audit_log('update_subject', 'academics', 'subjects', $subjectId, "Updated subject {$subjectName}");
            flash_set('success', "Subject '{$subjectName}' updated.");
        } catch (PDOException $e) {
            flash_set('error', 'Failed to update subject. Code may already exist.');
        }
    }
    redirect(app_url('/academic/subjects.php'));
}

// ----- DELETE / TOGGLE SUBJECT -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_subject') {
    csrf_verify();
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $newStatus = (int) ($_POST['is_active'] ?? 0);

    if ($subjectId > 0) {
        $pdo->prepare('UPDATE subjects SET is_active = :active WHERE subject_id = :id')
            ->execute(['active' => $newStatus, 'id' => $subjectId]);
        $statusText = $newStatus ? 'activated' : 'deactivated';
        audit_log('toggle_subject', 'academics', 'subjects', $subjectId, "{$statusText} subject");
        flash_set('success', "Subject {$statusText}.");
    }
    redirect(app_url('/academic/subjects.php'));
}

// ----- DELETE SUBJECT (permanent) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_subject') {
    csrf_verify();
    $subjectId = (int) ($_POST['subject_id'] ?? 0);

    if ($subjectId > 0) {
        try {
            // Check if subject is assigned to any class
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_subjects WHERE subject_id = :id");
            $stmt->execute(['id' => $subjectId]);
            $assignedCount = (int) $stmt->fetchColumn();

            if ($assignedCount > 0) {
                flash_set('error', "Cannot delete: subject is assigned to {$assignedCount} class(es). Deactivate it instead.");
            } else {
                $pdo->prepare('DELETE FROM subjects WHERE subject_id = :id')->execute(['id' => $subjectId]);
                audit_log('delete_subject', 'academics', 'subjects', $subjectId, 'Deleted subject');
                flash_set('success', 'Subject deleted permanently.');
            }
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete subject. It may have related records.');
        }
    }
    redirect(app_url('/academic/subjects.php'));
}

// ----- DATA FETCHING -----
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$sql = "SELECT s.*,
            (SELECT COUNT(*) FROM class_subjects cs WHERE cs.subject_id = s.subject_id) AS class_count,
            (SELECT COUNT(*) FROM class_subjects cs JOIN exam_marks em ON em.class_subject_id = cs.class_subject_id WHERE cs.subject_id = s.subject_id) AS exam_count
        FROM subjects s WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= ' AND (s.subject_name LIKE :s1 OR s.subject_code LIKE :s2)';
    $params['s1'] = $params['s2'] = "%{$search}%";
}
if ($filter === 'active') {
    $sql .= ' AND s.is_active = 1';
} elseif ($filter === 'inactive') {
    $sql .= ' AND s.is_active = 0';
}
$sql .= ' ORDER BY s.subject_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subjects = $stmt->fetchAll();

$totalSubjects = count($subjects);
$activeCount = count(array_filter($subjects, fn($s) => $s['is_active']));
$inactiveCount = $totalSubjects - $activeCount;

$pageTitle = 'Subjects Management';
require APP_ROOT . '/includes/header.php';
?>
<style>
.subject-stat-card { border-radius: 8px; padding: 1rem; }
.subject-stat-card .stat-number { font-size: 1.5rem; font-weight: 700; }
.subject-stat-card .stat-label { font-size: 0.8rem; color: #6B7A8D; text-transform: uppercase; letter-spacing: 0.3px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="fa fa-book text-gold me-2"></i>Subjects Management</h1>
    <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newSubjectModal"><i class="fa fa-plus me-1"></i> New Subject</button>
</div>

<!-- Stats Row -->
<div class="row g-2 mb-4">
    <div class="col-md-4">
        <div class="subject-stat-card bg-white shadow-sm border-start border-4 border-primary">
            <div class="stat-number"><?= $totalSubjects ?></div>
            <div class="stat-label"><i class="fa fa-book me-1"></i>Total Subjects</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="subject-stat-card bg-white shadow-sm border-start border-4 border-success">
            <div class="stat-number"><?= $activeCount ?></div>
            <div class="stat-label"><i class="fa fa-check-circle me-1 text-success"></i>Active Subjects</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="subject-stat-card bg-white shadow-sm border-start border-4 border-secondary">
            <div class="stat-number"><?= $inactiveCount ?></div>
            <div class="stat-label"><i class="fa fa-ban me-1 text-secondary"></i>Inactive Subjects</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-6">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by subject name or code..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="filter" class="form-select form-select-sm">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Subjects</option>
                    <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm btn-outline-primary w-100"><i class="fa fa-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Subjects Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-list text-gold me-2"></i>Subject Records</span>
        <span class="badge bg-primary"><?= $totalSubjects ?> subjects</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Subject Name</th>
                    <th>Code</th>
                    <th>Status</th>
                    <th>Classes Using It</th>
                    <th>Exam Entries</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $s): ?>
                    <tr class="<?= $s['is_active'] ? '' : 'text-muted' ?>">
                        <td class="fw-semibold"><?= e($s['subject_name']) ?></td>
                        <td><code><?= e($s['subject_code']) ?></code></td>
                        <td>
                            <?php if ($s['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $s['class_count'] ?> class(es)</td>
                        <td><?= (int) $s['exam_count'] ?> entries</td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <!-- Edit Button -->
                                <button type="button" class="btn btn-outline-primary" title="Edit Subject"
                                    onclick="editSubject(<?= (int) $s['subject_id'] ?>, '<?= e($s['subject_name'], "'") ?>', '<?= e($s['subject_code'], "'") ?>', <?= $s['is_active'] ? 'true' : 'false' ?>)">
                                    <i class="fa fa-edit"></i>
                                </button>

                                <!-- Toggle Active/Inactive -->
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_subject">
                                    <input type="hidden" name="subject_id" value="<?= (int) $s['subject_id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $s['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-outline-<?= $s['is_active'] ? 'warning' : 'success' ?>" title="<?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <i class="fa <?= $s['is_active'] ? 'fa-ban' : 'fa-check-circle' ?>"></i>
                                    </button>
                                </form>

                                <!-- Delete (only if no class assignments) -->
                                <?php if ((int) $s['class_count'] === 0): ?>
                                    <form method="POST" class="d-inline" data-confirm="Permanently delete subject '<?= e($s['subject_name']) ?>'? This cannot be undone.">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_subject">
                                        <input type="hidden" name="subject_id" value="<?= (int) $s['subject_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete Subject"><i class="fa fa-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-outline-danger" disabled title="Cannot delete: assigned to classes"><i class="fa fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No subjects found. <a href="#" data-bs-toggle="modal" data-bs-target="#newSubjectModal">Create one</a>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Subject Modal -->
<div class="modal fade" id="newSubjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_subject">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-plus-circle text-gold me-2"></i>New Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Subject Name <span class="required-mark">*</span></label>
                        <input type="text" name="subject_name" class="form-control" placeholder="e.g. Mathematics, English Language" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject Code <span class="required-mark">*</span></label>
                        <input type="text" name="subject_code" class="form-control" placeholder="e.g. MATH, ENG" required style="text-transform:uppercase;">
                        <div class="form-text">Short unique code (automatically uppercased).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_subject">
                <input type="hidden" name="subject_id" id="editSubjectId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-edit text-gold me-2"></i>Edit Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Subject Name <span class="required-mark">*</span></label>
                        <input type="text" name="subject_name" id="editSubjectName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject Code <span class="required-mark">*</span></label>
                        <input type="text" name="subject_code" id="editSubjectCode" class="form-control" required style="text-transform:uppercase;">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="editSubjectActive" class="form-check-input" value="1" checked>
                            <label class="form-check-label" for="editSubjectActive">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editSubject(id, name, code, isActive) {
    document.getElementById('editSubjectId').value = id;
    document.getElementById('editSubjectName').value = name;
    document.getElementById('editSubjectCode').value = code;
    document.getElementById('editSubjectActive').checked = isActive;
    var modal = new bootstrap.Modal(document.getElementById('editSubjectModal'));
    modal.show();
}
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>