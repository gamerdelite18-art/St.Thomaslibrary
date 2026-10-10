<?php
/**
 * edit_book.php - Update a book's details and stock
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$id   = int_param($_GET['id'] ?? $_POST['book_id'] ?? 0);
$book = db_one('SELECT * FROM books WHERE id = ?', [$id]);

if (!$book) {
    flash('danger', 'Book not found.');
    redirect('manage_books.php');
}

$out       = issued_count($id);          // copies currently with students
$errors    = [];
$form      = $book;                      // pre-fill from the database row

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $form['title']    = str_param($_POST['title'] ?? '', 200);
    $form['author']   = str_param($_POST['author'] ?? '', 150);
    $form['category'] = str_param($_POST['category'] ?? '', 100);
    $form['isbn']     = str_param($_POST['isbn'] ?? '', 20);
    $form['quantity'] = int_param($_POST['quantity'] ?? 0);

    if ($form['title']  === '') $errors['title']    = 'Title is required.';
    if ($form['author'] === '') $errors['author']   = 'Author is required.';
    if ($form['quantity'] < $out) {
        $errors['quantity'] = 'Minimum ' . $out . ' - that many copies are currently issued.';
    }
    if ($form['isbn'] !== '' && !preg_match('/^[0-9Xx\-]{10,17}$/', $form['isbn'])) {
        $errors['isbn'] = 'Enter a valid ISBN (10 or 13 digits).';
    }

    if (!$errors) {
        db_query(
            'UPDATE books SET title = ?, author = ?, category = ?, isbn = ?, quantity = ?, available_quantity = ?
             WHERE id = ?',
            [$form['title'], $form['author'], $form['category'], $form['isbn'] ?: null,
             $form['quantity'], $form['quantity'] - $out, $id]
        );

        flash('success', 'Book updated successfully.');
        redirect('manage_books.php');
    }
}

$current_page  = 'manage_books.php';     // highlight "Manage Books" in the sidebar
$page_title    = 'Edit Book';
$page_subtitle = $book['title'];
$page_actions  = '<a href="manage_books.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Books</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stock summary -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100" style="--stat-color:#2563eb">
            <div class="card-body">
                <div class="stat-value"><?= (int) $form['quantity'] ?></div>
                <div class="stat-label">Total copies</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100" style="--stat-color:#16a34a">
            <div class="card-body">
                <div class="stat-value"><?= (int) $form['available_quantity'] ?></div>
                <div class="stat-label">Available</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100" style="--stat-color:#f59e0b">
            <div class="card-body">
                <div class="stat-value"><?= $out ?></div>
                <div class="stat-label">Issued</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100" style="--stat-color:#64748b">
            <div class="card-body">
                <div class="stat-value" style="font-size:1.05rem"><?= fmt_date($form['added_date']) ?></div>
                <div class="stat-label">Added on</div>
            </div>
        </div>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>Edit Book Details</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="book_id" value="<?= (int) $id ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="title">Book Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                                   id="title" name="title" value="<?= e($form['title']) ?>" required>
                            <div class="invalid-feedback"><?= e($errors['title'] ?? '') ?></div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="author">Author <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($errors['author']) ? 'is-invalid' : '' ?>"
                                   id="author" name="author" value="<?= e($form['author']) ?>" required>
                            <div class="invalid-feedback"><?= e($errors['author'] ?? '') ?></div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="category">Category</label>
                            <select class="form-select" id="category" name="category">
                                <?php
                                // Keep the book's current category selectable even if it is
                                // not one of the standard options
                                $cats = book_categories();
                                if ($form['category'] && !in_array($form['category'], $cats, true)) {
                                    $cats[] = $form['category'];
                                }
                                options($cats, $form['category']);
                                ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="isbn">ISBN</label>
                            <input type="text" class="form-control <?= isset($errors['isbn']) ? 'is-invalid' : '' ?>"
                                   id="isbn" name="isbn" value="<?= e($form['isbn']) ?>">
                            <div class="invalid-feedback"><?= e($errors['isbn'] ?? '') ?></div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="quantity">Total Copies <span class="text-danger">*</span></label>
                            <input type="number" min="<?= max(1, $out) ?>" step="1"
                                   class="form-control <?= isset($errors['quantity']) ? 'is-invalid' : '' ?>"
                                   id="quantity" name="quantity" value="<?= (int) $form['quantity'] ?>" required>
                            <div class="invalid-feedback"><?= e($errors['quantity'] ?? '') ?></div>
                            <div class="form-text"><?= $out ?> cop(y/ies) currently issued - cannot go below that.</div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        <a href="manage_books.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Update Book</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
