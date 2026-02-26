<?php
/**
 * Session Security Handler
 * File ini harus di-include di setiap protected page setelah session_start() dan login check
 *
 * Usage:
 * <?php
 * session_start();
 * if (!isset($_SESSION['user_id'])) {
 *     header('Location: ../login.php');
 *     exit();
 * }
 * require_once '../includes/session-security.php';
 * ?>
 */

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require functions
if (!function_exists('checkAndHandleSessionTimeout')) {
    require_once __DIR__ . '/functions.php';
}

// Check session timeout - auto logout jika sudah 1 jam tanpa aktivitas
checkAndHandleSessionTimeout();

// Update current activity time
updateSessionActivity();
?>
