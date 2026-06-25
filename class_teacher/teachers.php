<?php
/**
 * class_teacher/teachers.php
 * Directory of all subject teachers assigned to the class teacher's
 * homeroom class, with contact information and subject details.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['class_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

// Get the teacher's assigned class
$classStmt = $pdo->prepare(
    "SELECT c.class_id, cl.level_name, c.stream_name FROM classes c
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id WHERE c.class_teacher_id = :tid LIMIT 1"
);
$classStmt->execute(['tid' => $teacherId]);
$myClass = $classStmt->fetch();

$pageTitle = 'Subject Teachers';
require APP_ROOT . '/includes/header.php';

if (!$myClass) {
    echo '<div class="alert alert-warning">You are not currently assigned as a class teacher for any class.</div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// Get all subject teachers for this class with their details
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.photo_path,
            sub.subject_name, sub.subject_code, sub.subject_id,
            d.department_name, st.job_title, st.staff_no,
            (SELECT COUNT(*) FROM class_subjects cs2 WHERE cs2.teacher_id = u.user_id AND cs2.class_id = :cid2) AS subjects_count
     FROM class_subjects cs
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN users u ON u.user_id = cs.teacher_id
     LEFT JOIN staff st ON st.user_id = u.user_id
     LEFT JOIN departments d ON d.department_id = st.department_id
     WHERE cs.class_id = :cid AND cs.teacher_id IS NOT NULL
     GROUP BY u.user_id, sub.subject_id
     ORDER BY sub.subject_name"
);
$stmt->execute(['cid' => $myClass['class_id'], 'cid2' => $myClass['class_id']]);
$teachers = $stmt->fetchAll();

// Group by teacher
$teacherMap = [];
foreach ($teachers as $t) {
    $uid = $t['user_id'];
    if (!isset($teacherMap[$uid])) {
        $teacherMap[$uid] = [
            'user_id' => $t['user_id'],
            'first_name' => $t['first_name'],
            'last_name' => $t['last_name'],
            'email' => $t['email'],
            'phone' => $t['phone'],
            'photo_path' => $t['photo_path'],
            'department_name' => $t['department_name'],
            'job_title' => $t['job_title'],
            'staff_no' => $t['staff_no'],
            'subjects' => [],
        ];
    }
    $teacherMap[$uid]['subjects'][] = [
        'subject_name' => $t['subject_name'],
        'subject_code' => $t['subject_code'],
    ];
}

$totalTeachers = count($teacherMap);
?>

<div class="welcome-section animate-fade-in">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Subject Teachers: <?= e($myClass['level_name'] . ' ' . $myClass['stream_name']) ?></h1>
      <p class="mb-0"><?= $totalTeachers ?> teacher(s) assigned to this class</p>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(app_url('/class_teacher/subjects.php')) ?>" class="btn btn-outline-light"><i class="fa fa-book me-1"></i> Subjects</a>
      <a href="<?= e(app_url('/class_teacher/dashboard.php')) ?>" class="btn btn-outline-light"><i class="fa fa-arrow-left me-1"></i> Dashboard</a>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-1">
    <div class="asms-kpi-card accent-navy">
      <i class="fa fa-chalkboard-teacher kpi-icon"></i>
      <div class="kpi-label">Total Teachers</div>
      <div class="kpi-value" data-counter="<?= $totalTeachers ?>">0</div>
      <div class="kpi-sub">Assigned to this class</div>
    </div>
  </div>
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-2">
    <div class="asms-kpi-card accent-blue">
      <i class="fa fa-book kpi-icon"></i>
      <div class="kpi-label">Total Subjects</div>
      <div class="kpi-value"><?= count($teachers) ?></div>
      <div class="kpi-sub">Across all teachers</div>
    </div>
  </div>
  <div class="col-md-4 col-sm-6 animate-fade-in animate-delay-3">
    <div class="asms-kpi-card accent-green">
      <i class="fa fa-envelope kpi-icon"></i>
      <div class="kpi-label">With Email</div>
      <div class="kpi-value"><?= count(array_filter($teacherMap, fn($t) => !empty($t['email']))) ?></div>
      <div class="kpi-sub">Contactable via email</div>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1 mb-4">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search teachers..." data-search="#teachersTable" style="width:220px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> All Teachers</span>
  </div>
  <div class="action-right">
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<?php if (empty($teacherMap)): ?>
  <div class="alert alert-warning animate-fade-in">
    <i class="fa fa-exclamation-triangle me-2"></i>No subject teachers have been assigned to this class yet. Contact the Academic Department.
  </div>
<?php else: ?>
  <div class="row g-3 animate-fade-in animate-delay-2">
    <?php foreach ($teacherMap as $t): ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 teacher-card">
          <div class="card-body text-center">
            <div class="mb-3">
              <?php if ($t['photo_path']): ?>
                <img src="<?= e($t['photo_path']) ?>" class="rounded-circle teacher-photo" width="80" height="80" style="object-fit:cover;border:3px solid #C5A55A;">
              <?php else: ?>
                <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center teacher-photo" style="width:80px;height:80px;font-size:28px;border:3px solid #C5A55A;">
                  <?= e(strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1))) ?>
                </div>
              <?php endif; ?>
            </div>
            <h5 class="card-title mb-1"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></h5>
            <p class="text-muted small mb-2"><?= e($t['job_title'] ?: 'Subject Teacher') ?></p>
            <?php if ($t['staff_no']): ?>
              <p class="text-muted small mb-2"><code><?= e($t['staff_no']) ?></code></p>
            <?php endif; ?>
            <hr class="my-2">
            <div class="text-start small">
              <p class="mb-1"><i class="fa fa-book text-gold me-1"></i> <strong>Subjects:</strong></p>
              <div class="mb-2">
                <?php foreach ($t['subjects'] as $sub): ?>
                  <span class="badge bg-gold me-1 mb-1"><?= e($sub['subject_name']) ?> (<?= e($sub['subject_code']) ?>)</span>
                <?php endforeach; ?>
              </div>
              <?php if ($t['department_name']): ?>
                <p class="mb-1"><i class="fa fa-building me-1"></i> <?= e($t['department_name']) ?></p>
              <?php endif; ?>
              <?php if ($t['email']): ?>
                <p class="mb-1"><i class="fa fa-envelope me-1"></i> <a href="mailto:<?= e($t['email']) ?>"><?= e($t['email']) ?></a></p>
              <?php endif; ?>
              <?php if ($t['phone']): ?>
                <p class="mb-1"><i class="fa fa-phone me-1"></i> <a href="tel:<?= e($t['phone']) ?>"><?= e($t['phone']) ?></a></p>
              <?php endif; ?>
            </div>
            <hr class="my-2">
            <div class="d-flex gap-1 justify-content-center">
              <?php if ($t['email']): ?>
                <a href="mailto:<?= e($t['email']) ?>" class="btn btn-sm btn-outline-primary" title="Send Email"><i class="fa fa-envelope"></i></a>
              <?php endif; ?>
              <?php if ($t['phone']): ?>
                <a href="tel:<?= e($t['phone']) ?>" class="btn btn-sm btn-outline-success" title="Call"><i class="fa fa-phone"></i></a>
              <?php endif; ?>
              <a href="<?= e(app_url('/communication/inbox.php')) ?>?to=<?= (int) $t['user_id'] ?>" class="btn btn-sm btn-outline-info" title="Send Message"><i class="fa fa-comment"></i></a>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Table View -->
  <div class="card mt-4 animate-fade-in animate-delay-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="fa fa-list text-gold me-2"></i>All Teachers (Detailed)</span>
      <span class="text-muted small"><?= $totalTeachers ?> teachers</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="teachersTable">
        <thead>
          <tr>
            <th>Teacher</th>
            <th>Staff No.</th>
            <th>Subjects</th>
            <th>Department</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($teacherMap as $t): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($t['photo_path']): ?>
                    <img src="<?= e($t['photo_path']) ?>" class="rounded-circle" width="32" height="32" style="object-fit:cover;">
                  <?php else: ?>
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:12px;">
                      <?= e(strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1))) ?>
                    </div>
                  <?php endif; ?>
                  <strong><?= e($t['first_name'] . ' ' . $t['last_name']) ?></strong>
                </div>
              </td>
              <td><code><?= e($t['staff_no'] ?: '-') ?></code></td>
              <td>
                <?php foreach ($t['subjects'] as $sub): ?>
                  <span class="badge bg-gold me-1"><?= e($sub['subject_name']) ?></span>
                <?php endforeach; ?>
              </td>
              <td><?= e($t['department_name'] ?: '-') ?></td>
              <td><?= $t['email'] ? e($t['email']) : '<span class="text-muted">-</span>' ?></td>
              <td><?= $t['phone'] ? e($t['phone']) : '<span class="text-muted">-</span>' ?></td>
              <td>
                <div class="d-flex gap-1">
                  <?php if ($t['email']): ?>
                    <a href="mailto:<?= e($t['email']) ?>" class="btn btn-sm btn-outline-primary" title="Email"><i class="fa fa-envelope"></i></a>
                  <?php endif; ?>
                  <a href="<?= e(app_url('/communication/inbox.php')) ?>?to=<?= (int) $t['user_id'] ?>" class="btn btn-sm btn-outline-info" title="Message"><i class="fa fa-comment"></i></a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($teacherMap)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No teachers found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<style>
.teacher-card {
  transition: all 0.3s ease;
  border: 1px solid rgba(0,0,0,0.08);
}
.teacher-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  border-color: #C5A55A;
}
.teacher-photo {
  transition: all 0.3s ease;
}
.teacher-card:hover .teacher-photo {
  border-color: #0A1C2E !important;
}
</style>

<?php require APP_ROOT . '/includes/footer.php'; ?>