<?php
/**
 * functions.php - Small shared helpers (escaping, flash messages, CSRF, dates)
 */

require_once __DIR__ . '/db.php';
// Fine engine (settings, calculation, payments) - available to every page
require_once __DIR__ . '/fines.php';

/* ============================================================
   OUTPUT SAFETY
   ============================================================ */

/**
 * Escape output so user data can never inject HTML/JS.
 *
 * Non-scalar input (?q[]=x) is not a string and must never reach the output,
 * so it renders as empty rather than raising a warning and printing "Array".
 */
function e($value)
{
    if (!is_scalar($value)) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   NAVIGATION
   ============================================================ */

/** Redirect and stop executing the current script. */
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

/* ============================================================
   FLASH MESSAGES  (one-shot success/error banners)
   ============================================================ */

/** Queue a message. $type = success | danger | warning | info */
function flash($type, $message)
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

/** Print and clear queued messages as Bootstrap alerts. */
function show_flash()
{
    if (empty($_SESSION['flash'])) {
        return;
    }

    foreach ($_SESSION['flash'] as $f) {
        // Allow-list the type so only valid Bootstrap classes are used
        $type  = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
        $icons = ['success' => 'check-circle-fill', 'danger' => 'exclamation-triangle-fill',
                  'warning' => 'exclamation-circle-fill', 'info' => 'info-circle-fill'];

        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show d-flex align-items-center gap-2" role="alert">'
           . '<i class="bi bi-' . $icons[$type] . '"></i>'
           . '<div class="flex-grow-1">' . e($f['msg']) . '</div>'
           . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
           . '</div>';
    }

    unset($_SESSION['flash']);
}

/* ============================================================
   INPUT HELPERS
   ============================================================ */

/**
 * Read a request value as a trimmed, length-capped string.
 *
 * Guards two things that a hand-crafted request can otherwise trigger:
 *  - an array value (?q[]=x) would make trim()/htmlspecialchars() throw a
 *    TypeError in PHP 8, turning a bad request into a 500
 *  - an oversized value would overflow the VARCHAR it is about to be
 *    stored in, and in strict SQL mode abort the whole query
 *
 * @param mixed  $value    raw value from $_GET / $_POST
 * @param int    $max_len  maximum characters to keep
 */
function str_param($value, $max_len = 255)
{
    if (!is_scalar($value)) {
        return '';
    }

    $value = trim((string) $value);

    if ($max_len > 0 && mb_strlen($value, 'UTF-8') > $max_len) {
        $value = mb_substr($value, 0, $max_len, 'UTF-8');
    }

    return $value;
}

/**
 * Integer request value.
 *
 * A hand-crafted value such as id[]=1 arrives as an array, and a bare (int)
 * cast turns that into 1 with no warning at all. That is the dangerous case:
 * a delete aimed at "no id" would silently hit record #1 instead of failing.
 * So anything non-numeric falls back to $default.
 */
function int_param($value, $default = 0)
{
    if (is_bool($value) || !is_scalar($value)) {
        return $default;
    }
    if (!is_numeric(trim((string) $value))) {
        return $default;
    }
    return (int) $value;
}

/**
 * Decimal request value, same non-numeric handling as int_param().
 * Also rejects values that cannot be represented as a finite amount, so
 * "INF"/"NAN" can never be stored into a DECIMAL column.
 */
function float_param($value, $default = 0.0)
{
    if (is_bool($value) || !is_scalar($value)) {
        return $default;
    }
    $value = trim((string) $value);
    if (!is_numeric($value)) {
        return $default;
    }
    $number = (float) $value;
    return is_finite($number) ? $number : $default;
}

/* ============================================================
   CSRF PROTECTION
   ============================================================ */

/** Create (once) and return the session CSRF token. */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every POST form. */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Abort the request when the submitted token is missing/wrong. */
function check_csrf()
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        // 403 is used because Apache maps unknown codes (e.g. 419) to 500
        http_response_code(403);
        exit('Invalid or expired request. Please go back and try again.');
    }
}

/* ============================================================
   FORMATTING
   ============================================================ */

/** 2026-05-04 -> 04 May 2026 */
function fmt_date($date)
{
    return ($date && $date !== '0000-00-00') ? date('d M Y', strtotime($date)) : '-';
}

/** Days left until a due date (negative = overdue). */
function days_left($due_date)
{
    return (int) ceil((strtotime($due_date) - strtotime(date('Y-m-d'))) / 86400);
}

/** Coloured badge for a book stock level. */
function stock_badge($available, $quantity)
{
    if ($available <= 0) {
        return '<span class="badge text-bg-danger">Out of stock</span>';
    }
    if ($available <= 2) {
        return '<span class="badge text-bg-warning">Low stock</span>';
    }
    return '<span class="badge text-bg-success">' . $available . ' / ' . $quantity . ' available</span>';
}

/* ============================================================
   LIBRARY / STOCK HELPERS
   ============================================================ */

/** How many copies of a book are currently out with students. */
function issued_count($book_id)
{
    return (int) db_val(
        "SELECT COUNT(*) FROM issued_books WHERE book_id = ? AND status = 'issued'",
        [$book_id]
    );
}

/** How many books a student is currently holding. */
function student_issued_count($student_id)
{
    return (int) db_val(
        "SELECT COUNT(*) FROM issued_books WHERE student_id = ? AND status = 'issued'",
        [$student_id]
    );
}

/** Select options for a <select> from a simple key => value list. */
function options($list, $selected = null)
{
    foreach ($list as $value => $label) {
        $is = ((string) $value === (string) $selected) ? ' selected' : '';
        echo '<option value="' . e($value) . '"' . $is . '>' . e($label) . '</option>';
    }
}

/** The fixed book categories used across add/edit forms. */
function book_categories()
{
    return ['Fiction', 'Non-Fiction', 'Science', 'Technology', 'Computer', 'History',
            'Biography', 'Economics', 'Self-Help', 'Children', 'Other'];
}
