<?php
/**
 * API Handler untuk RIS (Radiology Information System)
 * Menangani semua request API dari frontend
 *
 * Authentication: JWT Bearer token ATAU PHP session
 * Response format: JSON { success: bool, message: string, data: mixed }
 *
 * ENDPOINT LIST:
 * ─────────────────────────────────────────────────────────
 * POST   ?action=login                  - Login, return JWT token
 * POST   ?action=logout                 - Logout / invalidate session
 * GET    ?action=get_dashboard_stats    - Statistik dashboard
 * GET    ?action=get_server_info        - Server memory info
 * GET    ?action=get_system_status      - Status koneksi Orthanc & DB
 * GET    ?action=get_recent_exams       - 5 pemeriksaan terbaru
 * GET    ?action=get_examinations       - List pemeriksaan + filter + pagination
 * GET    ?action=get_examination_detail - Detail satu pemeriksaan
 * GET    ?action=get_examination_location - Info kamar/poli per no_rawat
 * GET    ?action=get_patient_data       - Data pasien
 * POST   ?action=save_expertise         - Simpan/update hasil radiologi
 * DELETE ?action=delete_expertise       - Hapus hasil radiologi
 * GET    ?action=get_viewer_study       - Search DICOM study di Orthanc
 * GET    ?action=get_doctors            - List dokter
 * GET    ?action=get_radiology_services - List jenis layanan radiologi
 * ─────────────────────────────────────────────────────────
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';
$method = $_SERVER['REQUEST_METHOD'];

// Actions yang tidak perlu auth
$publicActions = ['login'];

// Auth check (skip untuk public actions)
if (!in_array($action, $publicActions)) {
    $authUser = getAuthUser();
    if (!$authUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Silakan login terlebih dahulu.']);
        exit();
    }
}

$db = new Database();

// Routing
switch ($action) {
    // === AUTH ===
    case 'login':
        handleLogin($db, $method);
        break;
    case 'logout':
        handleLogout($method);
        break;

    // === DASHBOARD ===
    case 'get_dashboard_stats':
        handleGetDashboardStats($db, $method);
        break;
    case 'get_server_info':
        handleGetServerInfo($method);
        break;
    case 'get_system_status':
        handleGetSystemStatus($db, $method);
        break;
    case 'get_recent_exams':
        handleGetRecentExams($db, $method);
        break;

    // === EXAMINATIONS ===
    case 'get_examinations':
        handleGetExaminations($db, $method);
        break;
    case 'get_examination_detail':
        handleGetExaminationDetail($db, $method);
        break;
    case 'get_examination_location':
        handleGetExaminationLocation($db, $method);
        break;

    // === PATIENTS ===
    case 'get_patient_data':
        handleGetPatientData($db, $method);
        break;

    // === EXPERTISE ===
    case 'save_expertise':
        handleSaveExpertise($db, $method);
        break;
    case 'delete_expertise':
        handleDeleteExpertise($db, $method);
        break;

    // === VIEWER / ORTHANC ===
    case 'get_viewer_study':
        handleGetViewerStudy($db, $method);
        break;

    // === MASTER DATA ===
    case 'get_doctors':
        handleGetDoctors($db, $method);
        break;
    case 'get_radiology_services':
        handleGetRadiologyServices($db, $method);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
        break;
}

// ============================================================
// AUTH HANDLERS
// ============================================================

/**
 * POST ?action=login
 * Body: { "username": "xxx", "password": "xxx" }
 * Response: { success, data: { token, user_id, nama_pegawai } }
 */
function handleLogin($db, $method) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username dan password harus diisi']);
        return;
    }

    if (!$db->isConnected()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Database tidak terhubung']);
        return;
    }

    try {
        $query = "SELECT AES_DECRYPT(id_user, ?) as id_user, AES_DECRYPT(password, ?) as password
                  FROM user WHERE id_user = AES_ENCRYPT(?, ?)";
        $user = $db->fetch($query, [ENCRYPT_KEY_USER, ENCRYPT_KEY_PASS, $username, ENCRYPT_KEY_USER]);

        if ($user && $user['password'] === $password) {
            // Fetch nama pegawai
            $pegawai = $db->fetch("SELECT nama FROM pegawai WHERE nik = ?", [$user['id_user']]);
            $namaPegawai = $pegawai['nama'] ?? $user['id_user'];

            // Generate JWT
            $token = generateJWT($user['id_user'], $namaPegawai);

            // Set session juga (backward compatibility)
            $_SESSION['user_id'] = $user['id_user'];
            $_SESSION['nama_pegawai'] = $namaPegawai;
            $_SESSION['last_activity'] = time();

            echo json_encode([
                'success' => true,
                'message' => 'Login berhasil',
                'data' => [
                    'token' => $token,
                    'user_id' => $user['id_user'],
                    'nama_pegawai' => $namaPegawai
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Username atau password salah']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * POST ?action=logout
 */
function handleLogout($method) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logout berhasil']);
}

// ============================================================
// DASHBOARD HANDLERS
// ============================================================

/**
 * GET ?action=get_dashboard_stats
 * Response: statistik pasien, pemeriksaan, expertise hari ini
 */
function handleGetDashboardStats($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    if (!$db->isConnected()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Database tidak terhubung']);
        return;
    }

    $stats = [
        'pasien_hari_ini' => 0,
        'pemeriksaan_hari_ini' => 0,
        'total_pemeriksaan' => 0,
        'total_pasien' => 0,
        'expertise_selesai' => 0,
        'expertise_pending' => 0,
        'expertise_percent' => 0,
    ];

    try {
        $today = date('Y-m-d');

        // Pasien unik hari ini
        $result = $db->fetch(
            "SELECT COUNT(DISTINCT rp.no_rkm_medis) as total
             FROM periksa_radiologi pr
             INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
             WHERE DATE(pr.tgl_periksa) = ?", [$today]
        );
        $stats['pasien_hari_ini'] = intval($result['total'] ?? 0);

        // Pemeriksaan hari ini
        $result = $db->fetch(
            "SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
             FROM periksa_radiologi pr
             WHERE DATE(pr.tgl_periksa) = ?", [$today]
        );
        $stats['pemeriksaan_hari_ini'] = intval($result['total'] ?? 0);

        // Total pemeriksaan keseluruhan
        $result = $db->fetch(
            "SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
             FROM periksa_radiologi pr"
        );
        $stats['total_pemeriksaan'] = intval($result['total'] ?? 0);

        // Total pasien keseluruhan
        $result = $db->fetch(
            "SELECT COUNT(DISTINCT rp.no_rkm_medis) as total
             FROM periksa_radiologi pr
             INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat"
        );
        $stats['total_pasien'] = intval($result['total'] ?? 0);

        // Expertise selesai hari ini
        $result = $db->fetch(
            "SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
             FROM periksa_radiologi pr
             INNER JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
             AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
             WHERE DATE(pr.tgl_periksa) = ?", [$today]
        );
        $stats['expertise_selesai'] = intval($result['total'] ?? 0);

        // Expertise pending hari ini
        $result = $db->fetch(
            "SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
             FROM periksa_radiologi pr
             LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
             AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
             WHERE DATE(pr.tgl_periksa) = ?
             AND hr.no_rawat IS NULL", [$today]
        );
        $stats['expertise_pending'] = intval($result['total'] ?? 0);

        // Persentase
        $totalToday = $stats['expertise_selesai'] + $stats['expertise_pending'];
        if ($totalToday > 0) {
            $stats['expertise_percent'] = round(($stats['expertise_selesai'] / $totalToday) * 100);
        }

        echo json_encode(['success' => true, 'data' => $stats]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * GET ?action=get_server_info
 * Response: server memory usage
 */
function handleGetServerInfo($method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $memory = [
        'total_mb' => 0,
        'used_mb' => 0,
        'free_mb' => 0,
        'percent' => 0
    ];

    if (PHP_OS_FAMILY === 'Windows') {
        $output = [];
        @exec('wmic OS get TotalVisibleMemorySize /Value', $output);
        $totalMemory = 0;
        foreach ($output as $line) {
            if (strpos($line, 'TotalVisibleMemorySize') === 0) {
                $totalMemory = (int)str_replace('TotalVisibleMemorySize=', '', $line);
            }
        }

        $output = [];
        @exec('wmic OS get FreePhysicalMemory /Value', $output);
        $freeMemory = 0;
        foreach ($output as $line) {
            if (strpos($line, 'FreePhysicalMemory') === 0) {
                $freeMemory = (int)str_replace('FreePhysicalMemory=', '', $line);
            }
        }

        if ($totalMemory > 0) {
            $memory['total_mb'] = round($totalMemory / 1024, 2);
            $memory['free_mb'] = round($freeMemory / 1024, 2);
            $memory['used_mb'] = round(($totalMemory - $freeMemory) / 1024, 2);
            $memory['percent'] = round((($totalMemory - $freeMemory) / $totalMemory) * 100);
        }
    } else {
        if (file_exists('/proc/meminfo')) {
            $meminfo = explode("\n", file_get_contents('/proc/meminfo'));
            $memTotal = 0;
            $memFree = 0;

            foreach ($meminfo as $line) {
                if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m)) {
                    $memTotal = (int)$m[1];
                }
                if (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) {
                    $memFree = (int)$m[1];
                }
            }

            if ($memTotal > 0) {
                $memory['total_mb'] = round($memTotal / 1024, 2);
                $memory['free_mb'] = round($memFree / 1024, 2);
                $memory['used_mb'] = round(($memTotal - $memFree) / 1024, 2);
                $memory['percent'] = round((($memTotal - $memFree) / $memTotal) * 100);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'memory' => $memory,
            'php_version' => phpversion(),
            'server_time' => date('Y-m-d H:i:s')
        ]
    ]);
}

/**
 * GET ?action=get_system_status
 * Response: status koneksi Orthanc PACS & Database
 */
function handleGetSystemStatus($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    require_once __DIR__ . '/orthanc.php';

    $dbConnected = $db->isConnected();

    $orthanc = new OrthancAPI();
    $orthancConnected = $orthanc->testConnection();

    $totalStudies = 0;
    $totalWorklist = 0;
    if ($orthancConnected) {
        try {
            $totalStudies = $orthanc->getTotalStudies();
            $totalWorklist = $orthanc->getTotalWorklist();
        } catch (Exception $e) {
            // keep 0
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'database' => [
                'connected' => $dbConnected,
                'host' => DB_HOST
            ],
            'orthanc' => [
                'connected' => $orthancConnected,
                'url' => ORTHANC_URL,
                'total_studies' => $totalStudies,
                'total_worklist' => $totalWorklist
            ]
        ]
    ]);
}

/**
 * GET ?action=get_recent_exams&limit=5
 * Response: pemeriksaan terbaru
 */
function handleGetRecentExams($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    if (!$db->isConnected()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Database tidak terhubung']);
        return;
    }

    $limit = isset($_GET['limit']) ? max(1, min(50, intval($_GET['limit']))) : 5;

    try {
        $query = "SELECT
                    pr.no_rawat,
                    p.nm_pasien,
                    rp.no_rkm_medis,
                    pr.tgl_periksa,
                    pr.jam,
                    jpr.nm_perawatan,
                    hr.hasil,
                    CASE WHEN hr.no_rawat IS NOT NULL THEN 'Selesai' ELSE 'Pending' END as status
                 FROM periksa_radiologi pr
                 INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                 INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                 INNER JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
                 LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
                    AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
                 ORDER BY pr.tgl_periksa DESC, pr.jam DESC
                 LIMIT ?";

        $exams = $db->fetchAll($query, [$limit]);
        echo json_encode(['success' => true, 'data' => $exams]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// EXAMINATION HANDLERS
// ============================================================

/**
 * GET ?action=get_examinations&tgl1=YYYY-MM-DD&tgl2=YYYY-MM-DD&search=xxx&page=1&limit=15
 * Response: list pemeriksaan + pagination info
 */
function handleGetExaminations($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    if (!$db->isConnected()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Database tidak terhubung']);
        return;
    }

    $tgl1 = isset($_GET['tgl1']) ? formatTanggalDB(sanitize($_GET['tgl1'])) : date('Y-m-d');
    $tgl2 = isset($_GET['tgl2']) ? formatTanggalDB(sanitize($_GET['tgl2'])) : date('Y-m-d');
    $search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 15;

    try {
        // Build WHERE clause
        $whereClause = "WHERE pr.tgl_periksa BETWEEN ? AND ?";
        $params = [$tgl1, $tgl2];

        if (!empty($search)) {
            $whereClause .= " AND (pr.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $fromClause = "FROM periksa_radiologi pr
            INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN petugas pt ON pr.nip = pt.nip
            INNER JOIN penjab pj ON rp.kd_pj = pj.kd_pj
            INNER JOIN dokter d ON pr.kd_dokter = d.kd_dokter
            INNER JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
            LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
                AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
            LEFT JOIN permintaan_radiologi perr ON pr.no_rawat = perr.no_rawat
                AND pr.tgl_periksa = perr.tgl_hasil AND pr.jam = perr.jam_hasil";

        // Count query
        $countQuery = "SELECT COUNT(DISTINCT CONCAT(pr.no_rawat, pr.tgl_periksa, pr.jam)) as total
                       $fromClause $whereClause";
        $countResult = $db->fetch($countQuery, $params);
        $totalRecords = intval($countResult['total'] ?? 0);
        $totalPages = $limit > 0 ? ceil($totalRecords / $limit) : 1;

        // Validate page
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $limit;

        // Data query
        $dataQuery = "SELECT
            pr.no_rawat,
            rp.no_rkm_medis,
            p.nm_pasien,
            pt.nama as petugas_nama,
            pr.tgl_periksa,
            pr.jam,
            pr.dokter_perujuk,
            pr.kd_dokter,
            pj.png_jawab,
            d.nm_dokter,
            pr.kd_jenis_prw,
            jpr.nm_perawatan,
            pr.biaya,
            hr.no_foto,
            hr.hasil,
            perr.noorder,
            perr.diagnosa_klinis,
            CASE WHEN hr.no_rawat IS NOT NULL THEN 'Selesai' ELSE 'Pending' END as status_expertise
        $fromClause $whereClause
        GROUP BY CONCAT(pr.no_rawat, pr.tgl_periksa, pr.jam)
        ORDER BY pr.tgl_periksa DESC, pr.jam DESC
        LIMIT ? OFFSET ?";

        $dataParams = array_merge($params, [$limit, $offset]);
        $examinations = $db->fetchAll($dataQuery, $dataParams);

        echo json_encode([
            'success' => true,
            'data' => $examinations,
            'page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * GET ?action=get_examination_detail&no_rawat=X&tgl=Y&jam=Z
 * Response: detail satu pemeriksaan
 */
function handleGetExaminationDetail($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $no_rawat = isset($_GET['no_rawat']) ? sanitize($_GET['no_rawat']) : '';
    $tgl = isset($_GET['tgl']) ? sanitize($_GET['tgl']) : '';
    $jam = isset($_GET['jam']) ? sanitize($_GET['jam']) : '';

    if (empty($no_rawat) || empty($tgl) || empty($jam)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap (no_rawat, tgl, jam)']);
        return;
    }

    try {
        $exam = $db->fetch(
            "SELECT pr.no_rawat, rp.no_rkm_medis, p.nm_pasien, pr.tgl_periksa, pr.jam,
                    d.nm_dokter, pr.kd_dokter, jpr.nm_perawatan, pr.kd_jenis_prw, pr.biaya,
                    pr.dokter_perujuk, pt.nama as petugas_nama,
                    perr.diagnosa_klinis, perr.noorder,
                    hr.no_foto, hr.hasil, hr.created_at, hr.updated_at,
                    pj.png_jawab,
                    CASE WHEN hr.no_rawat IS NOT NULL THEN 'Selesai' ELSE 'Pending' END as status_expertise
             FROM periksa_radiologi pr
             INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
             INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
             INNER JOIN dokter d ON pr.kd_dokter = d.kd_dokter
             INNER JOIN petugas pt ON pr.nip = pt.nip
             INNER JOIN penjab pj ON rp.kd_pj = pj.kd_pj
             INNER JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
             LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
                AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
             LEFT JOIN permintaan_radiologi perr ON pr.no_rawat = perr.no_rawat
                AND pr.tgl_periksa = perr.tgl_hasil AND pr.jam = perr.jam_hasil
             WHERE pr.no_rawat = ? AND pr.tgl_periksa = ? AND pr.jam = ?",
            [$no_rawat, $tgl, $jam]
        );

        if (!$exam) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
            return;
        }

        echo json_encode(['success' => true, 'data' => $exam]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * GET ?action=get_examination_location&no_rawat=X
 * Response: info kamar/bangsal atau poli untuk no_rawat
 */
function handleGetExaminationLocation($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $no_rawat = isset($_GET['no_rawat']) ? sanitize($_GET['no_rawat']) : '';

    if (empty($no_rawat)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parameter no_rawat diperlukan']);
        return;
    }

    try {
        $location = ['label' => '', 'value' => ''];

        // Step 1: Cek kamar inap
        $kamarResult = $db->fetch(
            "SELECT IFNULL(kd_kamar, '') as kd_kamar
             FROM kamar_inap WHERE no_rawat = ?
             ORDER BY tgl_masuk DESC LIMIT 1",
            [$no_rawat]
        );
        $kamar = $kamarResult['kd_kamar'] ?? '';

        if (!empty($kamar)) {
            // Ambil nama bangsal via tabel kamar
            $bangResult = $db->fetch(
                "SELECT bangsal.nm_bangsal
                 FROM bangsal
                 INNER JOIN kamar ON bangsal.kd_bangsal = kamar.kd_bangsal
                 WHERE kamar.kd_kamar = ?",
                [$kamar]
            );
            $bangsal = $bangResult['nm_bangsal'] ?? '';

            // Fallback: via kamar_inap
            if (empty($bangsal)) {
                $bangResult2 = $db->fetch(
                    "SELECT bangsal.nm_bangsal
                     FROM bangsal
                     INNER JOIN kamar_inap ON bangsal.kd_bangsal = kamar_inap.kd_bangsal
                     WHERE kamar_inap.kd_kamar = ? LIMIT 1",
                    [$kamar]
                );
                $bangsal = $bangResult2['nm_bangsal'] ?? '';
            }

            if (!empty($bangsal)) {
                $location = [
                    'label' => 'Kamar',
                    'value' => $kamar . ', ' . $bangsal
                ];
            }
        }

        // Step 2: Fallback ke poli jika tidak ada kamar
        if (empty($location['value'])) {
            $poliResult = $db->fetch(
                "SELECT poliklinik.nm_poli
                 FROM poliklinik
                 INNER JOIN reg_periksa ON poliklinik.kd_poli = reg_periksa.kd_poli
                 WHERE reg_periksa.no_rawat = ? LIMIT 1",
                [$no_rawat]
            );
            $poli = $poliResult['nm_poli'] ?? '';

            if (!empty($poli)) {
                $location = [
                    'label' => 'Poli',
                    'value' => $poli
                ];
            }
        }

        echo json_encode(['success' => true, 'data' => $location]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// PATIENT HANDLERS
// ============================================================

/**
 * GET ?action=get_patient_data&no_rkm_medis=X
 */
function handleGetPatientData($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $no_rkm_medis = isset($_GET['no_rkm_medis']) ? sanitize($_GET['no_rkm_medis']) : '';

    if (empty($no_rkm_medis)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parameter no_rkm_medis diperlukan']);
        return;
    }

    try {
        $patient = $db->fetch(
            "SELECT no_rkm_medis, nm_pasien, alamat, no_telp FROM pasien WHERE no_rkm_medis = ?",
            [$no_rkm_medis]
        );

        if (!$patient) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pasien tidak ditemukan']);
            return;
        }

        echo json_encode(['success' => true, 'data' => $patient]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// EXPERTISE HANDLERS
// ============================================================

/**
 * POST ?action=save_expertise
 * Body: { no_rawat, tgl_periksa, jam, hasil, no_foto }
 */
function handleSaveExpertise($db, $method) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $no_rawat = isset($input['no_rawat']) ? sanitize($input['no_rawat']) : '';
    $tgl_periksa = isset($input['tgl_periksa']) ? sanitize($input['tgl_periksa']) : '';
    $jam = isset($input['jam']) ? sanitize($input['jam']) : '';
    $hasil = isset($input['hasil']) ? trim($input['hasil']) : '';
    $no_foto = isset($input['no_foto']) ? sanitize($input['no_foto']) : '';

    if (empty($no_rawat) || empty($tgl_periksa) || empty($jam)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap (no_rawat, tgl_periksa, jam)']);
        return;
    }

    try {
        if (empty($hasil)) {
            // Delete jika hasil kosong
            $db->delete('hasil_radiologi', [
                'no_rawat' => $no_rawat,
                'tgl_periksa' => $tgl_periksa,
                'jam' => $jam
            ]);
            echo json_encode(['success' => true, 'message' => 'Hasil radiologi berhasil dihapus']);
        } else {
            // Check if exists
            $existing = $db->fetch(
                "SELECT id FROM hasil_radiologi WHERE no_rawat = ? AND tgl_periksa = ? AND jam = ?",
                [$no_rawat, $tgl_periksa, $jam]
            );

            if ($existing) {
                $result = $db->update(
                    'hasil_radiologi',
                    ['hasil' => $hasil, 'no_foto' => $no_foto, 'updated_at' => date('Y-m-d H:i:s')],
                    ['no_rawat' => $no_rawat, 'tgl_periksa' => $tgl_periksa, 'jam' => $jam]
                );
            } else {
                $result = $db->insert('hasil_radiologi', [
                    'no_rawat' => $no_rawat,
                    'tgl_periksa' => $tgl_periksa,
                    'jam' => $jam,
                    'no_foto' => $no_foto,
                    'hasil' => $hasil,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Expertise berhasil disimpan']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error: ' . $db->getLastError()]);
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * DELETE ?action=delete_expertise&no_rawat=X&tgl_periksa=Y&jam=Z
 */
function handleDeleteExpertise($db, $method) {
    if ($method !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $no_rawat = isset($_GET['no_rawat']) ? sanitize($_GET['no_rawat']) : '';
    $tgl_periksa = isset($_GET['tgl_periksa']) ? sanitize($_GET['tgl_periksa']) : '';
    $jam = isset($_GET['jam']) ? sanitize($_GET['jam']) : '';

    if (empty($no_rawat) || empty($tgl_periksa) || empty($jam)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap (no_rawat, tgl_periksa, jam)']);
        return;
    }

    try {
        $existing = $db->fetch(
            "SELECT no_rawat FROM hasil_radiologi WHERE no_rawat = ? AND tgl_periksa = ? AND jam = ?",
            [$no_rawat, $tgl_periksa, $jam]
        );

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Hasil radiologi tidak ditemukan']);
            return;
        }

        $result = $db->delete('hasil_radiologi', [
            'no_rawat' => $no_rawat,
            'tgl_periksa' => $tgl_periksa,
            'jam' => $jam
        ]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Hasil radiologi berhasil dihapus']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error: ' . $db->getLastError()]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================================
// VIEWER / ORTHANC HANDLERS
// ============================================================

/**
 * GET ?action=get_viewer_study&no_rawat=X&tgl=Y&noorder=W
 * Response: studyInstanceUID dan OHIF viewer URL
 * Parameter jam bersifat opsional
 */
function handleGetViewerStudy($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $no_rawat = isset($_GET['no_rawat']) ? sanitize($_GET['no_rawat']) : '';
    $tgl = isset($_GET['tgl']) ? sanitize($_GET['tgl']) : '';
    $jam = isset($_GET['jam']) ? sanitize($_GET['jam']) : '';
    $noorder = isset($_GET['noorder']) ? sanitize($_GET['noorder']) : '';

    if (empty($no_rawat) || empty($tgl)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap (no_rawat, tgl)']);
        return;
    }

    // Get exam data for PatientID
    try {
        // Query dinamis: dengan atau tanpa jam
        $query = "SELECT rp.no_rkm_medis, pr.tgl_periksa, perr.noorder
             FROM periksa_radiologi pr
             INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
             LEFT JOIN permintaan_radiologi perr ON pr.no_rawat = perr.no_rawat
                AND pr.tgl_periksa = perr.tgl_hasil AND pr.jam = perr.jam_hasil
             WHERE pr.no_rawat = ? AND pr.tgl_periksa = ?";
        $params = [$no_rawat, $tgl];

        if (!empty($jam)) {
            $query .= " AND pr.jam = ?";
            $params[] = $jam;
        }

        $query .= " LIMIT 1";

        $exam = $db->fetch($query, $params);

        if (!$exam) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pemeriksaan tidak ditemukan']);
            return;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        return;
    }

    // Use noorder from param or from database
    if (empty($noorder)) {
        $noorder = $exam['noorder'] ?? '';
    }

    require_once __DIR__ . '/orthanc.php';
    $orthanc = new OrthancAPI();

    if (!$orthanc->testConnection()) {
        echo json_encode([
            'success' => false,
            'message' => 'Orthanc PACS tidak terhubung',
            'data' => ['orthanc_connected' => false]
        ]);
        return;
    }

    $studyInstanceUID = null;
    $searchUrl = ORTHANC_URL . '/tools/find';

    // Priority 1: Search by AccessionNumber
    if (!empty($noorder)) {
        $query = json_encode([
            'Level' => 'Study',
            'Query' => ['AccessionNumber' => $noorder]
        ]);
        $result = performOrthancSearch($searchUrl, $query);

        if ($result && !empty($result)) {
            $studyId = $result[0];
            $studyData = getStudyInstanceUID($studyId);
            if ($studyData) {
                $studyInstanceUID = $studyData;
            }
        }
    }

    // Priority 2: Fallback to PatientID + StudyDate
    if (empty($studyInstanceUID) && !empty($exam['no_rkm_medis']) && !empty($exam['tgl_periksa'])) {
        $formattedDate = str_replace('-', '', $exam['tgl_periksa']);

        $query = json_encode([
            'Level' => 'Study',
            'Query' => [
                'PatientID' => $exam['no_rkm_medis'],
                'StudyDate' => $formattedDate
            ]
        ]);
        $result = performOrthancSearch($searchUrl, $query);

        if ($result && !empty($result)) {
            $studyId = $result[0];
            $studyData = getStudyInstanceUID($studyId);
            if ($studyData) {
                $studyInstanceUID = $studyData;
            }
        }
    }

    if ($studyInstanceUID) {
        $ohifUrl = ORTHANC_URL . '/ohif/viewer?StudyInstanceUIDs=' . urlencode($studyInstanceUID);
        echo json_encode([
            'success' => true,
            'data' => [
                'study_instance_uid' => $studyInstanceUID,
                'ohif_url' => $ohifUrl,
                'orthanc_connected' => true
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Study tidak ditemukan di Orthanc',
            'data' => [
                'study_instance_uid' => null,
                'ohif_url' => null,
                'orthanc_connected' => true
            ]
        ]);
    }
}

/**
 * Helper: Perform search di Orthanc /tools/find
 */
function performOrthancSearch($url, $postData) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USERNAME . ':' . ORTHANC_PASSWORD);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

/**
 * Helper: Get StudyInstanceUID dari Orthanc study ID
 */
function getStudyInstanceUID($studyId) {
    $url = ORTHANC_URL . '/studies/' . $studyId;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USERNAME . ':' . ORTHANC_PASSWORD);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        return $data['MainDicomTags']['StudyInstanceUID'] ?? null;
    }
    return null;
}

// ============================================================
// MASTER DATA HANDLERS
// ============================================================

/**
 * GET ?action=get_doctors&search=xxx
 * Response: list dokter
 */
function handleGetDoctors($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    if (!$db->isConnected()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Database tidak terhubung']);
        return;
    }

    $search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

    try {
        if (!empty($search)) {
            $doctors = $db->fetchAll(
                "SELECT kd_dokter, nm_dokter FROM dokter WHERE nm_dokter LIKE ? ORDER BY nm_dokter ASC LIMIT 50",
                ['%' . $search . '%']
            );
        } else {
            $doctors = $db->fetchAll(
                "SELECT kd_dokter, nm_dokter FROM dokter ORDER BY nm_dokter ASC LIMIT 50"
            );
        }

        echo json_encode(['success' => true, 'data' => $doctors]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * GET ?action=get_radiology_services&search=xxx
 * Response: list jenis layanan radiologi
 */
function handleGetRadiologyServices($db, $method) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    if (!$db->isConnected()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Database tidak terhubung']);
        return;
    }

    $search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

    try {
        if (!empty($search)) {
            $services = $db->fetchAll(
                "SELECT kd_jenis_prw, nm_perawatan, biaya FROM jns_perawatan_radiologi
                 WHERE nm_perawatan LIKE ? ORDER BY nm_perawatan ASC",
                ['%' . $search . '%']
            );
        } else {
            $services = $db->fetchAll(
                "SELECT kd_jenis_prw, nm_perawatan, biaya FROM jns_perawatan_radiologi
                 ORDER BY nm_perawatan ASC"
            );
        }

        echo json_encode(['success' => true, 'data' => $services]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

?>
