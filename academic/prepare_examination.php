 <?php
/**
 * academic/prepare_examination.php
 * General Examination Preparation: once all marks are verified, this page
 * auto-generates the comprehensive examination summary for Admin review.
 * It compiles class rankings, subject averages, student performance
 * distributions, and prepares the data ready for Admin to post/publish.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['academic_officer']);

$pdo = get_db_connection();
$period = get_current_period($pdo);
$termId = (int) ($_GET['term_id'] ?? $period['term_id']);

// ---- Generate General Examination Summary ---------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_summary') {
    csrf_verify();
    $termIdPost = (int) ($_POST['term_id'] ?? 0);

    // 1. Compute class-level summaries
    $classSummaries = $pdo->prepare(
        "SELECT c.class_id, cl.level_name, c.stream_name,
            COUNT(DISTINCT s.student_id) AS total_students,
            COUNT(DISTINCT cs.subject_id) AS total_subjects,
            ROUND(AVG(rc.overall_average), 2) AS class_average,
            ROUND(AVG(rc.overall_gpa), 2) AS class_gpa,
            MAX(rc.overall_average) AS highest_score,
            MIN(rc.overall_average) AS lowest_score,
            SUM(CASE WHEN rc.status = 'published' THEN 1 ELSE 0 END) AS published_count
         FROM classes c
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         LEFT JOIN students s ON s.class_id = c.class_id AND s.status='active'
         LEFT JOIN report_cards rc ON rc.student_id = s.student_id AND rc.term_id = :term
         GROUP BY c.class_id
         ORDER BY cl.sort_order, c.stream_name"
    );
    $classSummaries->execute(['term' => $termIdPost]);
    $summaryData = $classSummaries->fetchAll();

    // 2. Compute subject performance across all classes
    $subjectSummary = $pdo->prepare(
        "SELECT sub.subject_name, sub.subject_code,
            COUNT(DISTINCT cs.class_id) AS class_count,
            COUNT(DISTINCT em.student_id) AS students_marked,
            ROUND(AVG(em.marks_obtained), 2) AS avg_marks,
            MAX(em.marks_obtained) AS highest_mark,
            MIN(em.marks_obtained) AS lowest_mark,
            SUM(CASE WHEN em.is_absent = 1 THEN 1 ELSE 0 END) AS absent_count
         FROM subjects sub
         JOIN class_subjects cs ON cs.subject_id = sub.subject_id
         LEFT JOIN exam_marks em ON em.class_subject_id = cs.class_subject_id
         WHERE sub.is_active = 1
         GROUP BY sub.subject_id
         ORDER BY sub.subject_name"
    );
    $subjectSummary->execute();
    $subjectData = $subjectSummary->fetchAll();

    // 3. Grade distribution across all students
    $gradeDistribution = $pdo->prepare(
        "SELECT gs.grade_letter, gs.remarks, COUNT(rc.report_card_id) AS student_count
         FROM grade_scale gs
         LEFT JOIN report_cards rc ON rc.overall_average BETWEEN gs.min_score AND gs.max_score AND rc.term_id = :term
         GROUP BY gs.grade_id
         ORDER BY gs.min_score DESC"
    );
    $gradeDistribution->execute(['term' => $termIdPost]);
    $gradeData = $gradeDistribution->fetchAll();

    // 4. Overall statistics
    $overallStats = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT s.student_id) AS total_students,
            COUNT(DISTINCT c.class_id) AS total_classes,
            COUNT(DISTINCT cs.subject_id) AS total_subjects,
            ROUND(AVG(rc.overall_average), 2) AS school_average,
            ROUND(AVG(rc.overall_gpa), 2) AS school_gpa,
            SUM(CASE WHEN rc.status = 'published' THEN 1 ELSE 0 END) AS published_reports,
            SUM(CASE WHEN rc.status = 'draft' THEN 1 ELSE 0 END) AS draft_reports
         FROM classes c
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         LEFT JOIN students s ON s.class_id = c.class_id AND s.status='active'
         LEFT JOIN class_subjects cs ON cs.class_id = c.class_id
         LEFT JOIN report_cards rc ON rc.student_id = s.student_id AND rc.term_id = :term"
    );
    $overallStats->execute(['term' => $termIdPost]);
    $overall = $overallStats->fetch();

    // Store summary in session for display
    $_SESSION['exam_summary'] = [
        'overall' => $overall,
        'classes' => $summaryData,
        'subjects' => $subjectData,
        'grades' => $gradeData,
        'term_id' => $termIdPost,
        'generated_at' => date('Y-m-d H:i:s'),
    ];

    audit_log('generate_exam_summary', 'academics', 'examination', $termIdPost, "Generated general examination summary for term #{$termIdPost}");
    flash_set('success', 'General examination summary generated successfully. Ready for Admin review.');
    redirect(app_url('/academic/prepare_examination.php') . '?term_id=' . $termIdPost);
}

// ---- Retrieve stored summary ----------------------------------------------
$summary = $_SESSION['exam_summary'] ?? null;
if ($summary && (int) $summary['term_id'] !== $termId) {
    $summary = null; // Different term selected, clear old summary
}

$terms = $pdo->query(
    'SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id ORDER BY t.start_date DESC'
)->fetchAll();

$pageTitle = 'Prepare General Examination';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Prepare General Examination <span class="badge bg-gold ms-2">For Admin Review</span></h1>
<p class="text-muted">Once all marks are verified and report cards computed, generate the comprehensive examination summary. Admin will review and post the final results.</p>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4">
        <label class="form-label">Term</label>
        <select name="term_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($terms as $t): ?>
            <option value="<?= (int) $t['term_id'] ?>" <?= $termId === (int) $t['term_id'] ? 'selected' : '' ?>>
              <?= e($t['year_name'] . ' - ' . $t['term_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 align-self-end">
        <form method="POST" class="d-inline">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="generate_summary">
          <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
          <button class="btn btn-gold"><i class="fa fa-cogs me-1"></i> Generate / Refresh Summary</button>
        </form>
      </div>
    </form>
  </div>
</div>

<?php if ($summary): ?>
  <!-- Overall School Statistics -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="asms-kpi-card accent-navy">
        <i class="fa fa-user-graduate kpi-icon"></i>
        <div class="kpi-label">Total Students</div>
        <div class="kpi-value" data-counter="<?= (int) ($summary['overall']['total_students'] ?? 0) ?>">0</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="asms-kpi-card accent-blue">
        <i class="fa fa-chalkboard kpi-icon"></i>
        <div class="kpi-label">Total Classes</div>
        <div class="kpi-value" data-counter="<?= (int) ($summary['overall']['total_classes'] ?? 0) ?>">0</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="asms-kpi-card accent-green">
        <i class="fa fa-book kpi-icon"></i>
        <div class="kpi-label">School Average</div>
        <div class="kpi-value"><?= e($summary['overall']['school_average'] ?? '-') ?>%</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="asms-kpi-card accent-gold">
        <i class="fa fa-star kpi-icon"></i>
        <div class="kpi-label">School GPA</div>
        <div class="kpi-value"><?= e($summary['overall']['school_gpa'] ?? '-') ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header"><i class="fa fa-chart-bar text-gold me-2"></i>Class Performance Summary</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Class</th>
                <th>Students</th>
                <th>Subjects</th>
                <th>Avg %</th>
                <th>GPA</th>
                <th>Highest</th>
                <th>Lowest</th>
                <th>Published</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summary['classes'] as $c): ?>
                <tr>
                  <td><?= e($c['level_name'] . ' ' . $c['stream_name']) ?></td>
                  <td><?= (int) $c['total_students'] ?></td>
                  <td><?= (int) $c['total_subjects'] ?></td>
                  <td class="fw-semibold"><?= $c['class_average'] !== null ? e($c['class_average']) . '%' : '-' ?></td>
                  <td><?= $c['class_gpa'] !== null ? e($c['class_gpa']) : '-' ?></td>
                  <td><?= $c['highest_score'] !== null ? e($c['highest_score']) . '%' : '-' ?></td>
                  <td><?= $c['lowest_score'] !== null ? e($c['lowest_score']) . '%' : '-' ?></td>
                  <td><span class="badge bg-success"><?= (int) $c['published_count'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header"><i class="fa fa-book text-gold me-2"></i>Subject Performance</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Subject</th>
                <th>Classes</th>
                <th>Students</th>
                <th>Avg Mark</th>
                <th>Highest</th>
                <th>Lowest</th>
                <th>Absent</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summary['subjects'] as $s): ?>
                <tr>
                  <td><?= e($s['subject_name']) ?></td>
                  <td><?= (int) $s['class_count'] ?></td>
                  <td><?= (int) $s['students_marked'] ?></td>
                  <td class="fw-semibold"><?= $s['avg_marks'] !== null ? e($s['avg_marks']) : '-' ?></td>
                  <td><?= $s['highest_mark'] !== null ? e($s['highest_mark']) : '-' ?></td>
                  <td><?= $s['lowest_mark'] !== null ? e($s['lowest_mark']) : '-' ?></td>
                  <td><span class="text-danger"><?= (int) $s['absent_count'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Grade Distribution -->
  <div class="card mb-4">
    <div class="card-header"><i class="fa fa-chart-pie text-gold me-2"></i>Grade Distribution</div>
    <div class="card-body">
      <div class="row">
        <?php foreach ($summary['grades'] as $g): $total = (int) ($summary['overall']['total_students'] ?? 1); $pct = $total > 0 ? round(((int) $g['student_count'] / $total) * 100, 1) : 0; ?>
          <div class="col-md-3 mb-3">
            <div class="border rounded p-3 text-center">
              <h2 class="mb-0"><?= e($g['grade_letter']) ?></h2>
              <p class="small text-muted mb-1"><?= e($g['remarks'] ?? '') ?></p>
              <div class="h4 mb-0"><?= (int) $g['student_count'] ?></div>
              <small class="text-muted"><?= e($pct) ?>% of students</small>
              <div class="progress mt-2" style="height:6px;">
                <div class="progress-bar bg-<?= $pct >= 50 ? 'success' : ($pct >= 20 ? 'warning' : 'danger') ?>" style="width:<?= e($pct) ?>%"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Summary Info & Admin Action -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="fa fa-check-circle text-gold me-2"></i>Examination Summary Ready for Admin</span>
      <span class="text-muted small">Generated: <?= e($summary['generated_at'] ?? 'N/A') ?></span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <h6>Summary Overview</h6>
          <ul class="list-unstyled mb-0">
            <li><strong>Total Students:</strong> <?= (int) ($summary['overall']['total_students'] ?? 0) ?></li>
            <li><strong>Total Classes:</strong> <?= (int) ($summary['overall']['total_classes'] ?? 0) ?></li>
            <li><strong>Total Subjects:</strong> <?= (int) ($summary['overall']['total_subjects'] ?? 0) ?></li>
            <li><strong>School Average:</strong> <?= e($summary['overall']['school_average'] ?? '-') ?>%</li>
            <li><strong>School GPA:</strong> <?= e($summary['overall']['school_gpa'] ?? '-') ?></li>
            <li><strong>Published Reports:</strong> <?= (int) ($summary['overall']['published_reports'] ?? 0) ?></li>
            <li><strong>Draft Reports:</strong> <?= (int) ($summary['overall']['draft_reports'] ?? 0) ?></li>
          </ul>
        </div>
        <div class="col-md-6">
          <h6>Next Steps for Admin</h6>
          <ol class="mb-0">
            <li>Review the class performance summaries above.</li>
            <li>Check subject performance and grade distribution.</li>
            <li>Go to <a href="<?= e(app_url('/academic/publish_results.php')) ?>?term_id=<?= (int) $termId ?>">Publish Results</a> to post individual report cards.</li>
            <li>Once all report cards are published, the general examination is complete.</li>
          </ol>
          <div class="mt-3">
            <button class="btn btn-outline-primary" onclick="window.print()"><i class="fa fa-print me-1"></i> Print Summary</button>
            <a href="<?= e(app_url('/academic/publish_results.php')) ?>?term_id=<?= (int) $termId ?>" class="btn btn-gold"><i class="fa fa-bullhorn me-1"></i> Go to Publish Results</a>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-body text-center py-5">
      <i class="fa fa-file-alt fa-4x text-muted mb-3"></i>
      <h5>No Examination Summary Generated Yet</h5>
      <p class="text-muted">Select a term and click "Generate / Refresh Summary" to compile the comprehensive examination report.</p>
      <p class="text-muted small mb-0">Ensure all marks are verified and report cards have been computed before generating the summary.</p>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>