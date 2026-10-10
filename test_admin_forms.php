<?php
/**
 * End-to-end happy-path test for the admin forms.
 *
 * The input-hardening change replaced raw trim()/(int) casts with str_param()
 * and int_param() across every admin form. Those helpers are stricter, so this
 * script proves ordinary, valid submissions still work: add a book, edit it,
 * add a student, edit the student, issue a book, return it, save the fine
 * settings, take a payment, and delete the test rows again.
 *
 * Every row it creates is removed at the end, and the fine settings are put
 * back the way they were found.
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

$cookie_jar = tempnam(sys_get_temp_dir(), 'lms_happy_');

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

/** Post to a page with a fresh CSRF token and follow the redirect. */
function submit($page, array $fields)
{
    $fields['csrf_token'] = token_from(http('GET', BASE_URL . $page));
    return http('POST', BASE_URL . $page, $fields, true);
}

function flat($body)
{
    return preg_replace('/\s+/', ' ', strip_tags($body));
}

/** A date string $days in the future. */
function date_plus($days)
{
    return date('Y-m-d', strtotime("+$days days"));
}

function errors_in($body)
{
    $f = [];
    foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:'] as $n) {
        if (stripos($body, $n) !== false) { $f[] = $n; }
    }
    return $f;
}

$settings_snapshot = db_all('SELECT * FROM settings ORDER BY setting_key');

echo "Logging in...\n";
submit('login.php', ['email' => 'admin@library.com', 'password' => 'admin123']);
out(strpos(http('GET', BASE_URL . 'index.php'), 'Fine') !== false, 'login works');
echo "\n";

/* ---------------- add a book ---------------- */

echo "1. Add a book with ordinary values\n";

$r = submit('add_book.php', [
    'title' => 'Harness Test Book', 'author' => 'Test Author',
    'category' => 'Testing', 'isbn' => '978-0000000001', 'quantity' => '3',
]);
out(!errors_in($r) && strpos(flat($r), 'added successfully') !== false, 'book added and confirmed',
    implode(', ', errors_in($r)));

$book_id = (int) db_val("SELECT id FROM books WHERE isbn = '978-0000000001'");
out($book_id > 0, "book row exists (id=$book_id)");

$b = db_one('SELECT title, author, category, quantity, available_quantity FROM books WHERE id = ?', [$book_id]);
out($b['title'] === 'Harness Test Book', 'title stored exactly');
out($b['author'] === 'Test Author', 'author stored exactly');
out((int) $b['quantity'] === 3, 'quantity stored as 3');
out((int) $b['available_quantity'] === 3, 'available_quantity starts at 3');

echo "\n";

/* ---------------- edit the book ---------------- */

echo "2. Edit the book\n";

$r = submit('edit_book.php?id=' . $book_id, [
    'id' => $book_id, 'title' => 'Harness Test Book v2', 'author' => 'Test Author',
    'category' => 'Testing', 'isbn' => '978-0000000001', 'quantity' => '5',
]);
out(!errors_in($r) && strpos(flat($r), 'updated successfully') !== false, 'book edit confirmed',
    implode(', ', errors_in($r)));

$b = db_one('SELECT title, quantity, available_quantity FROM books WHERE id = ?', [$book_id]);
out($b['title'] === 'Harness Test Book v2', 'edited title stored');
out((int) $b['quantity'] === 5 && (int) $b['available_quantity'] === 5, 'quantity updated to 5/5');

echo "\n";

/* ---------------- add and edit a student ---------------- */

echo "3. Add and edit a student\n";

$r = submit('add_student.php', [
    'name' => 'Harness Test Student', 'email' => 'harness@example.com', 'phone' => '5550100',
]);
out(!errors_in($r) && strpos(flat($r), 'registered successfully') !== false, 'student added and confirmed',
    implode(', ', errors_in($r)));

$student_id = (int) db_val("SELECT id FROM students WHERE email = 'harness@example.com'");
out($student_id > 0, "student row exists (id=$student_id)");

$r = submit('manage_students.php', [
    'action' => 'update', 'student_id' => $student_id,
    'name' => 'Harness Test Student Renamed', 'email' => 'harness@example.com', 'phone' => '5550199',
]);
out(!errors_in($r) && strpos(flat($r), 'updated successfully') !== false, 'student edit confirmed',
    implode(', ', errors_in($r)));
out(db_val('SELECT name FROM students WHERE id = ?', [$student_id]) === 'Harness Test Student Renamed',
    'edited student name stored');

echo "\n";

/* ---------------- issue a book ---------------- */

echo "4. Issue the book, then return it late so a fine is assessed\n";

$issue = date_plus(5);
$r = submit('issue_book.php', [
    'book_id' => $book_id, 'student_id' => $student_id,
    'issue_date' => date('Y-m-d'), 'return_date' => $issue,
]);
out(!errors_in($r) && strpos(flat($r), 'Due on') !== false, 'issue confirmed',
    implode(', ', errors_in($r)));

$loan_id = (int) db_val("SELECT id FROM issued_books WHERE book_id = ? AND student_id = ?", [$book_id, $student_id]);
out($loan_id > 0, "loan row exists (id=$loan_id)");

$q = db_one('SELECT quantity, available_quantity FROM books WHERE id = ?', [$book_id]);
out((int) $q['available_quantity'] === 4, 'available_quantity dropped to 4 while the copy is out',
    'got ' . $q['available_quantity']);

echo "\n";

/* ---------------- settings save with valid values ---------------- */

echo "5. Save fine settings with ordinary values\n";

$r = submit('fine_settings.php', [
    'per_day' => '7.50', 'enabled' => '1', 'grace_days' => '2', 'max_amount' => '100.00',
]);
out(!errors_in($r) && strpos(flat($r), 'saved') !== false, 'settings save confirmed',
    implode(', ', errors_in($r)));

$cfg = fine_settings(true);
out((float) $cfg['per_day'] === 7.50, 'per_day saved as 7.50', 'got ' . $cfg['per_day']);
out((int) $cfg['grace_days'] === 2, 'grace_days saved as 2', 'got ' . $cfg['grace_days']);
out((float) $cfg['max_amount'] === 100.00, 'max_amount saved as 100.00', 'got ' . $cfg['max_amount']);
out((int) $cfg['enabled'] === 1, 'enabled saved as on', 'got ' . $cfg['enabled']);

// And back to defaults so the run leaves the app as it found it
submit('fine_settings.php', ['per_day' => '5.00', 'enabled' => '1', 'grace_days' => '0', 'max_amount' => '0']);
$cfg = fine_settings(true);
out((float) $cfg['per_day'] === 5.00 && (int) $cfg['grace_days'] === 0, 'settings restored to defaults');

echo "\n";

/* ---------------- return late, fine assessed, then pay ---------------- */

echo "6. Return the book 4 days late, then collect the fine\n";

// Backdate the due date so the return genuinely lands late
db_query('UPDATE issued_books SET return_date = ? WHERE id = ?',
         [date('Y-m-d', strtotime('-4 days')), $loan_id]);

$r = submit('return_book.php', ['action' => 'return', 'id' => $loan_id]);
out(!errors_in($r), 'return page loaded without errors', implode(', ', errors_in($r)));

$loan = db_one('SELECT status, fine_days, fine_amount, fine_paid, fine_status FROM issued_books WHERE id = ?', [$loan_id]);
out($loan['status'] === 'returned', 'loan marked returned', 'got ' . $loan['status']);
out((int) $loan['fine_days'] === 4, '4 overdue days assessed', 'got ' . $loan['fine_days']);
out((float) $loan['fine_amount'] === 20.00, 'fine = 4 days x Rs 5 = Rs 20.00', 'got ' . $loan['fine_amount']);
out($loan['fine_status'] === 'unpaid', 'status is unpaid', 'got ' . $loan['fine_status']);

$q = db_one('SELECT available_quantity FROM books WHERE id = ?', [$book_id]);
out((int) $q['available_quantity'] === 5, 'copy back in stock after the return', 'got ' . $q['available_quantity']);

$r = submit('fines.php', [
    'action' => 'pay', 'issued_book_id' => $loan_id,
    'amount' => '12.50', 'payment_method' => 'Card', 'note' => 'Part payment via harness test',
]);
out(!errors_in($r), 'payment page loaded without errors', implode(', ', errors_in($r)));

$loan = db_one('SELECT fine_amount, fine_paid, fine_status FROM issued_books WHERE id = ?', [$loan_id]);
out((float) $loan['fine_paid'] === 12.50, 'fine_paid is 12.50', 'got ' . $loan['fine_paid']);
out($loan['fine_status'] === 'partial', 'status became partial', 'got ' . $loan['fine_status']);

$pay = db_one('SELECT amount, payment_method, note FROM fine_payments WHERE issued_book_id = ?', [$loan_id]);
out((float) $pay['amount'] === 12.50, 'payment row recorded for 12.50');
out($pay['payment_method'] === 'Card', 'payment method stored as Card', 'got ' . $pay['payment_method']);
out($pay['note'] === 'Part payment via harness test', 'payment note stored verbatim');

$r = submit('fines.php', [
    'action' => 'pay', 'issued_book_id' => $loan_id, 'settle' => '1', 'payment_method' => 'Cash',
]);
$loan = db_one('SELECT fine_paid, fine_status FROM issued_books WHERE id = ?', [$loan_id]);
out((float) $loan['fine_paid'] === 20.00, 'settle-in-full brought it to exactly 20.00', 'got ' . $loan['fine_paid']);
out($loan['fine_status'] === 'paid', 'status became paid', 'got ' . $loan['fine_status']);

echo "\n";

/* ---------------- bulk return ---------------- */

echo "7. Bulk return (the ids[] path)\n";

// Two more copies go out to the same student, both due back before today so
// that returning them together produces a real fine for each.
$bulk_loans = [];
for ($i = 0; $i < 2; $i++) {
    submit('issue_book.php', [
        'book_id' => $book_id, 'student_id' => $student_id,
        'issue_date' => date('Y-m-d', strtotime('-12 days')),
        'return_date' => date('Y-m-d', strtotime('-3 days')),
    ]);
}
$bulk_loans = array_map('intval', array_column(db_all(
    "SELECT id FROM issued_books WHERE book_id = ? AND student_id = ? AND status = 'issued'",
    [$book_id, $student_id]
), 'id'));

out(count($bulk_loans) === 2, 'two copies are out on loan', 'got ' . count($bulk_loans));

$q = db_one('SELECT available_quantity FROM books WHERE id = ?', [$book_id]);
out((int) $q['available_quantity'] === 3, '3 of 5 copies still available', 'got ' . $q['available_quantity']);

$r = submit('return_book.php', [
    'action' => 'return', 'bulk' => '1',
    'ids' => [$bulk_loans[0], $bulk_loans[1]],
]);
out(!errors_in($r), 'bulk return page loaded without errors', implode(', ', errors_in($r)));

foreach ($bulk_loans as $n => $lid) {
    $loan = db_one('SELECT status, fine_days, fine_amount, fine_status FROM issued_books WHERE id = ?', [$lid]);
    out($loan['status'] === 'returned', "bulk loan " . ($n + 1) . " ($lid) marked returned", 'got ' . $loan['status']);
    out((int) $loan['fine_days'] === 3, "bulk loan " . ($n + 1) . " assessed 3 overdue days", 'got ' . $loan['fine_days']);
    out((float) $loan['fine_amount'] === 15.00, "bulk loan " . ($n + 1) . " fined Rs 15.00", 'got ' . $loan['fine_amount']);
    out($loan['fine_status'] === 'unpaid', "bulk loan " . ($n + 1) . " is unpaid", 'got ' . $loan['fine_status']);
}

$q = db_one('SELECT available_quantity FROM books WHERE id = ?', [$book_id]);
out((int) $q['available_quantity'] === 5, 'all copies back in stock after the bulk return', 'got ' . $q['available_quantity']);

// A nested array in ids[] must be discarded, not silently read as loan #1.
// Issue a fresh copy so there is a real loan to aim the probe at.
submit('issue_book.php', [
    'book_id' => $book_id, 'student_id' => $student_id,
    'issue_date' => date('Y-m-d'), 'return_date' => date_plus(5),
]);
$victim = (int) db_val(
    "SELECT id FROM issued_books WHERE book_id = ? AND status = 'issued' LIMIT 1", [$book_id]);
out($victim > 0, "issued a fresh loan to probe ($victim)");

$r = submit('return_book.php', ['action' => 'return', 'bulk' => '1', 'ids' => [[$victim]]]);
$still = db_one('SELECT status FROM issued_books WHERE id = ?', [$victim]);
out($still && $still['status'] === 'issued',
    'a nested ids[][] value is ignored, that loan stays issued',
    'status is ' . ($still['status'] ?? 'missing'));

// A non-numeric id must also be dropped rather than cast to 0/1
$r = submit('return_book.php', ['action' => 'return', 'bulk' => '1', 'ids' => ['abc', '']]);
$still = db_one('SELECT status FROM issued_books WHERE id = ?', [$victim]);
out($still && $still['status'] === 'issued', 'non-numeric ids are ignored too');

echo "\n";

/* ---------------- search still works ---------------- */

echo "8. Searches and filters still return the right rows\n";

$r = http('GET', BASE_URL . 'manage_books.php?q=' . urlencode('Harness Test Book v2'));
out(strpos($r, 'Harness Test Book v2') !== false, 'book search finds the test book');

$r = http('GET', BASE_URL . 'manage_books.php?q=' . urlencode('nothingmatchesxyz'));
out(strpos($r, 'Harness Test Book v2') === false, 'a search with no matches returns nothing');

$r = http('GET', BASE_URL . 'manage_students.php?q=' . urlencode('Harness Test Student Renamed'));
out(strpos($r, 'Harness Test Student Renamed') !== false, 'student search finds the test student');

$r = http('GET', BASE_URL . 'fines.php?status=paid');
out(strpos($r, 'No fine records match this filter') === false, 'paid filter still renders a table');

$r = http('GET', BASE_URL . 'return_book.php?filter=overdue');
out(!errors_in($r), 'overdue filter renders cleanly', implode(', ', errors_in($r)));

echo "\n";

/* ---------------- cleanup ---------------- */

echo "9. Cleaning up the rows this script created\n";

db_query('DELETE FROM students WHERE id = ?', [$student_id]);
db_query('DELETE FROM books WHERE id = ?', [$book_id]);
out((int) db_val('SELECT COUNT(*) FROM books WHERE id = ?', [$book_id]) === 0, 'test book removed');
out((int) db_val('SELECT COUNT(*) FROM students WHERE id = ?', [$student_id]) === 0, 'test student removed');

db_query('DELETE FROM settings');foreach ($settings_snapshot as $row) {
    $cols = array_keys($row);
    db_query(
        'INSERT INTO settings (`' . implode('`, `', $cols) . '`) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')',
        array_values($row)
    );
}
$cfg = fine_settings(true);
out((float) $cfg['per_day'] === 5.00, 'fine settings restored to Rs 5/day');

@unlink($cookie_jar);

echo "\n" . str_repeat('-', 58) . "\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
