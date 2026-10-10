<?php
/**
 * index.php (project root) - Entry point: sends the visitor to the admin login.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

redirect('admin/' . (is_logged_in() ? 'index.php' : 'login.php'));
