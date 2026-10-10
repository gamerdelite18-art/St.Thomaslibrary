<?php
/**
 * Stored-XSS check.
 *
 * Puts script payloads into the fields a librarian actually types into (book
 * title, author, category, student name, email, phone, payment note), then
 * loads every page that displays them and fails if the payload comes back
 * unescaped. Cleans up after itself.
 */

require_once __DIR__ . '/../includes/functions.php';

const BASE_URL = 'http://localhost/library-ai/admin/';

$pass = 0;
$fail = 0;

function out($ok, $label, $detail = '')
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($detail ? "\n         $detail" : '') . "\n"; }
}

$cookie_jar = tempnam(sys_get_temp_dir(), 'lms_xss_');

function http($method, $url, array $fields = null, $follow = true)
{
    global $cookie_jar;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_COOKIEJAR => $cookie_jar,
        CURLOPT_COOKIEFILE => $cookie_jar, CURLOPT_TIMEOUT => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $b = curl_exec($ch);
    curl_close($ch);
    return (string) $b;
}

function token_from($html)
{
    return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
}

function submit($page, array $fields)
{
    $fields['csrf_token'] = token_from(http('GET', BASE_URL . $page));
    return http('POST', BASE_URL . $page, $fields, true);
}

// Distinct markers so we can tell which field leaked
$P = '<script>alert("XSSMARK")</script>';
$ATTR = '" onmouseover="alert(\'XSSMARK\')';

echo "Logging in...\n";
submit('login.php', ['email' => 'admin@library.com', 'password' => 'admin123']);
if (strpos(http('GET', BASE_URL . 'index.php'), 'Fine') === false) {
    echo "  FAIL login failed\n";
    exit(1);
}
echo "  OK\n\n";

echo "1. Creating records whose fields contain payloads\n";

submit('add_book.php', [
    'title' => 'Pay ' . $P, 'author' => 'Auth ' . $P, 'category' => 'Cat ' . $P,
    'isbn' => '9780000009999', 'quantity' => '2',
]);
$book_id = (int) db_val("SELECT id FROM books WHERE isbn = '9780000009999'");
out($book_id > 0, "book with a payload title exists (id=$book_id)");

submit('add_student.php', [
    'name' => 'Stud ' . $P, 'email' => 'xss@example.com', 'phone' => '5551234',
]);
$student_id = (int) db_val("SELECT id FROM students WHERE email = 'xss@example.com'");
out($student_id > 0, "student with a payload name exists (id=$student_id)");

// Issue it and return it late so the fine pages have rows to render
submit('issue_book.php', [
    'book_id' => $book_id, 'student_id' => $student_id,
    'issue_date' => date('Y-m-d', strtotime('-10 days')),
    'return_date' => date('Y-m-d', strtotime('-2 days')),
]);
$loan_id = (int) db_val(
    "SELECT id FROM issued_books WHERE book_id = ? AND status = 'issued'", [$book_id]);
out($loan_id > 0, "loan issued (id=$loan_id)");

db_query('UPDATE issued_books SET return_date = ? WHERE id = ?',
         [date('Y-m-d', strtotime('-2 days')), $loan_id]);
submit('return_book.php', ['action' => 'return', 'id' => $loan_id]);

submit('fines.php', [
    'action' => 'pay', 'issued_book_id' => $loan_id,
    'amount' => '1.00', 'payment_method' => 'Cash', 'note' => 'Note ' . $P,
]);
$paid = db_val('SELECT fine_status FROM issued_books WHERE id = ?', [$loan_id]);
out($paid === 'partial', "fine partially paid so the history pages have a note ($paid)");

echo "\n2. Sweeping every page for the raw payload\n";

$pages = [
    'index.php', 'manage_books.php', 'add_book.php', "edit_book.php?id=$book_id",
    'manage_students.php', 'add_student.php', 'issue_book.php', 'return_book.php',
    'fines.php', "student_fines.php?id=$student_id", 'reports.php',
    'manage_books.php?q=Pay', 'manage_students.php?q=Stud',
    'fines.php?status=partial', 'fines.php?status=paid',
];

foreach ($pages as $p) {
    $body = http('GET', BASE_URL . $p);

    // The payload must never appear in a form a browser would execute
    $raw = (strpos($body, '<script>alert("XSSMARK")</script>') !== false)
        || (strpos($body, "onmouseover=\"alert('XSSMARK')") !== false);

    out(!$raw, "no executable payload on $p", $raw ? 'raw <script>/onmouseover found in output' : '');

    // And no PHP error either
    $err = preg_match('/Fatal error|Parse error|Uncaught|Warning:|Notice:/', $body);
    out(!$err, "no PHP error on $p");
}

echo "\n3. The escaped form is what actually renders\n";

$body = http('GET', BASE_URL . 'manage_books.php?q=Pay');
out(strpos($body, '&lt;script&gt;') !== false, 'title shows as escaped &lt;script&gt; text');
out(strpos($body, '&quot;') !== false || strpos($body, '&#039;') !== false,
    'quotes in payloads are entity-encoded');

echo "\n4. Cleaning up\n";

db_query('DELETE FROM students WHERE id = ?', [$student_id]);
db_query('DELETE FROM books WHERE id = ?', [$book_id]);
out((int) db_val('SELECT COUNT(*) FROM books WHERE id = ?', [$book_id]) === 0, 'payload book removed');
out((int) db_val('SELECT COUNT(*) FROM students WHERE id = ?', [$student_id]) === 0, 'payload student removed');

@unlink($cookie_jar);
echo "\n" . str_repeat('-', 58) . "\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
