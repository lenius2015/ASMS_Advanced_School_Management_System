<?php
/**
 * academic/reports.php
 * Academic Department reports: teacher performance (submission status),
 * student performance across classes, subject analytics, and
 * class performance summaries.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer', 'head_of_school', 'director', 'system_admin', 'subject_teacher']);

$pdo = get_db_connection();
$period = get_current_period($pdo);
$termId = (int) ($_GET['term_id'] ?? $period['term_id']);
$reportType = $_GET['type'] ?? 'teachers';

// ---- Teacher Submission Report --------------------------------------------
$teacherReport = [];
if ($reportType === 'teachers' || $reportType === '') {
    $teacherReport = $pdo->query(
        "SELECT u.user_id, u.first_name, u.last_name,
            COUNT(DISTINCT cs.class_subject_id) AS assigned_subjects,
            COUNT(DISTINCT em.exam_id) AS exams_marked,
            SUM(CASE WHEN em.submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN em.verification_status = 'verified' THEN 1 ELSE 0 END) AS verified_count,
            SUM(CASE WHEN em.verification_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            COUNT(DISTINCT s.student_id) AS total_students
         FROM users u
         JOIN class_subjects cs ON cs.teacher_id = u.user_id
         LEFT JOIN exam_marks em ON em.class_subject_id = cs.class_subject_id
         LEFT JOIN students s ON s.class_id = cs.class_id AND s.status='active'
         WHERE u.role_id = (SELECT role_id FROM roles WHERE role_name='subject_teacher')
         GROUP BY u.user_id
         ORDER BY u.first_name"
    )->fetchAll();
}

// ---- Class Performance Report ---------------------------------------------
$classReport = [];
if ($reportType === 'classes') {
    $classReport = $pdo->prepare(
        "SELECT c.class_id, cl.level_name, c.stream_name,
            COUNT(DISTINCT s.student_id) AS student_count,
            COUNT(DISTINCT cs.subject_id) AS subject_count,
            ROUND(AVG(rc.overall_average), 2) AS class_avg,
            ROUND(AVG(rc.overall_gpa), 2) AS class_avg_gpa,
            SUM(CASE WHEN rc.status = 'published' THEN 1 ELSE 0 END) AS published_count,
            SUM(CASE WHEN rc.status = 'draft' THEN 1 ELSE 0 END) AS draft_count
         FROM classes c
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         LEFT JOIN students s ON s.class_id = c.class_id AND s.status='active'
         LEFT JOIN class_subjects cs ON cs.class_id = c.class_id
         LEFT JOIN report_cards rc ON rc.student_id = s.student_id AND rc.term_id = :term
         GROUP BY c.class_id
         ORDER BY cl.sort_order, c.stream_name"
    );
    $classReport->execute(['term' => $termId]);
    $classReport = $classReport->fetchAll();
}

// ---- Subject Performance Report -------------------------------------------
$subjectReport = [];
if ($reportType === 'subjects') {
    $subjectReport = $pdo->prepare(
        "SELECT sub.subject_id, sub.subject_name, sub.subject_code,
            COUNT(DISTINCT cs.class_subject_id) AS class_count,
            COUNT(DISTINCT em.student_id) AS students_marked,
            ROUND(AVG(em.marks_obtained), 2) AS avg_marks,
            SUM(CASE WHEN em.verification_status = 'verified' THEN 1 ELSE 0 END) AS verified_entries
         FROM subjects sub
         JOIN class_subjects cs ON cs.subject_id = sub.subject_id
         LEFT JOIN exam_marks em ON em.class_subject_id = cs.class_subject_id
         WHERE sub.is_active = 1
         GROUP BY sub.subject_id
         ORDER BY sub.subject_name"
    )->fetchAll();
}

// ---- Student Performance Overview (Top/Bottom) ----------------------------
$studentPerformance = [];
if ($reportType === 'students') {
    $studentPerformance = $pdo->prepare(
        "SELECT s.student_id, u.first_name, u.last_name, s.admission_no,
            cl.level_name, c.stream_name,
            rc.overall_average, rc.overall_gpa, rc.overall_position,
            rc.class_size, rc.status
         FROM report_cards rc
         JOIN students s ON s.student_id = rc.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN classes c ON c.class_id = s.class_id
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE rc.term_id = :term
         ORDER BY rc.overall_average DESC
         LIMIT 100"
    );
    $studentPerformance->execute(['term' => $termId]);
    $studentPerformance = $studentPerformance->fetchAll();
}

$terms = $pdo->query(
    'SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id ORDER BY t.start_date DESC'
)->fetchAll();

$pageTitle = 'Academic Reports';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Academic Reports</h1>
<p class="text-muted">Teacher submission status, class performance, subject analytics, and student performance overview.</p>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Report Type</label>
        <select name="type" class="form-select" onchange="this.form.submit()">
          <option value="teachers" <?= $reportType === 'teachers' ? 'selected' : '' ?>>Teacher Submission Report</option>
          <option value="classes" <?= $reportType === 'classes' ? 'selected' : '' ?>>Class Performance</option>
          <option value="subjects" <?= $reportType === 'subjects' ? 'selected' : '' ?>>Subject Analytics</option>
          <option value="students" <?= $reportType === 'students' ? 'selected' : '' ?>>Student Performance</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Term</label>
        <select name="term_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($terms as $t): ?>
            <option value="<?= (int) $t['term_id'] ?>" <?= $termId === (int) $t['term_id'] ? 'selected' : '' ?>>
              <?= e($t['year_name'] . ' - ' . $t['term_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 align-self-end">
        <button class="btn btn-outline-primary w-100" onclick="window.print()"><i class="fa fa-print me-1"></i> Print</button>
      </div>
    </form>
  </div>
</div>

<?php if ($reportType === 'teachers'): ?>
  <!-- Teacher Submission Report -->
  <div class="card">
    <div class="card-header"><i class="fa fa-chalkboard-teacher text-gold me-2"></i>Teacher Submission Status</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Teacher</th>
            <th>Assigned Subjects</th>
            <th>Exams Marked</th>
            <th>Submitted</th>
            <th>Verified</th>
            <th>Rejected</th>
            <th>Total Students</th>
            <th>Submission Rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($teacherReport as $t): $totalExams = (int) $t['exams_marked']; ?>
            <tr>
              <td><?= e($t['first_name'] . ' ' . $t['last_name']) ?></td>
              <td><?= (int) $t['assigned_subjects'] ?></td>
              <td><?= $totalExams ?></td>
              <td><?= (int) $t['submitted_count'] ?></td>
              <td><span class="text-success"><?= (int) $t['verified_count'] ?></span></td>
              <td>
                <?php $rej = (int) $t['rejected_count']; ?>
                <span class="<?= $rej > 0 ? 'text-danger' : 'text-muted' ?>"><?= $rej ?></span>
              </td>
              <td><?= (int) $t['total_students'] ?></td>
              <td>
                <?php if ((int) $t['assigned_subjects'] > 0): ?>
                  <?php $rate = $totalExams > 0 ? round(((int) $t['submitted_count'] / $totalExams) * 100, 1) : 0; ?>
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:8px;">
                      <div class="progress-bar bg-<?= $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger') ?>" style="width:<?= e($rate) ?>%"></div>
                    </div>
                    <small><?= e($rate) ?>%</small>
                  </div>
                <?php else: ?>
                  <span class="text-muted">N/A</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($teacherReport)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No teacher data found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($reportType === 'classes'): ?>
  <!-- Class Performance Report -->
  <div class="card">
    <div class="card-header"><i class="fa fa-chalkboard text-gold me-2"></i>Class Performance Summary</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Class</th>
            <th>Students</th>
            <th>Subjects</th>
            <th>Class Avg %</th>
            <th>Class Avg GPA</th>
            <th>Draft Reports</th>
            <th>Published Reports</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($classReport as $c): ?>
            <tr>
              <td><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></td>
              <td><?= (int) $c['student_count'] ?></td>
              <td><?= (int) $c['subject_count'] ?></td>
              <td class="fw-semibold"><?= $c['class_avg'] !== null ? e($c['class_avg']) . '%' : '-' ?></td>
              <td><?= $c['class_avg_gpa'] !== null ? e($c['class_avg_gpa']) : '-' ?></td>
              <td><span class="badge bg-secondary"><?= (int) $c['draft_count'] ?></span></td>
              <td><span class="badge bg-success"><?= (int) $c['published_count'] ?></span></td>
              <td>
                <a href="<?= e(app_url('/academic/class_results.php')) ?>?class_id=<?= (int) $c['class_id'] ?>&term_id=<?= (int) $termId ?>" class="btn btn-sm btn-outline-primary">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($classReport)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No class data found for this term.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($reportType === 'subjects'): ?>
  <!-- Subject Analytics -->
  <div class="card">
    <div class="card-header"><i class="fa fa-book text-gold me-2"></i>Subject Performance Analytics</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Subject</th>
            <th>Code</th>
            <th>Classes Taught</th>
            <th>Students Marked</th>
            <th>Avg Marks</th>
            <th>Verified Entries</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subjectReport as $s): ?>
            <tr>
              <td><?= e($s['subject_name']) ?></td>
              <td><code><?= e($s['subject_code']) ?></code></td>
              <td><?= (int) $s['class_count'] ?></td>
              <td><?= (int) $s['students_marked'] ?></td>
              <td class="fw-semibold"><?= $s['avg_marks'] !== null ? e($s['avg_marks']) : '-' ?></td>
              <td><span class="badge bg-success"><?= (int) $s['verified_entries'] ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($subjectReport)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No subject data found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($reportType === 'students'): ?>
  <!-- Student Performance Overview -->
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span><i class="fa fa-user-graduate text-gold me-2"></i>Student Performance Ranking</span>
      <span class="text-muted small">Top 100 students by overall average</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Rank</th>
            <th>Student</th>
            <th>Admission No.</th>
            <th>Class</th>
            <th>Average %</th>
            <th>GPA</th>
            <th>Class Position</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php $rank = 1; ?>
          <?php foreach ($studentPerformance as $sp): ?>
            <tr>
              <td class="fw-bold">#<?= $rank++ ?></td>
              <td><?= e($sp['first_name'] . ' ' . $sp['last_name']) ?></td>
              <td><code><?= e($sp['admission_no']) ?></code></td>
              <td><?= e(($sp['level_name'] ?? '') . ' ' . ($sp['stream_name'] ?? '')) ?></td>
              <td class="fw-semibold"><?= $sp['overall_average'] !== null ? e($sp['overall_average']) . '%' : '-' ?></td>
              <td><?= $sp['overall_gpa'] !== null ? e($sp['overall_gpa']) : '-' ?></td>
              <td>#<?= (int) $sp['overall_position'] ?> / <?= (int) $sp['class_size'] ?></td>
              <td>
                <span class="badge badge-status-<?= e($sp['status']) ?>"><?= e(ucfirst($sp['status'])) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($studentPerformance)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No published results yet for this term.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>