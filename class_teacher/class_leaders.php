<?php
/**
 * class_teacher/class_leaders.php
 * Full CRUD management of class leaders (Head Boy, Head Girl, Prefects,
 * Class Monitors, etc.). These positions are visible to Academic, Subject
 * Teachers, Admin, and Director panels.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();
$period = get_current_period($pdo);

// Get the teacher's assigned class
$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Class Leaders';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher for any class.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// ====== CREATE: Add new class leader ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_leader') {
    csrf_verify();
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $position = trim($_POST['position'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($studentId <= 0 || $position === '') {
        flash_set('error', 'Student and position are required.');
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO class_leaders (student_id, class_id, position, term_id, year_id, assigned_by, is_active)
                 VALUES (:sid, :cid, :pos, :term, :year, :by, :active)'
            )->execute([
                'sid' => $studentId,
                'cid' => $myClass['class_id'],
                'pos' => $position,
                'term' => $period['term_id'],
                'year' => $period['year_id'],
                'by' => $teacherId,
                'active' => $isActive,
            ]);
            audit_log('add_class_leader', 'class_teacher', 'class_leaders', (int) $pdo->lastInsertId(), "Added {$position}");
            flash_set('success', "{$position} assigned successfully.");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                flash_set('error', 'This student is already assigned this position for this term/year.');
            } else {
                flash_set('error', 'Failed to add leader: ' . $e->getMessage());
            }
        }
    }
    redirect(app_url('/class_teacher/class_leaders.php'));
}

// ====== UPDATE: Edit existing class leader ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_leader') {
    csrf_verify();
    $leaderId = (int) ($_POST['leader_id'] ?? 0);
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $position = trim($_POST['position'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($leaderId <= 0 || $studentId <= 0 || $position === '') {
        flash_set('error', 'All fields are required.');
    } else {
        $pdo->prepare(
            'UPDATE class_leaders SET student_id = :sid, position = :pos, is_active = :active WHERE leader_id = :id AND class_id = :cid'
        )->execute([
            'sid' => $studentId,
            'pos' => $position,
            'active' => $isActive,
            'id' => $leaderId,
            'cid' => $myClass['class_id'],
        ]);
        audit_log('edit_class_leader', 'class_teacher', 'class_leaders', $leaderId, "Updated {$position}");
        flash_set('success', 'Leader updated successfully.');
    }
    redirect(app_url('/class_teacher/class_leaders.php'));
}

// ====== DELETE: Remove a class leader ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_leader') {
    csrf_verify();
    $leaderId = (int) ($_POST['leader_id'] ?? 0);
    $pdo->prepare('DELETE FROM class_leaders WHERE leader_id = :id AND class_id = :cid')
        ->execute(['id' => $leaderId, 'cid' => $myClass['class_id']]);
    audit_log('delete_class_leader', 'class_teacher', 'class_leaders', $leaderId, 'Removed class leader');
    flash_set('success', 'Leader removed successfully.');
    redirect(app_url('/class_teacher/class_leaders.php'));
}

// ====== READ: Get current class leaders ======
$leaders = $pdo->prepare(
    "SELECT cl.*, u.first_name, u.last_name, u.photo_path, s.admission_no, s.gender
     FROM class_leaders cl
     JOIN students s ON s.student_id = cl.student_id
     JOIN users u ON u.user_id = s.user_id
     WHERE cl.class_id = :cid AND cl.term_id = :term AND cl.year_id = :year
     ORDER BY cl.created_at DESC"
);
$leaders->execute(['cid' => $myClass['class_id'], 'term' => $period['term_id'], 'year' => $period['year_id']]);
$leadersList = $leaders->fetchAll();

// Students not yet assigned as leaders (for the add form)
$eligibleStudents = $pdo->prepare(
    "SELECT s.student_id, u.first_name, u.last_name, s.admission_no FROM students s
     JOIN users u ON u.user_id = s.user_id
     WHERE s.class_id = :cid AND s.status = 'active'
     ORDER BY u.first_name"
);
$eligibleStudents->execute(['cid' => $myClass['class_id']]);
$allStudents = $eligibleStudents->fetchAll();

// Positions for quick select
$positions = [
    'Head Boy', 'Head Girl', 'Deputy Head Boy', 'Deputy Head Girl',
    'Class Monitor', 'Assistant Class Monitor',
    'Academic Prefect', 'Discipline Prefect', 'Sanitation Prefect',
    'Sports Prefect', 'Time Keeper', 'Health Prefect',
    'Library Prefect', 'Entertainment Prefect',
];

$totalLeaders = count($leadersList);
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Class Leaders: <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>
      <p class="mb-0"><?= $totalLeaders ?> leader(s) assigned for current term</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#addLeaderModal"><i class="fa fa-plus me-1"></i> Add Leader</button>
      <a href="<?= e(app_url('/class_teacher/dashboard.php')) ?>" class="btn btn-outline-light"><i class="fa fa-arrow-left me-1"></i> Dashboard</a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-user-tie kpi-icon"></i>
      <div class="kpi-label">Total Leaders</div>
      <div class="kpi-value" data-counter="<?= $totalLeaders ?>">0</div>
      <div class="kpi-sub">Active positions</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-male kpi-icon"></i>
      <div class="kpi-label">Head Boy(s)</div>
      <div class="kpi-value"><?= count(array_filter($leadersList, fn($l) => stripos($l['position'], 'Head Boy') !== false)) ?></div>
      <div class="kpi-sub">Boys leadership</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-gold">
      <i class="fa fa-female kpi-icon"></i>
      <div class="kpi-label">Head Girl(s)</div>
      <div class="kpi-value"><?= count(array_filter($leadersList, fn($l) => stripos($l['position'], 'Head Girl') !== false)) ?></div>
      <div class="kpi-sub">Girls leadership</div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6 animate-fade-in animate-delay-4">
    <div class="asms-kpi-card <?= $totalLeaders > 0 ? 'accent-green' : 'accent-orange' ?>">
      <i class="fa fa-flag kpi-icon"></i>
      <div class="kpi-label">Active</div>
      <div class="kpi-value"><?= count(array_filter($leadersList, fn($l) => $l['is_active'])) ?></div>
      <div class="kpi-sub">Currently serving</div>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1 mb-4">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search leaders..." data-search="#leadersTable" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> Current Term</span>
    <span class="filter-badge"><i class="fa fa-building"></i> All Positions</span>
  </div>
  <div class="action-right">
    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#addLeaderModal"><i class="fa fa-plus me-1"></i> Add New</button>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<div class="card animate-fade-in animate-delay-2">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fa fa-user-tie text-gold me-2"></i>Class Leadership</span>
    <span class="text-muted small"><?= $totalLeaders ?> total</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0" id="leadersTable">
      <thead>
        <tr>
          <th>Student</th>
          <th>Admission No.</th>
          <th>Position</th>
          <th>Gender</th>
          <th>Status</th>
          <th>Assigned</th>
          <th style="width:160px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leadersList as $l): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if ($l['photo_path']): ?>
                  <img src="<?= e($l['photo_path']) ?>" class="rounded-circle" width="32" height="32" style="object-fit:cover;">
                <?php else: ?>
                  <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:12px;">
                    <?= e(strtoupper(substr($l['first_name'], 0, 1) . substr($l['last_name'], 0, 1))) ?>
                  </div>
                <?php endif; ?>
                <strong><?= e($l['first_name'] . ' ' . $l['last_name']) ?></strong>
              </div>
            </td>
            <td><code><?= e($l['admission_no']) ?></code></td>
            <td><span class="badge bg-gold"><?= e($l['position']) ?></span></td>
            <td><?= e(ucfirst($l['gender'] ?? '-')) ?></td>
            <td>
              <?php if ($l['is_active']): ?>
                <span class="badge bg-success">Active</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= format_date($l['created_at']) ?></td>
            <td>
              <div class="d-flex gap-1">
                <!-- Edit Button -->
                <button class="btn btn-sm btn-outline-primary" title="Edit"
                  data-bs-toggle="modal" data-bs-target="#editLeaderModal"
                  data-id="<?= (int) $l['leader_id'] ?>"
                  data-student="<?= (int) $l['student_id'] ?>"
                  data-position="<?= e($l['position']) ?>"
                  data-active="<?= $l['is_active'] ?>">
                  <i class="fa fa-edit"></i>
                </button>
                <!-- Delete Button -->
                <form method="POST" data-confirm="Remove this leader? This action cannot be undone.">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_leader">
                  <input type="hidden" name="leader_id" value="<?= (int) $l['leader_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($leadersList)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">
            <i class="fa fa-user-tie fa-2x mb-2 d-block"></i>
            No class leaders assigned yet. Click "Add Leader" to assign positions.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center">
    <span class="text-muted small">Showing <?= $totalLeaders ?> leader(s)</span>
    <span class="text-muted small">Visible to: Academic, Teachers, Admin, Director</span>
  </div>
</div>

<!-- ====== ADD LEADER MODAL ====== -->
<div class="modal fade" id="addLeaderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="add_leader">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa fa-plus-circle text-gold me-1"></i> Add Class Leader</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Student <span class="required-mark">*</span></label>
            <select name="student_id" class="form-select" required>
              <option value="">-- Select Student --</option>
              <?php foreach ($allStudents as $s): ?>
                <option value="<?= (int) $s['student_id'] ?>"><?= e($s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['admission_no'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Position <span class="required-mark">*</span></label>
            <input type="text" name="position" class="form-control" list="positionList" required placeholder="e.g. Head Boy, Class Monitor">
            <datalist id="positionList">
              <?php foreach ($positions as $p): ?>
                <option value="<?= e($p) ?>">
              <?php endforeach; ?>
            </datalist>
            <div class="mt-1 small text-muted">
              <?php foreach ($positions as $p): ?>
                <span class="badge bg-light text-dark me-1 mb-1" style="cursor:pointer;" onclick="document.getElementsByName('position')[0].value='<?= e($p) ?>'"><?= e($p) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="is_active" id="addIsActive" checked>
            <label class="form-check-label" for="addIsActive">Active (currently serving)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Assign Leader</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ====== EDIT LEADER MODAL ====== -->
<div class="modal fade" id="editLeaderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="edit_leader">
        <input type="hidden" name="leader_id" id="editLeaderId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa fa-edit text-gold me-1"></i> Edit Class Leader</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Student <span class="required-mark">*</span></label>
            <select name="student_id" id="editStudentId" class="form-select" required>
              <option value="">-- Select Student --</option>
              <?php foreach ($allStudents as $s): ?>
                <option value="<?= (int) $s['student_id'] ?>"><?= e($s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['admission_no'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Position <span class="required-mark">*</span></label>
            <input type="text" name="position" id="editPosition" class="form-control" required placeholder="e.g. Head Boy, Class Monitor">
            <div class="mt-1 small text-muted">
              <?php foreach ($positions as $p): ?>
                <span class="badge bg-light text-dark me-1 mb-1" style="cursor:pointer;" onclick="document.getElementById('editPosition').value='<?= e($p) ?>'"><?= e($p) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="is_active" id="editIsActive">
            <label class="form-check-label" for="editIsActive">Active (currently serving)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Update Leader</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Populate edit modal with data from the button's data attributes
document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('editLeaderModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editLeaderId').value = btn.getAttribute('data-id');
            document.getElementById('editStudentId').value = btn.getAttribute('data-student');
            document.getElementById('editPosition').value = btn.getAttribute('data-position');
            document.getElementById('editIsActive').checked = btn.getAttribute('data-active') === '1';
        });
    }
});
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>