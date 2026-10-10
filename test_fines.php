<?php
/**
 * Fine engine unit test - run from the command line:
 *   C:\xampp\php\php.exe database\test_fines.php
 * Verifies the formula against the specification, including the exact example.
 */
require_once __DIR__ . '/../includes/functions.php';

$pass = 0;
$fail = 0;

function show($v)
{
    return is_array($v) ? implode(' / ', $v) : var_export($v, true);
}

function check($label, $expected, $actual)
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        printf("  OK   %-52s %s\n", $label, show($actual));
    } else {
        $fail++;
        printf("  FAIL %-52s expected %s, got %s\n", $label, show($expected), show($actual));
    }
}

echo "RATE / CONFIG\n";
$cfg = fine_settings();
check('default fine per day', '5.00', number_format($cfg['per_day'], 2));
check('fines enabled by default', true, $cfg['enabled']);
check('grace period defaults to 0', 0, $cfg['grace_days']);
check('no maximum by default', 0.0, $cfg['max_amount']);

echo "\nOVERDUE DAY COUNTING (date-only)\n";
check('returned on the due date',        0,  fine_overdue_days('2026-10-10', '2026-10-10'));
check('1 day late',                      1,  fine_overdue_days('2026-10-10', '2026-10-11'));
check('4 days late',                     4,  fine_overdue_days('2026-10-10', '2026-10-14'));
check('returned early',                  0,  fine_overdue_days('2026-10-10', '2026-10-05'));
check('not due yet (book still out)',    0,  fine_overdue_days(date('Y-m-d', strtotime('+5 days')), null));
check('crosses a month end',             3,  fine_overdue_days('2026-01-30', '2026-02-02'));
check('crosses a year end',              2,  fine_overdue_days('2025-12-30', '2026-01-01'));
check('spans a leap day (Feb 2028)',     2,  fine_overdue_days('2028-02-27', '2028-02-29'));
check('spans a whole year',            365,  fine_overdue_days('2025-06-01', '2026-06-01'));
check('spans a leap year',             366,  fine_overdue_days('2024-01-01', '2025-01-01'));
check('time of day must not matter',     4,  fine_overdue_days('2026-10-10 23:59:00', '2026-10-14 00:01:00'));
check('late by hours only is still 0',   0,  fine_overdue_days('2026-10-10 00:00:00', '2026-10-10 23:59:00'));
check('missing due date is safe',        0,  fine_overdue_days(null, '2026-10-14'));
check('missing return date is safe',     0,  fine_overdue_days('', null));

echo "\nFINE AMOUNT - specification example\n";
$ex = calculate_fine('2026-10-10', '2026-10-14');
check('example overdue days',            4, $ex['overdue_days']);
check('example fine (4 x Rs 5)',          '20.00', number_format($ex['fine'], 2));

echo "\nFINE AMOUNT - other cases\n";
check('on time -> no fine',            '0.00', number_format(calculate_fine('2026-10-10', '2026-10-10')['fine'], 2));
check('returned early -> no fine',     '0.00', number_format(calculate_fine('2026-10-10', '2026-10-08')['fine'], 2));
check('1 day late = Rs 5',             '5.00', number_format(calculate_fine('2026-10-10', '2026-10-11')['fine'], 2));
check('10 days late = Rs 50',         '50.00', number_format(calculate_fine('2026-10-01', '2026-10-11')['fine'], 2));
check('30 days late = Rs 150',       '150.00', number_format(calculate_fine('2026-09-10', '2026-10-10')['fine'], 2));
check('overdue but unreturned counts',  true,  calculate_fine(date('Y-m-d', strtotime('-3 days')), null)['fine'] > 0);

echo "\nPAYMENT STATE\n"
;
check('nothing owed -> none',      ['none', 'No Fine', 'success'],        fine_status_parts(0.00, 0.00));
check('nothing collected -> unpaid',['unpaid', 'Unpaid', 'danger'],     fine_status_parts(20.00, 0.00));
check('some collected -> partial', ['partial', 'Partially Paid', 'warning'], fine_status_parts(20.00, 8.00));
check('fully collected -> paid',   ['paid', 'Paid', 'success'],          fine_status_parts(20.00, 20.00));
check('overpaid is treated as paid',['paid', 'Paid', 'success'],         fine_status_parts(20.00, 25.00));

echo "\nGRACE PERIOD (0 by default - prove it is applied when set)\n";
save_fine_settings(5.00, true, 2, 0);
$cfg = null; // force a fresh read by re-running the helper below
function fresh_cfg() { $r = db_one("SELECT setting_value FROM settings WHERE setting_key = 'fine_grace_days'"); return (int) $r['setting_value']; }
check('grace days stored', 2, fresh_cfg());
check('still 0 fine for 2 days late', '0.00', number_format(calculate_fine('2026-10-10', '2026-10-12')['fine'], 2));
check('Rs 5 for 3 days late (1 chargeable)', '5.00', number_format(calculate_fine('2026-10-10', '2026-10-13')['fine'], 2));
check('Rs 15 for 5 days late (3 chargeable)', '15.00', number_format(calculate_fine('2026-10-10', '2026-10-15')['fine'], 2));

echo "\nMAXIMUM CAP\n";
save_fine_settings(5.00, true, 0, 20);
check('Rs 20 fine passes under the cap', '20.00', number_format(calculate_fine('2026-10-01', '2026-10-14')['fine'], 2));
check('Rs 100 fine is capped at Rs 20',  '20.00', number_format(calculate_fine('2026-10-01', '2026-10-21')['fine'], 2));
check('cap flag is reported',            true,   calculate_fine('2026-10-01', '2026-10-21')['capped']);

echo "\nDISABLED\n";
save_fine_settings(5.00, false, 0, 0);
check('no fine charged when switched off', '0.00', number_format(calculate_fine('2026-10-01', '2026-10-21')['fine'], 2));
check('overdue days still counted',       20,     calculate_fine('2026-10-01', '2026-10-21')['overdue_days']);

echo "\nCUSTOM RATE\n";
save_fine_settings(7.50, true, 0, 0);
check('4 days at Rs 7.50', '30.00', number_format(calculate_fine('2026-10-10', '2026-10-14')['fine'], 2));

// ---- restore the required defaults ----
save_fine_settings(5.00, true, 0, 0);
echo "\nDEFAULTS RESTORED\n";
check('rate back to Rs 5',   '5.00', number_format((float) db_val("SELECT setting_value FROM settings WHERE setting_key='fine_per_day'"), 2));
check('grace back to 0',     '0',    db_val("SELECT setting_value FROM settings WHERE setting_key='fine_grace_days'"));
check('cap back to none',    '0.00', number_format((float) db_val("SELECT setting_value FROM settings WHERE setting_key='fine_max_amount'"), 2));
check('enabled back on',     '1',    db_val("SELECT setting_value FROM settings WHERE setting_key='fine_enabled'"));

echo "\n" . str_repeat('-', 62) . "\n";
printf("RESULT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
