<?php
/**
 * academic/classes.php
 * Manage classes (level + stream), assign class teachers, link
 * subjects with their respective subject teachers per class,
 * view class rosters, and generate class reports.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

// ----- CREATE CLASS -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_class') {
    csrf_verify();
    $classLevelId = (int) ($_POST['class_level_id'] ?? 0);
    $streamName = trim($_POST['stream_name'] ?? 'A');
    $capacity = (int) ($_POST['capacity'] ?? 40);

    if ($classLevelId <= 0 || $streamName === '') {
        flash_set('error', 'Class level and stream name are required.');
    } else {
        try {
            $pdo->prepare('INSERT INTO classes (class_level_id, stream_name, year_id, capacity) VALUES (:cl, :sn, :y, :cap)')
                ->execute(['cl' => $classLevelId, 'sn' => $streamName, 'y' => $period['year_id'], 'cap' => $capacity]);
            audit_log('create_class', 'academics', 'classes', (int) $pdo->lastInsertId(), 'Created new class');
            flash_set('success', 'Class created.');
        } catch (PDOException $e) {
            flash_set('error', 'That class/stream already exists for this academic year.');
        }
    }
    redirect(app_url('/academic/classes.php'));
}

// ----- DELETE CLASS -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_class') {
    csrf_verify();
    $classId = (int) ($_POST['class_id'] ?? 0);
    if ($classId > 0) {
        try {
            // Check if class has students
            $count = (int) $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :id")->execute(['id' => $classId]);
            // Use direct query for count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = :id");
            $stmt->execute(['id' => $classId]);
            $studentCount = (int) $stmt->fetchColumn();
            
            if ($studentCount > 0) {
                flash_set('error', "Cannot delete class with {$studentCount} active student(s). Remove students first.");
            } else {
                $pdo->prepare('DELETE FROM class_subjects WHERE class_id = :id')->execute(['id' => $classId]);
                $pdo->prepare('DELETE FROM timetable WHERE class_subject_id IN (SELECT class_subject_id FROM class_subjects WHERE class_id = :id)')->execute(['id' => $classId]);
                $pdo->prepare('DELETE FROM classes WHERE class_id = :id')->execute(['id' => $classId]);
                audit_log('delete_class', 'academics', 'classes', $classId, 'Deleted class');
                flash_set('success', 'Class deleted successfully.');
            }
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete class. It may have related records.');
        }
    }
    redirect(app_url('/academic/classes.php'));
}

// ----- ASSIGN CLASS TEACHER -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_class_teacher') {
    csrf_verify();
    $classId = (int) ($_POST['class_id'] ?? 0);
    $teacherId = (int) ($_POST['teacher_id'] ?? 0) ?: null;
    $pdo->prepare('UPDATE classes SET class_teacher_id = :t WHERE class_id = :id')->execute(['t' => $teacherId, 'id' => $classId]);
    audit_log('assign_class_teacher', 'academics', 'classes', $classId, 'Assigned class teacher');
    flash_set('success', 'Class teacher updated.');
    redirect(app_url('/academic/classes.php'));
}

// ----- ASSIGN SUBJECT -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_subject') {
    csrf_verify();
    $classId = (int) ($_POST['class_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $teacherId = (int) ($_POST['subject_teacher_id'] ?? 0) ?: null;
    $redirect = $_POST['redirect'] ?? app_url('/academic/classes.php') . '?view=' . $classId;

    if ($classId <= 0 || $subjectId <= 0) {
        flash_set('error', 'Class and subject are required.');
    } else {
        $pdo->prepare(
            'INSERT INTO class_subjects (class_id, subject_id, teacher_id) VALUES (:c, :s, :t)
             ON DUPLICATE KEY UPDATE teacher_id = :t2'
        )->execute(['c' => $classId, 's' => $subjectId, 't' => $teacherId, 't2' => $teacherId]);
        audit_log('assign_subject_teacher', 'academics', 'class_subjects', null, 'Assigned subject teacher');
        flash_set('success', 'Subject assignment saved.');
    }
    redirect($redirect);
}

// ----- REMOVE SUBJECT ASSIGNMENT -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_subject') {
    csrf_verify();
    $classSubjectId = (int) ($_POST['class_subject_id'] ?? 0);
    $classId = (int) ($_POST['class_id'] ?? 0);
    if ($classSubjectId > 0) {
        $pdo->prepare("DELETE FROM class_subjects WHERE class_subject_id = :id")->execute(['id' => $classSubjectId]);
        audit_log('remove_subject_assignment', 'academics', 'class_subjects', $classSubjectId, 'Removed subject assignment');
        flash_set('success', 'Subject assignment removed.');
    }
    redirect(app_url('/academic/classes.php') . ($classId > 0 ? '?view=' . $classId : ''));
}

// ----- REASSIGN TEACHER -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign_teacher') {
    csrf_verify();
    $classSubjectId = (int) ($_POST['class_subject_id'] ?? 0);
    $teacherId = (int) ($_POST['teacher_id'] ?? 0) ?: null;
    $classId = (int) ($_POST['class_id'] ?? 0);
    if ($classSubjectId > 0) {
        $pdo->prepare("UPDATE class_subjects SET teacher_id = :t WHERE class_subject_id = :id")
            ->execute(['t' => $teacherId, 'id' => $classSubjectId]);
        audit_log('reassign_subject_teacher', 'academics', 'class_subjects', $classSubjectId, 'Reassigned subject teacher');
        flash_set('success', 'Teacher reassigned.');
    }
    redirect(app_url('/academic/classes.php') . ($classId > 0 ? '?view=' . $classId : ''));
}

// ----- DATA FETCHING -----
$classes = $pdo->query(
    "SELECT c.*, cl.level_name, u.first_name AS ct_fn, u.last_name AS ct_ln,
        (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.status='active') AS student_count
     FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     LEFT JOIN users u ON u.user_id = c.class_teacher_id
     ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$classLevels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();
$subjects = $pdo->query('SELECT * FROM subjects WHERE is_active = 1 ORDER BY subject_name')->fetchAll();
$classTeachers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role_id = (SELECT role_id FROM roles WHERE role_name='class_teacher') AND is_active=1")->fetchAll();
$subjectTeachers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role_id IN (SELECT role_id FROM roles WHERE role_name IN ('subject_teacher','class_teacher')) AND is_active=1 ORDER BY first_name")->fetchAll();

$viewClassId = (int) ($_GET['view'] ?? 0);
$classSubjects = [];
$classStudents = [];
$classInfo = null;

if ($viewClassId > 0) {
    // Get class info
    $ciStmt = $pdo->prepare(
        "SELECT c.*, cl.level_name, u.first_name AS ct_fn, u.last_name AS ct_ln
         FROM classes c
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         LEFT JOIN users u ON u.user_id = c.class_teacher_id
         WHERE c.class_id = :id"
    );
    $ciStmt->execute(['id' => $viewClassId]);
    $classInfo = $ciStmt->fetch();

    if ($classInfo) {
        // Get subject assignments
        $subStmt = $pdo->prepare(
            "SELECT cs.*, sub.subject_name, sub.subject_code, u.first_name, u.last_name
             FROM class_subjects cs
             JOIN subjects sub ON sub.subject_id = cs.subject_id
             LEFT JOIN users u ON u.user_id = cs.teacher_id
             WHERE cs.class_id = :id ORDER BY sub.subject_name"
        );
        $subStmt->execute(['id' => $viewClassId]);
        $classSubjects = $subStmt->fetchAll();

        // Get student roster
        $stuStmt = $pdo->prepare(
            "SELECT s.student_id, s.admission_no, u.first_name, u.last_name, u.gender, u.photo_path, s.status
             FROM students s
             JOIN users u ON u.user_id = s.user_id
             WHERE s.class_id = :id AND s.status = 'active'
             ORDER BY u.first_name, u.last_name"
        );
        $stuStmt->execute(['id' => $viewClassId]);
        $classStudents = $stuStmt->fetchAll();
    }
}

$pageTitle = 'Classes & Subjects';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0">Classes & Subjects</h1>
    <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#newClassModal"><i class="fa fa-plus me-1"></i> New Class</button>
</div>

<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>Students</th>
                    <th>Class Teacher</th>
                    <th>Capacity</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $c): ?>
                    <tr class="<?= $viewClassId === (int) $c['class_id'] ? 'table-active' : '' ?>">
                        <td><a href="?view=<?= (int) $c['class_id'] ?>" class="fw-semibold text-decoration-none"><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></a></td>
                        <td>
                            <span class="<?= (int) $c['student_count'] >= (int) $c['capacity'] ? 'text-danger fw-bold' : '' ?>">
                                <?= (int) $c['student_count'] ?> / <?= (int) $c['capacity'] ?>
                            </span>
                            <?php if ((int) $c['student_count'] > 0): ?>
                                <a href="?view=<?= (int) $c['class_id'] ?>&tab=students" class="small text-muted ms-1" title="View roster"><i class="fa fa-users"></i></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="d-flex gap-1">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="assign_class_teacher">
                                <input type="hidden" name="class_id" value="<?= (int) $c['class_id'] ?>">
                                <select name="teacher_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:140px;">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach ($classTeachers as $t): ?>
                                        <option value="<?= (int) $t['user_id'] ?>" <?= $c['class_teacher_id'] == $t['user_id'] ? 'selected' : '' ?>><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td><?= (int) $c['capacity'] ?></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="?view=<?= (int) $c['class_id'] ?>" class="btn btn-outline-primary" title="Manage Subjects & View Details"><i class="fa fa-eye"></i></a>
                                <a href="?view=<?= (int) $c['class_id'] ?>&tab=students" class="btn btn-outline-info" title="View Student Roster"><i class="fa fa-users"></i></a>
                                <a href="<?= e(app_url('/academic/reports.php')) ?>?class_id=<?= (int) $c['class_id'] ?>" class="btn btn-outline-success" title="Class Reports"><i class="fa fa-chart-bar"></i></a>
                                <?php if ((int) $c['student_count'] === 0): ?>
                                    <form method="POST" class="d-inline" data-confirm="Permanently delete this class? This cannot be undone.">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_class">
                                        <input type="hidden" name="class_id" value="<?= (int) $c['class_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete Class"><i class="fa fa-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-outline-danger" disabled title="Cannot delete: class has students"><i class="fa fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($classes)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No classes found. Create one using the "New Class" button.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Class Detail View -->
<?php if ($viewClassId > 0 && $classInfo): 
    $activeTab = $_GET['tab'] ?? 'subjects';
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-chalkboard text-gold me-2"></i><?= e($classInfo['level_name'] . ' ' . $classInfo['stream_name']) ?> — Details</span>
        <div>
            <span class="badge bg-<?= $classInfo['class_teacher_id'] ? 'success' : 'secondary' ?> me-2">
                <?= $classInfo['ct_fn'] ? 'CT: ' . e($classInfo['ct_fn'] . ' ' . $classInfo['ct_ln']) : 'No Class Teacher' ?>
            </span>
            <a href="<?= e(app_url('/academic/classes.php')) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-times"></i> Close</a>
        </div>
    </div>
    <div class="card-body">
        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'subjects' ? 'active' : '' ?>" href="?view=<?= $viewClassId ?>&tab=subjects">
                    <i class="fa fa-book me-1"></i> Subjects <span class="badge bg-secondary ms-1"><?= count($classSubjects) ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'students' ? 'active' : '' ?>" href="?view=<?= $viewClassId ?>&tab=students">
                    <i class="fa fa-users me-1"></i> Students <span class="badge bg-secondary ms-1"><?= count($classStudents) ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= e(app_url('/academic/reports.php')) ?>?class_id=<?= $viewClassId ?>">
                    <i class="fa fa-chart-bar me-1"></i> Class Reports
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= e(app_url('/academic/timetable.php')) ?>?class_id=<?= $viewClassId ?>">
                    <i class="fa fa-calendar-alt me-1"></i> Timetable
                </a>
            </li>
        </ul>

        <!-- Subjects Tab -->
        <?php if ($activeTab === 'subjects'): ?>
            <div class="row g-2 mb-3">
                <div class="col-md-12">
                    <form method="POST" class="row g-2 p-3 bg-light rounded">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="assign_subject">
                        <input type="hidden" name="class_id" value="<?= $viewClassId ?>">
                        <div class="col-md-4">
                            <select name="subject_id" class="form-select form-select-sm" required>
                                <option value="">-- Subject --</option>
                                <?php foreach ($subjects as $s): ?><option value="<?= (int) $s['subject_id'] ?>"><?= e($s['subject_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="subject_teacher_id" class="form-select form-select-sm">
                                <option value="">-- Unassigned Teacher --</option>
                                <?php foreach ($subjectTeachers as $t): ?><option value="<?= (int) $t['user_id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary w-100"><i class="fa fa-plus"></i> Add</button>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= e(app_url('/academic/timetable.php')) ?>?class_id=<?= $viewClassId ?>" class="btn btn-sm btn-outline-secondary w-100"><i class="fa fa-clock"></i> Timetable</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Code</th>
                            <th>Teacher</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classSubjects as $cs): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($cs['subject_name']) ?></td>
                                <td><code><?= e($cs['subject_code']) ?></code></td>
                                <td>
                                    <form method="POST" class="d-inline-flex gap-1">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="reassign_teacher">
                                        <input type="hidden" name="class_subject_id" value="<?= (int) $cs['class_subject_id'] ?>">
                                        <input type="hidden" name="class_id" value="<?= $viewClassId ?>">
                                        <select name="teacher_id" class="form-select form-select-sm" style="width:auto;min-width:150px;" onchange="this.form.submit()">
                                            <option value="">-- Unassigned --</option>
                                            <?php foreach ($subjectTeachers as $t): ?>
                                                <option value="<?= (int) $t['user_id'] ?>" <?= $cs['teacher_id'] == $t['user_id'] ? 'selected' : '' ?>><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <form method="POST" class="d-inline" data-confirm="Remove this subject from the class?">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="remove_subject">
                                        <input type="hidden" name="class_subject_id" value="<?= (int) $cs['class_subject_id'] ?>">
                                        <input type="hidden" name="class_id" value="<?= $viewClassId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove Subject"><i class="fa fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($classSubjects)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No subjects assigned to this class yet. Use the form above to add subjects.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Students Tab -->
        <?php if ($activeTab === 'students'): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small">Total Active Students: <strong><?= count($classStudents) ?></strong> / <?= (int) $classInfo['capacity'] ?> capacity</span>
                <a href="<?= e(app_url('/academic/students.php')) ?>?class_id=<?= $viewClassId ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-user-plus me-1"></i> Manage Students</a>
            </div>
            <?php if (!empty($classStudents)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Admission No.</th>
                                <th>Student Name</th>
                                <th>Gender</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classStudents as $stu): ?>
                                <tr>
                                    <td><code><?= e($stu['admission_no']) ?></code></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($stu['photo_path'] && file_exists(APP_ROOT . '/' . $stu['photo_path'])): ?>
                                                <img src="<?= e(app_url($stu['photo_path'])) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;" alt="">
                                            <?php else: ?>
                                                <div style="width:28px;height:28px;border-radius:50%;background:#e9ecef;display:flex;align-items:center;justify-content:center;"><i class="fa fa-user text-secondary" style="font-size:0.75rem;"></i></div>
                                            <?php endif; ?>
                                            <?= e($stu['first_name'] . ' ' . $stu['last_name']) ?>
                                        </div>
                                    </td>
                                    <td><?= e(ucfirst($stu['gender'] ?? '-')) ?></td>
                                    <td>
                                        <a href="<?= e(app_url('/director/student_profile.php')) ?>?id=<?= (int) $stu['student_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Profile"><i class="fa fa-eye"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-users fa-2x mb-2"></i>
                    <p class="mb-0">No active students in this class.</p>
                    <a href="<?= e(app_url('/academic/students.php')) ?>" class="btn btn-sm btn-outline-primary mt-2"><i class="fa fa-user-plus me-1"></i> Register Students</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- New Class Modal -->
<div class="modal fade" id="newClassModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_class">
                <div class="modal-header"><h5 class="modal-title">Create New Class</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Class Level</label>
                        <select name="class_level_id" class="form-select" required>
                            <?php foreach ($classLevels as $cl): ?><option value="<?= (int) $cl['class_level_id'] ?>"><?= e($cl['level_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2"><label class="form-label">Stream Name</label><input type="text" name="stream_name" class="form-control" placeholder="e.g. A, B, Blue" required></div>
                    <div class="mb-2"><label class="form-label">Capacity</label><input type="number" name="capacity" class="form-control" value="40" min="1"></div>
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