<?php
/**
 * auth/logout.php
 * Logs the user out and redirects to the login page.
 */
require_once __DIR__ . '/../config/config.php';

logout_user();
flash_set('success', 'You have been signed out successfully.');
redirect(app_url('/auth/login.php'));
