<?php
/**
 * Input-hardening regression test.
 *
 * Sends hand-crafted requests whose parameters are shaped as arrays
 * (?q[]=x / q[]=x) to every page and form in the admin area, and checks two
 * things:
 *
 *   1. Nothing crashes. In PHP 8, trim()/htmlspecialchars()/urlencode() throw a
 *      TypeError on an array, and a bare (int)/(float) cast silently turns the
 *      array into 1. The first bug produces a fatal error page; the second is
 *      far worse because it is invisible and aims a delete or a payment at
 *      record #1.
 *
 *   2. Nothing is silently mutated. The whole database is fingerprinted before
 *      and after, so a probe that quietly edited or deleted a real row fails
 *      the run even though the page looked fine.
 *
 * Usage:  php database/test_input_hardening.php
 * Needs Apache + MySQL running and the app reachable at the base URL below.
 */

require_once __DIR__ . '/../includes/functions.php';

const BASE_URL = 'http://localhost/library-ai/admin/';

$pass = 0;
$fail = 0;

function out($ok, $label, $detail = '')
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  OK   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail ? "\n         $detail" : '') . "\n";
    }
}

/* ---------------- fingerprinting ---------------- */

/** A stable description of everything a crafted request could corrupt. */
function fingerprint()
{
    $state = [];

    foreach (['books', 'students', 'admins', 'issued_books', 'fine_payments'] as $t) {
        $rows = db_all("SELECT * FROM `$t` ORDER BY 1");
        $state[$t] = md5(json_encode($rows));
    }

    $state['settings'] = md5(json_encode(db_all('SELECT * FROM settings ORDER BY setting_key')));
    return $state;
}

/**
 * Write a saved table back verbatim.
 *
 * The fine_settings.php probes are *valid* form submissions, so that page is
 * supposed to write. Restoring the snapshot afterwards means the run leaves the
 * database exactly as it found it, even if it crashed part way through, and
 * lets the fingerprint compare stay strict about updated_at too.
 */
function restore_table($table, array $rows)
{
    db_query("DELETE FROM `$table`");
    foreach ($rows as $r) {
        $cols = array_keys($r);
        db_query(
            "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES ("
                . implode(', ', array_fill(0, count($cols), '?')) . ")",
            array_values($r)
        );
    }
}

function compare($before, $after, $label)
{
    $diffs = [];
    foreach ($before as $k => $hash) {
        if ($after[$k] !== $hash) {
            $diffs[] = $k;
        }
    }
    out(empty($diffs), $label, $diffs ? 'changed: ' . implode(', ', $diffs) : '');
    return $diffs;
}

/* ---------------- HTTP client ---------------- */

$cookie_jar = tempnam(sys_get_temp_dir(), 'lms_cookies_');

function http($method, $url, array $fields = null, $follow = true)
{
    global $cookie_jar;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR      => $cookie_jar,
        CURLOPT_COOKIEFILE     => $cookie_jar,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body = curl_exec($ch);
    curl_close($ch);
    return (string) $body;
}

/** Pull a CSRF token out of a rendered page. */
function token_from($html)
{
    return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
}

/** Names of any PHP error text in a response body. */
function errors_in($body)
{
    $found = [];
    foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:', 'Deprecated:'] as $needle) {
        if (stripos($body, $needle) !== false) {
            $found[] = $needle;
        }
    }
    return $found;
}

/* ---------------- log in ---------------- */

echo "Logging in...\n";
$login = http('GET', BASE_URL . 'login.php');
if (!token_from($login)) {
    echo "  FAIL could not load login page / no CSRF token. Is Apache running at " . BASE_URL . "?\n";
    exit(1);
}
http('POST', BASE_URL . 'login.php', [
    'email' => 'admin@library.com', 'password' => 'admin123',
    'csrf_token' => token_from($login),
]);

if (!strpos(http('GET', BASE_URL . 'index.php'), 'Fine')) {
    echo "  FAIL login did not succeed (admin pages still redirecting to login)\n";
    exit(1);
}
echo "  OK   logged in\n\n";

// Snapshot everything before the probes, so a silently-mutated row is caught
$before = fingerprint();
$settings_snapshot = db_all('SELECT * FROM settings ORDER BY setting_key');

/* ---------------- 1. GET array parameters ---------------- */

echo "1. Crafted GET parameters (array-shaped input)\n";

$get_probes = [
    'manage_books.php?q[]=x'                  => 'books search',
    'manage_books.php?category[]=x'           => 'books category filter',
    'manage_books.php?q[]=a&category[]=b'     => 'books both filters',
    'manage_students.php?q[]=x'               => 'students search',
    'return_book.php?q[]=x'                   => 'return search',
    'return_book.php?filter[]=overdue'        => 'return filter',
    'return_book.php?filter[]=x'              => 'return filter bogus',
    'reports.php?q[]=x'                       => 'reports search',
    'fines.php?q[]=x'                         => 'fines search',
    'fines.php?status[]=paid'                 => 'fines status filter',
    'fines.php?status[]=nonsense'             => 'fines bogus status',
    'fines.php?q[]=x&status[]=unpaid'         => 'fines both filters',
    'edit_book.php?id[]=1'                    => 'edit book by array id',
    'student_fines.php?id[]=1'                => 'student fines by array id',
    'student_fines.php?id[]=99999'            => 'student fines missing array id',
];

foreach ($get_probes as $qs => $label) {
    $body = http('GET', BASE_URL . $qs);
    $errs = errors_in($body);
    out(empty($errs), $label . "  ($qs)", implode(', ', $errs));
}

echo "\n";

/* ---------------- 2. POST array parameters ---------------- */

echo "2. Crafted POST parameters (array-shaped input)\n";

/**
 * Every destructive form, with each of its own fields replaced by an array.
 * These are the cases where the old code was silently aiming at record #1.
 */
$post_probes = [
    'add_book.php'         => ['action' => '', 'title' => ['x'], 'author' => ['x'], 'category' => ['x'], 'isbn' => ['x'], 'quantity' => ['x']],
    'edit_book.php'        => ['id' => ['1'], 'title' => ['x'], 'author' => ['x'], 'category' => ['x'], 'isbn' => ['x'], 'quantity' => ['x']],
    'add_student.php'      => ['name' => ['x'], 'email' => ['x'], 'phone' => ['x']],
    'manage_students.php'  => ['action' => ['delete'], 'student_id' => ['1']],
    'manage_students.php'  => ['action' => ['update'], 'student_id' => ['1'], 'name' => ['x'], 'email' => ['x'], 'phone' => ['x']],
    'manage_books.php'     => ['action' => ['delete'], 'book_id' => ['1']],
    'manage_books.php'     => ['action' => ['update_qty'], 'book_id' => ['1'], 'quantity' => ['999']],
    'manage_books.php'     => ['action' => ['delete'], 'book_id' => ['4'], 'back' => ['x']],
    'issue_book.php'       => ['book_id' => ['1'], 'student_id' => ['1'], 'issue_date' => ['x'], 'return_date' => ['x']],
    'return_book.php'      => ['action' => ['return'], 'id' => ['3']],
    'return_book.php'      => ['action' => ['return'], 'bulk' => '1', 'ids' => [['3']]],
    'return_book.php'      => ['action' => ['return'], 'bulk' => '1', 'ids' => ['3', ['4']]],
    'return_book.php'      => ['action' => ['return'], 'bulk' => ['1'], 'ids' => [['3']]],
    'fines.php'            => ['action' => ['pay'], 'issued_book_id' => ['4'], 'amount' => ['1']],
    'fines.php'            => ['action' => ['pay'], 'issued_book_id' => ['4'], 'amount' => '1', 'payment_method' => ['x'], 'note' => ['x'], 'settle' => ['1']],
    'fine_settings.php'    => ['per_day' => ['99'], 'grace_days' => ['99'], 'max_amount' => ['99']],
    'fine_settings.php'    => ['per_day' => '5', 'enabled' => ['1'], 'grace_days' => ['-1'], 'max_amount' => '1e999'],
];

foreach ($post_probes as $file => $fields) {
    $fields['csrf_token'] = token_from(http('GET', BASE_URL . $file));
    $body = http('POST', BASE_URL . $file, $fields);
    $errs = errors_in($body);

    $shown = [];
    foreach ($fields as $k => $v) {
        if (is_array($v)) {
            $shown[] = $k . '[]';
        }
    }

    out(empty($errs), sprintf('%-18s %s', $file, implode(' ', $shown)), implode(', ', $errs));
}

echo "\n";

/* ---------------- 3. unauthenticated login probes ---------------- */

echo "3. Crafted POST to the login form (reachable without a session)\n";

// Separate cookie jar so the logged-in session cannot mask the result
$anon_jar = $cookie_jar;
$cookie_jar = tempnam(sys_get_temp_dir(), 'lms_anon_');

$anon = [
    ['email' => ['x'], 'password' => 'x'],
    ['email' => 'admin@library.com', 'password' => ['x']],
    ['email' => [['x']], 'password' => [['x']]],
    ['email' => str_repeat('a', 5000), 'password' => str_repeat('b', 5000)],
    ['email' => '<script>alert(1)</script>', 'password' => 'x'],
];

foreach ($anon as $i => $fields) {
    $fields['csrf_token'] = token_from(http('GET', BASE_URL . 'login.php'));
    $body = http('POST', BASE_URL . 'login.php', $fields);
    $errs = errors_in($body);

    $desc = [];
    foreach ($fields as $k => $v) {
        if ($k === 'csrf_token') {
            continue;
        }
        $desc[] = $k . (is_array($v) ? '[]' : '');
    }
    out(empty($errs), 'login probe ' . ($i + 1) . ' (' . implode(' ', $desc) . ')', implode(', ', $errs));
}

// The script tag must come back escaped, never raw
$probe = http('POST', BASE_URL . 'login.php', [
    'email' => '<script>alert(1)</script>', 'password' => 'x',
    'csrf_token' => token_from(http('GET', BASE_URL . 'login.php')),
]);
out(strpos($probe, '<script>alert(1)</script>') === false, 'login echoes email escaped, not raw');

@unlink($cookie_jar);
$cookie_jar = $anon_jar;

echo "\n";

/* ---------------- 4. nothing was silently mutated ---------------- */

echo "4. Database unchanged by the probes above\n";

// fine_settings.php is meant to write when the form is valid, so put its rows
// back before comparing, then hold the whole database to the snapshot
restore_table('settings', $settings_snapshot);

$after = fingerprint();
compare($before, $after, 'every table and the fine settings are byte-identical');

echo "\n";

/* ---------------- 5. param helper unit checks ---------------- */

echo "5. int_param() / float_param() / str_param() semantics\n";

$cases = [
    ['int_param("42")',            int_param('42'),            42],
    ['int_param("0")',             int_param('0'),             0],
    ['int_param("abc")',           int_param('abc'),           0],
    ['int_param("")',              int_param(''),              0],
    ['int_param(null)',            int_param(null),            0],
    ['int_param(["5"])',           int_param(['5']),           0],
    ['int_param([])',              int_param([]),              0],
    ['int_param("7x")',            int_param('7x'),            0],
    ['int_param("-3")',            int_param('-3'),            -3],
    // A value larger than a 64-bit int saturates at PHP_INT_MAX rather than
    // wrapping, which is harmless: the lookup simply matches no row.
    ['int_param("99999999999999999999999")', int_param('99999999999999999999999'), PHP_INT_MAX],
    ['int_param("1.9")',           int_param('1.9'),           1],
    ['int_param(true)',            int_param(true),            0],
    ['float_param("5.50")',        float_param('5.50'),        5.5],
    ['float_param("abc")',         float_param('abc'),         0.0],
    ['float_param(null)',          float_param(null),          0.0],
    ['float_param(["5"])',         float_param(['5']),         0.0],
    ['float_param("INF")',         float_param('INF'),         0.0],
    ['float_param("NAN")',         float_param('NAN'),         0.0],
    ['float_param("1e999")',       float_param('1e999'),       0.0],
    ['float_param("0.0")',         float_param('0.0'),         0.0],
    ['str_param(["x"])',           str_param(['x']),           ''],
    ['str_param(null)',            str_param(null),            ''],
    ['str_param("  hi  ")',        str_param('  hi  '),        'hi'],
    ['str_param(str_repeat("x",300), 10)', str_param(str_repeat('x', 300), 10), 'xxxxxxxxxx'],
];

foreach ($cases as [$expr, $got, $want]) {
    $ok = is_float($want) || is_int($want)
        ? (is_float($got) || is_int($got)) && abs($got - $want) < 0.000001
        : $got === $want;
    out($ok, sprintf('%-38s => %s', $expr, var_export($got, true)),
        'expected ' . var_export($want, true));
}

// e() must never emit "Array" nor a warning
out(e(['x']) === '', 'e(array) returns empty string');
out(e('a & b') === 'a &amp; b', 'e() escapes ampersands');
out(e('<b>') === '&lt;b&gt;', 'e() escapes angle brackets');

echo "\n";

/* ---------------- summary ---------------- */

@unlink($cookie_jar);

echo str_repeat('-', 58) . "\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
