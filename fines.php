<?php
/**
 * fines.php - Late fine report and payment collection
 * Search by student or book, filter by payment state, take payments.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

/* ============================================================
   POST: collect a payment (full or partial)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    check_csrf();

    $loan_id = int_param($_POST['issued_book_id'] ?? 0);
    $settle  = !empty($_POST['settle']);                 // "Mark as Paid" button
    $method  = $_POST['payment_method'] ?? 'Cash';
    $note    = str_param($_POST['note'] ?? '', 255);

    // A crafted POST must not be able to crash the payment path
    $amount = is_scalar($_POST['amount'] ?? null) ? (float) $_POST['amount'] : 0.0;

    if (!in_array($method, ['Cash', 'Card', 'UPI', 'Online'], true)) {
        $method = 'Cash';
    }

    $result = record_fine_payment($loan_id, $amount, $method, $note ?: null, $settle);

    flash($result['ok'] ? 'success' : 'danger', $result['message']);

    // Keep the librarian where they were
    $back = 'fines.php';
    foreach (['q', 'status'] as $key) {
        $v = str_param($_POST[$key] ?? '', 100);
        if ($v !== '') {
            $back .= '&' . $key . '=' . urlencode($v);
        }
    }
    redirect($back);
}

/* ============================================================
   FILTERS
   ============================================================ */
$q      = str_param($_GET['q'] ?? '', 100);
$status = str_param($_GET['status'] ?? 'all', 20);
if (!in_array($status, ['all', 'pending', 'unpaid', 'partial', 'paid', 'none', 'overdue', 'live'], true)) {
    $status = 'all';
}

$sql = "SELECT ib.*, b.title, b.author, b.isbn,
               s.name AS student_name, s.email AS student_email, s.phone AS student_phone
          FROM issued_books ib
          JOIN books b    ON b.id = ib.book_id
          JOIN students s ON s.id = ib.student_id";

$params = [];

if ($q !== '') {
    // Search across student AND book details in one box
    $sql     .= ' WHERE (s.name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?
                         OR b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?
                         OR CAST(ib.book_id AS CHAR) = ?)';
    $like     = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like; $params[] = $like;
    $params[] = $q;
}

// Payment-state filters run on the stored columns (returned loans are final).
// Loans that are still out are NOT in those columns yet: their fine is accruing
// live and fine_status is still 'none', so they must be matched on the due date
// instead, otherwise these filters would silently hide money that is owed.
switch ($status) {
    case 'unpaid':
        $sql .= " AND ((ib.status = 'returned' AND ib.fine_status = 'unpaid')
                       OR (ib.status = 'issued'    AND ib.return_date < CURDATE()))";
        break;
    case 'partial': $sql .= " AND ib.status = 'returned' AND ib.fine_status = 'partial'"; break;
    case 'paid':    $sql .= " AND ib.status = 'returned' AND ib.fine_status = 'paid'";    break;
    case 'none':    $sql .= " AND ib.status = 'returned' AND ib.fine_status = 'none'";    break;
    case 'pending':
        // "Anything unpaid" = a returned loan with a balance left, or a book that
        // is still out and already past its due date.
        $sql .= " AND ((ib.status = 'returned' AND ib.fine_amount > ib.fine_paid)
                       OR (ib.status = 'issued'    AND ib.return_date < CURDATE()))";
        break;
    case 'overdue': $sql .= " AND ib.status = 'issued' AND ib.return_date < CURDATE()";   break;
    case 'live':    $sql .= " AND ib.status = 'issued'";                                   break;
}

// These filters are about money owed, so the exact figure is worked out in PHP
// and rows that come to nil (e.g. still inside the grace period) are dropped.
$money_only = ($status === 'pending' || $status === 'unpaid');

$sql .= ' ORDER BY ib.return_date ASC, ib.id ASC';
$loans = db_all($sql, $params);

/* ============================================================
   WORK OUT EVERY FINE FOR THE RESULT SET
   ============================================================ */
$rows = [];
$t_display = ['assessed' => 0.0, 'collected' => 0.0, 'pending' => 0.0, 'late' => 0];

foreach ($loans as $l) {
    $f = loan_fine($l);

    if ($money_only && $f['remaining'] <= 0) {
        continue;
    }

    $l['fine'] = $f;                 // attach for the template
    $rows[]    = $l;

    $t_display['assessed']  += $f['amount'];
    $t_display['collected'] += $f['paid'];
    $t_display['pending']   += $f['remaining'];
    if ($f['days'] > 0) {
        $t_display['late']++;
    }
}

foreach ($t_display as $k => $v) {
    $t_display[$k] = is_int($v) ? $v : round($v, 2);
}

// Overall figures across the whole library, independent of the filter
$overall = fines_summary();
$cfg     = fine_settings();

/* ============================================================
   PAGE
   ============================================================ */
$current_page  = 'fines.php';
$page_title    = 'Fines & Payments';
$page_subtitle = 'Late return fines at ' . rupees($cfg['per_day']) . ' per day'
               . ($cfg['enabled'] ? '' : ' - FINES CURRENTLY DISABLED')
               . ($cfg['grace_days'] > 0 ? ' (after ' . $cfg['grace_days'] . ' grace day(s))' : '')
               . ($cfg['max_amount'] > 0 ? ' (capped at ' . rupees($cfg['max_amount']) . ')' : '');
$page_actions  = '<a href="fine_settings.php" class="btn btn-outline-secondary btn-sm">'
               . '<i class="bi bi-gear me-1"></i> Fine Settings</a>'
               . '<button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm">'
               . '<i class="bi bi-printer me-1"></i> Print</button>';

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$cfg['enabled']): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Fine collection is switched <strong>off</strong>. Fines already
            assessed are kept, but no new fine is being charged.</div>
        <a href="fine_settings.php" class="btn btn-sm btn-primary">Turn on</a>
    </div>
<?php endif; ?>

<!-- ===================== SUMMARY CARDS ===================== -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Total Assessed',  $overall['assessed'],  'cash-stack',      '#2563eb', 'All fines raised'],
        ['Total Collected', $overall['collected'], 'check-circle',    '#16a34a', 'Payments received'],
        ['Total Pending',   $overall['pending'],   'hourglass-split', '#dc2626', 'Still to be collected'],
        ['Overdue Books',   $overall['overdue'],   'exclamation-triangle', '#f59e0b', 'Fine accruing now'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100" style="--stat-color:<?= $c[3] ?>">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon"><i class="bi bi-<?= $c[2] ?>"></i></span>
                    <div>
                        <div class="stat-value"><?= is_int($c[1]) ? $c[1] : rupees($c[1]) ?></div>
                        <div class="stat-label"><?= e($c[0]) ?></div>
                        <div class="small text-muted"><?= e($c[4]) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ===================== FILTERS ===================== -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-5">
                <label class="form-label" for="q"><i class="bi bi-search me-1"></i>Search</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>"
                       placeholder="Student name, email, phone, book title, ISBN or book ID...">
            </div>
            <div class="col-8 col-md-4 col-lg-3">
                <label class="form-label" for="status">Fine Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="all"     <?= $status === 'all' ? 'selected' : '' ?>>All records</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending (anything unpaid)</option>
                    <option value="unpaid"  <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="paid"    <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="none"    <?= $status === 'none' ? 'selected' : '' ?>>No Fine (on time)</option>
                    <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue (still out)</option>
                    <option value="live"    <?= $status === 'live' ? 'selected' : '' ?>>Still issued</option>
                </select>
            </div>
            <div class="col-4 col-md-2 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
            </div>
            <div class="col-12 col-lg-2 d-grid">
                <a href="fines.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ===================== FINE TABLE ===================== -->
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-cash-coin me-2"></i>Fine Records</span>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge text-bg-secondary"><?= count($rows) ?> record(s)</span>
            <span class="badge text-bg-primary">Shown assessed: <?= rupees($t_display['assessed']) ?></span>
            <span class="badge text-bg-danger">Shown pending: <?= rupees($t_display['pending']) ?></span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Student / Member</th>
                    <th>Book</th>
                    <th>Book ID</th>
                    <th>Issued</th>
                    <th>Due Date</th>
                    <th>Returned</th>
                    <th class="text-center">Overdue Days</th>
                    <th class="text-end">Fine</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Remaining</th>
                    <th>Fine Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="12" class="empty-state">
                    <i class="bi bi-inbox"></i>No fine records match this search.
                </td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $r):
                $f   = $r['fine'];
                $out = $f['days'] > 0;
                ?>
                <tr<?= $out ? ' class="table-warning"' : '' ?>>
                    <td>
                        <a href="student_fines.php?id=<?= (int) $r['student_id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($r['student_name']) ?></a>
                        <div class="small text-muted"><?= e($r['student_email']) ?></div>
                    </td>
                    <td>
                        <div class="text-truncate-2"><?= e($r['title']) ?></div>
                        <div class="small text-muted"><?= e($r['author']) ?></div>
                    </td>
                    <td class="small text-muted">#<?= (int) $r['book_id'] ?></td>
                    <td class="small"><?= fmt_date($r['issue_date']) ?></td>
                    <td class="small"><?= fmt_date($r['return_date']) ?></td>
                    <td class="small">
                        <?php if (!empty($r['returned_at'])): ?>
                            <?= fmt_date($r['returned_at']) ?>
                        <?php else: ?>
                            <span class="text-muted">Not returned</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($out): ?>
                            <span class="badge text-bg-danger"><?= $f['days'] ?></span>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= rupees($f['amount']) ?></td>
                    <td class="text-end text-success"><?= rupees($f['paid']) ?></td>
                    <td class="text-end fw-semibold <?= $f['remaining'] > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?= rupees($f['remaining']) ?>
                    </td>
                    <td><?= fine_badge($f['label'], $f['css']) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($f['live']): ?>
                            <span class="badge text-bg-info" title="Calculated live until the book is returned">
                                <?= $f['amount'] > 0 ? 'Accruing' : 'On loan' ?>
                            </span>
                        <?php elseif ($f['remaining'] > 0): ?>
                            <button class="btn btn-sm btn-success" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#pay<?= (int) $r['id'] ?>"
                                    aria-expanded="false" aria-controls="pay<?= (int) $r['id'] ?>" title="Collect payment">
                                <i class="bi bi-cash"></i> Pay
                            </button>
                        <?php else: ?>
                            <span class="text-muted small">Settled</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Collapsible payment form -->
                <?php if (!$f['live'] && $f['remaining'] > 0): ?>
                <tr class="collapse" id="pay<?= (int) $r['id'] ?>">
                    <td colspan="12" class="bg-light">
                        <form method="post" class="row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="pay">
                            <input type="hidden" name="issued_book_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="q" value="<?= e($q) ?>">
                            <input type="hidden" name="status" value="<?= e($status) ?>">

                            <div class="col-6 col-md-3">
                                <label class="form-label">Outstanding</label>
                                <div class="form-control-plaintext fw-bold text-danger">
                                    <?= rupees($f['remaining']) ?>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label" for="amt<?= (int) $r['id'] ?>">Amount received</label>
                                <input type="number" step="0.01" min="0.01"
                                       max="<?= e($f['remaining']) ?>"
                                       class="form-control form-control-sm" id="amt<?= (int) $r['id'] ?>"
                                       name="amount" value="<?= e($f['remaining']) ?>" required>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Method</label>
                                <select class="form-select form-select-sm" name="payment_method">
                                    <option>Cash</option><option>Card</option>
                                    <option>UPI</option><option>Online</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Note</label>
                                <input type="text" class="form-control form-control-sm" name="note"
                                       placeholder="Optional">
                            </div>
                            <div class="col-12 col-md-2 d-flex gap-2">
                                <button type="submit" name="settle" value="1"
                                        class="btn btn-sm btn-success flex-grow-1" title="Collect the full balance">
                                    <i class="bi bi-check-lg"></i> Settle
                                </button>
                                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                                    Add
                                </button>
                            </div>
                        </form>
                        <div class="small text-muted mt-1">
                            <i class="bi bi-info-circle me-1"></i>
                            "Add" records exactly the amount typed (for a part payment).
                            "Settle" collects the whole <?= rupees($f['remaining']) ?> balance.
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>

            <?php if ($rows): ?>
            <tfoot class="table-light">
                <tr class="fw-semibold">
                    <td colspan="7">Totals for this view</td>
                    <td class="text-end"><?= rupees($t_display['assessed']) ?></td>
                    <td class="text-end text-success"><?= rupees($t_display['collected']) ?></td>
                    <td class="text-end text-danger"><?= rupees($t_display['pending']) ?></td>
                    <td colspan="3" class="small text-muted"><?= $t_display['late'] ?> late record(s)</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <div class="card-footer bg-white small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Rows marked <span class="badge text-bg-warning">warning</span> are late.
        A fine is worked out once when the book is returned and then frozen;
        for books still out the figure shown is recalculated live and is not stored.
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
