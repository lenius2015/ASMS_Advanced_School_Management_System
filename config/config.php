<?php
/**
 * config/config.php
 * Core application bootstrap: secure session config, error handling,
 * timezone, and global constants. Include this FIRST on every page,
 * before any output.
 */

// ---- Error reporting -------------------------------------------------
// In production, log errors to file; never display raw errors to users.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage_php_errors.log');

date_default_timezone_set('Africa/Dar_es_Salaam');

// ---- Secure session configuration ------------------------------------
// Must run before session_start(). This file should be included before
// any session_start() call anywhere in the app.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ASMS_SESSION');
    session_start();
}

// ---- Global constants -------------------------------------------------
define('APP_NAME', 'OMUNJU PRIMARY AND SECONDARY SCHOOLS');
define('APP_ROOT', dirname(__DIR__));               // absolute filesystem path to /asms
// Compute the base URL by trimming the document root from the script filename.
// This ensures APP_BASE_URL always points to the application root regardless
// of which subdirectory the current script lives in (e.g. /auth, /director, etc.).
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
if (str_ends_with($scriptDir, '/auth') || str_ends_with($scriptDir, '/director') || str_ends_with($scriptDir, '/academic') || str_ends_with($scriptDir, '/teacher') || str_ends_with($scriptDir, '/student') || str_ends_with($scriptDir, '/parent') || str_ends_with($scriptDir, '/bursar') || str_ends_with($scriptDir, '/class_teacher') || str_ends_with($scriptDir, '/head_of_school') || str_ends_with($scriptDir, '/communication') || str_ends_with($scriptDir, '/profile')) {
    $scriptDir = dirname($scriptDir);
}
define('APP_BASE_URL', rtrim($scriptDir, '/')); // best-effort; override below if needed

define('SESSION_TIMEOUT_SECONDS', 20 * 60); // 20 minutes idle timeout
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);

define('UPLOAD_DOCS_DIR', APP_ROOT . '/uploads/documents');
define('UPLOAD_PHOTOS_DIR', APP_ROOT . '/uploads/photos');
define('UPLOAD_REPORTCARDS_DIR', APP_ROOT . '/uploads/report_cards');

// ---- Required includes -------------------------------------------------
require_once __DIR__ . '/database.php';
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/audit.php';
