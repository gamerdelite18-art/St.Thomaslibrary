<?php
/**
 * auth.php - Session based admin authentication
 * Include this at the top of every protected page.
 */

require_once __DIR__ . '/db.php';
// Pulled in here as well so any page that only includes auth.php still has
// redirect(), flash(), e() and the rest of the helpers available.
require_once __DIR__ . '/functions.php';

// Start the session once (must be before any output)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** True when an admin is logged in. */
function is_logged_in()
{
    return !empty($_SESSION['admin_id']);
}

/** Details of the logged-in admin (or null). */
function current_admin()
{
    static $admin = null;
    if ($admin === null && is_logged_in()) {
        $admin = db_one('SELECT id, name, email FROM admins WHERE id = ?', [$_SESSION['admin_id']]);
    }
    return $admin;
}

/** Block the page and bounce to the login screen. Call at the top of protected pages. */
function require_login()
{
    if (!is_logged_in()) {
        flash('warning', 'Please log in to access the admin panel.');
        redirect('login.php');
    }
}

/** Verify credentials, start the session. Returns true/false. */
function attempt_login($email, $password)
{
    // Basic input trimming
    $email    = trim($email);
    $password = (string) $password;

    $admin = db_one('SELECT * FROM admins WHERE email = ? LIMIT 1', [$email]);

    // password_verify compares against the stored bcrypt hash
    if ($admin && password_verify($password, $admin['password'])) {
        session_regenerate_id(true);               // prevent session fixation
        $_SESSION['admin_id']   = (int) $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['admin_email']= $admin['email'];
        unset($_SESSION['csrf_token']);            // new token after login
        return true;
    }

    return false;
}

/** Destroy the session completely. */
function logout()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
