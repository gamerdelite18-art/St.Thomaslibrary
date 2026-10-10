<?php
/**
 * Money-precision checks for the fine engine.
 *
 * Amounts are DECIMAL(10,2) in MySQL but are computed in PHP floats, so the
 * dangerous spot is a rate that does not divide evenly: 3 x 0.1 is
 * 0.30000000000000004 in binary floating point. If that unrounded value ever
 * reaches a comparison against a stored amount, a librarian paying the exact
 * outstanding balance can be told they are overpaying.
 */

require_once __DIR__ . '/../includes/functions.php';

$pass = 0;
$fail = 0;

function out($ok, $label, $detail = '')
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($detail ? "\n         $detail" : '') . "\n"; }
}

function money_eq($a, $b)
{
    return round((float) $a, 2) === round((float) $b, 2);
}

$settings_snapshot = db_all('SELECT * FROM settings ORDER BY setting_key');

function set_rate($per_day, $grace = 0, $max = 0)
{
    save_fine_settings($per_day, 1, $grace, $max);
}

echo "1. Rates that do not divide evenly\n";

// The classic float trap
out((0.1 + 0.2) !== 0.3, 'sanity: 0.1 + 0.2 really is not 0.3 in PHP', 'assumption no longer holds, review this test');

foreach ([
    ['per_day' => 0.1,  'days' => 3, 'want' => 0.30],
    ['per_day' => 0.1,  'days' => 7, 'want' => 0.70],
    ['per_day' => 0.07, 'days' => 3, 'want' => 0.21],
    ['per_day' => 0.33, 'days' => 3, 'want' => 0.99],
    ['per_day' => 1.15, 'days' => 3, 'want' => 3.45],
    // A sub-paisa rate is itself rounded to 2dp when saved, so 0.005/day is
    // stored as 0.01/day and three days come to Rs 0.03.
    ['per_day' => 0.005,'days' => 3, 'want' => 0.03],
] as $c) {
    set_rate($c['per_day']);
    // A due date N days ago means the book is exactly N days late today
    $due = date('Y-m-d', strtotime('-' . $c['days'] . ' days'));
    $calc = calculate_fine($due, null);
    out((int) $calc['overdue_days'] === $c['days'],
        '  overdue_days is ' . $c['days'], 'got ' . $calc['overdue_days']);
    out(money_eq($calc['fine'], $c['want']),
        sprintf('Rs %s/day x %d days = Rs %s', $c['per_day'], $c['days'], number_format($c['want'], 2)),
        'got ' . var_export($calc['fine'], true));
    // And the value must be exactly 2dp, with no binary dust after it
    out($calc['fine'] === round($calc['fine'], 2),
        '  value is already rounded, no float dust', 'got ' . var_export($calc['fine'], true));
}

echo "\n2. Remaining balance after partial payments with a messy rate\n";

foreach ([
    ['per_day' => 0.1,  'total' => 0.30, 'payments' => [0.10, 0.10, 0.10]],
    ['per_day' => 0.07, 'total' => 0.21, 'payments' => [0.07, 0.07, 0.07]],
    ['per_day' => 1.15, 'total' => 3.45, 'payments' => [1.00, 1.00, 1.00, 0.45]],
] as $c) {
    $paid = 0.0;
    $sum  = array_sum($c['payments']);
    out(money_eq($sum, $c['total']),
        sprintf('  instalments sum to the Rs %s total', number_format($c['total'], 2)),
        'they sum to ' . number_format($sum, 2));

    foreach ($c['payments'] as $p) {
        $paid = round($paid + $p, 2);
        $remaining = round($c['total'] - $paid, 2);
        out($remaining >= -0.001, sprintf(
            'Rs %s total, after paying Rs %s remaining is Rs %s (never negative)',
            number_format($c['total'], 2), number_format($p, 2), number_format($remaining, 2)),
            'got ' . $remaining);
    }
    out(money_eq($paid, $c['total']), sprintf(
        '  paying the exact balance in instalments settles it (paid Rs %s of Rs %s)',
        number_format($paid, 2), number_format($c['total'], 2)));
}

echo "\n3. loan_fine() on real stored decimals\n";

// Store exact DECIMAL(10,2) values and confirm the derived fields stay clean
db_query("INSERT INTO students (name, email) VALUES ('Precision Test', 'precision@test.local')");
$sid = (int) db_val('SELECT LAST_INSERT_ID()');
$lid = null;
try {
    foreach ([[0.30, 0.10], [0.21, 0.07], [3.45, 3.44], [0.99, 0.99], [10.00, 0.00]] as [$amt, $pd]) {
        db_query("INSERT INTO issued_books (book_id, student_id, issue_date, return_date, returned_at,
                                           status, fine_days, fine_amount, fine_paid, fine_status)
                  VALUES (1, ?, CURDATE() - INTERVAL 9 DAY, CURDATE() - INTERVAL 4 DAY,
                          CURDATE() - INTERVAL 2 DAY, 'returned', 2, ?, ?, ?)",
                 [$sid, $amt, $pd, $amt > $pd ? ($pd > 0 ? 'partial' : 'unpaid') : 'paid']);
        $lid = (int) db_val('SELECT LAST_INSERT_ID()');

        // Pass the real row: loan_fine() reads status/return_date too, and
        // deliberately treats a missing column as 0 rather than erroring.
        $row = db_one('SELECT * FROM issued_books WHERE id = ?', [$lid]);
        $f = loan_fine($row);

        out($f['amount'] === round($f['amount'], 2),
            sprintf('amount %s stays 2dp', $f['amount']), var_export($f['amount'], true));
        out($f['paid'] === round($f['paid'], 2),
            sprintf('paid %s stays 2dp', $f['paid']), var_export($f['paid'], true));
        out($f['remaining'] >= 0,
            sprintf('remaining %s is never negative (amount %s, paid %s)',
                $f['remaining'], $f['amount'], $f['paid']));
        out(money_eq($f['remaining'], $amt - $pd),
            sprintf('remaining equals amount minus paid (%s)', number_format($amt - $pd, 2)),
            'got ' . $f['remaining']);
    }
} finally {
    db_query('DELETE FROM students WHERE id = ?', [$sid]);
}

echo "\n4. A payment of the exact outstanding balance is accepted\n";

// Rs 0.1/day for 3 days, then pay the exact Rs 0.30 through the real engine
set_rate(0.1);
db_query("INSERT INTO students (name, email) VALUES ('Precision Pay', 'precpay@test.local')");
$sid = (int) db_val('SELECT LAST_INSERT_ID()');
try {
    db_query("INSERT INTO issued_books (book_id, student_id, issue_date, return_date, returned_at,
                                       status, fine_days, fine_amount, fine_paid, fine_status)
              VALUES (1, ?, CURDATE() - INTERVAL 9 DAY, CURDATE() - INTERVAL 2 DAY,
                      CURDATE() - INTERVAL 1 DAY, 'returned', 1, 0.10, 0.00, 'unpaid')", [$sid]);
    $lid = (int) db_val('SELECT LAST_INSERT_ID()');

    $r = record_fine_payment($lid, 0.10, 'Cash', 'exact remainder');
    out($r['ok'] === true, 'paying exactly Rs 0.10 against a Rs 0.10 fine succeeds',
        var_export($r['message'] ?? $r, true));

    $row = db_one('SELECT fine_amount, fine_paid, fine_status FROM issued_books WHERE id = ?', [$lid]);
    out(money_eq($row['fine_paid'], 0.10), 'fine_paid is exactly 0.10', 'got ' . $row['fine_paid']);
    out($row['fine_status'] === 'paid', 'status is paid, not partial', 'got ' . $row['fine_status']);

    // One paisa over must still be refused
    $r2 = record_fine_payment($lid, 0.01, 'Cash', 'one paisa over');
    out($r2['ok'] === false, 'a payment Rs 0.01 over the settled fine is refused',
        var_export($r2['message'] ?? $r2, true));
} finally {
    db_query('DELETE FROM students WHERE id = ?', [$sid]);
}

echo "\n5. Rounding the settings themselves\n";

foreach ([[0.005, 0.01], [1.005, 1.01], [2.674, 2.67], [0.1, 0.10]] as [$in, $want]) {
    set_rate($in);
    $cfg = fine_settings(true);
    out(money_eq($cfg['per_day'], $want),
        sprintf('per_day %s stores as %s', $in, number_format($want, 2)),
        'got ' . $cfg['per_day']);
}

echo "\n6. Restoring settings\n";

db_query('DELETE FROM settings');
foreach ($settings_snapshot as $row) {
    $cols = array_keys($row);
    db_query(
        'INSERT INTO settings (`' . implode('`, `', $cols) . '`) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')',
        array_values($row)
    );
}
$cfg = fine_settings(true);
out(money_eq($cfg['per_day'], 5.00), 'settings restored to Rs 5.00/day');

echo "\n" . str_repeat('-', 58) . "\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
