<?php
/**
 * sidebar.php - Admin panel navigation
 * Included twice: inside the desktop sidebar and the mobile offcanvas.
 * Expects $current_page (string) and $current_admin (array) to be set.
 */

$nav_items = [
    ['file' => 'index.php',         'icon' => 'speedometer2', 'label' => 'Dashboard'],
    ['file' => 'manage_books.php',   'icon' => 'collection',    'label' => 'Manage Books'],
    ['file' => 'add_book.php',       'icon' => 'plus-square',   'label' => 'Add Book'],
    ['file' => 'manage_students.php','icon' => 'people',        'label' => 'Students'],
    ['file' => 'add_student.php',    'icon' => 'person-plus',   'label' => 'Add Student'],
    ['file' => 'issue_book.php',     'icon' => 'box-arrow-up-right', 'label' => 'Issue Book'],
    ['file' => 'return_book.php',    'icon' => 'box-arrow-in-down-left', 'label' => 'Return Book'],
    ['file' => 'fines.php',          'icon' => 'cash-coin',     'label' => 'Fines & Payments'],
    ['file' => 'reports.php',        'icon' => 'bar-chart-line','label' => 'Reports'],
    ['file' => 'fine_settings.php',  'icon' => 'sliders',       'label' => 'Fine Settings'],
];
?>
<ul class="nav flex-column sidebar-nav gap-1">
    <?php foreach ($nav_items as $item):
        $active = ($current_page === $item['file']) ? ' active' : ''; ?>
        <li class="nav-item">
            <a class="nav-link<?= $active ?>" href="<?= $item['file'] ?>">
                <i class="bi bi-<?= $item['icon'] ?>"></i>
                <span><?= e($item['label']) ?></span>
            </a>
        </li>
    <?php endforeach; ?>

    <li class="nav-item mt-3 pt-3 border-top border-secondary-subtle">
        <a class="nav-link" href="logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </li>
</ul>
