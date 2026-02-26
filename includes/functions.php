<?php
/**
 * Fungsi-fungsi helper untuk RIS
 */

/**
 * Enkripsi data menggunakan AES
 * @param string $data
 * @param string $key
 * @return string
 */
function encryptAES($data, $key) {
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', hash('sha256', $key, true), false);
    return $encrypted;
}

/**
 * Dekripsi data menggunakan AES
 * @param string $data
 * @param string $key
 * @return string
 */
function decryptAES($data, $key) {
    $decrypted = openssl_decrypt($data, 'AES-256-CBC', hash('sha256', $key, true), false);
    return $decrypted;
}

/**
 * Format tanggal ke format Indonesia
 * @param string $date
 * @return string
 */
function formatTanggal($date) {
    $bulan = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];

    $timestamp = strtotime($date);
    $day = date('d', $timestamp);
    $month = date('F', $timestamp);
    $year = date('Y', $timestamp);

    $monthName = isset($bulan[$month]) ? $bulan[$month] : $month;
    return $day . ' ' . $monthName . ' ' . $year;
}

/**
 * Format angka ke format mata uang
 * @param float $angka
 * @return string
 */
function formatMataUang($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

/**
 * Validasi input
 * @param string $data
 * @return string
 */
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect ke halaman lain
 * @param string $url
 */
function redirect($url) {
    header('Location: ' . $url);
    exit();
}

/**
 * Generate flash message
 * @param string $message
 * @param string $type (success, error, warning, info)
 */
function setFlashMessage($message, $type = 'info') {
    $_SESSION['flash_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Tampilkan flash message
 * @return array|null
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Check apakah user sudah login
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get user dari session
 * @return array|null
 */
function getUser() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

/**
 * Format tanggal untuk database (YYYY-MM-DD)
 * @param string $date
 * @return string
 */
function formatTanggalDB($date) {
    // Jika format sudah YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    // Jika format DD/MM/YYYY
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
        $parts = explode('/', $date);
        return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
    }
    return $date;
}

/**
 * Format tanggal dari database ke format tampilan (DD/MM/YYYY)
 * @param string $date
 * @return string
 */
function formatTanggalTampil($date) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $parts = explode('-', $date);
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    return $date;
}

/**
 * Generate response JSON
 * @param bool $success
 * @param string $message
 * @param mixed $data
 * @return string
 */
function jsonResponse($success, $message = '', $data = null) {
    header('Content-Type: application/json');
    return json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Truncate text
 * @param string $text
 * @param int $length
 * @param string $suffix
 * @return string
 */
function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) > $length) {
        return substr($text, 0, $length) . $suffix;
    }
    return $text;
}

/**
 * Inisialisasi session dengan last_activity tracking
 * Harus dipanggil setelah session_start() dan check user login
 */
function initializeSessionActivity() {
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Check apakah session sudah expire karena inactivity
 * Timeout default: 1 jam (3600 detik)
 * @param int $timeout_seconds
 * @return bool true jika masih aktif, false jika sudah timeout
 */
function checkSessionInactivity($timeout_seconds = 3600) {
    if (!isset($_SESSION['last_activity'])) {
        return true; // Session baru
    }

    $current_time = time();
    $elapsed_time = $current_time - $_SESSION['last_activity'];

    if ($elapsed_time > $timeout_seconds) {
        // Session timeout karena inactivity
        return false;
    }

    return true;
}

/**
 * Update activity timestamp di session
 * Dipanggil setiap kali ada user activity (keyboard, mouse, atau request)
 */
function updateSessionActivity() {
    $_SESSION['last_activity'] = time();
}

/**
 * Handle auto logout jika session inactive
 * Harus dipanggil di setiap protected page sebelum content rendering
 */
function checkAndHandleSessionTimeout() {
    if (isset($_SESSION['user_id'])) {
        if (!checkSessionInactivity(3600)) { // 1 jam = 3600 detik
            // Session timeout - destroy session
            session_destroy();
            header('Location: ../login.php?logout=inactivity');
            exit();
        }
    }
}

// ============================================================
// JWT Authentication Functions (untuk API)
// ============================================================

/**
 * Get JWT secret key dari config
 * @return string
 */
function getJWTSecret() {
    return hash('sha256', ENCRYPT_KEY_USER . ':' . ENCRYPT_KEY_PASS, true);
}

/**
 * Base64 URL-safe encode
 * @param string $data
 * @return string
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL-safe decode
 * @param string $data
 * @return string
 */
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Generate JWT token
 * @param string $userId
 * @param string $namaPegawai
 * @param int $expireSeconds (default 24 jam)
 * @return string JWT token
 */
function generateJWT($userId, $namaPegawai, $expireSeconds = 86400) {
    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $payload = base64UrlEncode(json_encode([
        'user_id' => $userId,
        'nama_pegawai' => $namaPegawai,
        'iat' => time(),
        'exp' => time() + $expireSeconds
    ]));

    $signature = base64UrlEncode(
        hash_hmac('sha256', "$header.$payload", getJWTSecret(), true)
    );

    return "$header.$payload.$signature";
}

/**
 * Validate dan decode JWT token
 * @param string $token
 * @return array|false payload jika valid, false jika tidak
 */
function validateJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    list($header, $payload, $signature) = $parts;

    // Verify signature
    $expectedSignature = base64UrlEncode(
        hash_hmac('sha256', "$header.$payload", getJWTSecret(), true)
    );

    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }

    // Decode payload
    $data = json_decode(base64UrlDecode($payload), true);
    if (!$data) {
        return false;
    }

    // Check expiration
    if (isset($data['exp']) && $data['exp'] < time()) {
        return false;
    }

    return $data;
}

/**
 * Get authenticated user dari JWT header atau session
 * @return array|null ['user_id' => ..., 'nama_pegawai' => ...]
 */
function getAuthUser() {
    // Priority 1: JWT Bearer token
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
    }

    if (!empty($authHeader) && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $payload = validateJWT($matches[1]);
        if ($payload) {
            return [
                'user_id' => $payload['user_id'],
                'nama_pegawai' => $payload['nama_pegawai']
            ];
        }
    }

    // Priority 2: Session
    if (isset($_SESSION['user_id'])) {
        return [
            'user_id' => $_SESSION['user_id'],
            'nama_pegawai' => $_SESSION['nama_pegawai'] ?? 'User'
        ];
    }

    return null;
}

?>

