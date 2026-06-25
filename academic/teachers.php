<?php
/**
 * academic/teachers.php
 * Teacher Management for Academic Department.
 * View all teachers with full details including payment info,
 * assign/reassign subjects, and manage teacher status.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'head_of_school', 'director']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

// Handle teacher status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    csrf_verify();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $allowed = ['active', 'on_leave', 'suspended', 'terminated', 'retired'];
    if ($staffId > 0 && in_array($status, $allowed)) {
        $pdo->prepare("UPDATE staff SET status = :s WHERE staff_id = :id")->execute(['s' => $status, 'id' => $staffId]);
        audit_log('update_teacher_status', 'academics', 'staff', $staffId, "Changed status to {$status}");
        flash_set('success', 'Teacher status updated.');
    }
    redirect(app_url('/academic/teachers.php'));
}

// Handle subject assignment removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_subject') {
    csrf_verify();
    $classSubjectId = (int) ($_POST['class_subject_id'] ?? 0);
    if ($classSubjectId > 0) {
        $pdo->prepare("DELETE FROM class_subjects WHERE class_subject_id = :id")->execute(['id' => $classSubjectId]);
        audit_log('remove_subject_assignment', 'academics', 'class_subjects', $classSubjectId, 'Removed subject assignment');
        flash_set('success', 'Subject assignment removed.');
    }
    redirect(app_url('/academic/teachers.php'));
}

// Handle subject teacher reassignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign_teacher') {
    csrf_verify();
    $classSubjectId = (int) ($_POST['class_subject_id'] ?? 0);
    $teacherId = (int) ($_POST['teacher_id'] ?? 0) ?: null;
    if ($classSubjectId > 0) {
        $pdo->prepare("UPDATE class_subjects SET teacher_id = :t WHERE class_subject_id = :id")
            ->execute(['t' => $teacherId, 'id' => $classSubjectId]);
        audit_log('reassign_subject_teacher', 'academics', 'class_subjects', $classSubjectId, 'Reassigned subject teacher');
        flash_set('success', 'Teacher reassigned.');
    }
    redirect(app_url('/academic/teachers.php'));
}

// Search/filter
$search = trim($_GET['q'] ?? '');
$deptFilter = (int) ($_GET['department_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

// Get all teachers (staff with teaching roles)
$sql = "SELECT st.*, u.first_name, u.last_name, u.username, u.email, u.phone, u.gender, u.photo_path,
               r.role_name, d.department_name,
               (SELECT COUNT(*) FROM staff_documents WHERE staff_id = st.staff_id) AS doc_count,
               (SELECT COUNT(*) FROM class_subjects cs WHERE cs.teacher_id = u.user_id) AS subject_count
        FROM staff st
        JOIN users u ON u.user_id = st.user_id
        JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN departments d ON d.department_id = st.department_id
        WHERE r.role_name IN ('subject_teacher', 'class_teacher', 'department_head')";
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR st.staff_no LIKE :s3 OR st.job_title LIKE :s4)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
}
if ($deptFilter > 0) {
    $sql .= ' AND st.department_id = :dept';
    $params['dept'] = $deptFilter;
}
if ($statusFilter !== '') {
    $sql .= ' AND st.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY u.first_name, u.last_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

// Get departments for filter
$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

// Get all subject assignments for the selected teacher view
$viewTeacherId = (int) ($_GET['view'] ?? 0);
$teacherSubjects = [];
$teacherInfo = null;
if ($viewTeacherId > 0) {
    $tStmt = $pdo->prepare(
        "SELECT st.*, u.first_name, u.last_name, u.email, u.phone, u.photo_path, r.role_name, d.department_name
         FROM staff st
         JOIN users u ON u.user_id = st.user_id
         JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN departments d ON d.department_id = st.department_id
         WHERE st.staff_id = :id"
    );
    $tStmt->execute(['id' => $viewTeacherId]);
    $teacherInfo = $tStmt->fetch();

    if ($teacherInfo) {
        $subStmt = $pdo->prepare(
            "SELECT cs.*, sub.subject_name, sub.subject_code, cl.level_name, c.stream_name, c.class_id
             FROM class_subjects cs
             JOIN subjects sub ON sub.subject_id = cs.subject_id
             JOIN classes c ON c.class_id = cs.class_id
             JOIN class_levels cl ON cl.class_level_id = c.class_level_id
             WHERE cs.teacher_id = :uid
             ORDER BY cl.sort_order, c.stream_name, sub.subject_name"
        );
        $subStmt->execute(['uid' => $teacherInfo['user_id']]);
        $teacherSubjects = $subStmt->fetchAll();
    }
}

// Get all subject teachers for reassign dropdown
$allTeachers = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name FROM users u
     JOIN roles r ON r.role_id = u.role_id
     WHERE r.role_name IN ('subject_teacher', 'class_teacher') AND u.is_active = 1
     ORDER BY u.first_name"
)->fetchAll();

// Get all subjects for quick assign
$allSubjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY subject_name")->fetchAll();
$allClasses = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$pageTitle = 'Teachers Management';
require APP_ROOT . '/includes/header.php';
?>
<style>
.teacher-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; background: #e9ecef; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.payment-detail { font-size: 0.85rem; }
.payment-detail .label { color: #6B7A8D; }
.payment-detail .value { font-weight: 600; }
.stat-badge { font-size: 0.75rem; padding: 0.25rem 0.5rem; }
.teacher-card { transition: all 0.2s; border-left: 4px solid transparent; }
.teacher-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.teacher-card.status-active { border-left-color: #1F8A55; }
.teacher-card.status-on_leave { border-left-color: #DD6B20; }
.teacher-card.status-suspended { border-left-color: #C53030; }
.teacher-card.status-terminated { border-left-color: #718096; }
.teacher-card.status-retired { border-left-color: #718096; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="fa fa-chalkboard-teacher text-gold me-2"></i>Teachers Management</h1>
    <div class="d-flex gap-2">
        <a href="<?= e(app_url('/academic/classes.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="fa fa-chalkboard me-1"></i> Class & Subject Assignments</a>
        <a href="<?= e(app_url('/academic/teachers.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="fa fa-sync-alt"></i></a>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, staff no, or title..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="department_id" class="form-select form-select-sm">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['department_id'] ?>" <?= $deptFilter === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="on_leave" <?= $statusFilter === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    <option value="terminated" <?= $statusFilter === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                    <option value="retired" <?= $statusFilter === 'retired' ? 'selected' : '' ?>>Retired</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-outline-primary w-100"><i class="fa fa-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Teachers Grid -->
<div class="row g-3 mb-4">
    <?php foreach ($teachers as $t): 
        $statusClass = $t['status'];
        $userId = (int) $t['user_id'];
    ?>
        <div class="col-xl-4 col-md-6">
            <div class="card teacher-card status-<?= e($statusClass) ?> h-100">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="teacher-avatar">
                            <?php if ($t['photo_path'] && file_exists(APP_ROOT . '/' . $t['photo_path'])): ?>
                                <img src="<?= e(app_url($t['photo_path'])) ?>" class="teacher-avatar" alt="">
                            <?php else: ?>
                                <i class="fa fa-user text-secondary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">
                                <a href="?view=<?= (int) $t['staff_id'] ?>" class="text-decoration-none"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></a>
                            </h6>
                            <div class="small text-muted mb-1">
                                <code><?= e($t['staff_no']) ?></code> &middot; <span class="badge bg-secondary"><?= e(str_replace('_', ' ', $t['role_name'])) ?></span>
                            </div>
                            <div>
                                <?php
                                    $statusMap = ['active'=>'success','on_leave'=>'warning','suspended'=>'danger','terminated'=>'dark','retired'=>'secondary'];
                                    $sBadge = $statusMap[$t['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $sBadge ?>"><?= e(ucfirst(str_replace('_', ' ', $t['status']))) ?></span>
                                <span class="badge bg-info stat-badge"><?= (int) $t['subject_count'] ?> subject(s)</span>
                            </div>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="?view=<?= (int) $t['staff_id'] ?>"><i class="fa fa-eye me-2"></i>View Details</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="staff_id" value="<?= (int) $t['staff_id'] ?>">
                                        <input type="hidden" name="status" value="active">
                                        <button type="submit" class="dropdown-item <?= $t['status'] === 'active' ? 'active' : '' ?>"><i class="fa fa-check-circle me-2 text-success"></i>Set Active</button>
                                    </form>
                                </li>
                                <li>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="staff_id" value="<?= (int) $t['staff_id'] ?>">
                                        <input type="hidden" name="status" value="on_leave">
                                        <button type="submit" class="dropdown-item <?= $t['status'] === 'on_leave' ? 'active' : '' ?>"><i class="fa fa-clock me-2 text-warning"></i>Set On Leave</button>
                                    </form>
                                </li>
                                <li>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="staff_id" value="<?= (int) $t['staff_id'] ?>">
                                        <input type="hidden" name="status" value="suspended">
                                        <button type="submit" class="dropdown-item <?= $t['status'] === 'suspended' ? 'active' : '' ?>"><i class="fa fa-ban me-2 text-danger"></i>Set Suspended</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Contact Info -->
                    <div class="row g-1 mb-2 small">
                        <div class="col-6"><i class="fa fa-envelope text-muted me-1"></i><?= e($t['email'] ?: '-') ?></div>
                        <div class="col-6"><i class="fa fa-phone text-muted me-1"></i><?= e($t['phone'] ?: '-') ?></div>
                        <div class="col-6"><i class="fa fa-venus-mars text-muted me-1"></i><?= e(ucfirst($t['gender'] ?? '-')) ?></div>
                        <div class="col-6"><i class="fa fa-building text-muted me-1"></i><?= e($t['department_name'] ?: '-') ?></div>
                    </div>

                    <!-- Payment Details -->
                    <div class="bg-light rounded p-2 payment-detail">
                        <div class="small fw-bold text-muted mb-1"><i class="fa fa-wallet me-1"></i>Payment Details</div>
                        <div class="row g-1">
                            <div class="col-6">
                                <span class="label">Basic Salary:</span>
                                <span class="value"><?= $t['basic_salary'] > 0 ? number_format((float) $t['basic_salary'], 0) : '-' ?></span>
                            </div>
                            <div class="col-6">
                                <span class="label">Bank:</span>
                                <span class="value"><?= e($t['bank_name'] ?: '-') ?></span>
                            </div>
                            <div class="col-6">
                                <span class="label">Account No:</span>
                                <span class="value"><?= e($t['bank_account_no'] ?: '-') ?></span>
                            </div>
                            <div class="col-6">
                                <span class="label">Branch:</span>
                                <span class="value"><?= e($t['bank_branch'] ?: '-') ?></span>
                            </div>
                            <div class="col-6">
                                <span class="label">TIN:</span>
                                <span class="value"><?= e($t['tin_number'] ?: '-') ?></span>
                            </div>
                            <div class="col-6">
                                <span class="label">NSSF:</span>
                                <span class="value"><?= e($t['nssf_number'] ?: '-') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Employment Info -->
                    <div class="mt-2 small text-muted">
                        <i class="fa fa-briefcase me-1"></i><?= e($t['job_title']) ?> &middot; 
                        <?= e(ucfirst(str_replace('_', ' ', $t['employment_type']))) ?> &middot;
                        Hired: <?= e(format_date($t['date_hired'])) ?>
                        <?php if ($t['education_level']): ?> &middot; <?= e($t['education_level']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-white border-top-0 pt-0">
                    <div class="d-flex gap-1">
                        <a href="?view=<?= (int) $t['staff_id'] ?>" class="btn btn-sm btn-outline-primary flex-fill"><i class="fa fa-eye me-1"></i> View Subjects</a>
                        <a href="<?= e(app_url('/director/staff_detail.php?id=' . (int) $t['staff_id'])) ?>" class="btn btn-sm btn-outline-info flex-fill"><i class="fa fa-id-card me-1"></i> Full Profile</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($teachers)): ?>
        <div class="col-12">
            <div class="text-center text-muted py-5">
                <i class="fa fa-chalkboard-teacher fa-3x mb-3"></i>
                <p class="mb-0">No teachers found matching your criteria.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Teacher Detail View -->
<?php if ($viewTeacherId > 0 && $teacherInfo): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-list text-gold me-2"></i>Subject Assignments: <?= e($teacherInfo['first_name'] . ' ' . $teacherInfo['last_name']) ?></span>
        <a href="<?= e(app_url('/academic/teachers.php')) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-times"></i> Close</a>
    </div>
    <div class="card-body">
        <!-- Quick Assign Subject Form -->
        <form method="POST" action="<?= e(app_url('/academic/classes.php')) ?>" class="row g-2 mb-3 p-3 bg-light rounded">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="assign_subject">
            <div class="col-md-4">
                <select name="class_id" class="form-select form-select-sm" required>
                    <option value="">-- Select Class --</option>
                    <?php foreach ($allClasses as $cl): ?>
                        <option value="<?= (int) $cl['class_id'] ?>"><?= e($cl['level_name'] . ' ' . $cl['stream_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="subject_id" class="form-select form-select-sm" required>
                    <option value="">-- Subject --</option>
                    <?php foreach ($allSubjects as $s): ?>
                        <option value="<?= (int) $s['subject_id'] ?>"><?= e($s['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <input type="hidden" name="subject_teacher_id" value="<?= (int) $teacherInfo['user_id'] ?>">
                <span class="form-control-sm d-flex align-items-center text-muted">
                    <i class="fa fa-user me-1"></i> <?= e($teacherInfo['first_name'] . ' ' . $teacherInfo['last_name']) ?>
                </span>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="fa fa-plus"></i> Assign</button>
            </div>
        </form>

        <!-- Current Subject Assignments -->
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Subject</th>
                        <th>Code</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teacherSubjects as $ts): ?>
                        <tr>
                            <td><?= e($ts['level_name'] . ' ' . $ts['stream_name']) ?></td>
                            <td><?= e($ts['subject_name']) ?></td>
                            <td><code><?= e($ts['subject_code']) ?></code></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <!-- Reassign Teacher -->
                                    <form method="POST" class="d-inline-flex gap-1" data-confirm="Change the teacher for this subject?">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="reassign_teacher">
                                        <input type="hidden" name="class_subject_id" value="<?= (int) $ts['class_subject_id'] ?>">
                                        <select name="teacher_id" class="form-select form-select-sm" style="width:auto;min-width:140px;" onchange="this.form.submit()">
                                            <option value="">-- Change Teacher --</option>
                                            <?php foreach ($allTeachers as $at): ?>
                                                <option value="<?= (int) $at['user_id'] ?>" <?= $ts['teacher_id'] == $at['user_id'] ? 'selected' : '' ?>><?= e($at['first_name'] . ' ' . $at['last_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <!-- Remove Subject -->
                                    <form method="POST" class="d-inline" data-confirm="Remove this subject assignment?">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="remove_subject">
                                        <input type="hidden" name="class_subject_id" value="<?= (int) $ts['class_subject_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"><i class="fa fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($teacherSubjects)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No subject assignments for this teacher.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Teacher Full Details Card -->
<div class="card">
    <div class="card-header"><i class="fa fa-id-card text-gold me-2"></i>Full Teacher Details</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="text-muted small text-uppercase">Personal Information</h6>
                <table class="table table-sm table-borderless">
                    <tr><td class="text-muted" style="width:140px;">Full Name</td><td><?= e($teacherInfo['first_name'] . ' ' . $teacherInfo['last_name']) ?></td></tr>
                    <tr><td class="text-muted">Staff No</td><td><code><?= e($teacherInfo['staff_no']) ?></code></td></tr>
                    <tr><td class="text-muted">Role</td><td><?= e(str_replace('_', ' ', $teacherInfo['role_name'])) ?></td></tr>
                    <tr><td class="text-muted">Department</td><td><?= e($teacherInfo['department_name'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">Job Title</td><td><?= e($teacherInfo['job_title'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">Gender</td><td><?= e(ucfirst($teacherInfo['gender'] ?? '-')) ?></td></tr>
                    <tr><td class="text-muted">Email</td><td><?= e($teacherInfo['email'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">Phone</td><td><?= e($teacherInfo['phone'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">Status</td><td><span class="badge bg-<?= $sBadge ?>"><?= e(ucfirst(str_replace('_', ' ', $teacherInfo['status']))) ?></span></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted small text-uppercase">Employment & Payment Details</h6>
                <table class="table table-sm table-borderless">
                    <tr><td class="text-muted" style="width:140px;">Employment Type</td><td><?= e(ucfirst(str_replace('_', ' ', $teacherInfo['employment_type']))) ?></td></tr>
                    <tr><td class="text-muted">Date Hired</td><td><?= e(format_date($teacherInfo['date_hired'])) ?></td></tr>
                    <tr><td class="text-muted">Education Level</td><td><?= e($teacherInfo['education_level'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">Years of Experience</td><td><?= (int) ($teacherInfo['years_of_experience'] ?? 0) ?> yrs</td></tr>
                    <tr><td class="text-muted">Basic Salary</td><td class="fw-bold"><?= $teacherInfo['basic_salary'] > 0 ? number_format((float) $teacherInfo['basic_salary'], 0) . ' TZS' : '-' ?></td></tr>
                    <tr><td class="text-muted">Bank Name</td><td><?= e($teacherInfo['bank_name'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">Account No</td><td><?= e($teacherInfo['bank_account_no'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">Bank Branch</td><td><?= e($teacherInfo['bank_branch'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">TIN Number</td><td><?= e($teacherInfo['tin_number'] ?: '-') ?></td></tr>
                    <tr><td class="text-muted">NSSF Number</td><td><?= e($teacherInfo['nssf_number'] ?: '-') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>