<?php
/**
 * add_student.php - Register a new student / library member
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$errors = [];
$form   = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $form['name']  = str_param($_POST['name'] ?? '', 150);
    $form['email'] = str_param($_POST['email'] ?? '', 150);
    $form['phone'] = str_param($_POST['phone'] ?? '', 20);

    // Validation
    if ($form['name'] === '') {
        $errors['name'] = 'Student name is required.';
    } elseif (mb_strlen($form['name']) < 3) {
        $errors['name'] = 'Name must be at least 3 characters.';
    }

    if ($form['email'] === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        // UNIQUE index on email - catch the clash before it errors out
        $exists = db_val('SELECT COUNT(*) FROM students WHERE email = ?', [$form['email']]);
        if ($exists) {
            $errors['email'] = 'This email is already registered.';
        }
    }

    if ($form['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $form['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }

    if (!$errors) {
        db_query('INSERT INTO students (name, email, phone) VALUES (?, ?, ?)',
                 [$form['name'], $form['email'], $form['phone'] ?: null]);

        flash('success', 'Student "' . $form['name'] . '" registered successfully.');
        redirect('manage_students.php');
    }
}

$current_page  = 'add_student.php';
$page_title    = 'Add Student';
$page_subtitle = 'Register a new library member';
$page_actions  = '<a href="manage_students.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Students</a>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-plus me-2"></i>Student Details</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="name">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                                       id="name" name="name" value="<?= e($form['name']) ?>"
                                       placeholder="e.g. Aarav Sharma" required autofocus>
                                <div class="invalid-feedback"><?= e($errors['name'] ?? '') ?></div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="email">Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                                       id="email" name="email" value="<?= e($form['email']) ?>"
                                       placeholder="student@example.com" required>
                                <div class="invalid-feedback"><?= e($errors['email'] ?? '') ?></div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                                       id="phone" name="phone" value="<?= e($form['phone']) ?>"
                                       placeholder="9876543210">
                                <div class="invalid-feedback"><?= e($errors['phone'] ?? '') ?></div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                        <a href="manage_students.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Register Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
