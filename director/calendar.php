<?php
/**
 * director/calendar.php
 * School Calendar Management - Admin/Director manages academic years,
 * terms, holidays, and important school events. This is the central
 * calendar that sets dates for the entire school.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'head_of_school', 'system_admin']);

$pdo = get_db_connection();

// ---- Academic Year CRUD ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_year') {
    csrf_verify();
    $yearName = trim($_POST['year_name'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $isCurrent = !empty($_POST['is_current']) ? 1 : 0;

    if ($yearName === '' || $startDate === '' || $endDate === '') {
        flash_set('error', 'Year name, start date, and end date are required.');
    } else {
        // If setting as current, unset any existing current year
        if ($isCurrent) {
            $pdo->exec("UPDATE academic_years SET is_current = 0");
        }
        $pdo->prepare(
            'INSERT INTO academic_years (year_name, start_date, end_date, is_current) VALUES (:name, :start, :end, :curr)'
        )->execute(['name' => $yearName, 'start' => $startDate, 'end' => $endDate, 'curr' => $isCurrent]);
        audit_log('create_academic_year', 'calendar', 'academic_years', (int) $pdo->lastInsertId(), "Created academic year {$yearName}");
        flash_set('success', "Academic year {$yearName} created.");
    }
    redirect(app_url('/director/calendar.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_current_year') {
    csrf_verify();
    $yearId = (int) ($_POST['year_id'] ?? 0);
    $pdo->exec('UPDATE academic_years SET is_current = 0');
    $pdo->prepare('UPDATE academic_years SET is_current = 1 WHERE year_id = :id')->execute(['id' => $yearId]);
    audit_log('set_current_year', 'calendar', 'academic_years', $yearId, "Set as current academic year");
    flash_set('success', 'Current academic year updated.');
    redirect(app_url('/director/calendar.php'));
}

// ---- Term CRUD ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_term') {
    csrf_verify();
    $yearId = (int) ($_POST['year_id'] ?? 0);
    $termName = trim($_POST['term_name'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $isCurrent = !empty($_POST['is_current']) ? 1 : 0;

    if ($yearId <= 0 || $termName === '' || $startDate === '' || $endDate === '') {
        flash_set('error', 'All fields are required for creating a term.');
    } else {
        if ($isCurrent) {
            $pdo->exec("UPDATE terms SET is_current = 0");
        }
        $pdo->prepare(
            'INSERT INTO terms (year_id, term_name, start_date, end_date, is_current) VALUES (:yid, :name, :start, :end, :curr)'
        )->execute(['yid' => $yearId, 'name' => $termName, 'start' => $startDate, 'end' => $endDate, 'curr' => $isCurrent]);
        audit_log('create_term', 'calendar', 'terms', (int) $pdo->lastInsertId(), "Created term {$termName}");
        flash_set('success', "Term {$termName} created.");
    }
    redirect(app_url('/director/calendar.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_current_term') {
    csrf_verify();
    $termId = (int) ($_POST['term_id'] ?? 0);
    $pdo->exec('UPDATE terms SET is_current = 0');
    $pdo->prepare('UPDATE terms SET is_current = 1 WHERE term_id = :id')->execute(['id' => $termId]);

    // Also update system settings for current period
    $termInfo = $pdo->prepare('SELECT year_id FROM terms WHERE term_id = :id');
    $termInfo->execute(['id' => $termId]);
    $term = $termInfo->fetch();
    if ($term) {
        $pdo->prepare("UPDATE system_settings SET setting_value = :val WHERE setting_key = 'current_term_id'")->execute(['val' => (string) $termId]);
        $pdo->prepare("UPDATE system_settings SET setting_value = :val WHERE setting_key = 'current_academic_year_id'")->execute(['val' => (string) $term['year_id']]);
    }

    audit_log('set_current_term', 'calendar', 'terms', $termId, "Set as current term");
    flash_set('success', 'Current term updated.');
    redirect(app_url('/director/calendar.php'));
}

// ---- School Event / Holiday CRUD ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_event') {
    csrf_verify();
    $eventTitle = trim($_POST['event_title'] ?? '');
    $eventDate = $_POST['event_date'] ?? '';
    $eventType = $_POST['event_type'] ?? 'event';
    $description = trim($_POST['description'] ?? '');

    if ($eventTitle === '' || $eventDate === '') {
        flash_set('error', 'Event title and date are required.');
    } else {
        // Create table if not exists
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS school_events (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                event_title VARCHAR(150) NOT NULL,
                event_date DATE NOT NULL,
                event_type ENUM('holiday','event','exam','meeting','deadline','other') NOT NULL DEFAULT 'event',
                description TEXT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_events_date (event_date)
            ) ENGINE=InnoDB"
        );
        $pdo->prepare(
            'INSERT INTO school_events (event_title, event_date, event_type, description, created_by)
             VALUES (:title, :date, :type, :desc, :by)'
        )->execute([
            'title' => $eventTitle, 'date' => $eventDate, 'type' => $eventType,
            'desc' => $description ?: null, 'by' => current_user_id(),
        ]);
        audit_log('create_event', 'calendar', 'school_events', (int) $pdo->lastInsertId(), "Created event: {$eventTitle}");
        flash_set('success', 'Event added to school calendar.');
    }
    redirect(app_url('/director/calendar.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_event') {
    csrf_verify();
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $pdo->prepare('DELETE FROM school_events WHERE event_id = :id')->execute(['id' => $eventId]);
    flash_set('success', 'Event removed.');
    redirect(app_url('/director/calendar.php'));
}

// ---- Data ----------------------------------------------------------------
$years = $pdo->query('SELECT * FROM academic_years ORDER BY start_date DESC')->fetchAll();
$terms = $pdo->query(
    'SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id ORDER BY t.start_date DESC'
)->fetchAll();

// Get events for the current/selected year
$selectedYearId = (int) ($_GET['year_id'] ?? 0);
if ($selectedYearId <= 0) {
    $currentYear = $pdo->query("SELECT year_id FROM academic_years WHERE is_current = 1")->fetch();
    $selectedYearId = $currentYear ? (int) $currentYear['year_id'] : 0;
}

$events = [];
if ($selectedYearId > 0) {
    // Check if table exists
    try {
        $eventsStmt = $pdo->prepare(
            "SELECT se.*, u.first_name, u.last_name
             FROM school_events se
             LEFT JOIN users u ON u.user_id = se.created_by
             WHERE se.event_date BETWEEN (SELECT start_date FROM academic_years WHERE year_id = :yid)
               AND (SELECT end_date FROM academic_years WHERE year_id = :yid2)
             ORDER BY se.event_date ASC"
        );
        $eventsStmt->execute(['yid' => $selectedYearId, 'yid2' => $selectedYearId]);
        $events = $eventsStmt->fetchAll();
    } catch (PDOException $e) {
        // Table doesn't exist yet
        $events = [];
    }
}

$period = get_current_period($pdo);

$pageTitle = 'School Calendar';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">School Calendar Management</h1>
<p class="text-muted">Manage academic years, terms, holidays, and important school events. Admin sets the school calendar that drives the entire system.</p>

<!-- Academic Years Section -->
<div class="card mb-4">
  <div class="card-header"><i class="fa fa-calendar text-gold me-2"></i>Academic Years</div>
  <div class="card-body">
    <form method="POST" class="row g-2 mb-3 border-bottom pb-3">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="create_year">
      <div class="col-md-3">
        <input type="text" name="year_name" class="form-control" placeholder="e.g. 2026" required>
      </div>
      <div class="col-md-3">
        <input type="date" name="start_date" class="form-control" required>
      </div>
      <div class="col-md-3">
        <input type="date" name="end_date" class="form-control" required>
      </div>
      <div class="col-md-2">
        <div class="form-check mt-2">
          <input type="checkbox" name="is_current" class="form-check-input" id="newYearCurrent">
          <label class="form-check-label" for="newYearCurrent">Set Current</label>
        </div>
      </div>
      <div class="col-md-1">
        <button class="btn btn-primary w-100"><i class="fa fa-plus"></i></button>
      </div>
    </form>

    <table class="table table-sm mb-0">
      <thead><tr><th>Year</th><th>Start</th><th>End</th><th>Current</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($years as $y): ?>
          <tr>
            <td><?= e($y['year_name']) ?></td>
            <td><?= format_date($y['start_date']) ?></td>
            <td><?= format_date($y['end_date']) ?></td>
            <td>
              <?php if ($y['is_current']): ?>
                <span class="badge bg-success">Current</span>
              <?php else: ?>
                <form method="POST" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="set_current_year">
                  <input type="hidden" name="year_id" value="<?= (int) $y['year_id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary">Set Current</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <a href="?year_id=<?= (int) $y['year_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-calendar-alt"></i> View Events</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Terms Section -->
<div class="card mb-4">
  <div class="card-header"><i class="fa fa-list text-gold me-2"></i>Terms</div>
  <div class="card-body">
    <form method="POST" class="row g-2 mb-3 border-bottom pb-3">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="create_term">
      <div class="col-md-2">
        <select name="year_id" class="form-select" required>
          <option value="">-- Year --</option>
          <?php foreach ($years as $y): ?>
            <option value="<?= (int) $y['year_id'] ?>" <?= $y['is_current'] ? 'selected' : '' ?>><?= e($y['year_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <input type="text" name="term_name" class="form-control" placeholder="e.g. Term 1" required>
      </div>
      <div class="col-md-2">
        <input type="date" name="start_date" class="form-control" required>
      </div>
      <div class="col-md-2">
        <input type="date" name="end_date" class="form-control" required>
      </div>
      <div class="col-md-2">
        <div class="form-check mt-2">
          <input type="checkbox" name="is_current" class="form-check-input" id="newTermCurrent">
          <label class="form-check-label" for="newTermCurrent">Set Current</label>
        </div>
      </div>
      <div class="col-md-1">
        <button class="btn btn-primary w-100"><i class="fa fa-plus"></i></button>
      </div>
    </form>

    <table class="table table-sm mb-0">
      <thead><tr><th>Year</th><th>Term</th><th>Start</th><th>End</th><th>Current</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($terms as $t): ?>
          <tr>
            <td><?= e($t['year_name']) ?></td>
            <td><?= e($t['term_name']) ?></td>
            <td><?= format_date($t['start_date']) ?></td>
            <td><?= format_date($t['end_date']) ?></td>
            <td>
              <?php if ($t['is_current']): ?>
                <span class="badge bg-success">Current</span>
              <?php else: ?>
                <form method="POST" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="set_current_term">
                  <input type="hidden" name="term_id" value="<?= (int) $t['term_id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary">Set Current</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- School Events / Holidays Section -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="fa fa-star text-gold me-2"></i>School Events & Holidays (<?= $selectedYearId > 0 ? 'Selected Year' : 'All' ?>)</span>
    <button class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#newEventModal"><i class="fa fa-plus me-1"></i> Add Event</button>
  </div>
  <div class="card-body">
    <?php if ($selectedYearId > 0): ?>
      <div class="row mb-3">
        <div class="col-md-4">
          <select name="year_filter" class="form-select form-select-sm" onchange="window.location='?year_id='+this.value">
            <?php foreach ($years as $y): ?>
              <option value="<?= (int) $y['year_id'] ?>" <?= $selectedYearId === (int) $y['year_id'] ? 'selected' : '' ?>><?= e($y['year_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Date</th><th>Event</th><th>Type</th><th>Description</th><th>Added By</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($events as $ev): ?>
            <tr>
              <td><?= format_date($ev['event_date']) ?></td>
              <td class="fw-semibold"><?= e($ev['event_title']) ?></td>
              <td>
                <span class="badge bg-<?= $ev['event_type'] === 'holiday' ? 'danger' : ($ev['event_type'] === 'exam' ? 'warning' : ($ev['event_type'] === 'meeting' ? 'info' : 'secondary')) ?>">
                  <?= e(ucfirst($ev['event_type'])) ?>
                </span>
              </td>
              <td class="small text-muted"><?= e($ev['description'] ?? '-') ?></td>
              <td class="small"><?= e(trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? '')) ?: '-') ?></td>
              <td>
                <form method="POST" data-confirm="Remove this event?">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_event">
                  <input type="hidden" name="event_id" value="<?= (int) $ev['event_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($events)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">No events scheduled for this academic year.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="fa fa-info-circle text-gold me-2"></i>Current System Period</div>
  <div class="card-body">
    <p class="mb-1"><strong>Current Academic Year ID:</strong> <?= (int) $period['year_id'] ?></p>
    <p class="mb-0"><strong>Current Term ID:</strong> <?= (int) $period['term_id'] ?></p>
    <p class="text-muted small mt-2 mb-0">The current period drives data visibility across the entire system. Set the current year and term above.</p>
  </div>
</div>

<!-- New Event Modal -->
<div class="modal fade" id="newEventModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="create_event">
        <div class="modal-header"><h5 class="modal-title">Add School Event / Holiday</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Event Title <span class="required-mark">*</span></label>
            <input type="text" name="event_title" class="form-control" required placeholder="e.g. Independence Day, Staff Meeting">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Date <span class="required-mark">*</span></label>
              <input type="date" name="event_date" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Type</label>
              <select name="event_type" class="form-select">
                <option value="event">Event</option>
                <option value="holiday">Holiday</option>
                <option value="exam">Examination</option>
                <option value="meeting">Meeting</option>
                <option value="deadline">Deadline</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Optional details..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add to Calendar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>