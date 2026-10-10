<?php
/**
 * return_book.php - Mark issued books as returned
 * Sets status = 'returned', stamps returned_at and restores available stock.
 * Supports returning one book or a bulk selection.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

/* ============================================================
   POST: return one or many books
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return') {
    check_csrf();

    // "Return Selected" submits bulk=1 + ids[]; a row button submits id=<id>
    // The pressed button decides which set of ids is used.
    $raw_ids = isset($_POST['bulk'])
        ? ($_POST['ids'] ?? [])
        : (isset($_POST['id']) ? [$_POST['id']] : []);

    // Drop anything that is not a plain integer. intval() on an array yields 1,
    // so a nested ids[][]=1 would otherwise return somebody's book for them.
    $ids = [];
    foreach ((array) $raw_ids as $candidate) {
        $n = int_param($candidate);
        if ($n > 0) {
            $ids[] = $n;
        }
    }
    $ids = array_values(array_unique($ids));

    if (!$ids) {
        flash('warning', 'Please select at least one book to return.');
        redirect('return_book.php');
    }

    $pdo = db();
    $done = 0;
    $fines = [];   // fine summary per returned loan, for the flash message

    try {
        $pdo->beginTransaction();

        // Lock the row first, and read the due date so the fine can be worked out
        $find  = $pdo->prepare('SELECT id, book_id, return_date FROM issued_books
                                 WHERE id = ? AND status = \'issued\' FOR UPDATE');
        $close = $pdo->prepare('UPDATE issued_books SET status = \'returned\', returned_at = CURDATE() WHERE id = ?');
        $stock = $pdo->prepare('UPDATE books SET available_quantity = available_quantity + 1 WHERE id = ?');

        foreach ($ids as $id) {
            $find->execute([$id]);
            $row = $find->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                continue; // already returned or does not exist
            }

            // Assess the fine ONCE, while the row is still status = 'issued'.
            // This must happen BEFORE the loan is closed, because
            // save_fine_on_return() only writes to a loan that is still issued.
            // The row is already locked above, so nothing else can touch it.
            $calc = save_fine_on_return($id, $row['return_date'], date('Y-m-d'));

            $close->execute([$id]);
            $stock->execute([$row['book_id']]);

            if ($calc['assessed']) {
                $fines[] = $calc;
            }
            $done++;
        }

        $pdo->commit();

        // Build the flash message
        $msg = $done . ' book(s) marked as returned and stock restored.';

        $total_fine = 0.0;
        foreach ($fines as $f) {
            $total_fine += $f['fine'];
        }

        if ($total_fine > 0) {
            $late = 0;
            foreach ($fines as $f) {
                if ($f['overdue_days'] > 0) {
                    $late++;
                }
            }
            $msg .= ' Late fine: ' . rupees_text($total_fine) . ' for ' . $late
                  . ' late book(s) - record the payment under Fines.';
            flash('warning', $msg);
        } else {
            flash('success', $msg . ' No late fines.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', 'Return failed: ' . $e->getMessage());
    }

    redirect('return_book.php');
}

/* ============================================================
   LIST currently issued books (with search + filter)
   ============================================================ */
$q      = str_param($_GET['q'] ?? '', 200);
$filter = str_param($_GET['filter'] ?? 'all', 20);   // all | overdue
if ($filter !== 'overdue') {
    $filter = 'all';
}

$sql = "SELECT ib.*, b.title, b.author, b.category, s.name AS student_name, s.email AS student_email
          FROM issued_books ib
          JOIN books b    ON b.id = ib.book_id
          JOIN students s ON s.id = ib.student_id
         WHERE ib.status = 'issued'";

$params = [];
if ($q !== '') {
    $sql     .= ' AND (b.title LIKE ? OR s.name LIKE ? OR s.email LIKE ? OR b.isbn LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter === 'overdue') {
    $sql .= ' AND ib.return_date < CURDATE()';
}
$sql .= ' ORDER BY ib.return_date ASC, ib.id ASC';   // most overdue first

$loans    = db_all($sql, $params);
$out_all = (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'issued'");
$out_due = (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'issued' AND return_date < CURDATE()");

// Fine configuration, read once for the table
$fine_cfg = fine_settings();
$rate     = $fine_cfg['per_day'];

$current_page  = 'return_book.php';
$page_title    = 'Return Book';
$page_subtitle = $out_all . ' book(s) currently issued' . ($out_due ? ' - ' . $out_due . ' overdue' : '');
$page_actions  = '<a href="issue_book.php" class="btn btn-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i> Issue Book</a>'
               . '<a href="reports.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart-line me-1"></i> Reports</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===================== FILTERS ===================== -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-5">
                <label class="form-label" for="q"><i class="bi bi-search me-1"></i>Search</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>"
                       placeholder="Book title, student name or email...">
            </div>
            <div class="col-8 col-md-3 col-lg-3">
                <label class="form-label" for="filter">Show</label>
                <select class="form-select" id="filter" name="filter">
                    <option value="all"     <?= $filter === 'all' ? 'selected' : '' ?>>All issued</option>
                    <option value="overdue" <?= $filter === 'overdue' ? 'selected' : '' ?>>Overdue only</option>
                </select>
            </div>
            <div class="col-4 col-md-3 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
            </div>
            <div class="col-12 col-lg-2 d-grid">
                <a href="return_book.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- ===================== ISSUED BOOKS TABLE ===================== -->
<?php if (!$loans): ?>
    <div class="card">
        <div class="card-body empty-state">
            <i class="bi bi-check2-circle"></i>
            Nothing to return<?= ($q !== '' || $filter !== 'all') ? ' for this filter' : '' ?>.
        </div>
    </div>
<?php else: ?>

<form method="post" data-confirm="Mark the selected book(s) as returned?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="return">

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-box-arrow-in-down-left me-2"></i>Books Out With Students</span>
            <div class="d-flex gap-2">
                <span class="badge text-bg-primary"><?= count($loans) ?> shown</span>
                <button type="submit" name="bulk" value="1" class="btn btn-sm btn-success">
                    <i class="bi bi-check2-all me-1"></i> Return Selected
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:40px">
                            <input class="form-check-input" type="checkbox" id="checkAll" aria-label="Select all">
                        </th>
                        <th>Book</th>
                        <th>Student</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th>Late</th>
                        <th>Live Fine</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($loans as $l):
                    $left = days_left($l['return_date']);
                    // Live fine: worked out from today's date, never written here
                    $calc = calculate_fine($l['return_date'], null);
                    ?>
                    <tr<?= $calc['overdue_days'] > 0 ? ' class="table-warning"' : '' ?>>
                        <td>
                            <input class="form-check-input" type="checkbox" name="ids[]"
                                   value="<?= (int) $l['id'] ?>" aria-label="Select row">
                        </td>
                        <td>
                            <div class="fw-semibold"><?= e($l['title']) ?></div>
                            <div class="small text-muted"><?= e($l['author']) ?></div>
                        </td>
                        <td>
                            <div><?= e($l['student_name']) ?></div>
                            <div class="small text-muted"><?= e($l['student_email']) ?></div>
                        </td>
                        <td class="small"><?= fmt_date($l['issue_date']) ?></td>
                        <td class="small"><?= fmt_date($l['return_date']) ?></td>
                        <td class="small">
                            <?php if ($calc['overdue_days'] > 0): ?>
                                <span class="badge text-bg-danger"><?= $calc['overdue_days'] ?>d late</span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($calc['fine'] > 0): ?>
                                <span class="fw-semibold text-danger"><?= rupees($calc['fine']) ?></span>
                                <span class="text-muted d-block"><?= $calc['overdue_days'] ?> &times; <?= rupees($rate) ?></span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($left < 0): ?>
                                <span class="badge text-bg-danger">Overdue <?= abs($left) ?>d</span>
                            <?php elseif ($left === 0): ?>
                                <span class="badge text-bg-warning">Due today</span>
                            <?php elseif ($left <= 3): ?>
                                <span class="badge text-bg-warning"><?= $left ?>d left</span>
                            <?php else: ?>
                                <span class="badge text-bg-info"><?= $left ?>d left</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button type="submit" name="id" value="<?= (int) $l['id'] ?>"
                                    class="btn btn-sm btn-success">
                                <i class="bi bi-check-lg"></i> Return
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer bg-white small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Returning a book sets its status to <strong>returned</strong>, records the actual return date
            and increases <strong>available quantity</strong> by 1.
        </div>
    </div>
</form>

<?php endif; ?>

<script>
    // Toggle all checkboxes
    document.getElementById('checkAll')?.addEventListener('change', function () {
        document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
            cb.checked = this.checked;
        }, this);
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
