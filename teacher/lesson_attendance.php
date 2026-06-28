<?php
/**
 * teacher/lesson_attendance.php
 * Enhanced lesson attendance with timetable slot selection, topic recording,
 * and per-student attendance marking. Links to the new lesson_attendance tables.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['subject_teacher']);

$pdo = get_db_connection();
$teacherId = current_user_id();

// Get the teacher's timetable slots for the selected day
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDay = date('l', strtotime($selectedDate));

// ---- Handle form submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_lesson') {
    csrf_verify();

    $timetableId  = (int) ($_POST['timetable_id'] ?? 0);
    $lessonDate   = $_POST['lesson_date'] ?? date('Y-m-d');
    $topicCovered = trim($_POST['topic_covered'] ?? '');
    $lessonNotes  = trim($_POST['lesson_notes'] ?? '');
    $classSubjectId = (int) ($_POST['class_subject_id'] ?? 0);
    $statuses     = $_POST['status'] ?? [];

    if ($timetableId <= 0 || $classSubjectId <= 0 || $topicCovered === '') {
        flash_set('error', 'Timetable slot and topic are required.');
        redirect(app_url('/teacher/lesson_attendance.php'));
    }

    // Verify this timetable slot belongs to the teacher
    $check = $pdo->prepare(
        "SELECT tt.timetable_id FROM timetable tt
         JOIN class_subjects cs ON cs.class_subject_id = tt.class_subject_id
         WHERE tt.timetable_id = :tid AND cs.teacher_id = :tid2"
    );
    $check->execute(['tid' => $timetableId, 'tid2' => $teacherId]);
    if (!$check->fetch()) {
        flash_set('error', 'You are not assigned to this timetable slot.');
        redirect(app_url('/teacher/lesson_attendance.php'));
    }

    // Upsert lesson_attendance record (one per timetable slot per day)
    $stmt = $pdo->prepare(
        "INSERT INTO lesson_attendance (timetable_id, teacher_id, class_subject_id, lesson_date, topic_covered, lesson_notes)
         VALUES (:tid, :teach, :cs, :date, :topic, :notes)
         ON DUPLICATE KEY UPDATE topic_covered = VALUES(topic_covered), lesson_notes = VALUES(lesson_notes)"
    );
    $stmt->execute([
        'tid'   => $timetableId,
        'teach' => $teacherId,
        'cs'    => $classSubjectId,
        'date'  => $lessonDate,
        'topic' => $topicCovered,
        'notes' => $lessonNotes,
    ]);
    $lessonAttendanceId = $pdo->lastInsertId();
    // If it was an update (ON DUPLICATE KEY), lastInsertId may be 0 on some MySQL versions
    if ($lessonAttendanceId === 0) {
        $s = $pdo->prepare('SELECT lesson_attendance_id FROM lesson_attendance WHERE timetable_id = :tid AND lesson_date = :date');
        $s->execute(['tid' => $timetableId, 'date' => $lessonDate]);
        $lessonAttendanceId = (int) $s->fetchColumn();
    }

    // Save per-student attendance
    $upsert = $pdo->prepare(
        "INSERT INTO lesson_attendance_students (lesson_attendance_id, student_id, status)
         VALUES (:laid, :sid, :status)
         ON DUPLICATE KEY UPDATE status = VALUES(status)"
    );
    foreach ($statuses as $studentId => $status) {
        $upsert->execute([
            'laid'   => $lessonAttendanceId,
            'sid'    => (int) $studentId,
            'status' => $status,
        ]);
    }

    audit_log('save_lesson_attendance', 'attendance', 'lesson_attendance', $lessonAttendanceId, "Topic: {$topicCovered}");
    flash_set('success', 'Lesson attendance saved successfully.');
    redirect(app_url('/teacher/lesson_attendance.php') . '?date=' . $lessonDate);
}

// ---- Teacher's timetable slots for today/selected day ----
$slots = $pdo->prepare(
    "SELECT tt.*, cs.class_subject_id, cs.class_id, sub.subject_name, cl.level_name, c.stream_name
     FROM timetable tt
     JOIN class_subjects cs ON cs.class_subject_id = tt.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE cs.teacher_id = :tid AND tt.day_of_week = :day
     ORDER BY tt.start_time"
);
$slots->execute(['tid' => $teacherId, 'day' => $selectedDay]);
$todaySlots = $slots->fetchAll();

// ---- Collect existing lesson data for today's slots ----
$existingLessons = [];
if (!empty($todaySlots)) {
    $ids = array_column($todaySlots, 'timetable_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$selectedDate]);
    $existing = $pdo->prepare(
        "SELECT * FROM lesson_attendance WHERE timetable_id IN ({$placeholders}) AND lesson_date = ?"
    );
    $existing->execute($params);
    foreach ($existing->fetchAll() as $row) {
        $existingLessons[$row['timetable_id']] = $row;
    }
}

// ---- Get students for a selected slot (for the form) ----
$selectedSlot = (int) ($_GET['slot'] ?? 0);
$slotStudents = [];
$slotData = null;
$slotLesson = null;
if ($selectedSlot > 0) {
    foreach ($todaySlots as $s) {
        if ((int) $s['timetable_id'] === $selectedSlot) {
            $slotData = $s;
            break;
        }
    }
    if ($slotData) {
        $sStmt = $pdo->prepare(
            "SELECT s.student_id, u.first_name, u.last_name, s.admission_no
             FROM students s JOIN users u ON u.user_id = s.user_id
             WHERE s.class_id = :cid AND s.status = 'active' ORDER BY u.first_name"
        );
        $sStmt->execute(['cid' => $slotData['class_id']]);
        $slotStudents = $sStmt->fetchAll();

        // Load existing lesson data if any
        if (isset($existingLessons[$selectedSlot])) {
            $slotLesson = $existingLessons[$selectedSlot];
            // Load per-student statuses
            $las = $pdo->prepare('SELECT student_id, status FROM lesson_attendance_students WHERE lesson_attendance_id = :laid');
            $las->execute(['laid' => $slotLesson['lesson_attendance_id']]);
            $slotLesson['students'] = array_column($las->fetchAll(), 'status', 'student_id');
        }
    }
}

// ---- History of recent lesson attendance entries ----
$history = $pdo->prepare(
    "SELECT la.*, sub.subject_name, cl.level_name, c.stream_name,
            tt.day_of_week, tt.start_time, tt.end_time
     FROM lesson_attendance la
     JOIN timetable tt ON tt.timetable_id = la.timetable_id
     JOIN class_subjects cs ON cs.class_subject_id = la.class_subject_id
     JOIN subjects sub ON sub.subject_id = cs.subject_id
     JOIN classes c ON c.class_id = cs.class_id
     JOIN class_levels cl ON cl.class_level_id = c.class_level_id
     WHERE la.teacher_id = :tid
     ORDER BY la.lesson_date DESC, tt.start_time DESC
     LIMIT 50"
);
$history->execute(['tid' => $teacherId]);
$historyRows = $history->fetchAll();

$pageTitle = 'Lesson Attendance';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4"><i class="fa fa-chalkboard-teacher text-gold me-2"></i>Lesson Attendance</h1>

<div class="row g-3 mb-4">
  <!-- Left: Date picker + Timetable slots -->
  <div class="col-md-5">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-calendar text-gold me-2"></i>Your Schedule — <?= e($selectedDay) ?></span>
        <form method="GET" class="d-flex gap-1">
          <input type="date" name="date" class="form-control form-control-sm" value="<?= e($selectedDate) ?>" onchange="this.form.submit()" style="width:160px;">
        </form>
      </div>
      <div class="list-group list-group-flush">
        <?php if (empty($todaySlots)): ?>
          <div class="list-group-item text-muted text-center py-4">No lessons scheduled for <?= e($selectedDay) ?>.</div>
        <?php else: ?>
          <?php foreach ($todaySlots as $slot):
            $hasExisting = isset($existingLessons[$slot['timetable_id']]);
            $isActive = $selectedSlot === (int) $slot['timetable_id'];
          ?>
            <a href="?date=<?= e($selectedDate) ?>&slot=<?= (int) $slot['timetable_id'] ?>"
               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $isActive ? 'active' : '' ?>">
              <div>
                <div class="fw-semibold"><?= e(substr($slot['start_time'],0,5)) ?> - <?= e(substr($slot['end_time'],0,5)) ?></div>
                <small><?= e($slot['subject_name']) ?> &middot; <?= e($slot['level_name'] . ' ' . $slot['stream_name']) ?></small>
                <?php if ($slot['room']): ?><br><small class="text-muted">Room: <?= e($slot['room']) ?></small><?php endif; ?>
              </div>
              <?php if ($hasExisting): ?>
                <span class="badge bg-success rounded-pill"><i class="fa fa-check"></i></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Attendance form for selected slot -->
  <div class="col-md-7">
    <?php if ($slotData): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>
            <i class="fa fa-edit text-gold me-2"></i>
            <?= e($slotData['subject_name']) ?> —
            <?= e($slotData['level_name'] . ' ' . $slotData['stream_name']) ?>
            <small class="text-muted ms-2"><?= e(substr($slotData['start_time'],0,5)) ?> - <?= e(substr($slotData['end_time'],0,5)) ?></small>
          </span>
          <?php if ($slotLesson): ?>
            <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i>Saved</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_lesson">
            <input type="hidden" name="timetable_id" value="<?= (int) $slotData['timetable_id'] ?>">
            <input type="hidden" name="class_subject_id" value="<?= (int) $slotData['class_subject_id'] ?>">
            <input type="hidden" name="lesson_date" value="<?= e($selectedDate) ?>">

            <div class="row g-2 mb-3">
              <div class="col-md-8">
                <label class="form-label">Topic / Lesson Covered <span class="text-danger">*</span></label>
                <input type="text" name="topic_covered" class="form-control" required
                       value="<?= e($slotLesson['topic_covered'] ?? '') ?>"
                       placeholder="e.g. Algebra: Solving Quadratic Equations">
              </div>
              <div class="col-md-4">
                <label class="form-label">Date</label>
                <input type="text" class="form-control" value="<?= e(format_date($selectedDate)) ?>" disabled>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Lesson Notes (optional)</label>
              <textarea name="lesson_notes" class="form-control" rows="2" placeholder="Any notes about the lesson..."><?= e($slotLesson['lesson_notes'] ?? '') ?></textarea>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label mb-0 fw-semibold">Student Attendance</label>
              <div>
                <button type="button" class="btn btn-sm btn-outline-success me-1" onclick="setAll('present')">All Present</button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="setAll('absent')">All Absent</button>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Admission No.</th>
                    <th>Student Name</th>
                    <th style="width:160px;">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $idx = 1; foreach ($slotStudents as $stu):
                    $currentStatus = $slotLesson['students'][$stu['student_id']] ?? 'present';
                  ?>
                    <tr>
                      <td class="text-muted"><?= $idx++ ?></td>
                      <td><code><?= e($stu['admission_no']) ?></code></td>
                      <td><?= e($stu['first_name'] . ' ' . $stu['last_name']) ?></td>
                      <td>
                        <select name="status[<?= (int) $stu['student_id'] ?>]" class="form-select form-select-sm status-select">
                          <?php foreach (['present','absent','late','excused'] as $st): ?>
                            <option value="<?= $st ?>" <?= $currentStatus === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="mt-3 d-flex gap-2">
              <button class="btn btn-primary"><i class="fa fa-save me-1"></i> Save Lesson Attendance</button>
              <a href="?date=<?= e($selectedDate) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-body text-center text-muted py-5">
          <i class="fa fa-arrow-left me-2"></i>Select a timetable slot from the left to record lesson attendance.
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- History -->
<div class="card">
  <div class="card-header"><i class="fa fa-history text-gold me-2"></i>Recent Lesson Records</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Date</th>
          <th>Day</th>
          <th>Time</th>
          <th>Subject</th>
          <th>Class</th>
          <th>Topic Covered</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($historyRows as $h): ?>
          <tr>
            <td><?= e(format_date($h['lesson_date'])) ?></td>
            <td><?= e($h['day_of_week']) ?></td class="small text-muted">
            <td><?= e(substr($h['start_time'],0,5)) ?> - <?= e(substr($h['end_time'],0,5)) ?></td>
            <td><?= e($h['subject_name']) ?></td>
            <td><?= e($h['level_name'] . ' ' . $h['stream_name']) ?></td>
            <td><?= e($h['topic_covered']) ?></td>
            <td class="small text-muted"><?= e($h['lesson_notes'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($historyRows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No lesson records yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function setAll(status) {
    document.querySelectorAll('.status-select').forEach(function(sel) {
        sel.value = status;
    });
}
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>