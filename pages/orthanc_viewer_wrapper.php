<?php
/**
 * Orthanc Viewer Wrapper - Handle authentication untuk OHIF Viewer
 * Digunakan untuk mobile/tablet yang iframe tidak forward auth
 */

session_start();

// Check login RIS
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/Database.php';
require_once '../includes/functions.php';
require_once '../includes/session-security.php';

// Get parameters
$studyInstanceUID = isset($_GET['StudyInstanceUIDs']) ? sanitize($_GET['StudyInstanceUIDs']) : '';
$viewerType = isset($_GET['viewer']) ? sanitize($_GET['viewer']) : 'ohif';

if (empty($studyInstanceUID)) {
    exit('Missing StudyInstanceUIDs parameter');
}

// Redirect ke Orthanc dengan credentials di URL (untuk bypass login sekali)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // User submit login form
    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // Verify credentials dengan Orthanc
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, ORTHANC_URL . '/system');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        // Auth successful - store in session
        $_SESSION['orthanc_auth'] = base64_encode($username . ':' . $password);

        // Redirect ke viewer
        header('Location: ' . $_SERVER['REQUEST_URI'] . '&auth=1');
        exit();
    } else {
        $error = 'Credentials tidak valid. Cek username dan password Orthanc.';
    }
}

// Build OHIF URL
if ($viewerType === 'segmentation') {
    $ohifUrl = ORTHANC_URL . '/ohif/segmentation?StudyInstanceUIDs=' . urlencode($studyInstanceUID);
} else {
    $ohifUrl = ORTHANC_URL . '/ohif/viewer?StudyInstanceUIDs=' . urlencode($studyInstanceUID);
}

// Add auth if available
if (isset($_SESSION['orthanc_auth'])) {
    $auth = base64_decode($_SESSION['orthanc_auth']);
    list($user, $pass) = explode(':', $auth);
    $ohifUrl = str_replace('://', '://' . urlencode($user) . ':' . urlencode($pass) . '@', $ohifUrl);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OHIF Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f5f5;
        }
        .viewer-wrapper {
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .viewer-container {
            flex: 1;
            overflow: hidden;
        }
        .viewer-container iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        .login-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .login-card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .login-card h4 {
            margin-bottom: 20px;
            color: #333;
        }
        .alert {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="viewer-wrapper">
        <div class="viewer-container">
            <?php if (!isset($_SESSION['orthanc_auth'])): ?>
            <!-- Login Modal -->
            <div class="login-modal">
                <div class="login-card">
                    <h4>Login Orthanc PACS</h4>

                    <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <p class="text-muted small mb-3">
                        Masukkan credentials Orthanc untuk melanjutkan.
                    </p>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" required autofocus
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>

                        <button type="submit" name="login" class="btn btn-primary w-100">
                            Login
                        </button>

                        <hr class="my-3">

                        <a href="dashboard.php" class="btn btn-secondary w-100">
                            Kembali
                        </a>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <!-- OHIF Viewer -->
            <iframe src="<?php echo htmlspecialchars($ohifUrl); ?>" id="ohifViewer"></iframe>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
