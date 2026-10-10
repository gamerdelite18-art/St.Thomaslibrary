<?php
/**
 * manage_students.php - View, search, edit and delete students
 * (add / edit / delete all handled here; add_student.php handles the add form)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

/* ============================================================
   POST ACTIONS  (update / delete)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = str_param($_POST['action'] ?? '', 20);
    $id     = int_param($_POST['student_id'] ?? 0);
    $back_q = str_param($_POST['back'] ?? '', 100);
    $back   = $back_q !== '' ? '?q=' . urlencode($back_q) : '';

    /* ---------- Update student ---------- */
    if ($action === 'update') {
    $name  = str_param($_POST['name'] ?? '', 150);
    $email = str_param($_POST['email'] ?? '', 150);
    $phone = str_param($_POST['phone'] ?? '', 20);
        $err   = [];

        if ($name === '')                              $err[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Valid email is required.';
        // Another student must not already own this email
        $clash = db_val('SELECT COUNT(*) FROM students WHERE email = ? AND id <> ?', [$email, $id]);
        if ($clash) $err[] = 'This email is already registered to another student.';

        if ($err) {
            flash('danger', implode(' ', $err));
        } else {
            db_query('UPDATE students SET name = ?, email = ?, phone = ? WHERE id = ?',
                     [$name, $email, $phone ?: null, $id]);
            flash('success', 'Student details updated successfully.');
        }
        redirect('manage_students.php' . $back);
    }

    /* ---------- Delete student ---------- */
    if ($action === 'delete') {
        $student = db_one('SELECT name FROM students WHERE id = ?', [$id]);

        if (!$student) {
            flash('danger', 'Student not found.');
        } elseif (student_issued_count($id) > 0) {
            flash('danger', 'Cannot delete "' . $student['name'] . '" - they still have books issued. Process the returns first.');
        } else {
            // Deleting cascades away their loans AND their fine payments, so a
            // student who still owes money must not be removable - otherwise an
            // outstanding debt could be written off with one click.
            $fs = student_fine_summary($id);

            if ($fs['pending'] > 0) {
                flash('danger', 'Cannot delete "' . $student['name'] . '" - they still owe '
                    . rupees_text($fs['pending']) . ' in fines. Record the payment under Fines first.');
            } else {
                $paid = (float) $fs['collected'];
                $had_fines = (int) db_val(
                    'SELECT COUNT(*) FROM fine_payments fp
                       JOIN issued_books ib ON ib.id = fp.issued_book_id
                      WHERE ib.student_id = ?',
                    [$id]
                );

                // FK ON DELETE CASCADE also clears the student's past issue history
                db_query('DELETE FROM students WHERE id = ?', [$id]);

                $note = '';
                if ($had_fines > 0) {
                    $note = ' Their fine history and ' . $had_fines . ' payment record(s) totalling '
                          . rupees_text($paid) . ' were removed with them.';
                }
                flash('success', 'Student "' . $student['name'] . '" deleted.' . $note);
            }
        }
        redirect('manage_students.php' . $back);
    }
}

/* ============================================================
   SEARCH + LIST (with per-student issued/overdue counts)
   ============================================================ */
$q = str_param($_GET['q'] ?? '', 150);

$sql = "SELECT s.*,
               COALESCE(i.active_count, 0)  AS active_count,
               COALESCE(i.overdue_count, 0) AS overdue_count
          FROM students s
          LEFT JOIN (
               SELECT student_id,
                      SUM(status = 'issued')                                          AS active_count,
                      SUM(status = 'issued' AND return_date < CURDATE())            AS overdue_count
                 FROM issued_books
                GROUP BY student_id
          ) i ON i.student_id = s.id";

$params = [];
if ($q !== '') {
    $sql     .= ' WHERE s.name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?';
    $like     = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$sql .= ' ORDER BY s.name ASC';

$students = db_all($sql, $params);
$total    = (int) db_val('SELECT COUNT(*) FROM students');

// Fine totals for the whole page in a fixed number of queries, rather than
// two per student (which is what calling student_fine_summary() in the loop did)
$fine_map = student_fine_summaries(array_column($students, 'id'));

$current_page  = 'manage_students.php';
$page_title    = 'Students';
$page_subtitle = count($students) . ' student(s) shown of ' . $total . ' registered';
$page_actions  = '<a href="add_student.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i> Add Student</a>'
               . '<a href="issue_book.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i> Issue Book</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===================== SEARCH BAR ===================== -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-8 col-lg-6">
                <label class="form-label" for="q"><i class="bi bi-search me-1"></i>Search Students</label>
                <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>"
                       placeholder="Name, email or phone number...">
            </div>
            <div class="col-8 col-md-3 col-lg-3 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Search</button>
            </div>
            <?php if ($q !== ''): ?>
                <div class="col-4 col-md-1 col-lg-2 d-grid">
                    <a href="manage_students.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ===================== STUDENTS TABLE ===================== -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2"></i>Registered Students</span>
        <span class="badge text-bg-secondary"><?= count($students) ?> record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:45px">#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Books Held</th>
                    <th>Fines</th>
                    <th>Registered</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$students): ?>
                <tr><td colspan="8" class="empty-state">
                    <i class="bi bi-inbox"></i>
                    No students found. <a href="add_student.php">Register the first student</a>.
                </td></tr>
            <?php endif; ?>

            <?php foreach ($students as $s):
                $active  = (int) $s['active_count'];
                $overdue = (int) $s['overdue_count'];
                $fs      = $fine_map[(int) $s['id']] ?? ['pending' => 0.0, 'collected' => 0.0];
                $pending = $fs['pending'];
                $paid    = $fs['collected']; ?>
                <tr>
                    <td class="text-muted"><?= (int) $s['id'] ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-circle text-bg-primary d-inline-flex align-items-center justify-content-center"
                                  style="width:32px;height:32px"><?= e(strtoupper(substr($s['name'], 0, 1))) ?></span>
                            <span class="fw-semibold"><?= e($s['name']) ?></span>
                        </div>
                    </td>
                    <td class="small text-muted"><?= e($s['email']) ?></td>
                    <td class="small"><?= e($s['phone'] ?: '-') ?></td>
                    <td>
                        <?php if ($active > 0): ?>
                            <span class="badge text-bg-primary"><?= $active ?> issued</span>
                        <?php else: ?>
                            <span class="badge text-bg-success">None</span>
                        <?php endif; ?>
                        <?php if ($overdue > 0): ?>
                            <span class="badge text-bg-danger"><?= $overdue ?> overdue</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <a href="student_fines.php?id=<?= (int) $s['id'] ?>"
                           class="text-decoration-none fw-semibold<?= $pending > 0 ? ' text-danger' : ' text-muted' ?>">
                            <?= rupees($pending) ?>
                        </a>
                        <div class="small text-muted"><?= rupees($paid) ?> paid</div>
                    </td>
                    <td class="small text-muted"><?= fmt_date($s['created_at']) ?></td>
                    <td class="text-end text-nowrap">
                        <!-- Edit button toggles an inline row form (no extra page needed) -->
                        <button class="btn btn-sm btn-outline-primary" type="button"
                                data-bs-toggle="collapse" data-bs-target="#edit<?= (int) $s['id'] ?>"
                                aria-expanded="false" aria-controls="edit<?= (int) $s['id'] ?>" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" class="d-inline"
                              data-confirm="Delete student &quot;<?= e($s['name']) ?>&quot; permanently?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="back" value="<?= e($q) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>

                <!-- Collapsible inline edit form -->
                <tr class="collapse" id="edit<?= (int) $s['id'] ?>">
                    <td colspan="8" class="bg-light">
                        <form method="post" class="row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="back" value="<?= e($q) ?>">

                            <div class="col-12 col-md-3">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control form-control-sm"
                                       value="<?= e($s['name']) ?>" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control form-control-sm"
                                       value="<?= e($s['email']) ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" class="form-control form-control-sm"
                                       value="<?= e($s['phone']) ?>">
                            </div>
                            <div class="col-12 col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                                    <i class="bi bi-check-lg"></i> Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="collapse" data-bs-target="#edit<?= (int) $s['id'] ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
