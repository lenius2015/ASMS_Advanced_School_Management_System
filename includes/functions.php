<?php
/**
 * includes/functions.php
 * General-purpose helper functions shared across the application.
 */

/**
 * Escape output for safe HTML rendering. Use this around every piece of
 * user-supplied or database-sourced data printed into HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a given path (relative to the app's base URL) and stop execution.
 */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Flash messages: set a message to be shown once on the next page load.
 */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get_all(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Render flash messages as Bootstrap alerts. Call this inside the layout.
 */
function flash_render(): void
{
    foreach (flash_get_all() as $f) {
        $class = match ($f['type']) {
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            default   => 'alert-info',
        };
        echo '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">'
            . e($f['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            . '</div>';
    }
}

/**
 * Generate a unique username for a new user.
 *
 * Priority:
 *   1. If an email is provided, use the FULL email address as the username.
 *   2. If no email, generate from firstname.lastname and append a number if needed.
 *
 * The generated username is guaranteed to be unique in the users table.
 *
 * @param PDO    $pdo       Database connection
 * @param string $firstName First name
 * @param string $lastName  Last name
 * @param string $email     Email address (optional)
 * @return string           A unique, valid username
 */
function generate_username(PDO $pdo, string $firstName, string $lastName, string $email = ''): string
{
    // Use the FULL email as the username if provided
    if ($email !== '') {
        $username = strtolower(trim($email));
        // Check uniqueness - if exists, append a number
        $base = $username;
        $suffix = '';
        $attempts = 0;
        do {
            $usernameToCheck = $base . $suffix;
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
            $stmt->execute(['u' => $usernameToCheck]);
            $exists = (int) $stmt->fetchColumn() > 0;
            if (!$exists) {
                return $usernameToCheck;
            }
            $attempts++;
            $suffix = $attempts;
        } while ($attempts < 100);
    }

    // Fallback: generate from firstname.lastname
    $base = strtolower(
        preg_replace('/[^a-z0-9]/', '', $firstName) . '.' .
        preg_replace('/[^a-z0-9]/', '', $lastName)
    );
    $base = trim($base, '.');
    if ($base === '') {
        $base = 'user';
    }

    $suffix = '';
    $attempts = 0;
    do {
        $username = $base . $suffix;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
        $stmt->execute(['u' => $username]);
        $exists = (int) $stmt->fetchColumn() > 0;
        if (!$exists) {
            return $username;
        }
        $attempts++;
        $suffix = $attempts;
    } while ($attempts < 100);

    // Ultimate fallback: use random number
    return $base . random_int(1000, 9999);
}

/**
 * Generate the next sequential human-friendly ID, e.g. STU-2026-0001.
 * Uses the id_sequences table with a row lock (FOR UPDATE) to stay safe
 * under concurrency.
 *
 * IMPORTANT: This function does NOT start its own transaction — PDO does
 * not support nested transactions, so if you call this from inside a
 * larger transaction (the common case: creating a student/staff record
 * and its sequential ID together), that outer transaction's BEGIN covers
 * the FOR UPDATE lock here too. If you call this standalone (no existing
 * transaction), wrap the call yourself: $pdo->beginTransaction(); ... $pdo->commit();
 *
 * @param string $prefix e.g. 'STU' or 'STF'
 * @param int    $year   e.g. 2026
 */
function generate_sequential_id(PDO $pdo, string $prefix, int $year): string
{
    $key = $prefix . '-' . $year;

    $stmt = $pdo->prepare('SELECT last_value FROM id_sequences WHERE seq_key = :key FOR UPDATE');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();

    if ($row === false) {
        $pdo->prepare('INSERT INTO id_sequences (seq_key, last_value) VALUES (:key, 0)')->execute(['key' => $key]);
        $next = 1;
    } else {
        $next = (int) $row['last_value'] + 1;
    }

    $pdo->prepare('UPDATE id_sequences SET last_value = :v WHERE seq_key = :key')
        ->execute(['v' => $next, 'key' => $key]);

    return sprintf('%s-%04d-%04d', $prefix, $year, $next);
}

/**
 * Compute a letter grade and GPA for a given percentage score using the
 * grade_scale table.
 *
 * @return array{grade_letter:string,gpa:float,remarks:string}|null
 */
function compute_grade(PDO $pdo, float $percentage): ?array
{
    $stmt = $pdo->prepare(
        'SELECT grade_letter, gpa_value, remarks FROM grade_scale
         WHERE :score BETWEEN min_score AND max_score
         LIMIT 1'
    );
    $stmt->execute(['score' => $percentage]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'grade_letter' => $row['grade_letter'],
        'gpa'          => (float) $row['gpa_value'],
        'remarks'      => $row['remarks'],
    ];
}

/**
 * Format a number as Tanzanian Shillings currency string.
 */
function format_money($amount): string
{
    return 'TZS ' . number_format((float) $amount, 2);
}

/**
 * Format a date for display (e.g. "12 Jan 2026").
 */
function format_date(?string $date): string
{
    if (empty($date)) {
        return '-';
    }
    try {
        return (new DateTime($date))->format('d M Y');
    } catch (Exception $e) {
        return e($date);
    }
}

/**
 * Get a system setting value by key, with optional default.
 */
function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();

    $cache[$key] = $row ? $row['setting_value'] : $default;
    return $cache[$key];
}

/**
 * Get the current academic year and term IDs from system settings.
 *
 * @return array{year_id:int,term_id:int}
 */
function get_current_period(PDO $pdo): array
{
    return [
        'year_id' => (int) get_setting($pdo, 'current_academic_year_id', '1'),
        'term_id' => (int) get_setting($pdo, 'current_term_id', '1'),
    ];
}

/**
 * Create a notification for a single user.
 */
function notify_user(PDO $pdo, int $userId, string $title, string $body, string $type = 'system', ?string $link = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, body, type, link) VALUES (:user_id, :title, :body, :type, :link)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'title'   => $title,
        'body'    => $body,
        'type'    => $type,
        'link'    => $link,
    ]);
}

/**
 * Verify that the currently logged-in parent/guardian is linked to the
 * given student_id. Returns the student_id if valid, or null otherwise.
 * Call this on every parent/*.php page before showing a specific child's data.
 */
function verify_guardian_owns_student(PDO $pdo, int $userId, int $studentId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT s.student_id FROM students s
         JOIN student_guardians sg ON sg.student_id = s.student_id
         JOIN guardians g ON g.guardian_id = sg.guardian_id
         WHERE g.user_id = :uid AND s.student_id = :sid"
    );
    $stmt->execute(['uid' => $userId, 'sid' => $studentId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['student_id'] : null;
}

/**
 * Get the first linked child for a guardian user, used as a default
 * when no student_id is specified in the URL.
 */
function get_default_child(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT s.student_id FROM students s
         JOIN student_guardians sg ON sg.student_id = s.student_id
         JOIN guardians g ON g.guardian_id = sg.guardian_id
         WHERE g.user_id = :uid ORDER BY s.student_id LIMIT 1"
    );
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['student_id'] : null;
}
/**
 * Render a user's avatar: show photo if available, otherwise show initials.
 * Returns HTML string for use in templates.
 */
function render_avatar(?string $photoPath, string $firstName, string $lastName, int $size = 40, string $classes = ''): string
{
    $initials = e(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
    $style = "width:{$size}px;height:{$size}px;font-size:" . round($size * 0.45) . "px;";

    if ($photoPath) {
        $fullPath = $photoPath;
        // Handle both absolute and relative paths
        if (!str_starts_with($photoPath, '/') && !str_contains($photoPath, ':\\')) {
            $fullPath = APP_ROOT . '/' . $photoPath;
        }
        if (file_exists($fullPath)) {
            $url = e(app_url($photoPath));
            return "<img src=\"{$url}\" alt=\"{$initials}\" class=\"rounded-circle object-fit-cover {$classes}\" style=\"{$style}\">";
        }
    }

    return "<div class=\"rounded-circle bg-navy text-white d-inline-flex align-items-center justify-content-center fw-semibold {$classes}\" style=\"{$style}\">{$initials}</div>";
}

/**
 * Get all uploaded documents for a student.
 * @return array
 */
function get_student_documents(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare(
        "SELECT sd.*, u.first_name AS uploader_fn, u.last_name AS uploader_ln
         FROM student_documents sd
         LEFT JOIN users u ON u.user_id = sd.uploaded_by
         WHERE sd.student_id = :sid
         ORDER BY sd.created_at DESC"
    );
    $stmt->execute(['sid' => $studentId]);
    return $stmt->fetchAll();
}

/**
 * Determine registration completeness status for a student.
 * Returns an array with 'level' (complete|incomplete) and 'badge' HTML.
 */
function registration_completeness(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare(
        "SELECT registration_complete FROM students WHERE student_id = :sid"
    );
    $stmt->execute(['sid' => $studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        return ['level' => 'incomplete', 'badge' => '<span class="badge bg-secondary">Unknown</span>'];
    }

    if ((int) $student['registration_complete'] === 1) {
        return [
            'level' => 'complete',
            'badge' => '<span class="badge bg-success"><i class="fa fa-check-circle me-1"></i>Complete</span>'
        ];
    }

    // Check for required documents
    $reqStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM student_documents
         WHERE student_id = :sid AND document_type IN ('birth_certificate', 'medical_checkup')"
    );
    $reqStmt->execute(['sid' => $studentId]);
    $requiredCount = (int) $reqStmt->fetchColumn();

    // Also count any documents at all
    $anyStmt = $pdo->prepare("SELECT COUNT(*) FROM student_documents WHERE student_id = :sid");
    $anyStmt->execute(['sid' => $studentId]);
    $totalDocs = (int) $anyStmt->fetchColumn();

    if ($requiredCount >= 2) {
        return [
            'level' => 'complete',
            'badge' => '<span class="badge bg-success"><i class="fa fa-check-circle me-1"></i>Complete</span>'
        ];
    } elseif ($totalDocs > 0) {
        return [
            'level' => 'pending',
            'badge' => '<span class="badge bg-warning text-dark"><i class="fa fa-clock me-1"></i>Pending</span>'
        ];
    }

    return [
        'level' => 'incomplete',
        'badge' => '<span class="badge bg-danger"><i class="fa fa-exclamation-circle me-1"></i>Incomplete</span>'
    ];
}

/**
 * Get a user's profile data by user_id.
 */
function get_user_profile(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT u.*, r.role_name FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = :uid"
    );
    $stmt->execute(['uid' => $userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function validate_upload(array $file, array $allowedExt, int $maxBytes = 5_242_880): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return 'No file was uploaded.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'File upload failed (error code ' . (int) $file['error'] . ').';
    }
    if ($file['size'] > $maxBytes) {
        return 'File is too large. Maximum allowed size is ' . round($maxBytes / 1_048_576, 1) . ' MB.';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return 'File type .' . e($ext) . ' is not allowed.';
    }

    return null;
}

/**
 * Move an uploaded file into a target directory with a safe, unique filename.
 * Returns the relative path stored, or throws on failure.
 */
function store_upload(array $file, string $targetDir, string $prefix = 'file'): string
{
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = rtrim($targetDir, '/') . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to save uploaded file.');
    }

    return $destination;
}