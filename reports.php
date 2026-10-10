<?php
/**
 * reports.php - Issued / Returned / Overdue reports + summary
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$q = str_param($_GET['q'] ?? '', 200);

// Shared WHERE fragment for the search box (title or student name)
$filter_sql  = '';
$filter_bind = [];
if ($q !== '') {
    $filter_sql  = ' AND (b.title LIKE ? OR s.name LIKE ? OR s.email LIKE ?)';
    $like        = '%' . $q . '%';
    $filter_bind = [$like, $like, $like];
}

/* ---------- Headline numbers ---------- */
$r_issued   = (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'issued'");
$r_returned = (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'returned'");
$r_overdue  = (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'issued' AND return_date < CURDATE()");
$r_due7     = (int) db_val("SELECT COUNT(*) FROM issued_books
                            WHERE status = 'issued' AND return_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$r_books    = (int) db_val('SELECT COUNT(*) FROM books');
$r_students = (int) db_val('SELECT COUNT(*) FROM students');

// Fine totals for the headline cards
$fine_summary = fines_summary();

/* ---------- Report: CURRENTLY ISSUED ---------- */
$issued_rows = db_all(
    "SELECT ib.*, b.title, b.author, s.name AS student_name, s.email AS student_email
       FROM issued_books ib
       JOIN books b    ON b.id = ib.book_id
       JOIN students s ON s.id = ib.student_id
      WHERE ib.status = 'issued'" . $filter_sql . "
      ORDER BY ib.return_date ASC",
    $filter_bind
);

/* ---------- Report: RETURNED ---------- */
$returned_rows = db_all(
    "SELECT ib.*, b.title, b.author, s.name AS student_name, s.email AS student_email
       FROM issued_books ib
       JOIN books b    ON b.id = ib.book_id
       JOIN students s ON s.id = ib.student_id
      WHERE ib.status = 'returned'" . $filter_sql . "
      ORDER BY ib.returned_at DESC, ib.id DESC
      LIMIT 200",
    $filter_bind
);

/* ---------- Report: OVERDUE ---------- */
$overdue_rows = db_all(
    "SELECT ib.*, b.title, b.author, s.name AS student_name, s.email AS student_email,
            DATEDIFF(CURDATE(), ib.return_date) AS days_overdue
       FROM issued_books ib
       JOIN books b    ON b.id = ib.book_id
       JOIN students s ON s.id = ib.student_id
      WHERE ib.status = 'issued' AND ib.return_date < CURDATE()" . $filter_sql . "
      ORDER BY days_overdue DESC",
    $filter_bind
);

/* ---------- Extra: fine report ---------- */
$fine_cfg     = fine_settings();
$fine_summary = fines_summary();

// Every loan that carries a fine, plus the shared search filter
$fine_rows = db_all(
    "SELECT ib.*, b.title, b.isbn, s.name AS student_name, s.email AS student_email
       FROM issued_books ib
       JOIN books b    ON b.id = ib.book_id
       JOIN students s ON s.id = ib.student_id
      WHERE 1 = 1" . $filter_sql . "
      ORDER BY (ib.fine_amount + (ib.status = 'issued')) DESC, ib.return_date ASC",
    $filter_bind
);

$fine_items = [];
foreach ($fine_rows as $r) {
    $f = loan_fine($r);
    if ($f['amount'] > 0) {
        $r['fine'] = $f;
        $fine_items[] = $r;
    }
}

/* ---------- Extra: most issued titles ---------- */
$top_books = db_all(
    "SELECT b.title, b.author, COUNT(ib.id) AS times
       FROM issued_books ib
       JOIN books b ON b.id = ib.book_id
      GROUP BY b.id, b.title, b.author
      ORDER BY times DESC, b.title ASC
      LIMIT 5"
);

/* ---------- Extra: category split ---------- */
$by_category = db_all(
    'SELECT COALESCE(category, "Uncategorised") AS category,
            COUNT(*) AS titles,
            COALESCE(SUM(quantity), 0) AS copies
       FROM books GROUP BY category ORDER BY titles DESC'
);

$current_page  = 'reports.php';
$page_title    = 'Reports';
$page_subtitle = 'Circulation, returns and overdue tracking';
$page_actions  = '<button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                      <i class="bi bi-printer me-1"></i> Print
                  </button>';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===================== SUMMARY CARDS ===================== -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Issued (active)', $r_issued,   'box-arrow-up-right',      '#f59e0b', 'Currently with students'],
        ['Returned',         $r_returned, 'box-arrow-in-down-left', '#16a34a', 'Completed loans'],
        ['Overdue',          $r_overdue,  'exclamation-triangle-fill', '#dc2626', 'Past the due date'],
        ['Due in 7 days',    $r_due7,     'clock',                  '#0ea5e9', 'Upcoming returns'],
        ['Titles',           $r_books,    'collection',             '#2563eb', 'In the catalogue'],
        ['Students',         $r_students, 'people',                 '#0d9488', 'Registered members'],
        ['Fines Pending',    $fine_summary['pending'],   'hourglass-split', '#dc2626', 'To be collected'],
        ['Fines Collected',  $fine_summary['collected'], 'check-circle',    '#16a34a', 'Received to date'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-12 col-sm-6 col-xl-4">
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

<!-- ===================== SEARCH ===================== -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-8 col-lg-6">
                <label class="form-label" for="q"><i class="bi bi-search me-1"></i>Filter reports</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>"
                       placeholder="Book title, student name or email...">
            </div>
            <div class="col-8 col-md-3 col-lg-3 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Apply</button>
            </div>
            <div class="col-4 col-md-1 col-lg-3 d-grid">
                <a href="reports.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ===================== TABBED TABLES ===================== -->
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs flex-nowrap overflow-auto" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-issued" type="button" role="tab">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Issued (<?= count($issued_rows) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-returned" type="button" role="tab">
                    <i class="bi bi-box-arrow-in-down-left me-1"></i> Returned (<?= count($returned_rows) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-overdue" type="button" role="tab">
                    <i class="bi bi-exclamation-triangle me-1"></i> Overdue (<?= count($overdue_rows) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-fines" type="button" role="tab">
                    <i class="bi bi-cash-coin me-1"></i> Fines (<?= count($fine_items) ?>)
                </button>
            </li>
        </ul>
    </div>

    <div class="tab-content">

        <!-- ---------- ISSUED ---------- -->
        <div class="tab-pane fade show active" id="tab-issued" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>#</th><th>Book</th><th>Student</th><th>Issue Date</th><th>Due Date</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$issued_rows): ?>
                        <tr><td colspan="6" class="empty-state"><i class="bi bi-inbox"></i>No issued books.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($issued_rows as $r):
                        $left = days_left($r['return_date']); ?>
                        <tr>
                            <td class="text-muted"><?= (int) $r['id'] ?></td>
                            <td><div class="fw-semibold"><?= e($r['title']) ?></div>
                                <div class="small text-muted"><?= e($r['author']) ?></div></td>
                            <td><div><?= e($r['student_name']) ?></div>
                                <div class="small text-muted"><?= e($r['student_email']) ?></div></td>
                            <td class="small"><?= fmt_date($r['issue_date']) ?></td>
                            <td class="small"><?= fmt_date($r['return_date']) ?></td>
                            <td>
                                <?php if ($left < 0): ?>
                                    <span class="badge text-bg-danger">Overdue</span>
                                <?php else: ?>
                                    <span class="badge text-bg-info">On loan</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---------- RETURNED ---------- -->
        <div class="tab-pane fade" id="tab-returned" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>#</th><th>Book</th><th>Student</th><th>Issue Date</th>
                        <th>Due Date</th><th>Returned On</th><th>Result</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$returned_rows): ?>
                        <tr><td colspan="7" class="empty-state"><i class="bi bi-inbox"></i>No returned books.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($returned_rows as $r):
                        // Returned on time when the actual date is on/before the due date
                        $late = (!empty($r['returned_at']) && $r['returned_at'] > $r['return_date']); ?>
                        <tr>
                            <td class="text-muted"><?= (int) $r['id'] ?></td>
                            <td><div class="fw-semibold"><?= e($r['title']) ?></div>
                                <div class="small text-muted"><?= e($r['author']) ?></div></td>
                            <td><div><?= e($r['student_name']) ?></div>
                                <div class="small text-muted"><?= e($r['student_email']) ?></div></td>
                            <td class="small"><?= fmt_date($r['issue_date']) ?></td>
                            <td class="small"><?= fmt_date($r['return_date']) ?></td>
                            <td class="small"><?= fmt_date($r['returned_at'] ?? null) ?></td>
                            <td>
                                <?php if ($late): ?>
                                    <span class="badge text-bg-warning">Late</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">On time</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---------- OVERDUE ---------- -->
        <div class="tab-pane fade" id="tab-overdue" role="tabpanel">
            <?php if ($overdue_rows): ?>
                <div class="alert alert-danger rounded-0 border-0 mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><strong><?= count($overdue_rows) ?> overdue book(s).</strong>
                    Follow up with the students listed below.</div>
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>#</th><th>Book</th><th>Student</th><th>Due Date</th>
                        <th>Days Overdue</th><th>Contact</th><th class="text-end">Action</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$overdue_rows): ?>
                        <tr><td colspan="7" class="empty-state">
                            <i class="bi bi-check2-circle"></i>No overdue books. All clear!
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($overdue_rows as $r): ?>
                        <tr class="table-danger">
                            <td class="text-muted"><?= (int) $r['id'] ?></td>
                            <td><div class="fw-semibold"><?= e($r['title']) ?></div>
                                <div class="small text-muted"><?= e($r['author']) ?></div></td>
                            <td><div><?= e($r['student_name']) ?></div>
                                <div class="small text-muted"><?= e($r['student_email']) ?></div></td>
                            <td class="small"><?= fmt_date($r['return_date']) ?></td>
                            <td><span class="badge text-bg-danger"><?= (int) $r['days_overdue'] ?> day(s)</span></td>
                            <td class="small text-muted"><?= e($r['student_email']) ?></td>
                            <td class="text-end">
                                <a href="return_book.php?q=<?= urlencode($r['student_name']) ?>"
                                   class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-box-arrow-in-down-left"></i> Return
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ---------- FINES ---------- -->
        <div class="tab-pane fade" id="tab-fines" role="tabpanel">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="small">
                    Rate <strong><?= rupees($fine_cfg['per_day']) ?></strong> per overdue day
                    <?= $fine_cfg['grace_days'] > 0 ? '(after ' . $fine_cfg['grace_days'] . ' grace day(s))' : '' ?>
                    <?= $fine_cfg['max_amount'] > 0 ? '(max ' . rupees($fine_cfg['max_amount']) . ')' : '' ?>.
                    Collected <strong class="text-success"><?= rupees($fine_summary['collected']) ?></strong>,
                    pending <strong class="text-danger"><?= rupees($fine_summary['pending']) ?></strong>.
                </div>
                <a href="fines.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-cash-coin me-1"></i> Fines &amp; Payments
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>Student</th><th>Book</th><th>Book ID</th>
                        <th>Due</th><th>Returned</th><th class="text-center">Days Late</th>
                        <th class="text-end">Fine</th><th class="text-end">Paid</th>
                        <th class="text-end">Remaining</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$fine_items): ?>
                        <tr><td colspan="10" class="empty-state">
                            <i class="bi bi-emoji-smile"></i>No fines on record.
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($fine_items as $r):
                        $f = $r['fine']; ?>
                        <tr<?= $f['days'] > 0 ? ' class="table-warning"' : '' ?>>
                            <td><?= e($r['student_name']) ?>
                                <div class="small text-muted"><?= e($r['student_email']) ?></div></td>
                            <td><div class="text-truncate-2"><?= e($r['title']) ?></div></td>
                            <td class="small text-muted">#<?= (int) $r['book_id'] ?></td>
                            <td class="small"><?= fmt_date($r['return_date']) ?></td>
                            <td class="small">
                                <?= !empty($r['returned_at']) ? fmt_date($r['returned_at']) : '<span class="text-muted">Not returned</span>' ?>
                            </td>
                            <td class="text-center"><?= $f['days'] ?></td>
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
        </div>
    </div>
</div>

<!-- ===================== EXTRA INSIGHTS ===================== -->
<div class="row g-3 mt-1">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-trophy me-2"></i>Most Issued Titles</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>Book</th><th class="text-end">Times Issued</th></tr></thead>
                    <tbody>
                    <?php if (!$top_books): ?>
                        <tr><td colspan="3" class="empty-state">No issues recorded.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($top_books as $i => $t): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><div class="fw-semibold"><?= e($t['title']) ?></div>
                                <div class="small text-muted"><?= e($t['author']) ?></div></td>
                            <td class="text-end"><span class="badge text-bg-primary"><?= (int) $t['times'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Catalogue by Category</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Category</th><th class="text-end">Titles</th><th class="text-end">Copies</th></tr></thead>
                    <tbody>
                    <?php if (!$by_category): ?>
                        <tr><td colspan="3" class="empty-state">No books yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($by_category as $c): ?>
                        <tr>
                            <td><?= e($c['category']) ?></td>
                            <td class="text-end"><?= (int) $c['titles'] ?></td>
                            <td class="text-end"><?= (int) $c['copies'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
