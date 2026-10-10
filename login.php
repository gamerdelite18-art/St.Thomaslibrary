<?php
/**
 * login.php - Admin login (the only page reachable without a session)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in? Go straight to the dashboard.
if (is_logged_in()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $email    = str_param($_POST['email'] ?? '', 150);
    $password = $_POST['password'] ?? '';
    if (!is_scalar($password)) {
        $password = '';
    }

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (attempt_login($email, $password)) {
        flash('success', 'Welcome back, ' . $_SESSION['admin_name'] . '!');
        redirect('index.php');
    } else {
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login &middot; Library Management System</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/css/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">

<div class="card login-card">
    <div class="card-body p-4 p-sm-5">

        <div class="text-center mb-4">
            <span class="login-logo"><i class="bi bi-journal-book-fill"></i></span>
            <h1 class="h4 fw-bold mt-3 mb-1">Library Manager</h1>
            <p class="text-muted small mb-0">Sign in to the admin panel</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
                <i class="bi bi-exclamation-triangle-fill"></i><?= e($error) ?>
            </div>
        <?php endif; ?>
        <?php show_flash(); ?>

        <form method="post" action="login.php" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?= e(str_param($_POST['email'] ?? '', 150)) ?>"
                           placeholder="admin@library.com" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="Enter password" required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePass"
                            aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </button>
        </form>

        <div class="text-center mt-4">
            <div class="alert alert-light border small mb-0 text-start">
                <strong class="d-block mb-1 text-muted">Demo login</strong>
                <div class="small">Email: <code>admin@library.com</code></div>
                <div class="small">Password: <code>admin123</code></div>
            </div>
        </div>
    </div>
</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    // Show/hide password
    document.getElementById('togglePass').addEventListener('click', function () {
        var pw = document.getElementById('password');
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        this.innerHTML = '<i class="bi bi-eye' + (show ? '-slash' : '') + '"></i>';
    });
</script>
</body>
</html>
