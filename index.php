<?php
/**
 * index.php - ADMIN DASHBOARD
 * Library statistics + recent activity.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

/* ---------- Gather statistics ---------- */
$total_books     = (int) db_val('SELECT COUNT(*) FROM books');
$total_students  = (int) db_val('SELECT COUNT(*) FROM students');
$issued_count_db = (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'issued'");
$returned_count  = (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'returned'");
$available_total = (int) db_val('SELECT COALESCE(SUM(available_quantity), 0) FROM books');
$overdue_count   = (int) db_val(
    "SELECT COUNT(*) FROM issued_books WHERE status = 'issued' AND return_date < CURDATE()"
);
$total_copies    = (int) db_val('SELECT COALESCE(SUM(quantity), 0) FROM books');

// Most recently added books
$recent_books = db_all('SELECT * FROM books ORDER BY added_date DESC, id DESC LIMIT 5');

// ---- FINE DATA ----
$fine_cfg     = fine_settings();
$fine_summary = fines_summary();

// Loans that carry a fine, worst first: still-out (live) then unpaid returns
$FINE_ROW_LIMIT = 25;
$fine_rows = db_all(
    "SELECT ib.*, b.title, b.isbn, s.id AS student_id, s.name AS student_name
       FROM issued_books ib
       JOIN books b    ON b.id = ib.book_id
       JOIN students s ON s.id = ib.student_id
      WHERE ib.status = 'issued' OR ib.fine_amount > 0
      ORDER BY ib.return_date ASC, ib.id ASC
      LIMIT " . ($FINE_ROW_LIMIT + 1)
);

// Only keep the rows that actually carry a fine
$fine_items = [];
foreach ($fine_rows as $row) {
    $f = loan_fine($row);
    if ($f['amount'] > 0 || $f['days'] > 0) {
        $row['fine'] = $f;
        $fine_items[] = $row;
    }
}

// We asked for one row more than we show, so we can tell the librarian that
// the table is only the worst N rather than silently cutting the list off.
$fine_truncated = count($fine_items) > $FINE_ROW_LIMIT;
if ($fine_truncated) {
    array_pop($fine_items);
}

// Due-soon reminders for books that are not late yet (next 3 days)
$due_soon = [];
foreach (db_all("SELECT ib.*, b.title FROM issued_books ib JOIN books b ON b.id = ib.book_id
                  WHERE ib.status = 'issued' ORDER BY ib.return_date ASC") as $row) {
    $left = days_left($row['return_date']);
    if ($left >= 0 && $left <= 3) {
        $due_soon[] = $row;
    }
}

// Currently issued books (newest first, overdue highlighted)
$recent_issues = db_all(
    "SELECT ib.*, b.title, b.author, s.name AS student_name
       FROM issued_books ib
       JOIN books b    ON b.id = ib.book_id
       JOIN students s ON s.id = ib.student_id
      WHERE ib.status = 'issued'
      ORDER BY ib.return_date ASC
      LIMIT 6"
);

/* ---------- Page chrome ---------- */
$current_page  = 'index.php';
$page_title    = 'Dashboard';
$page_subtitle = 'Library overview at a glance';
$page_actions  = '<a href="fines.php" class="btn btn-primary btn-sm"><i class="bi bi-cash-coin me-1"></i> Fines</a>'
               . '<a href="issue_book.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i> Issue Book</a>'
               . '<a href="add_book.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus-lg me-1"></i> Add Book</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===================== STAT CARDS ===================== -->
<div class="row g-3 mb-4">
    <?php
    // label, value, icon, colour, small note
    $stats = [
        ['Total Books',     $total_books,    'collection',           '#2563eb', $total_copies . ' total copies'],
        ['Total Students',  $total_students, 'people',               '#0d9488', 'registered members'],
        ['Issued Books',    $issued_count_db,'box-arrow-up-right',   '#f59e0b', $overdue_count . ' overdue'],
        ['Returned Books',  $returned_count, 'box-arrow-in-down-left', '#16a34a', 'completed returns'],
        ['Available Books', $available_total, 'check2-circle',        '#7c3aed', 'ready to issue'],
        ['Overdue',         $overdue_count,  'exclamation-triangle', '#dc2626', 'past due date'],
    ];

    foreach ($stats as $s):
    ?>
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card stat-card h-100" style="--stat-color: <?= $s[3] ?>">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon"><i class="bi bi-<?= $s[2] ?>"></i></span>
                    <div>
                        <div class="stat-value"><?= $s[1] ?></div>
                        <div class="stat-label"><?= e($s[0]) ?></div>
                        <div class="small text-muted"><?= e($s[4]) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ===================== NOTIFICATIONS ===================== -->
<?php if (!$fine_cfg['enabled']): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2">
        <i class="bi bi-pause-circle-fill"></i>
        <div class="flex-grow-1">Late fines are switched off in
            <a href="fine_settings.php" class="alert-link">Fine Settings</a>.</div>
    </div>
<?php endif; ?>

<?php if ($fine_items || $due_soon): ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bell me-2"></i>Notifications</span>
            <span class="badge text-bg-secondary"><?= count($fine_items) + count($due_soon) ?></span>
        </div>
        <ul class="list-group list-group-flush notify-list">
            <?php foreach ($fine_items as $r):
                $f = $r['fine']; ?>
                <li class="list-group-item d-flex align-items-start gap-2 notify-item notify-<?= $f['live'] ? 'danger' : 'warning' ?>">
                    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                    <div class="flex-grow-1">
                        <?php if ($f['live']): ?>
                            <?= e(fine_notification_text($r['student_name'], $r['title'], $f['days'], $f['amount'], $fine_cfg['per_day'])) ?>
                            <span class="badge text-bg-info ms-1">still out</span>
                        <?php else: ?>
                            <?= e($r['student_name']) ?>'s copy of "<?= e($r['title']) ?>" was
                            <?= $f['days'] ?> day(s) late. Fine <?= rupees($f['amount']) ?>
                            &mdash; <?= rupees($f['remaining']) ?> still unpaid.
                        <?php endif; ?>
                    </div>
                    <a href="student_fines.php?id=<?= (int) $r['student_id'] ?>" class="btn btn-sm btn-outline-secondary">
                        View
                    </a>
                </li>
            <?php endforeach; ?>

            <?php foreach ($due_soon as $r): ?>
                <li class="list-group-item d-flex align-items-start gap-2 notify-item notify-info">
                    <i class="bi bi-alarm-fill mt-1"></i>
                    <div class="flex-grow-1">
                        "<?= e($r['title']) ?>" is due on <?= fmt_date($r['return_date']) ?>.
                        <?php if ($fine_cfg['enabled']): ?>
                            A fine of <?= rupees($fine_cfg['per_day']) ?> per day starts the day after.
                        <?php endif; ?>
                    </div>
                    <span class="badge text-bg-<?= days_left($r['return_date']) === 0 ? 'warning' : 'info' ?>">
                        <?= days_left($r['return_date']) === 0 ? 'Due today' : days_left($r['return_date']) . 'd left' ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ===================== FINE SUMMARY ===================== -->
<div class="row g-3 mb-4">
    <?php
    $fine_cards = [
        ['Fines Assessed',  $fine_summary['assessed'],  'receipt',         '#2563eb', 'Total raised'],
        ['Collected',       $fine_summary['collected'], 'check-circle',    '#16a34a', 'Payments received'],
        ['Pending Fines',   $fine_summary['pending'],   'hourglass-split', '#dc2626', 'Still to collect'],
        ['Rate',            $fine_cfg['per_day'],      'currency-rupee',  '#7c3aed', 'per overdue day'],
    ];
    foreach ($fine_cards as $c): ?>
        <div class="col-6 col-xl-3">
            <div class="card stat-card h-100" style="--stat-color:<?= $c[3] ?>">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon"><i class="bi bi-<?= $c[2] ?>"></i></span>
                    <div>
                        <div class="stat-value"><?= rupees($c[1]) ?></div>
                        <div class="stat-label"><?= e($c[0]) ?></div>
                        <div class="small text-muted"><?= e($c[4]) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ===================== TABLES ===================== -->
<div class="row g-3">

    <!-- Currently issued books -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hourglass-split me-2"></i>Currently Issued</span>
                <a href="return_book.php" class="btn btn-sm btn-outline-primary">Process Returns</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr><th>Book</th><th>Student</th><th>Due</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$recent_issues): ?>
                        <tr><td colspan="4" class="empty-state">
                            <i class="bi bi-inbox"></i>No books are currently issued.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recent_issues as $r):
                        $left = days_left($r['return_date']); ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-truncate-2"><?= e($r['title']) ?></div>
                                <div class="small text-muted"><?= e($r['author']) ?></div>
                            </td>
                            <td class="small"><?= e($r['student_name']) ?></td>
                            <td class="small"><?= fmt_date($r['return_date']) ?></td>
                            <td>
                                <?php if ($left < 0): ?>
                                    <span class="badge text-bg-danger">Overdue <?= abs($left) ?>d</span>
                                <?php elseif ($left === 0): ?>
                                    <span class="badge text-bg-warning">Due today</span>
                                <?php else: ?>
                                    <span class="badge text-bg-info"><?= $left ?>d left</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recently added books -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Recently Added</span>
                <a href="manage_books.php" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <ul class="list-group list-group-flush">
            <?php if (!$recent_books): ?>
                <li class="list-group-item empty-state">
                    <i class="bi bi-inbox"></i>No books in the catalogue yet.</li>
            <?php endif; ?>
            <?php foreach ($recent_books as $b): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold text-truncate-2"><?= e($b['title']) ?></div>
                        <div class="small text-muted"><?= e($b['author']) ?> &middot; <?= e($b['category']) ?></div>
                    </div>
                    <div class="text-end"><?= stock_badge($b['available_quantity'], $b['quantity']) ?></div>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<!-- ===================== FINE DETAIL ===================== -->
<div class="card mt-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-cash-coin me-2"></i>Fine Detail</span>
        <div class="d-flex gap-2">
            <?php if ($fine_summary['pending'] > 0): ?>
                <span class="badge text-bg-danger"><?= rupees($fine_summary['pending']) ?> pending</span>
            <?php endif; ?>
            <a href="fines.php" class="btn btn-sm btn-outline-primary">Open Fines &amp; Payments</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Student / Member</th>
                    <th>Book</th>
                    <th>Book ID</th>
                    <th>Due Date</th>
                    <th>Return Date</th>
                    <th class="text-center">Overdue Days</th>
                    <th class="text-end">Fine</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Remaining</th>
                    <th>Fine Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$fine_items): ?>
                <tr><td colspan="10" class="empty-state">
                    <i class="bi bi-emoji-smile"></i>No late returns and no pending fines. All clear.
                </td></tr>
            <?php endif; ?>

            <?php foreach ($fine_items as $r):
                $f = $r['fine']; ?>
                <tr<?= $f['days'] > 0 ? ' class="table-warning"' : '' ?>>
                    <td>
                        <a href="student_fines.php?id=<?= (int) $r['student_id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($r['student_name']) ?></a>
                    </td>
                    <td><div class="text-truncate-2"><?= e($r['title']) ?></div></td>
                    <td class="small text-muted">#<?= (int) $r['book_id'] ?></td>
                    <td class="small"><?= fmt_date($r['return_date']) ?></td>
                    <td class="small">
                        <?php if (!empty($r['returned_at'])): ?>
                            <?= fmt_date($r['returned_at']) ?>
                        <?php else: ?>
                            <span class="text-muted">Not returned</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($f['days'] > 0): ?>
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
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($fine_truncated): ?>
        <div class="card-footer bg-white small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Showing the <?= $FINE_ROW_LIMIT ?> most urgent rows.
            <a href="fines.php">See every fine and collect a payment</a>.
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
