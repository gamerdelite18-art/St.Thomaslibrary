<?php
/**
 * header.php - Opens the HTML document, top navbar, sidebar and content area.
 *
 * Pages set these before including it:
 *   $page_title     (string) required
 *   $page_subtitle  (string) optional
 *   $page_actions   (string) optional raw HTML for buttons on the right
 *   $current_page   (string) required - used to highlight the sidebar
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

require_login();

$current_page  = $current_page  ?? '';
$page_title    = $page_title    ?? 'Dashboard';
$page_subtitle = $page_subtitle ?? '';
$page_actions  = $page_actions  ?? '';
$admin         = current_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> &middot; Library Management System</title>

    <!-- Bootstrap 5 (vendored locally - works offline) -->
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons (vendored locally) -->
    <link href="../assets/vendor/bootstrap-icons/css/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Project styles -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- ===================== TOP NAVBAR ===================== -->
<nav class="navbar navbar-expand-lg app-navbar sticky-top">
    <div class="container-fluid">

        <!-- Mobile: sidebar toggle -->
        <button class="btn btn-outline-light d-lg-none me-2 p-0" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar"
                aria-controls="mobileSidebar" aria-label="Open navigation" style="width:40px;height:40px">
            <i class="bi bi-list fs-3"></i>
        </button>

        <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="index.php">
            <span class="brand-mark"><i class="bi bi-journal-book-fill"></i></span>
            <span class="d-none d-sm-inline">Library Manager</span>
        </a>

        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="text-white-50 d-none d-md-inline small">
                <?= date('D, d M Y') ?>
            </span>

            <!-- Admin dropdown -->
            <div class="dropdown">
                <button class="btn btn-outline-light d-flex align-items-center gap-2 dropdown-toggle"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span class="d-none d-sm-inline"><?= e($admin['name'] ?? 'Admin') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="px-3 py-2">
                        <div class="fw-semibold"><?= e($admin['name'] ?? 'Admin') ?></div>
                        <div class="small text-muted"><?= e($admin['email'] ?? '') ?></div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- ===================== SIDEBAR (mobile offcanvas) ===================== -->
<div class="offcanvas offcanvas-start app-offcanvas" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="mobileSidebarLabel">
            <i class="bi bi-journal-book-fill me-2"></i>Library Manager
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-2">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </div>
</div>

<!-- ===================== APP LAYOUT ===================== -->
<div class="app-layout">

    <!-- Sidebar (desktop) -->
    <aside class="app-sidebar d-none d-lg-block">
        <div class="p-2">
            <?php include __DIR__ . '/sidebar.php'; ?>
        </div>
        <div class="sidebar-foot small text-muted px-3 py-3 border-top">
            <i class="bi bi-shield-check me-1"></i> Admin Panel v1.0
        </div>
    </aside>

    <!-- Content -->
    <main class="app-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h3 mb-1 fw-bold"><?= e($page_title) ?></h1>
                <?php if ($page_subtitle): ?>
                    <p class="text-muted mb-0"><?= e($page_subtitle) ?></p>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2"><?= $page_actions ?></div>
        </div>

        <?php show_flash(); ?>
