<?php
/**
 * academic/students.php
 * Student list view for academic officers.
 * Academic handles academic matters (exams, marks, reports, timetables, etc.)
 * NOT school management — student registration/editing/deletion is done by Head of School.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'academic_officer']);

$pdo = get_db_connection();

// ---- FETCH STUDENTS ---------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$classFilter = (int) ($_GET['class_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT s.*, u.first_name, u.last_name, u.username, u.photo_path, u.email, u.phone,
               cl.level_name, c.stream_name
        FROM students s
        LEFT JOIN users u ON u.user_id = s.user_id
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
        WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR s.admission_no LIKE :s3)';
    $params['s1'] = $params['s2'] = $params['s3'] = "%{$search}%";
}
if ($classFilter > 0) {
    $sql .= ' AND s.class_id = :cls';
    $params['cls'] = $classFilter;
}
if ($statusFilter !== '') {
    $sql .= ' AND s.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY s.created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$classes = $pdo->query(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id ORDER BY cl.sort_order, c.stream_name"
)->fetchAll();

$pageTitle = 'Student Directory';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-user-graduate text-gold me-2"></i>Student Directory</h1>
  <span class="text-muted small"><i class="fa fa-info-circle me-1"></i>Academic view only — student registration is managed by the Head of School.</span>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Search name or admission no." value="<?= e($search) ?>"></div>
      <div class="col-md-3">
        <select name="class_id" class="form-select">
          <option value="0">All Classes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['class_id'] ?>" <?= $classFilter === (int) $c['class_id'] ? 'selected' : '' ?>><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['active','graduated','transferred','suspended','expelled','inactive'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="fa fa-search"></i> Filter</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Admission No.</th>
        <th>Name</th>
        <th>Class</th>
        <th>Gender</th>
        <th>Status</th>
        <th>NIDA</th>
        <th>Passport</th>
        <th>Admitted</th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($students as $s):
          $sid = (int) $s['student_id'];
        ?>
          <tr>
            <td><code><?= e($s['admission_no']) ?></code></td>
            <td>
              <?= render_avatar($s['photo_path'] ?? null, $s['first_name'] ?? '', $s['last_name'] ?? '', 28, 'me-1') ?>
              <?= e(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?>
            </td>
            <td><?= e($s['level_name'] ? $s['level_name'] . ' ' . $s['stream_name'] : 'Unassigned') ?></td>
            <td><?= e(ucfirst($s['gender'] ?? '-')) ?></td>
            <td><span class="badge badge-status-<?= e($s['status']) ?>"><?= e(ucfirst($s['status'])) ?></span></td>
            <td class="small"><?= e($s['nida_number'] ?: '-') ?></td>
            <td class="small"><?= e($s['passport_number'] ?: '-') ?></td>
            <td class="small text-muted"><?= format_date($s['admission_date']) ?></td>
            <td>
              <a href="<?= e(app_url('/director/student_profile.php')) ?>?id=<?= $sid ?>" class="btn btn-sm btn-outline-primary" title="View Profile"><i class="fa fa-eye"></i> View</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="9" class="text-center text-muted py-4">No students found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>