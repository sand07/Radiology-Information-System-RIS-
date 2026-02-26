<?php
session_start();

// Jika sudah login, redirect ke dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: pages/dashboard.php');
    exit();
}

require_once 'config/Database.php';
require_once 'includes/functions.php';

$error = '';
$info = '';
$db = new Database();

// Check database connection
if (!$db->isConnected()) {
    $error = 'Koneksi database gagal: ' . $db->getLastError() .
             ' Pastikan database server (192.168.100.108) online dan accessible.';
}

// Cek apakah logout karena inactivity
if (isset($_GET['logout']) && $_GET['logout'] === 'inactivity') {
    $info = 'Sesi Anda telah berakhir karena tidak ada aktivitas selama 1 jam. Silakan login kembali.';
}

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi';
    } elseif (!$db->isConnected()) {
        $error = 'Koneksi database gagal';
    } else {
        try {
            // Query menggunakan metode yang sama seperti sistem yang ada
            // SELECT AES_DECRYPT(id_user,'nur') as id_user, AES_DECRYPT(password,'windi') as password
            // FROM user WHERE id_user = AES_ENCRYPT('username','nur')
            $query = "SELECT AES_DECRYPT(id_user, ?) as id_user, AES_DECRYPT(password, ?) as password
                      FROM user WHERE id_user = AES_ENCRYPT(?, ?)";

            $user = $db->fetch($query, [ENCRYPT_KEY_USER, ENCRYPT_KEY_PASS, $username, ENCRYPT_KEY_USER]);

            if ($user) {
                // Plain text password comparison (sesuai method yang ada)
                if ($user['password'] === $password) {
                    // Fetch pegawai nama berdasarkan id_user (cocok dengan nik di pegawai)
                    $pegawaiQuery = "SELECT nama FROM pegawai WHERE nik = ?";
                    $pegawai = $db->fetch($pegawaiQuery, [$user['id_user']]);
                    $namaPegawai = $pegawai['nama'] ?? $user['id_user'];

                    // Set session
                    $_SESSION['user_id'] = $user['id_user'];
                    $_SESSION['nama_pegawai'] = $namaPegawai;
                    $_SESSION['last_activity'] = time(); // Initialize activity tracking

                    header('Location: pages/dashboard.php');
                    exit();
                } else {
                    $error = 'Username atau password salah';
                }
            } else {
                $error = 'Username atau password salah';
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RIS RS Tk.III dr. Reksodiwiryo</title>
    <link rel="icon" type="image/png" href="assets/images/logo-ris.png?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #2c3e50;
            position: relative;
            overflow-x: hidden;
            background: linear-gradient(-45deg, #ffffff, #f0fdf4, #eef5dc, #f8f9fa);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }

        @keyframes gradientShift {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)), url('assets/images/hospital-bg.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            filter: blur(4px);
            z-index: -1;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background:
                radial-gradient(circle at 20% 50%, rgba(77, 124, 15, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(77, 124, 15, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(77, 124, 15, 0.12) 0%, transparent 50%);
            background-size: 200% 200%;
            animation: radiologyGlow 15s ease-in-out infinite;
            z-index: -1;
            pointer-events: none;
        }

        @keyframes radiologyGlow {
            0% {
                background-position: 0% 0%, 100% 100%, 50% 50%;
            }
            50% {
                background-position: 100% 100%, 0% 0%, 50% 50%;
            }
            100% {
                background-position: 0% 0%, 100% 100%, 50% 50%;
            }
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            max-width: 420px;
            width: 100%;
            border: 1px solid #e8eef5;
            position: relative;
            z-index: 10;
            animation: floatIn 0.8s ease-out;
        }

        @keyframes floatIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Medical Scan Pulse */
        .login-container::before {
            content: '';
            position: absolute;
            top: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #4D7C0F, transparent);
            animation: scanLine 3s ease-in-out infinite;
            z-index: 10;
        }

        @keyframes scanLine {
            0% {
                top: -2px;
            }
            50% {
                top: 50%;
            }
            100% {
                top: calc(100% - 2px);
            }
        }

        .login-header {
            background: white;
            color: #2c3e50;
            padding: 28px 32px;
            text-align: center;
            border-bottom: 1px solid #f0f4f8;
        }

        .login-header h1 {
            font-size: 26px;
            font-weight: 700;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            letter-spacing: -0.5px;
        }

        .login-header h1 i {
            font-size: 28px;
            color: #4D7C0F;
        }

        .login-header p {
            margin-top: 6px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }

        .login-body {
            padding: 32px 32px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            padding: 11px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: #f9fafb;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-control:hover {
            border-color: #9ca3af;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #4D7C0F;
            background: white;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1);
        }

        .input-group-text {
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #6b7280;
            font-size: 14px;
            padding: 0 12px;
        }

        .btn-login {
            background: #4D7C0F;
            border: none;
            padding: 11px 20px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            width: 100%;
            margin-top: 8px;
            color: white;
            cursor: pointer;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-login:hover {
            background: #3D5C0D;
            transform: none;
            box-shadow: 0 4px 12px rgba(77, 124, 15, 0.2);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .alert {
            border-radius: 8px;
            margin-bottom: 20px;
            padding: 12px 14px;
            font-size: 13px;
            border-left: 4px solid;
        }

        .alert-danger {
            background: #fef2f2;
            border-left-color: #dc2626;
            color: #7f1d1d;
        }

        .alert i {
            margin-right: 8px;
        }

        .text-center {
            text-align: center;
        }

        .text-center small {
            font-size: 12px;
            color: #9ca3af;
        }

        /* Password Toggle Button */
        .password-group {
            position: relative;
        }

        .toggle-password {
            border: 1px solid #d1d5db !important;
            background: #f9fafb !important;
            color: #6b7280 !important;
            padding: 0 12px !important;
            transition: all 0.2s ease;
        }

        .toggle-password:hover {
            background: white !important;
            border-color: #9ca3af !important;
            color: #4D7C0F !important;
        }

        .toggle-password:focus {
            outline: none !important;
            border-color: #4D7C0F !important;
            color: #4D7C0F !important;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1) !important;
        }

        .toggle-password i {
            font-size: 16px;
        }

        /* Loading Overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner-box {
            background: white;
            border-radius: 12px;
            padding: 50px 60px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .ecg-loading {
            width: 300px;
            height: 100px;
            margin: 0 auto 20px auto;
        }

        .ecg-line-loading {
            stroke: #4D7C0F;
            stroke-width: 2.5;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            animation: ecgLoadingPulse 1.5s ease-in-out infinite;
        }

        @keyframes ecgLoadingPulse {
            0%, 100% {
                stroke-dasharray: 1500;
                stroke-dashoffset: 1500;
                opacity: 0.4;
            }
            50% {
                stroke-dasharray: 1500;
                stroke-dashoffset: 0;
                opacity: 1;
            }
        }

        .loading-text {
            color: #2c3e50;
            font-weight: 500;
            font-size: 14px;
            margin-top: 8px;
        }

        /* Medical Background Container */
        .medical-background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(77, 124, 15, 0.03) 0%, rgba(77, 124, 15, 0.08) 50%, rgba(77, 124, 15, 0.03) 100%);
        }

        /* ECG Grid Animation */
        .medical-grid {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0.4;
        }

        .ecg-line {
            stroke: #4D7C0F;
            stroke-width: 3;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            animation: ecgPulse 3s ease-in-out infinite;
        }

        @keyframes ecgPulse {
            0% {
                stroke-dasharray: 2000;
                stroke-dashoffset: 2000;
                opacity: 0.3;
            }
            50% {
                opacity: 0.7;
            }
            100% {
                stroke-dasharray: 2000;
                stroke-dashoffset: 0;
                opacity: 0.3;
            }
        }

        /* Pulse Waves */
        .pulse-wave {
            position: absolute;
            border: 2px solid #4D7C0F;
            border-radius: 50%;
        }

        .pulse-1 {
            width: 150px;
            height: 150px;
            top: 25%;
            left: 15%;
            animation: pulse1 2.5s ease-out infinite;
        }

        .pulse-2 {
            width: 120px;
            height: 120px;
            top: 65%;
            right: 20%;
            animation: pulse2 2.5s ease-out infinite 0.3s;
        }

        .pulse-3 {
            width: 180px;
            height: 180px;
            top: 50%;
            left: 50%;
            animation: pulse3 2.5s ease-out infinite 0.6s;
        }

        @keyframes pulse1 {
            0% {
                transform: scale(0.5) translate(0, 0);
                opacity: 1;
                border-color: rgba(77, 124, 15, 1);
                box-shadow: 0 0 20px rgba(77, 124, 15, 0.8);
            }
            100% {
                transform: scale(2.5) translate(0, 0);
                opacity: 0;
                border-color: rgba(77, 124, 15, 0);
                box-shadow: 0 0 0px rgba(77, 124, 15, 0);
            }
        }

        @keyframes pulse2 {
            0% {
                transform: scale(0.5) translate(0, 0);
                opacity: 1;
                border-color: rgba(77, 124, 15, 1);
                box-shadow: 0 0 20px rgba(77, 124, 15, 0.8);
            }
            100% {
                transform: scale(2.5) translate(0, 0);
                opacity: 0;
                border-color: rgba(77, 124, 15, 0);
                box-shadow: 0 0 0px rgba(77, 124, 15, 0);
            }
        }

        @keyframes pulse3 {
            0% {
                transform: translateX(-50%) scale(0.5);
                opacity: 1;
                border-color: rgba(77, 124, 15, 1);
                box-shadow: 0 0 20px rgba(77, 124, 15, 0.8);
            }
            100% {
                transform: translateX(-50%) scale(2.5);
                opacity: 0;
                border-color: rgba(77, 124, 15, 0);
                box-shadow: 0 0 0px rgba(77, 124, 15, 0);
            }
        }

        /* Medical Particles Animation */
        .medical-particles {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: radial-gradient(circle, #4D7C0F, rgba(77, 124, 15, 0.3));
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(77, 124, 15, 0.5);
            animation: floatAround 20s infinite ease-in-out;
        }

        .particle.medical-icon {
            width: 50px;
            height: 50px;
            background: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #4D7C0F;
            box-shadow: none;
        }

        .particle.medical-icon i {
            filter: drop-shadow(0 0 15px rgba(77, 124, 15, 0.8));
        }

        .medical-particles .particle:nth-child(1) {
            left: 5%;
            top: 15%;
            animation-delay: 0s;
            animation-duration: 18s;
        }

        .medical-particles .particle:nth-child(2) {
            left: 85%;
            top: 55%;
            animation-delay: 2s;
            animation-duration: 20s;
        }

        .medical-particles .particle:nth-child(3) {
            left: 50%;
            top: 25%;
            animation-delay: 4s;
            animation-duration: 19s;
        }

        .medical-particles .particle:nth-child(4) {
            left: 15%;
            top: 75%;
            animation-delay: 1s;
            animation-duration: 21s;
        }

        .medical-particles .particle:nth-child(5) {
            left: 75%;
            top: 5%;
            animation-delay: 3s;
            animation-duration: 22s;
        }

        @keyframes floatAround {
            0% {
                transform: translate(0, 0) scale(0.8);
                opacity: 0.3;
            }
            15% {
                opacity: 0.8;
            }
            50% {
                transform: translate(150px, -150px) scale(1.5);
                opacity: 1;
            }
            85% {
                opacity: 0.6;
            }
            100% {
                transform: translate(0, 0) scale(0.8);
                opacity: 0.3;
            }
        }
    </style>
</head>
<body>
    <!-- Animated Medical Background -->
    <div class="medical-background">
        <!-- Heartbeat Grid -->
        <svg class="medical-grid" viewBox="0 0 1000 1000" preserveAspectRatio="none">
            <!-- ECG Line Animation -->
            <polyline class="ecg-line" points="0,500 100,500 150,450 200,550 250,500 350,500 400,480 450,520 500,500 600,500 650,450 700,550 750,500 850,500 900,480 950,520 1000,500" />
        </svg>

        <!-- Floating Medical Icons -->
        <div class="medical-particles">
            <div class="particle medical-icon">
                <i class="fas fa-heart"></i>
            </div>
            <div class="particle medical-icon">
                <i class="fas fa-flask"></i>
            </div>
            <div class="particle medical-icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="particle medical-icon">
                <i class="fas fa-heartbeat"></i>
            </div>
            <div class="particle medical-icon">
                <i class="fas fa-dna"></i>
            </div>
        </div>

        <!-- Pulse Waves -->
        <div class="pulse-wave pulse-1"></div>
        <div class="pulse-wave pulse-2"></div>
        <div class="pulse-wave pulse-3"></div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-box">
            <svg class="ecg-loading" viewBox="0 0 300 100" preserveAspectRatio="none">
                <polyline class="ecg-line-loading" points="0,50 30,50 45,20 60,80 75,50 90,50 110,40 130,60 150,50 180,50 195,20 210,80 225,50 240,50 260,40 280,60 300,50" />
            </svg>
            <div class="loading-text">Memproses login...</div>
        </div>
    </div>

    <div class="login-container">
        <div class="login-header">
            <img src="assets/images/logo-ris.png?v=<?php echo time(); ?>" alt="Logo RIS" style="max-width: 80px; margin: 0 auto; display: block;">
        </div>

        <div class="login-body">
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($info)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle"></i> <?php echo $info; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               placeholder="Masukkan username" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group password-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Masukkan password" required>
                        <button type="button" class="btn btn-outline-secondary toggle-password" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-login">
                    <i class="fas fa-sign-in-alt"></i> Masuk
                </button>
            </form>

            <div class="text-center mt-4">
                <small class="text-muted">
                    Radiology Information System &copy; 2026
                </small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        const togglePasswordBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        if (togglePasswordBtn && passwordInput) {
            togglePasswordBtn.addEventListener('click', function(e) {
                e.preventDefault();

                // Toggle password visibility
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);

                // Toggle eye icon
                const icon = togglePasswordBtn.querySelector('i');
                if (type === 'password') {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            });
        }

        // Handle loading animation on form submit
        const loginForm = document.querySelector('form');
        if (loginForm) {
            loginForm.addEventListener('submit', function() {
                const loadingOverlay = document.getElementById('loadingOverlay');
                if (loadingOverlay) {
                    loadingOverlay.classList.add('show');
                }
            });
        }
    </script>
</body>
</html>
