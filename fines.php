<?php
/**
 * fines.php - Late-return fine engine
 *
 * The single source of truth for every fine number in the system.
 * Loaded automatically through includes/functions.php.
 *
 * Design notes
 * ------------
 * 1. All maths is DATE-ONLY. Dates are read as calendar days at midnight and
 *    compared with DateTime::diff(), so the time of day can never add a day
 *    and a DST change can never shorten or lengthen a month.
 * 2. The fine for a book that is still out is calculated on the fly and is
 *    NEVER written to the database. Reading a record a hundred times returns
 *    the same number and changes nothing.
 * 3. The fine is written to the database exactly once, inside the return
 *    transaction, guarded by status = 'issued'. A book therefore cannot be
 *    fined twice.
 */

require_once __DIR__ . '/db.php';

/* ============================================================
   CONFIGURATION
   ============================================================ */

/**
 * Current fine settings, cached for the request.
 * Missing rows fall back to the documented defaults:
 * Rs 5 per day, fines enabled, 0 grace days, no maximum.
 *
 * Pass true to force a re-read, which is what save_fine_settings() does so a
 * page that saves the settings immediately shows the new values.
 */
function fine_settings($refresh = false)
{
    static $cfg = null;
    if ($cfg !== null && !$refresh) {
        return $cfg;
    }

    $values = [];
    foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
        $values[$row['setting_key']] = $row['setting_value'];
    }

    $cfg = [
        'enabled'    => !array_key_exists('fine_enabled', $values) || (int) $values['fine_enabled'] === 1,
        'per_day'    => array_key_exists('fine_per_day', $values)    ? (float) $values['fine_per_day']    : 5.00,
        'grace_days' => array_key_exists('fine_grace_days', $values) ? (int) $values['fine_grace_days'] : 0,
        'max_amount' => array_key_exists('fine_max_amount', $values) ? (float) $values['fine_max_amount'] : 0.00,
    ];

    return $cfg;
}

/** True when fines are switched on. */
function fines_enabled()
{
    return fine_settings()['enabled'];
}

/* ============================================================
   DATE MATHS
   ============================================================ */

/** Parse a date-only string safely. Returns null when unusable. */
function fine_parse_date($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return null;
    }
    // "Y-m-d H:i:s" and "Y-m-d" both land on midnight, which is what we want
    try {
        return (new DateTimeImmutable(substr((string) $date, 0, 10)))->setTime(0, 0);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * How many days late a book is.
 *
 * $due_date     the due date the librarian set when issuing
 * $returned_at  actual return date, or null/'' while the book is still out
 *
 * Counting starts the DAY AFTER the due date, so returning on the due date
 * gives 0 days and therefore no fine.
 */
function fine_overdue_days($due_date, $returned_at = null)
{
    $due = fine_parse_date($due_date);
    if ($due === null) {
        return 0;
    }

    // Book still out: measure up to today
    $end = $returned_at ? fine_parse_date($returned_at) : new DateTimeImmutable('today');
    if ($end === null) {
        return 0;
    }

    if ($end <= $due) {
        return 0; // returned on time, or not due yet
    }

    // ->days is a true calendar-day count, so month ends, leap years and
    // daylight-saving shifts are all handled correctly.
    return (int) $due->diff($end)->days;
}

/* ============================================================
   FINE CALCULATION
   ============================================================ */

/**
 * Work out the fine for one loan.
 *
 * Fine = overdue days x rate per day, then the grace period is deducted and
 * the optional cap is applied.
 *
 * @return array{overdue_days:int, chargeable_days:int, fine:float, rate:float,
 *               grace:int, capped:bool, enabled:bool}
 */
function calculate_fine($due_date, $returned_at = null)
{
    $cfg        = fine_settings();
    $overdue    = fine_overdue_days($due_date, $returned_at);
    $chargeable = max(0, $overdue - $cfg['grace_days']);

    $fine   = 0.0;
    $capped = false;

    if ($cfg['enabled'] && $chargeable > 0) {
        $fine = round($chargeable * $cfg['per_day'], 2);

        // max_amount of 0 (or less) means "no limit"
        if ($cfg['max_amount'] > 0 && $fine > $cfg['max_amount']) {
            $fine   = round($cfg['max_amount'], 2);
            $capped = true;
        }
    }

    return [
        'overdue_days'   => $overdue,
        'chargeable_days'=> $chargeable,
        'fine'           => $fine,
        'rate'           => $cfg['per_day'],
        'grace'          => $cfg['grace_days'],
        'capped'         => $capped,
        'enabled'        => $cfg['enabled'],
    ];
}

/**
 * Fine for a loan, using the value already stored when the book is back and
 * a live calculation while it is still out.
 *
 * @return array{days:int, amount:float, paid:float, remaining:float,
 *               status:string, label:string, css:string, live:bool}
 */
function loan_fine(array $loan)
{
    $returned = ($loan['status'] ?? '') === 'returned';
    $isBack   = $returned && !empty($loan['returned_at']);

    if ($isBack) {
        // Frozen figures: re-reading them can never change them.
        // Defaults keep this safe for callers that select only some columns.
        $days   = (int) ($loan['fine_days']   ?? 0);
        $amount = (float) ($loan['fine_amount'] ?? 0);
        $paid   = (float) ($loan['fine_paid']   ?? 0);
        $live   = false;
    } else {
        $calc   = calculate_fine($loan['return_date'] ?? null, null);
        $days   = $calc['overdue_days'];
        $amount = $calc['fine'];
        $paid   = 0.0; // nothing is collected before the book comes back
        $live   = true;
    }

    $remaining = round($amount - $paid, 2);
    if ($remaining < 0) {          // guard against an over-payment
        $remaining = 0.0;
        $paid      = $amount;
    }

    [$status, $label, $css] = fine_status_parts($amount, $paid);

    return [
        'days'      => $days,
        'amount'    => round($amount, 2),
        'paid'      => round($paid, 2),
        'remaining' => $remaining,
        'status'    => $status,
        'label'     => $label,
        'css'       => $css,
        'live'      => $live,
    ];
}

/**
 * Derive the payment state.
 * @return array{0:string,1:string,2:string} status key, label, badge css
 */
function fine_status_parts($amount, $paid)
{
    $amount    = round((float) $amount, 2);
    $paid      = round((float) $paid, 2);
    $remaining = round($amount - $paid, 2);

    if ($amount <= 0) {
        return ['none', 'No Fine', 'success'];        // on time / nothing owed
    }
    if ($remaining <= 0) {
        return ['paid', 'Paid', 'success'];           // settled in full
    }
    if ($paid > 0) {
        return ['partial', 'Partially Paid', 'warning'];
    }
    return ['unpaid', 'Unpaid', 'danger'];            // nothing collected yet
}

/* ============================================================
   DISPLAY HELPERS
   ============================================================ */

/**
 * 1234.5 -> ₹ 1,234.50
 *
 * Returns the real rupee character rather than an HTML entity, so the result is
 * safe whether it is echoed raw into markup or passed through e().
 */
function rupees($amount)
{
    return "\u{20B9}" . number_format((float) $amount, 2);
}

/** Plain text rupee amount (no HTML) - for flash messages. */
function rupees_text($amount)
{
    return 'Rs ' . number_format((float) $amount, 2);
}

/** Coloured badge showing the payment state. */
function fine_badge($label, $css)
{
    return '<span class="badge text-bg-' . $css . '">' . e($label) . '</span>';
}

/**
 * Sentence used in the dashboard notification strip.
 * Written for the librarian's view, so it names the student who has the book.
 */
function fine_notification_text($student_name, $title, $overdue_days, $fine_amount, $rate)
{
    return sprintf(
        "%s has had '%s' %d day%s overdue. Fine accrued so far: %s (Rs %s per day).",
        rtrim($student_name) . "'s copy",
        $title,
        $overdue_days,
        $overdue_days === 1 ? '' : 's',
        rupees_text($fine_amount),
        number_format((float) $rate, 2)
    );
}

/* ============================================================
   AGGREGATES
   ============================================================ */

/**
 * Headline fine figures for the dashboard and reports.
 * Returned-book fines come from the stored values; books still out are
 * included at their live accrued value.
 */
function fines_summary()
{
    $back = db_one(
        "SELECT COALESCE(SUM(fine_amount), 0) AS assessed,
                COALESCE(SUM(fine_paid), 0)   AS collected,
                COALESCE(SUM(fine_amount - fine_paid), 0) AS pending
           FROM issued_books
          WHERE status = 'returned'"
    );

    $live = calculate_live_fines();

    return [
        'assessed'  => round((float) $back['assessed'] + $live['assessed'], 2),
        'collected' => round((float) $back['collected'], 2),
        'pending'   => round((float) $back['pending'] + $live['pending'], 2),
        'overdue'   => $live['overdue_count'],
        'accruing'  => $live['accruing_count'],
    ];
}

/**
 * Sum the live fines on books that are still out, in one pass.
 * Kept separate so fines_summary() stays a single cheap query.
 */
function calculate_live_fines()
{
    $rows = db_all(
        "SELECT ib.id, ib.return_date, ib.fine_amount
           FROM issued_books ib
          WHERE ib.status = 'issued'"
    );

    $assessed = $pending = 0.0;
    $overdue_count = $accruing_count = 0;

    foreach ($rows as $row) {
        $calc  = calculate_fine($row['return_date'], null);
        $days  = $calc['overdue_days'];

        if ($days > 0) {
            $overdue_count++;
            if ($calc['fine'] > 0) {
                $assessed += $calc['fine'];
                $pending  += $calc['fine'];
                $accruing_count++;
            }
        }
    }

    return [
        'assessed'       => round($assessed, 2),
        'pending'        => round($pending, 2),
        'overdue_count'  => $overdue_count,
        'accruing_count' => $accruing_count,
    ];
}

/**
 * Fine totals for one student.
 */
function student_fine_summary($student_id)
{
    $back = db_one(
        "SELECT COALESCE(SUM(fine_amount), 0) AS assessed,
                COALESCE(SUM(fine_paid), 0)   AS collected,
                COALESCE(SUM(fine_amount - fine_paid), 0) AS pending
           FROM issued_books
          WHERE student_id = ? AND status = 'returned'",
        [$student_id]
    );

    $out = db_all(
        "SELECT return_date FROM issued_books WHERE student_id = ? AND status = 'issued'",
        [$student_id]
    );

    $live_pending = 0.0;
    $overdue      = 0;
    foreach ($out as $row) {
        $calc = calculate_fine($row['return_date'], null);
        if ($calc['overdue_days'] > 0) {
            $overdue++;
            $live_pending += $calc['fine'];
        }
    }

    return [
        'assessed'  => round((float) $back['assessed'] + $live_pending, 2),
        'collected' => round((float) $back['collected'], 2),
        'pending'   => round((float) $back['pending'] + $live_pending, 2),
        'overdue'   => $overdue,
    ];
}

/**
 * Fine totals for many students in a fixed number of queries.
 *
 * student_fine_summary() costs two queries, so calling it once per row of a
 * list page turns into 1 + 2N queries. This does the whole page in three.
 *
 * @param  array $student_ids
 * @return array [student_id => same shape as student_fine_summary()]
 */
function student_fine_summaries(array $student_ids)
{
    $student_ids = array_values(array_unique(array_map('intval', $student_ids)));
    $out = [];
    foreach ($student_ids as $id) {
        $out[$id] = ['assessed' => 0.0, 'collected' => 0.0, 'pending' => 0.0, 'overdue' => 0];
    }
    if (!$student_ids) {
        return $out;
    }

    $in = implode(',', array_fill(0, count($student_ids), '?'));

    // 1. Stored fines on books already returned
    $returned = db_all(
        "SELECT student_id,
                COALESCE(SUM(fine_amount), 0)          AS assessed,
                COALESCE(SUM(fine_paid), 0)            AS collected,
                COALESCE(SUM(fine_amount - fine_paid), 0) AS pending
           FROM issued_books
          WHERE status = 'returned' AND student_id IN ($in)
          GROUP BY student_id",
        $student_ids
    );

    foreach ($returned as $r) {
        $id = (int) $r['student_id'];
        if (isset($out[$id])) {
            $out[$id]['assessed']  = (float) $r['assessed'];
            $out[$id]['collected'] = (float) $r['collected'];
            $out[$id]['pending']   = (float) $r['pending'];
        }
    }

    // 2. Books still out, whose fines are accrued live
    $live = db_all(
        "SELECT student_id, return_date
           FROM issued_books
          WHERE status = 'issued' AND student_id IN ($in)",
        $student_ids
    );

    foreach ($live as $r) {
        $id = (int) $r['student_id'];
        if (!isset($out[$id])) {
            continue;
        }
        $calc = calculate_fine($r['return_date'], null);
        if ($calc['overdue_days'] > 0) {
            $out[$id]['overdue']++;
            $out[$id]['assessed'] += $calc['fine'];
            $out[$id]['pending']   += $calc['fine'];
        }
    }

    foreach ($out as $id => $v) {
        $out[$id]['assessed']  = round($v['assessed'], 2);
        $out[$id]['collected'] = round($v['collected'], 2);
        $out[$id]['pending']   = round($v['pending'], 2);
    }

    return $out;
}

/* ============================================================
   WRITES
   ============================================================ */

/**
 * Assess and store the final fine for a book being returned.
 * MUST be called inside the return transaction, while the row is still
 * status = 'issued'.
 *
 * The UPDATE is guarded by status = 'issued'. That guard is what makes the fine
 * a genuinely one-time event: a loan that has already been returned is never
 * re-assessed, so its collected amount can never be reset to 0 by a second call.
 *
 * @return array the fine numbers plus 'assessed' (bool), for the flash message
 */
function save_fine_on_return($issued_book_id, $due_date, $returned_at)
{
    $calc = calculate_fine($due_date, $returned_at);
    [$status] = fine_status_parts($calc['fine'], 0.0);

    $stmt = db_query(
        "UPDATE issued_books
            SET fine_days = ?, fine_amount = ?, fine_paid = 0.00, fine_status = ?
          WHERE id = ? AND status = 'issued'",
        [$calc['overdue_days'], $calc['fine'], $status, $issued_book_id]
    );

    // rowCount() of 0 means the loan was not 'issued', so nothing was written.
    $calc['assessed'] = ($stmt->rowCount() === 1);

    return $calc;
}

/**
 * Record a payment against a fine and move its status on.
 *
 * @param float  $amount   money being handed over now
 * @param string $method   Cash / Card / UPI / Online
 * @param string $note     optional note
 * @param bool   $settle   true = pay whatever is outstanding
 * @return array{ok:bool, message:string}
 */
function record_fine_payment($issued_book_id, $amount, $method = 'Cash', $note = null, $settle = false)
{
    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Lock the row so two librarians cannot both read the same fine_paid
        // and one payment overwrite the other.
        $loan = db_one(
            "SELECT * FROM issued_books WHERE id = ? AND status = 'returned' FOR UPDATE",
            [$issued_book_id]
        );

        if (!$loan) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That loan is not available for payment.'];
        }

    $amount      = round((float) $amount, 2);
    $outstanding = round((float) $loan['fine_amount'] - (float) $loan['fine_paid'], 2);

    // The columns are narrow, so never let a crafted value reach the INSERT
    $method = in_array($method, ['Cash', 'Card', 'UPI', 'Online'], true) ? $method : 'Cash';
    $note   = ($note === null || $note === '') ? null : str_param($note, 255);

        if ($outstanding <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This fine is already fully paid.'];
        }

        // "Mark as paid" hands over the whole outstanding balance
        if ($settle) {
            $amount = $outstanding;
        }

        if ($amount <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Enter a payment amount greater than zero.'];
        }
        if ($amount > $outstanding) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Payment cannot be more than the outstanding ' . rupees_text($outstanding) . '.'];
        }

        db_query(
            'INSERT INTO fine_payments (issued_book_id, amount, payment_date, payment_method, note)
             VALUES (?, ?, CURDATE(), ?, ?)',
            [$issued_book_id, $amount, $method, $note]
        );

        $new_paid = round((float) $loan['fine_paid'] + $amount, 2);

        // fine_status is an ENUM, so store the status KEY, not the label
        [$status, $label] = fine_status_parts((float) $loan['fine_amount'], $new_paid);

        db_query('UPDATE issued_books SET fine_paid = ?, fine_status = ? WHERE id = ?',
                 [$new_paid, $status, $issued_book_id]);

        $pdo->commit();

        return ['ok' => true, 'message' => 'Collected ' . rupees_text($amount) . '. Fine is now ' . $label . '.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Payment failed: ' . $e->getMessage()];
    }
}

/** Update the fine configuration from the settings form. */
function save_fine_settings($per_day, $enabled, $grace_days, $max_amount)
{
    $values = [
        'fine_per_day'    => number_format((float) $per_day, 2),
        'fine_enabled'    => $enabled ? '1' : '0',
        'fine_grace_days' => (string) (int) $grace_days,
        'fine_max_amount' => number_format((float) $max_amount, 2),
    ];

    foreach ($values as $key => $value) {
        // REPLACE keeps this working whether or not the row already exists
        db_query('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
    }

    // Drop the cached copy so this request sees what was just saved
    fine_settings(true);
}
