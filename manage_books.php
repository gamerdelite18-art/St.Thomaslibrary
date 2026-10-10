<?php
/**
 * manage_books.php - List, search, update quantity and delete books
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

/* ============================================================
   POST ACTIONS  (update quantity / delete)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = str_param($_POST['action'] ?? '', 20);
    $id     = int_param($_POST['book_id'] ?? 0);
    $back   = str_param($_POST['back'] ?? '', 100);

    /* ---------- Update quantity ---------- */
    if ($action === 'update_qty') {
        $qty = int_param($_POST['quantity'] ?? 0);
        $out = issued_count($id);

        if ($id < 1) {
            flash('danger', 'Invalid book selected.');
        } elseif ($qty < $out) {
            flash('danger', 'Quantity cannot be less than ' . $out . ' - that many copies are currently issued.');
        } else {
            // available = total copies - copies currently with students
            db_query('UPDATE books SET quantity = ?, available_quantity = ? WHERE id = ?',
                     [$qty, $qty - $out, $id]);
            flash('success', 'Quantity updated successfully.');
        }
        redirect('manage_books.php' . ($back !== '' ? '?q=' . urlencode($back) : ''));
    }

    /* ---------- Delete book ---------- */
    if ($action === 'delete') {
        $book = db_one('SELECT title, quantity, available_quantity FROM books WHERE id = ?', [$id]);
        $out  = issued_count($id);

        if (!$book) {
            flash('danger', 'Book not found.');
        } elseif ($out > 0) {
            // Prevent losing track of copies that are still with students
            flash('danger', 'Cannot delete "' . $book['title'] . '" - ' . $out . ' cop(y/ies) are still issued.');
        } else {
            db_query('DELETE FROM books WHERE id = ?', [$id]);
            flash('success', 'Book "' . $book['title'] . '" deleted.');
        }
        redirect('manage_books.php' . ($back !== '' ? '?q=' . urlencode($back) : ''));
    }
}

/* ============================================================
   SEARCH + LIST
   ============================================================ */
$q        = str_param($_GET['q'] ?? '', 200);
$category = str_param($_GET['category'] ?? '', 100);

// Build a dynamic WHERE clause, but every value is still bound as a parameter
$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = '(title LIKE ? OR author LIKE ? OR isbn LIKE ? OR category LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($category !== '') {
    $where[]  = 'category = ?';
    $params[] = $category;
}
$sql = 'SELECT * FROM books';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY title ASC';

$books  = db_all($sql, $params);
$total  = (int) db_val('SELECT COUNT(*) FROM books');

$current_page  = 'manage_books.php';
$page_title    = 'Manage Books';
$page_subtitle = count($books) . ' book(s) shown of ' . $total . ' in the catalogue';
$page_actions  = '<a href="add_book.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Add Book</a>'
               . '<a href="issue_book.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i> Issue</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===================== SEARCH BAR ===================== -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-5">
                <label class="form-label" for="q"><i class="bi bi-search me-1"></i>Search</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>"
                       placeholder="Title, author, ISBN or category...">
            </div>
            <div class="col-8 col-md-3 col-lg-3">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All categories</option>
                    <?php options(book_categories(), $category); ?>
                </select>
            </div>
            <div class="col-4 col-md-3 col-lg-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
            </div>
            <?php if ($q !== '' || $category !== ''): ?>
                <div class="col-12 col-lg-2 d-grid">
                    <a href="manage_books.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ===================== BOOKS TABLE ===================== -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection me-2"></i>Book Catalogue</span>
        <span class="badge text-bg-secondary"><?= count($books) ?> record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:45px">#</th>
                    <th>Title / Author</th>
                    <th>Category</th>
                    <th>ISBN</th>
                    <th>Stock</th>
                    <th class="text-center">Update Qty</th>
                    <th>Added</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$books): ?>
                <tr><td colspan="8" class="empty-state">
                    <i class="bi bi-inbox"></i>
                    No books found. <a href="add_book.php">Add your first book</a>.
                </td></tr>
            <?php endif; ?>

            <?php foreach ($books as $b):
                $out = issued_count($b['id']); ?>
                <tr>
                    <td class="text-muted"><?= (int) $b['id'] ?></td>

                    <td>
                        <div class="fw-semibold"><?= e($b['title']) ?></div>
                        <div class="small text-muted"><?= e($b['author']) ?></div>
                    </td>

                    <td><span class="badge text-bg-light border"><?= e($b['category'] ?: 'Uncategorised') ?></span></td>
                    <td class="small text-muted"><?= e($b['isbn'] ?: '-') ?></td>

                    <td>
                        <?= stock_badge((int) $b['available_quantity'], (int) $b['quantity']) ?>
                        <div class="small text-muted"><?= $out ?> issued</div>
                    </td>

                    <!-- Quick quantity update -->
                    <td class="text-center">
                        <form method="post" class="d-inline-flex gap-1 justify-content-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_qty">
                            <input type="hidden" name="book_id" value="<?= (int) $b['id'] ?>">
                            <input type="hidden" name="back" value="<?= e($q) ?>">
                            <input type="number" name="quantity" min="<?= max(1, $out) ?>" step="1"
                                   class="form-control form-control-sm text-center" style="width:78px"
                                   value="<?= (int) $b['quantity'] ?>" aria-label="Quantity">
                            <button type="submit" class="btn btn-sm btn-primary" title="Save quantity">
                                <i class="bi bi-check-lg"></i>
                            </button>
                        </form>
                    </td>

                    <td class="small text-muted"><?= fmt_date($b['added_date']) ?></td>

                    <td class="text-end text-nowrap">
                        <a href="edit_book.php?id=<?= (int) $b['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="post" class="d-inline"
                              data-confirm="Delete &quot;<?= e($b['title']) ?>&quot; permanently?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="book_id" value="<?= (int) $b['id'] ?>">
                            <input type="hidden" name="back" value="<?= e($q) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
