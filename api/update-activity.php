<?php
/**
 * API Endpoint untuk update session activity
 * Dipanggil oleh JavaScript ketika ada user activity
 */

session_start();

// Verifikasi user sudah login
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit();
}

// Update last_activity timestamp
$_SESSION['last_activity'] = time();

// Return response
header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Activity updated',
    'timestamp' => $_SESSION['last_activity']
]);
exit();
?>
