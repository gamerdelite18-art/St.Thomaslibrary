<?php
/**
 * logout.php - Ends the session and returns to the login screen
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

logout();

// New session just to carry the goodbye message
session_start();
flash('success', 'You have been logged out successfully.');
session_regenerate_id(true);

redirect('login.php');
