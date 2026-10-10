<?php
/**
 * Fine WRITE-path test - exercises the return assessment and the payment
 * engine against the live database, then puts everything back as it was.
 *
 *   C:\xampp\php\php.exe database\test_fine_writes.php
 */
require_once __DIR__ . '/../includes/functions.php';

$pass = 0;
$fail = 0;

function show($v) { return is_array($v) ? implode(' / ', $v) : var_export($v, true); }
function check($label, $expected, $actual)
{
    global $pass, $fail;
    if ($expected === $actual) { $pass++; printf("  OK   %-52s %s\n", $label, show($actual)); }
    else { $fail++; printf("  FAIL %-52s expected %s, got %s\n", $label, show($expected), show($actual)); }
}
function section($t) { echo "\n$t\n"; }

// Work on a throwaway loan so demo data is never left altered
$pdo = db();
$pdo->exec("INSERT INTO issued_books (book_id, student_id, issue_date, return_date, status)
            VALUES (1, 1, CURDATE() - INTERVAL 10 DAY, CURDATE() - INTERVAL 3 DAY, 'issued')");
$loan_id = (int) db_val('SELECT LAST_INSERT_ID()');
echo "Throwaway loan id = $loan_id (due 3 days ago, still out)\n";

section('LIVE FINE ON AN OUTSTANDING BOOK (must not write anything)');
$before = db_one('SELECT * FROM issued_books WHERE id = ?', [$loan_id]);
$f = loan_fine($before);
check('live overdue days', 3, $f['days']);
check('live fine', '15.00', number_format($f['amount'], 2));
check('flagged as live', true, $f['live']);
check('status while still out', 'unpaid', $f['status']);
check('nothing collected yet', '0.00', number_format($f['paid'], 2));
check('remaining equals fine', '15.00', number_format($f['remaining'], 2));

// Read it 50 times - the numbers must not move and nothing may be written
for ($i = 0; $i < 50; $i++) { $again = loan_fine(db_one('SELECT * FROM issued_books WHERE id = ?', [$loan_id])); }
check('stable after 50 reads (fine)', '15.00', number_format($again['amount'], 2));
$after = db_one('SELECT * FROM issued_books WHERE id = ?', [$loan_id]);
check('fine_amount still 0.00 in DB', '0.00', number_format((float) $after['fine_amount'], 2));
check('fine_days still 0 in DB', 0, (int) $after['fine_days']);
check('fine_status still none in DB', 'none', $after['fine_status']);
check('row unchanged', $before, $after);

section('RETURN ASSESSMENT (the freeze)');
$pdo->beginTransaction();
save_fine_on_return($loan_id, $before['return_date'], date('Y-m-d'));
db_query("UPDATE issued_books SET status = 'returned', returned_at = CURDATE() WHERE id = ?", [$loan_id]);
$pdo->commit();

$ret = db_one('SELECT * FROM issued_books WHERE id = ?', [$loan_id]);
check('fine_days frozen', 3, (int) $ret['fine_days']);
check('fine_amount frozen', '15.00', number_format((float) $ret['fine_amount'], 2));
check('fine_status', 'unpaid', $ret['fine_status']);
$f = loan_fine($ret);
check('no longer live', false, $f['live']);

section('RE-ASSESSMENT OF A RETURNED LOAN MUST BE REFUSED');
$again = save_fine_on_return($loan_id, $ret['return_date'], date('Y-m-d'));
check('assessed flag is false', false, $again['assessed']);
$re = db_one('SELECT fine_days, fine_amount, fine_paid, fine_status FROM issued_books WHERE id = ?', [$loan_id]);
check('fine_days untouched', 3, (int) $re['fine_days']);
check('fine_amount untouched', '15.00', number_format((float) $re['fine_amount'], 2));
check('fine_status untouched', 'unpaid', $re['fine_status']);

section('PARTIAL PAYMENT');
$r = record_fine_payment($loan_id, 5.00, 'Cash', 'first part');
check('accepted', true, $r['ok']);
$p = db_one('SELECT * FROM issued_books WHERE id = ?', [$loan_id]);
check('fine_paid', '5.00', number_format((float) $p['fine_paid'], 2));
check('fine_amount untouched', '15.00', number_format((float) $p['fine_amount'], 2));
check('status -> partial', 'partial', $p['fine_status']);
$f = loan_fine($p);
check('remaining', '10.00', number_format($f['remaining'], 2));

section('PAYMENT GUARDS');
$r = record_fine_payment($loan_id, 0, 'Cash');
check('zero rejected', false, $r['ok']);
$r = record_fine_payment($loan_id, -5, 'Cash');
check('negative rejected', false, $r['ok']);
$r = record_fine_payment($loan_id, 99.99, 'Cash');
check('more than outstanding rejected', false, $r['ok']);
$p = db_one('SELECT fine_paid, fine_status FROM issued_books WHERE id = ?', [$loan_id]);
check('rejects changed nothing', '5.00', number_format((float) $p['fine_paid'], 2));
check('status still partial', 'partial', $p['fine_status']);
$r = record_fine_payment(999999, 5, 'Cash');
check('unknown loan rejected', false, $r['ok']);

section('SECOND PARTIAL PAYMENT ACCUMULATES');
record_fine_payment($loan_id, 5.00, 'UPI');
$p = db_one('SELECT * FROM issued_books WHERE id = ?', [$loan_id]);
check('fine_paid accumulates to 10', '10.00', number_format((float) $p['fine_paid'], 2));
check('still partial', 'partial', $p['fine_status']);

section('SETTLE THE REST');
$r = record_fine_payment($loan_id, 0, 'Card', null, true);
check('settle accepted', true, $r['ok']);
$p = db_one('SELECT * FROM issued_books WHERE id = ?', [$loan_id]);
check('fine_paid = fine_amount', '15.00', number_format((float) $p['fine_paid'], 2));
check('status -> paid', 'paid', $p['fine_status']);
$f = loan_fine($p);
check('remaining zero', '0.00', number_format($f['remaining'], 2));

section('NO DOUBLE PAYMENT AFTER SETTLED');
$r = record_fine_payment($loan_id, 5, 'Cash');
check('further payment refused', false, $r['ok']);
$p = db_one('SELECT fine_paid FROM issued_books WHERE id = ?', [$loan_id]);
check('still 15.00', '15.00', number_format((float) $p['fine_paid'], 2));

section('RE-ASSESSMENT CANNOT DESTROY COLLECTED MONEY');
// The regression this guards: save_fine_on_return() used to run
// "SET fine_paid = 0.00" on a loan that had already been paid.
$before = db_one('SELECT fine_amount, fine_paid, fine_status FROM issued_books WHERE id = ?', [$loan_id]);
$again  = save_fine_on_return($loan_id, $ret['return_date'], date('Y-m-d'));
$after  = db_one('SELECT fine_amount, fine_paid, fine_status FROM issued_books WHERE id = ?', [$loan_id]);
check('assessed flag is false', false, $again['assessed']);
check('fine_amount preserved', '15.00', number_format((float) $after['fine_amount'], 2));
check('collected money preserved', '15.00', number_format((float) $after['fine_paid'], 2));
check('status still paid', 'paid', $after['fine_status']);

section('PAYMENT HISTORY');
$rows = db_all('SELECT amount, payment_method, note FROM fine_payments WHERE issued_book_id = ? ORDER BY id', [$loan_id]);
check('3 payments recorded', 3, count($rows));
check('payment 1', '5.00|Cash|first part', $rows[0]['amount'] . '|' . $rows[0]['payment_method'] . '|' . $rows[0]['note']);
check('payment 2 (UPI)', '5.00|UPI|', $rows[1]['amount'] . '|' . $rows[1]['payment_method'] . '|' . $rows[1]['note']);
check('payment 3 settled the rest', '5.00|Card|', $rows[2]['amount'] . '|' . $rows[2]['payment_method'] . '|' . $rows[2]['note']);
check('payments sum to fine', '15.00', number_format(array_sum(array_column($rows, 'amount')), 2));

section('ON-TIME RETURN GETS NO FINE');
$pdo->exec("INSERT INTO issued_books (book_id, student_id, issue_date, return_date, status)
            VALUES (2, 2, CURDATE() - INTERVAL 5 DAY, CURDATE(), 'issued')");
$ok_id = (int) db_val('SELECT LAST_INSERT_ID()');
$ok_row = db_one('SELECT * FROM issued_books WHERE id = ?', [$ok_id]);
$pdo->beginTransaction();
save_fine_on_return($ok_id, $ok_row['return_date'], date('Y-m-d'));
db_query("UPDATE issued_books SET status = 'returned', returned_at = CURDATE() WHERE id = ?", [$ok_id]);
$pdo->commit();
$ok_row = db_one('SELECT * FROM issued_books WHERE id = ?', [$ok_id]);
check('no days late', 0, (int) $ok_row['fine_days']);
check('no fine', '0.00', number_format((float) $ok_row['fine_amount'], 2));
check('status none', 'none', $ok_row['fine_status']);
$r = record_fine_payment($ok_id, 10, 'Cash');
check('cannot pay a zero fine', false, $r['ok']);

section('CLEANUP');
db_query('DELETE FROM issued_books WHERE id IN (?, ?)', [$loan_id, $ok_id]);
check('throwaway loans removed', 0, (int) db_val('SELECT COUNT(*) FROM issued_books WHERE id IN (' . $loan_id . ',' . $ok_id . ')'));
check('orphan payments cascade away', 0, (int) db_val('SELECT COUNT(*) FROM fine_payments WHERE issued_book_id = ' . $loan_id));
check('demo loan count back to 6', 6, (int) db_val('SELECT COUNT(*) FROM issued_books'));

echo "\n" . str_repeat('-', 62) . "\n";
printf("RESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
