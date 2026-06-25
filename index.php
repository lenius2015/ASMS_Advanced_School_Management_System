<?php
/**
 * index.php
 * Application entry point. Redirects to login, or to the user's
 * role-specific dashboard if already authenticated.
 */
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect(role_home_url(current_role()));
}

redirect(app_url('/auth/login.php'));
