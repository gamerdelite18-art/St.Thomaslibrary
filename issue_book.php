<?php
/**
 * issue_book.php - Issue a book to a student
 * Decrements books.available_quantity inside a transaction.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$errors = [];

// Default dates: issued today, due in 14 days
$form = [
    'book_id'    => '',
    'student_id' => '',
    'issue_date' => date('Y-m-d'),
    'due_date'   => date('Y-m-d', strtotime('+14 days')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $form['book_id']    = int_param($_POST['book_id'] ?? 0);
    $form['student_id'] = int_param($_POST['student_id'] ?? 0);
    $form['issue_date'] = str_param($_POST['issue_date'] ?? '', 10);
    $form['due_date']   = str_param($_POST['return_date'] ?? '', 10);

    /* ---------- Validation ---------- */
    if ($form['student_id'] < 1) $errors[] = 'Please select a student.';
    if ($form['book_id'] < 1)    $errors[] = 'Please select a book.';

    if (!$form['issue_date'] || strtotime($form['issue_date']) === false) {
        $errors[] = 'Please enter a valid issue date.';
    }
    if (!$form['due_date'] || strtotime($form['due_date']) === false) {
        $errors[] = 'Please enter a valid return (due) date.';
    } elseif (strtotime($form['due_date']) < strtotime($form['issue_date'])) {
        $errors[] = 'The return date cannot be earlier than the issue date.';
    }

    $book    = $form['book_id']    ? db_one('SELECT * FROM books WHERE id = ?', [$form['book_id']])    : null;
    $student = $form['student_id'] ? db_one('SELECT * FROM students WHERE id = ?', [$form['student_id']]) : null;

    if ($form['student_id'] && !$student) $errors[] = 'Selected student does not exist.';
    if ($form['book_id'] && !$book)       $errors[] = 'Selected book does not exist.';
    if ($book && (int) $book['available_quantity'] < 1) {
        $errors[] = 'No copies of "' . $book['title'] . '" are available.';
    }

    /* ---------- Save (all-or-nothing) ---------- */
    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            // Guarded decrement: only succeeds while copies remain
            $stmt = db_query('UPDATE books SET available_quantity = available_quantity - 1
                              WHERE id = ? AND available_quantity > 0', [$form['book_id']]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('No copies left - another admin may have taken the last one.');
            }

            db_query(
                'INSERT INTO issued_books (book_id, student_id, issue_date, return_date, status)
                 VALUES (?, ?, ?, ?, "issued")',
                [$form['book_id'], $form['student_id'], $form['issue_date'], $form['due_date']]
            );

            $pdo->commit();

            flash('success', '"' . $book['title'] . '" issued to ' . $student['name'] . '. Due on ' . fmt_date($form['due_date']) . '.');
            redirect('issue_book.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

/* ---------- Dropdown data ---------- */
$students = db_all('SELECT id, name, email FROM students ORDER BY name ASC');
$books    = db_all(
    'SELECT id, title, author, available_quantity, quantity
       FROM books WHERE available_quantity > 0 ORDER BY title ASC'
);
$all_books_count = (int) db_val('SELECT COUNT(*) FROM books');

// Recently issued
$recent = db_all(
    "SELECT ib.*, b.title, s.name AS student_name
       FROM issued_books ib
       JOIN books b    ON b.id = ib.book_id
       JOIN students s ON s.id = ib.student_id
      WHERE ib.status = 'issued'
      ORDER BY ib.issue_date DESC, ib.id DESC LIMIT 5"
);

$current_page  = 'issue_book.php';
$page_title    = 'Issue Book';
$page_subtitle = 'Lend a book to a registered student';
$page_actions  = '<a href="return_book.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-in-down-left me-1"></i> Process Returns</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Could not issue the book:</strong>
        <ul class="mb-0 mt-1"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if (!$students || !$all_books_count): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-circle-fill"></i>
        <div class="flex-grow-1">
            <?= !$students ? 'No students registered yet.' : 'The book catalogue is empty.' ?>
            Add <?= !$students ? 'a student' : 'a book' ?> before issuing.
        </div>
        <a href="<?= !$students ? 'add_student.php' : 'add_book.php' ?>" class="btn btn-sm btn-primary">Add now</a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- ===================== ISSUE FORM ===================== -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-box-arrow-up-right me-2"></i>Issue Details</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>

                    <!-- Student -->
                    <div class="mb-3">
                        <label class="form-label" for="student_id">Select Student <span class="text-danger">*</span></label>
                        <select class="form-select" id="student_id" name="student_id" required>
                            <option value="">-- Choose a student --</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $form['student_id'] == $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['name']) ?> &mdash; <?= e($s['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Book -->
                    <div class="mb-3">
                        <label class="form-label" for="book_id">Select Book <span class="text-danger">*</span></label>
                        <select class="form-select" id="book_id" name="book_id" required>
                            <option value="">-- Choose a book --</option>
                            <?php foreach ($books as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= $form['book_id'] == $b['id'] ? 'selected' : '' ?>>
                                    <?= e($b['title']) ?> (<?= e($b['author']) ?>) &mdash; <?= (int) $b['available_quantity'] ?> available
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only books with at least one available copy are listed.</div>
                    </div>

                    <!-- Dates -->
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="issue_date">Issue Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="issue_date" name="issue_date"
                                   value="<?= e($form['issue_date']) ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="return_date">Due Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="return_date" name="return_date"
                                   value="<?= e($form['due_date']) ?>" min="<?= date('Y-m-d') ?>" required>
                            <div class="form-text">A fine of <?= rupees(fine_settings()['per_day']) ?> per day
                                starts the day after this date.</div>
                        </div>
                    </div>

                    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
                        <button type="reset" class="btn btn-outline-secondary">Reset</button>
                        <button type="submit" class="btn btn-primary" <?= (!$students || !$books) ? 'disabled' : '' ?>>
                            <i class="bi bi-check-lg me-1"></i> Issue Book
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===================== RECENT ISSUES ===================== -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Latest Issues</span>
                <span class="badge text-bg-primary"><?= (int) db_val("SELECT COUNT(*) FROM issued_books WHERE status = 'issued'") ?> out</span>
            </div>
            <ul class="list-group list-group-flush">
            <?php if (!$recent): ?>
                <li class="list-group-item empty-state"><i class="bi bi-inbox"></i>Nothing issued yet.</li>
            <?php endif; ?>
            <?php foreach ($recent as $r):
                $left = days_left($r['return_date']); ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold text-truncate-2"><?= e($r['title']) ?></div>
                        <div class="small text-muted">
                            <?= e($r['student_name']) ?> &middot; due <?= fmt_date($r['return_date']) ?>
                        </div>
                    </div>
                    <?php if ($left < 0): ?>
                        <span class="badge text-bg-danger">Overdue <?= abs($left) ?>d</span>
                    <?php else: ?>
                        <span class="badge text-bg-info"><?= $left ?>d left</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
