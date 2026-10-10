<?php
/**
 * add_book.php - Add a new book to the catalogue
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$errors = [];
$form   = ['title' => '', 'author' => '', 'category' => '', 'isbn' => '', 'quantity' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $form['title']    = str_param($_POST['title'] ?? '', 200);
    $form['author']   = str_param($_POST['author'] ?? '', 150);
    $form['category'] = str_param($_POST['category'] ?? '', 100);
    $form['isbn']     = str_param($_POST['isbn'] ?? '', 20);
    $form['quantity'] = int_param($_POST['quantity'] ?? 0);

    // Validation
    if ($form['title']  === '') $errors['title']    = 'Title is required.';
    if ($form['author'] === '') $errors['author']   = 'Author is required.';
    if ($form['quantity'] < 1)  $errors['quantity'] = 'Quantity must be at least 1.';
    if ($form['isbn'] !== '' && !preg_match('/^[0-9Xx\-]{10,17}$/', $form['isbn'])) {
        $errors['isbn'] = 'Enter a valid ISBN (10 or 13 digits).';
    }

    if (!$errors) {
        // Prepared statement - available always starts equal to the total copies
        db_query(
            'INSERT INTO books (title, author, category, isbn, quantity, available_quantity, added_date)
             VALUES (?, ?, ?, ?, ?, ?, CURDATE())',
            [$form['title'], $form['author'], $form['category'], $form['isbn'] ?: null,
             $form['quantity'], $form['quantity']]
        );

        flash('success', 'Book "' . $form['title'] . '" added successfully.');
        redirect('manage_books.php');
    }
}

$current_page  = 'add_book.php';
$page_title    = 'Add Book';
$page_subtitle = 'Add a new title to the library catalogue';
$page_actions  = '<a href="manage_books.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Books</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-plus-square me-2"></i>New Book Details</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <!-- Title -->
                        <div class="col-12">
                            <label class="form-label" for="title">Book Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                                   id="title" name="title" value="<?= e($form['title']) ?>"
                                   placeholder="e.g. The Great Gatsby" required>
                            <div class="invalid-feedback"><?= e($errors['title'] ?? '') ?></div>
                        </div>

                        <!-- Author -->
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="author">Author <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($errors['author']) ? 'is-invalid' : '' ?>"
                                   id="author" name="author" value="<?= e($form['author']) ?>"
                                   placeholder="e.g. F. Scott Fitzgerald" required>
                            <div class="invalid-feedback"><?= e($errors['author'] ?? '') ?></div>
                        </div>

                        <!-- Category -->
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="category">Category</label>
                            <select class="form-select" id="category" name="category">
                                <option value="">-- Select --</option>
                                <?php options(book_categories(), $form['category']); ?>
                            </select>
                        </div>

                        <!-- ISBN -->
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="isbn">ISBN</label>
                            <input type="text" class="form-control <?= isset($errors['isbn']) ? 'is-invalid' : '' ?>"
                                   id="isbn" name="isbn" value="<?= e($form['isbn']) ?>"
                                   placeholder="9780743273565">
                            <div class="invalid-feedback"><?= e($errors['isbn'] ?? '') ?></div>
                            <div class="form-text">10 or 13 digits, dashes allowed.</div>
                        </div>

                        <!-- Quantity -->
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="quantity">Total Copies <span class="text-danger">*</span></label>
                            <input type="number" min="1" step="1"
                                   class="form-control <?= isset($errors['quantity']) ? 'is-invalid' : '' ?>"
                                   id="quantity" name="quantity" value="<?= e($form['quantity']) ?>" required>
                            <div class="invalid-feedback"><?= e($errors['quantity'] ?? '') ?></div>
                            <div class="form-text">Number of physical copies in the library.</div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        <a href="manage_books.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Book</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
