<?php
/**
 * head_of_school/discipline.php
 * Discipline records: log new incidents, track action taken, and
 * optionally notify parents/guardians.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['head_of_school', 'department_head', 'class_teacher']);

$pdo = get_db_connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_incident') {
    csrf_verify();

    $studentId = (int) ($_POST['student_id'] ?? 0);
    $incidentDate = $_POST['incident_date'] ?? date('Y-m-d');
    $category = $_POST['category'] ?? 'minor';
    $description = trim($_POST['description'] ?? '');
    $actionTaken = trim($_POST['action_taken'] ?? '');
    $notifyParent = isset($_POST['notify_parent']);

    if ($studentId <= 0 || $description === '') {
        $error = 'Student and description are required.';
    } else {
        $pdo->prepare(
            'INSERT INTO discipline_records (student_id, incident_date, category, description, action_taken, reported_by, parent_notified)
             VALUES (:sid, :date, :cat, :desc, :action, :by, :notify)'
        )->execute([
            'sid' => $studentId, 'date' => $incidentDate, 'cat' => $category,
            'desc' => $description, 'action' => $actionTaken ?: null, 'by' => current_user_id(), 'notify' => $notifyParent ? 1 : 0,
        ]);
        $recordId = (int) $pdo->lastInsertId();

        if ($notifyParent) {
            $guardianStmt = $pdo->prepare(
                "SELECT g.user_id FROM guardians g
                 JOIN student_guardians sg ON sg.guardian_id = g.guardian_id
                 WHERE sg.student_id = :sid AND g.user_id IS NOT NULL"
            );
            $guardianStmt->execute(['sid' => $studentId]);
            foreach ($guardianStmt->fetchAll() as $g) {
                notify_user($pdo, (int) $g['user_id'], 'Discipline Notice', 'A discipline record has been logged for your child. Please check the parent portal for details.', 'discipline', app_url('/parent/discipline.php'));
            }
        }

        audit_log('log_discipline', 'discipline', 'discipline_records', $recordId, 'Logged a discipline incident');
        flash_set('success', 'Discipline record logged successfully.');
        redirect(app_url('/head_of_school/discipline.php'));
    }
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT d.*, u.first_name, u.last_name, s.admission_no, rep.first_name AS rfn, rep.last_name AS rln
        FROM discipline_records d
        JOIN students s ON s.student_id = d.student_id
        JOIN users u ON u.user_id = s.user_id
        LEFT JOIN users rep ON rep.user_id = d.reported_by
        WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3)';
    $params['s1'] = $params['s2'] = $params['s3'] = "%{$search}%";
}
$sql .= ' ORDER BY d.created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$students = $pdo->query(
    "SELECT s.student_id, u.first_name, u.last_name, s.admission_no FROM students s
     JOIN users u ON u.user_id = s.user_id WHERE s.status='active' ORDER BY u.first_name"
)->fetchAll();

$pageTitle = 'Discipline Records';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">Discipline Records</h1>
  <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#logIncidentModal"><i class="fa fa-plus me-1"></i> Log Incident</button>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-6"><input type="text" name="q" class="form-control" placeholder="Search student name or admission no." value="<?= e($search) ?>"></div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="fa fa-search"></i></button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Date</th><th>Student</th><th>Category</th><th>Description</th><th>Action Taken</th><th>Reported By</th><th>Parent Notified</th></tr></thead>
      <tbody>
        <?php foreach ($records as $r): ?>
          <tr>
            <td class="small"><?= format_date($r['incident_date']) ?></td>
            <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?> <span class="text-muted small">(<?= e($r['admission_no']) ?>)</span></td>
            <td><span class="badge badge-status-<?= $r['category']==='severe' ? 'overdue' : ($r['category']==='moderate' ? 'pending' : 'active') ?>"><?= e(ucfirst($r['category'])) ?></span></td>
            <td class="small"><?= e($r['description']) ?></td>
            <td class="small"><?= e($r['action_taken'] ?: '-') ?></td>
            <td class="small text-muted"><?= e(trim(($r['rfn'] ?? '') . ' ' . ($r['rln'] ?? ''))) ?></td>
            <td><?= $r['parent_notified'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?><tr><td colspan="7" class="text-center text-muted py-4">No discipline records found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="logIncidentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="log_incident">
        <div class="modal-header">
          <h5 class="modal-title">Log Discipline Incident</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
          <div class="mb-2">
            <label class="form-label">Student <span class="required-mark">*</span></label>
            <select name="student_id" class="form-select" required>
              <option value="">-- Select Student --</option>
              <?php foreach ($students as $s): ?>
                <option value="<?= (int) $s['student_id'] ?>"><?= e($s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['admission_no'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Incident Date</label><input type="date" name="incident_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-6"><label class="form-label">Category</label>
              <select name="category" class="form-select"><option value="minor">Minor</option><option value="moderate">Moderate</option><option value="severe">Severe</option></select>
            </div>
          </div>
          <div class="mb-2"><label class="form-label">Description <span class="required-mark">*</span></label><textarea name="description" class="form-control" rows="3" required></textarea></div>
          <div class="mb-2"><label class="form-label">Action Taken</label><textarea name="action_taken" class="form-control" rows="2"></textarea></div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="notify_parent" id="notifyParent">
            <label class="form-check-label" for="notifyParent">Notify parent/guardian via in-app notification</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
