<?php
/**
 * config/database.php
 * Central PDO database connection.
 *
 * IMPORTANT: Edit the constants below for your environment, or better,
 * set them as real environment variables on your server and remove the
 * hard-coded fallbacks before going to production.
 */

// ---- Database credentials (edit these for your server) -------------------
define('DB_HOST', getenv('ASMS_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('ASMS_DB_NAME') ?: 'asms_db');
define('DB_USER', getenv('ASMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('ASMS_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a shared PDO instance (singleton-style via static variable).
 *
 * @return PDO
 */
function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // use real prepared statements
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Never leak DB credentials or raw exception details to the browser.
        error_log('[ASMS] Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('A system error occurred. Please contact the system administrator.');
    }

    return $pdo;
}
