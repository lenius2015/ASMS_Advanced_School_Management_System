<?php
/**
 * includes/auth.php
 * Authentication and authorization helpers.
 * Defines session-based login, role checking, and user management functions.
 */

/**
 * Generate an application URL relative to the base.
 */
function app_url(string $path = ''): string
{
    $base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
    if ($path === '' || $path === '/') {
        return $base ?: '/';
    }
    // Strip leading slash from path if base already has one
    $path = ltrim($path, '/');
    return rtrim($base, '/') . '/' . $path;
}

/**
 * Check if a user is currently logged in.
 */
function is_logged_in(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    // Enforce session idle timeout
    if (defined('SESSION_TIMEOUT_SECONDS') && SESSION_TIMEOUT_SECONDS > 0) {
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_TIMEOUT_SECONDS) {
            // Session expired - log out gracefully
            $_SESSION = [];
            session_destroy();
            return false;
        }
    }
    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Get the current user's ID from the session.
 */
function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get the current user's role name from the session.
 */
function current_role(): ?string
{
    return $_SESSION['role_name'] ?? null;
}

/**
 * Redirect to the appropriate home/dashboard URL for a given role.
 */
function role_home_url(string $role): string
{
    $map = [
        'director'        => '/director/dashboard.php',
        'system_admin'    => '/director/dashboard.php',
        'school_board'    => '/director/board_dashboard.php',
        'head_of_school'  => '/head_of_school/dashboard.php',
        'department_head' => '/head_of_school/dashboard.php',
        'academic_officer'=> '/academic/dashboard.php',
        'subject_teacher' => '/teacher/dashboard.php',
        'class_teacher'   => '/class_teacher/dashboard.php',
        'student'         => '/student/dashboard.php',
        'parent'          => '/parent/dashboard.php',
        'bursar'          => '/bursar/dashboard.php',
    ];
    $path = $map[$role] ?? '/auth/login.php';
    return app_url($path);
}

/**
 * Require authentication. If not logged in, redirect to login page.
 * Optionally specify a redirect-back URL via 'next' parameter.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        $next = $currentUrl ? '?next=' . urlencode($currentUrl) : '';
        redirect(app_url('/auth/login.php') . $next);
    }
}

/**
 * Require that the current user has one of the specified roles.
 * If the user is not logged in, redirect to login.
 * If the user lacks permission, show a 403 error.
 */
function require_role(string|array ...$allowedRoles): void
{
    // Flatten in case an array was passed as first argument
    $roles = [];
    foreach ($allowedRoles as $r) {
        if (is_array($r)) {
            array_push($roles, ...$r);
        } else {
            $roles[] = $r;
        }
    }
    require_login();
    $role = current_role();
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        $pdo = get_db_connection();
        $schoolName = get_setting($pdo, 'school_name', 'ASMS School');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<title>Access Denied | ' . htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo '<link href="' . e(app_url('/assets/css/style.css')) . '" rel="stylesheet">';
        echo '</head><body class="p-5 text-center">';
        echo '<div class="container"><h1 class="display-4 text-danger">403</h1>';
        echo '<p class="lead">You do not have permission to access this page.</p>';
        echo '<p class="text-muted">Your role: ' . e($role ?? 'none') . '</p>';
        echo '<a href="' . e(app_url('/index.php')) . '" class="btn btn-primary">Go Home</a>';
        echo '</div></body></html>';
        exit;
    }
}

/**
 * Log out the current user: clear session, regenerate ID, destroy.
 */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Attempt to authenticate a user with username/email and password.
 *
 * @param PDO    $pdo      Database connection
 * @param string $username Username or email address
 * @param string $password Plain-text password
 *
 * @return array{ok:bool,message:string,user_id:?int,role_name:?string,must_change_password:bool}
 */
function attempt_login(PDO $pdo, string $username, string $password): array
{
    $user = null;

    // Look up user by username OR email
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.password_hash, u.must_change_password,
                r.role_name, u.is_active, u.failed_login_attempts, u.locked_until
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE (u.username = :login OR u.email = :login2)
         LIMIT 1'
    );
    $stmt->execute(['login' => $username, 'login2' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return [
            'ok'                  => false,
            'message'             => 'Invalid username/email or password.',
            'user_id'             => null,
            'role_name'           => null,
            'must_change_password'=> false,
        ];
    }

    if (!$user['is_active']) {
        return [
            'ok'                  => false,
            'message'             => 'Your account has been deactivated. Contact the system administrator.',
            'user_id'             => null,
            'role_name'           => null,
            'must_change_password'=> false,
        ];
    }

    // Check if account is locked due to too many failed attempts
    if ($user['locked_until'] !== null) {
        $lockedUntil = strtotime($user['locked_until']);
        if ($lockedUntil > time()) {
            $minutesLeft = ceil(($lockedUntil - time()) / 60);
            return [
                'ok'                  => false,
                'message'             => 'Account temporarily locked due to too many failed login attempts. Try again in ' . $minutesLeft . ' minute(s).',
                'user_id'             => null,
                'role_name'           => null,
                'must_change_password'=> false,
            ];
        }
    }

    if (!password_verify($password, $user['password_hash'])) {
        // Increment failed login attempts and lock if exceeded
        $newAttempts = (int) ($user['failed_login_attempts'] ?? 0) + 1;
        $maxAttempts = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;
        $lockMinutes = defined('LOCKOUT_MINUTES') ? LOCKOUT_MINUTES : 15;
        if ($newAttempts >= $maxAttempts) {
            $pdo->prepare('UPDATE users SET failed_login_attempts = :att, locked_until = DATE_ADD(NOW(), INTERVAL :min MINUTE) WHERE user_id = :uid')
                ->execute(['att' => $newAttempts, 'min' => $lockMinutes, 'uid' => $user['user_id']]);
        } else {
            $pdo->prepare('UPDATE users SET failed_login_attempts = :att WHERE user_id = :uid')
                ->execute(['att' => $newAttempts, 'uid' => $user['user_id']]);
        }
        // Log failed attempt
        $failStmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, module, description, ip_address)
             VALUES (:uid, :action, :module, :desc, :ip)'
        );
        $failStmt->execute([
            'uid'    => $user['user_id'],
            'action' => 'login_failed',
            'module' => 'auth',
            'desc'   => 'Failed login attempt ' . $newAttempts . ' of ' . $maxAttempts . ' for user ' . $username,
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        return [
            'ok'                  => false,
            'message'             => 'Invalid username/email or password.',
            'user_id'             => null,
            'role_name'           => null,
            'must_change_password'=> false,
        ];
    }

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['user_id']    = (int) $user['user_id'];
    $_SESSION['role_name']  = $user['role_name'];
    $_SESSION['full_name']  = '';

    // Fetch full name and photo from users table
    $profileStmt = $pdo->prepare(
        'SELECT CONCAT(first_name, \' \', last_name) AS full_name, photo_path FROM users WHERE user_id = :uid'
    );
    $profileStmt->execute(['uid' => $user['user_id']]);
    $profile = $profileStmt->fetch();
    $_SESSION['full_name'] = $profile['full_name'] ?? $username;
    $_SESSION['photo_path'] = $profile['photo_path'] ?? null;

    // Update last login timestamp and reset failed attempts
    $pdo->prepare('UPDATE users SET last_login_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE user_id = :uid')
        ->execute(['uid' => $user['user_id']]);

    return [
        'ok'                   => true,
        'message'              => 'Login successful.',
        'user_id'              => (int) $user['user_id'],
        'role_name'            => $user['role_name'],
        'must_change_password' => (bool) $user['must_change_password'],
    ];
}