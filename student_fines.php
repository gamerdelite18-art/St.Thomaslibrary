<?php
/**
 * student_fines.php - Per-student fine view
 *
 * The system is admin-only (no student login), so the librarian uses this
 * page to answer "what does this member owe?". It shows the student's
 * current loans, due dates, overdue books, live fine, total unpaid fine
 * and the payment state of every fine.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$id      = int_param($_GET['id'] ?? 0);
$student = db_one('SELECT * FROM students WHERE id = ?', [$id]);

if (!$student) {
    flash('danger', 'Student not found.');
    redirect('manage_students.php');
}

/* ---------- Currently issued (fine calculated live) ---------- */
$loans_out = db_all(
    "SELECT ib.*, b.title, b.author
       FROM issued_books ib
       JOIN books b ON b.id = ib.book_id
      WHERE ib.student_id = ? AND ib.status = 'issued'
      ORDER BY ib.return_date ASC",
    [$id]
);

/* ---------- Returned history (fines already frozen) ---------- */
$loans_back = db_all(
    "SELECT ib.*, b.title, b.author
       FROM issued_books ib
       JOIN books b ON b.id = ib.book_id
      WHERE ib.student_id = ? AND ib.status = 'returned'
      ORDER BY ib.returned_at DESC, ib.id DESC",
    [$id]
);

$summary = student_fine_summary($id);
$cfg     = fine_settings();

/* ---------- Payment history for this student ---------- */
$payments = db_all(
    "SELECT p.*, b.title
       FROM fine_payments p
       JOIN issued_books ib ON ib.id = p.issued_book_id
       JOIN books b         ON b.id  = ib.book_id
      WHERE ib.student_id = ?
      ORDER BY p.payment_date DESC, p.id DESC
      LIMIT 20",
    [$id]
);

$current_page  = 'manage_students.php';   // keep "Students" highlighted
$page_title    = 'Fines: ' . $student['name'];
$page_subtitle = $student['email'] . ($student['phone'] ? ' - ' . $student['phone'] : '');
$page_actions  = '<a href="manage_students.php" class="btn btn-outline-secondary btn-sm">'
               . '<i class="bi bi-arrow-left me-1"></i> Back to Students</a>'
               . '<a href="fines.php?q=' . urlencode($student['name']) . '" class="btn btn-outline-primary btn-sm">'
               . '<i class="bi bi-cash-coin me-1"></i> All Fines</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===================== SUMMARY ===================== -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Books With Student', count($loans_out), 'book',         '#2563eb', 'Currently issued'],
        ['Overdue',            $summary['overdue'], 'exclamation-triangle', '#f59e0b', 'Past the due date'],
        ['Current Fine',       $summary['pending'], 'hourglass-split', '#dc2626', 'Including live accruals'],
        ['Total Unpaid',       $summary['pending'], 'cash-stack',   '#7c3aed', 'To be collected'],
        ['Total Assessed',     $summary['assessed'], 'receipt',    '#0d9488', 'All fines raised'],
        ['Paid to Date',       $summary['collected'], 'check-circle', '#16a34a', 'Collected'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card stat-card h-100" style="--stat-color:<?= $c[3] ?>">
                <div class="card-body text-center">
                    <div class="stat-value"><?= is_int($c[1]) ? $c[1] : rupees($c[1]) ?></div>
                    <div class="stat-label"><?= e($c[0]) ?></div>
                    <div class="small text-muted"><?= e($c[4]) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$cfg['enabled']): ?>
    <div class="alert alert-warning py-2">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        Fines are currently switched off, so no new fine is being charged.
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- ===================== CURRENT LOANS ===================== -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-book me-2"></i>Currently Issued</span>
                <span class="badge text-bg-primary">Fine accruing live</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Book</th><th>Due</th><th class="text-center">Overdue</th><th class="text-end">Fine</th></tr></thead>
                    <tbody>
                    <?php if (!$loans_out): ?>
                        <tr><td colspan="4" class="empty-state"><i class="bi bi-inbox"></i>No books out.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($loans_out as $l):
                        $f = loan_fine($l); ?>
                        <tr<?= $f['days'] > 0 ? ' class="table-warning"' : '' ?>>
                            <td>
                                <div class="text-truncate-2"><?= e($l['title']) ?></div>
                                <div class="small text-muted">Issued <?= fmt_date($l['issue_date']) ?></div>
                            </td>
                            <td class="small"><?= fmt_date($l['return_date']) ?></td>
                            <td class="text-center">
                                <?php if ($f['days'] > 0): ?>
                                    <span class="badge text-bg-danger"><?= $f['days'] ?>d</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">On time</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold <?= $f['amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= rupees($f['amount']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===================== FINES ===================== -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cash-coin me-2"></i>Fine History</span>
                <a href="fines.php?q=<?= urlencode($student['email']) ?>" class="btn btn-sm btn-outline-primary">
                    Collect Payment
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>Book</th><th>Due</th><th>Returned</th>
                        <th class="text-center">Days</th><th class="text-end">Fine</th>
                        <th class="text-end">Left</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$loans_back): ?>
                        <tr><td colspan="7" class="empty-state"><i class="bi bi-inbox"></i>No returned books yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($loans_back as $l):
                        $f = loan_fine($l); ?>
                        <tr<?= $f['days'] > 0 ? ' class="table-warning"' : '' ?>>
                            <td><div class="text-truncate-2"><?= e($l['title']) ?></div></td>
                            <td class="small"><?= fmt_date($l['return_date']) ?></td>
                            <td class="small"><?= fmt_date($l['returned_at']) ?></td>
                            <td class="text-center small"><?= $f['days'] ?></td>
                            <td class="text-end small"><?= rupees($f['amount']) ?></td>
                            <td class="text-end small fw-semibold <?= $f['remaining'] > 0 ? 'text-danger' : 'text-muted' ?>">
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

<!-- ===================== PAYMENT HISTORY ===================== -->
<div class="card mt-3">
    <div class="card-header"><i class="bi bi-receipt me-2"></i>Payments Received</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Book</th><th class="text-end">Amount</th><th>Method</th><th>Note</th></tr></thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr><td colspan="5" class="empty-state"><i class="bi bi-inbox"></i>No payments recorded.</td></tr>
            <?php endif; ?>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="small"><?= fmt_date($p['payment_date']) ?></td>
                    <td class="small"><div class="text-truncate-2"><?= e($p['title']) ?></div></td>
                    <td class="text-end fw-semibold text-success"><?= rupees($p['amount']) ?></td>
                    <td class="small"><span class="badge text-bg-light border"><?= e($p['payment_method']) ?></span></td>
                    <td class="small text-muted"><?= e($p['note'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
