<?php
session_start();

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/Database.php';
require_once '../includes/functions.php';
require_once '../includes/session-security.php';
require_once '../api/orthanc.php';

$db = new Database();
$user = getUser();

// Jika user tidak ada, redirect ke login
if (!$user) {
    $user = ['id_user' => 'User'];
}

// Check database connection
if (!$db->isConnected()) {
    $error = 'Koneksi database gagal. Pastikan database server (192.168.100.108) online dan accessible.
              Detail: ' . $db->getLastError();
    $examinations = [];
} else {
    $error = '';
}

// Inisialisasi variabel filter dan pagination
$tgl1 = isset($_GET['tgl1']) ? formatTanggalDB($_GET['tgl1']) : date('Y-m-d');
$tgl2 = isset($_GET['tgl2']) ? formatTanggalDB($_GET['tgl2']) : date('Y-m-d');
$cari = isset($_GET['cari']) ? sanitize($_GET['cari']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15; // Jumlah baris per halaman
$offset = ($page - 1) * $perPage;

// Build WHERE clause untuk digunakan di kedua query (count dan select)
$whereClause = "WHERE pr.tgl_periksa BETWEEN ? AND ?";
$params = [$tgl1, $tgl2];

// Smart search: bisa cari by no_rawat, no_rkm_medis, atau nama pasien
if (!empty($cari)) {
    $whereClause .= " AND (pr.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ?)";
    $params[] = '%' . $cari . '%';
    $params[] = '%' . $cari . '%';
    $params[] = '%' . $cari . '%';
}

// Only execute query if database is connected
if ($db->isConnected()) {
    try {
        // Query 1: Get total count untuk pagination
        $countQuery = "SELECT COUNT(DISTINCT CONCAT(pr.no_rawat, pr.tgl_periksa, pr.jam)) as total
                       FROM periksa_radiologi pr
                       INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                       INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                       INNER JOIN petugas pt ON pr.nip = pt.nip
                       INNER JOIN penjab pj ON rp.kd_pj = pj.kd_pj
                       INNER JOIN dokter d ON pr.kd_dokter = d.kd_dokter
                       INNER JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
                       LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
                       INNER JOIN permintaan_radiologi perr ON pr.no_rawat = perr.no_rawat AND pr.tgl_periksa = perr.tgl_hasil AND pr.jam = perr.jam_hasil
                       " . $whereClause;

        $countResult = $db->fetch($countQuery, $params);
        $totalRecords = intval($countResult['total'] ?? 0);
        $totalPages = ceil($totalRecords / $perPage);

        // Validasi page number
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        // Query 2: Get data dengan pagination
        $query = "SELECT
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
            perr.diagnosa_klinis
        FROM periksa_radiologi pr
        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
        INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
        INNER JOIN petugas pt ON pr.nip = pt.nip
        INNER JOIN penjab pj ON rp.kd_pj = pj.kd_pj
        INNER JOIN dokter d ON pr.kd_dokter = d.kd_dokter
        INNER JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
        LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
        INNER JOIN permintaan_radiologi perr ON pr.no_rawat = perr.no_rawat AND pr.tgl_periksa = perr.tgl_hasil AND pr.jam = perr.jam_hasil
        " . $whereClause . "
        GROUP BY CONCAT(pr.no_rawat, pr.tgl_periksa, pr.jam)
        ORDER BY pr.tgl_periksa DESC, pr.jam DESC
        LIMIT ? OFFSET ?";

        $dataParams = array_merge($params, [$perPage, $offset]);
        $examinations = $db->fetchAll($query, $dataParams);
    } catch (Exception $e) {
        $examinations = [];
        $totalRecords = 0;
        $totalPages = 0;
        $error = 'Error mengambil data: ' . $e->getMessage();
    }
} else {
    $examinations = [];
    $totalRecords = 0;
    $totalPages = 0;
    if (empty($error)) {
        $error = 'Database tidak terhubung. Silakan cek koneksi ke server.';
    }
}

// Pre-fetch kamar info, poli, dan TTD untuk semua no_rawat (optimize N+1 query problem)
$kamarInfoCache = [];
$ttdStatusCache = [];
if (!empty($examinations) && $db->isConnected()) {
    $no_rawatList = array_unique(array_column($examinations, 'no_rawat'));

    // Fetch TTD status untuk semua no_rawat
    try {
        $placeholders = implode(',', array_fill(0, count($no_rawatList), '?'));
        $ttdQuery = "SELECT DISTINCT no_rawat FROM expertise_ttd_dokter WHERE no_rawat IN (" . $placeholders . ")";
        $ttdResults = $db->fetchAll($ttdQuery, $no_rawatList);

        foreach ($ttdResults as $ttdRow) {
            $ttdStatusCache[$ttdRow['no_rawat']] = true;
        }
    } catch (Exception $e) {
        // Skip jika ada error
    }

    // Untuk setiap no_rawat, ambil kd_kamar terbaru
    foreach ($no_rawatList as $no_rawat) {
        try {
            // Step 1: Ambil kamar terbaru
            $kamarQuery = "SELECT IFNULL(kd_kamar, '') as kd_kamar
                           FROM kamar_inap
                           WHERE no_rawat = ?
                           ORDER BY tgl_masuk DESC
                           LIMIT 1";
            $kamarResult = $db->fetch($kamarQuery, [$no_rawat]);
            $kamar = ($kamarResult['kd_kamar'] ?? '');

            if (!empty($kamar)) {
                // Jika ada kamar, ambil nm_bangsal
                // Try approach 1: join ke table kamar (singular)
                $bangQuery = "SELECT bangsal.nm_bangsal
                              FROM bangsal
                              INNER JOIN kamar ON bangsal.kd_bangsal = kamar.kd_bangsal
                              WHERE kamar.kd_kamar = ?";
                $bangResult = $db->fetch($bangQuery, [$kamar]);
                $bangsal = ($bangResult['nm_bangsal'] ?? '');

                // If not found, try approach 2: join ke kamar_inap
                if (empty($bangsal)) {
                    $bangQuery2 = "SELECT bangsal.nm_bangsal
                                   FROM bangsal
                                   INNER JOIN kamar_inap ON bangsal.kd_bangsal = kamar_inap.kd_bangsal
                                   WHERE kamar_inap.kd_kamar = ?
                                   LIMIT 1";
                    $bangResult2 = $db->fetch($bangQuery2, [$kamar]);
                    $bangsal = ($bangResult2['nm_bangsal'] ?? '');
                }

                if (!empty($bangsal)) {
                    $namakamar = $kamar . ", " . $bangsal;

                    $kamarInfoCache[$no_rawat] = [
                        'label' => 'Kamar',
                        'value' => $namakamar
                    ];
                }
            }

            // Jika belum ada kamar, ambil dari poli
            if (empty($kamarInfoCache[$no_rawat])) {
                $poliQuery = "SELECT poliklinik.nm_poli
                              FROM poliklinik
                              INNER JOIN reg_periksa ON poliklinik.kd_poli = reg_periksa.kd_poli
                              WHERE reg_periksa.no_rawat = ?
                              LIMIT 1";
                $poliResult = $db->fetch($poliQuery, [$no_rawat]);
                $namakamar = ($poliResult['nm_poli'] ?? '');

                if (!empty($namakamar)) {
                    $kamarInfoCache[$no_rawat] = [
                        'label' => 'Poli',
                        'value' => $namakamar
                    ];
                }
            }
        } catch (Exception $e) {
            // Skip
            continue;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemeriksaan - RIS RS Tk.III dr. Reksodiwiryo</title>
    <link rel="icon" type="image/png" href="../assets/images/logo-ris.png?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tempusdominus-bootstrap-4@5.39.0/build/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary-color: #4D7C0F;
            --primary-light: #EBF5D4;
            --primary-dark: #3D5C0D;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --text-dark: #212529;
            --text-gray: #6c757d;
            --border-light: #dee2e6;
            --bg-light: #f8f9fa;
            --bg-gray: #e9ecef;
            --sidebar-bg: #ffffff;
            --sidebar-hover: #f5f6fa;
        }

        html {
            height: 100%;
        }

        body {
            padding-top: 44px;
            background: var(--bg-light);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 13px;
            min-height: 100%;
            display: flex;
            flex-direction: column;
        }

        .container-fluid {
            padding: 8px 16px;
        }

        .container-fluid.py-4 {
            padding-top: 20px !important;
            padding-bottom: 8px !important;
            margin-top: 16px;
        }

        .card.mb-4 {
            margin-bottom: 8px !important;
        }

        /* Navbar - AdminLTE Style */
        .navbar-sticky {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            z-index: 1020;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%) !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 0 !important;
            margin: 0 !important;
            min-height: 50px;
        }

        .navbar-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 6px 12px !important;
            min-height: 50px;
            flex-wrap: wrap;
        }

        .navbar-brand {
            font-weight: 700;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            padding: 0 !important;
            margin: 0 !important;
            flex: 1;
            min-width: 0;
            font-size: 14px;
        }

        .navbar-logo {
            height: 32px;
            flex-shrink: 0;
            margin-right: 6px;
        }

        .navbar-text-lg,
        .navbar-text-md,
        .navbar-text-sm {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .navbar-text-lg {
            font-size: 15px;
            display: inline;
        }

        .navbar-text-md {
            font-size: 13px;
            display: none;
        }

        .navbar-text-sm {
            font-size: 12px;
            display: none;
        }

        /* Server Time Display */
        .navbar-time {
            text-align: right;
            white-space: nowrap;
            display: flex;
            flex-direction: column;
            justify-content: center;
            flex-shrink: 0;
            padding: 0 8px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
        }

        .navbar-date {
            font-size: 11px;
            line-height: 1;
        }

        .navbar-clock {
            font-size: 12px;
            color: #fff;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            line-height: 1;
            margin-top: 2px;
        }

        /* Desktop */
        @media (min-width: 992px) {
            .navbar-content {
                gap: 12px;
                padding: 8px 16px !important;
            }

            .navbar-brand {
                font-size: 15px;
                gap: 8px;
                flex: 1;
                min-width: 0;
            }

            .navbar-logo {
                height: 32px;
            }

            .navbar-text-lg {
                display: inline;
                font-size: 15px;
                white-space: normal;
            }

            .navbar-time {
                padding: 0 12px;
                flex-shrink: 0;
            }

            .navbar-date {
                font-size: 12px;
            }

            .navbar-clock {
                font-size: 14px;
            }
        }

        /* Tablet (768px - 991px) */
        @media (min-width: 768px) and (max-width: 991px) {
            .navbar-content {
                gap: 10px;
                padding: 6px 12px !important;
            }

            .navbar-brand {
                font-size: 13px;
                gap: 6px;
                flex: 1;
                min-width: 0;
            }

            .navbar-logo {
                height: 28px;
            }

            .navbar-text-lg {
                display: inline;
                font-size: 13px;
                white-space: normal;
            }

            .navbar-time {
                padding: 0 8px;
                flex-shrink: 0;
            }

            .navbar-date {
                font-size: 10px;
            }

            .navbar-clock {
                font-size: 11px;
            }
        }

        /* Mobile (480px - 767px) */
        @media (min-width: 480px) and (max-width: 767px) {
            .navbar-content {
                gap: 6px;
                padding: 5px 10px !important;
                min-height: 48px;
            }

            .navbar-brand {
                font-size: 11px;
                gap: 4px;
                flex: 1;
                min-width: 0;
            }

            .navbar-logo {
                height: 24px;
                margin-right: 4px;
            }

            .navbar-text-lg {
                display: inline;
                font-size: 11px;
                white-space: normal;
            }

            .navbar-time {
                padding: 0 4px;
                font-size: 10px;
                flex-shrink: 0;
            }

            .navbar-date {
                font-size: 9px;
            }

            .navbar-clock {
                font-size: 10px;
                margin-top: 1px;
            }
        }

        /* Mobile Small (<480px) */
        @media (max-width: 479px) {
            .navbar-content {
                gap: 4px;
                padding: 4px 8px !important;
                min-height: 46px;
            }

            .navbar-brand {
                font-size: 10px;
                gap: 2px;
                flex: 1;
                min-width: 0;
            }

            .navbar-logo {
                height: 22px;
                margin-right: 2px;
            }

            .navbar-text-lg {
                display: inline;
                font-size: 9px;
                white-space: normal;
            }

            .navbar-time {
                padding: 0 2px;
                font-size: 9px;
                flex-shrink: 0;
            }

            .navbar-date {
                font-size: 8px;
            }

            .navbar-clock {
                font-size: 8px;
                margin-top: 1px;
            }
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8) !important;
            font-weight: 500;
            font-size: 13px;
            margin-left: 12px !important;
            padding: 8px 12px !important;
            transition: all 0.3s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            color: white !important;
        }

        .dropdown-menu {
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border-radius: 4px;
            margin-top: 6px;
            padding: 0;
            min-width: 180px;
        }

        .dropdown-item {
            padding: 10px 16px;
            font-size: 13px;
            color: var(--text-dark);
            transition: all 0.2s ease;
            border: none;
        }

        .dropdown-item:hover {
            background: var(--primary-light);
            color: var(--primary-color);
        }

        .dropdown-divider {
            margin: 4px 0 !important;
        }

        /* Sidebar - AdminLTE Style */
        .sidebar {
            position: fixed;
            top: 50px;
            left: 0;
            width: 260px;
            height: calc(100vh - 50px);
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-light);
            box-shadow: 1px 0 3px rgba(0, 0, 0, 0.08);
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: #bbb;
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: #999;
        }

        .sidebar-menu {
            list-style: none;
            padding: 12px 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin: 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-dark);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover {
            background: var(--sidebar-hover);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
        }

        .sidebar-menu a.active {
            background: var(--primary-light);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
            font-weight: 600;
        }

        .sidebar-menu i {
            font-size: 16px;
            width: 18px;
            text-align: center;
            color: inherit;
        }

        .sidebar-divider {
            height: 1px;
            background: var(--border-light);
            margin: 8px 0;
        }

        .sidebar-section-title {
            padding: 10px 16px 6px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Main content with sidebar */
        .main-wrapper {
            margin-left: 260px;
            transition: margin-left 0.3s ease;
            flex: 1;
        }

        .sidebar-toggle-btn {
            background: transparent !important;
            color: white !important;
            border: none !important;
            font-size: 18px !important;
            padding: 8px 12px !important;
            margin-right: 12px !important;
        }

        .sidebar-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.1) !important;
        }

        /* Page Loading Overlay */
        .page-loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .page-loading-overlay.show {
            display: flex;
        }

        .page-loading-overlay .spinner-box {
            background: white;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .page-loading-overlay .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4D7C0F;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px auto;
        }

        .page-loading-overlay .loading-text {
            color: #2c3e50;
            font-weight: 500;
            font-size: 14px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Sidebar Hidden State */
        .sidebar.hidden {
            transform: translateX(-100%);
        }

        .main-wrapper.sidebar-hidden {
            margin-left: 0;
        }

        /* Dashboard Stats */
        .stat-card {
            background: white;
            border: none;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .stat-card-icon {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }

        .stat-card-title {
            font-size: 12px;
            color: var(--text-gray);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-card-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .stat-card-subtitle {
            font-size: 11px;
            color: var(--text-gray);
            margin-top: 8px;
        }

        .icon-blue {
            background: rgba(77, 124, 15, 0.1);
            color: var(--primary-color);
        }

        .icon-green {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .icon-orange {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .icon-red {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        /* Card Styling */
        .card {
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        .card-header {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-bottom: 1px solid var(--border-light);
            padding: 12px 16px;
        }

        .card-header .card-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .card-header .card-title i {
            color: var(--primary-color);
            font-size: 16px;
        }

        .card-body {
            padding: 16px;
        }

        /* Table */
        .table {
            margin-bottom: 0;
            font-size: 12px;
        }

        .table thead th {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
            padding: 10px 14px;
            background: var(--bg-gray);
        }

        .table tbody tr:hover {
            background: var(--primary-light);
        }

        .table tbody td {
            padding: 12px 14px;
            vertical-align: middle;
            color: var(--text-dark);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success-color);
            font-weight: 600;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning-color);
            font-weight: 600;
        }

        /* Button Colors Override */
        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark) !important;
            border-color: var(--primary-dark) !important;
        }

        .btn-primary:focus, .btn-primary:active {
            background-color: var(--primary-dark) !important;
            border-color: var(--primary-dark) !important;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.25) !important;
        }

        .btn-info {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }

        .btn-info:hover {
            background-color: var(--primary-dark) !important;
            border-color: var(--primary-dark) !important;
        }

        .btn-outline-info {
            color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }

        .btn-outline-info:hover {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }

        /* Table Primary Row */
        .table-primary,
        .table-primary > th,
        .table-primary > td {
            background-color: rgba(77, 124, 15, 0.08) !important;
            border-color: var(--primary-color) !important;
        }

        tr.table-primary {
            background-color: rgba(77, 124, 15, 0.08) !important;
        }

        tr.table-primary td {
            background-color: rgba(77, 124, 15, 0.08) !important;
        }

        /* Alert Info */
        .alert-info {
            background-color: rgba(77, 124, 15, 0.1) !important;
            border-color: var(--primary-color) !important;
            color: var(--primary-dark) !important;
        }

        .alert-info .fa-info-circle {
            color: var(--primary-color) !important;
        }

        /* Pagination Styling */
        .pagination {
            gap: 4px;
        }

        .page-link {
            color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            background-color: white;
            transition: all 0.2s ease;
        }

        .page-link:hover {
            background-color: var(--primary-light) !important;
            color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }

        .page-item.active .page-link {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }

        .page-item.disabled .page-link {
            color: #6c757d;
            border-color: #dee2e6;
            background-color: #f8f9fa;
        }

        /* Footer */
        .footer {
            background: white;
            border-top: 1px solid var(--border-light);
            padding: 16px 20px;
            margin-top: 40px;
            margin-bottom: 0;
            text-align: center;
            color: var(--text-gray);
            font-size: 12px;
            flex-shrink: 0;
        }

        .footer-content {
            max-width: 100%;
            margin: 0 auto;
        }

        .footer-text {
            margin: 0;
            color: var(--text-gray);
            line-height: 1.5;
            font-size: 12px;
        }

        .footer-text-small {
            margin-top: 4px !important;
            font-size: 10px !important;
        }

        .footer-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-block;
        }

        .footer-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .footer {
                padding: 12px 15px;
                margin-top: 30px;
                font-size: 11px;
            }

            .footer-text {
                font-size: 11px;
            }

            .footer-text-small {
                font-size: 9px !important;
                margin-top: 2px !important;
            }
        }

        @media (max-width: 480px) {
            .footer {
                padding: 10px 12px;
                margin-top: 20px;
                font-size: 10px;
            }

            .footer-text {
                font-size: 10px;
            }

            .footer-text-small {
                font-size: 8px !important;
                margin-top: 2px !important;
            }
        }

        /* Mobile Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 280px;
                transform: translateX(-100%);
            }

            .sidebar:not(.hidden) {
                transform: translateX(0);
                box-shadow: 2px 0 16px rgba(0, 0, 0, 0.15);
            }

            .sidebar.hidden {
                transform: translateX(-100%);
            }

            /* Mobile: main-wrapper tidak perlu margin-left */
            .main-wrapper {
                margin-left: 0;
            }

            .main-wrapper.sidebar-hidden {
                margin-left: 0;
            }

            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }

            .sidebar-overlay.show {
                display: block;
            }

            .navbar-toggler {
                display: none !important;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-card-value {
                font-size: 24px;
            }
        }

        /* Custom button for updated expertise (dark green) */
        .btn-dark-success {
            background-color: #1a7a3a !important;
            border-color: #1a7a3a !important;
            color: white !important;
        }

        .btn-dark-success:hover {
            background-color: #0f5428 !important;
            border-color: #0f5428 !important;
        }

        .btn-dark-success:focus, .btn-dark-success:active {
            background-color: #0f5428 !important;
            border-color: #0f5428 !important;
            box-shadow: 0 0 0 3px rgba(26, 122, 58, 0.25) !important;
        }

        /* Blinking text animation untuk noorder tanpa TTD */
        .blinking-text {
            animation: blinking 0.8s infinite;
            font-weight: 600;
            color: #dc2626;
        }

        @keyframes blinking {
            0%, 49% {
                opacity: 1;
            }
            50%, 100% {
                opacity: 0.3;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="page-loading-overlay" id="pageLoadingOverlay">
        <div class="spinner-box">
            <div class="spinner"></div>
            <div class="loading-text">Memuat...</div>
        </div>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <!-- Welcome Message -->
        <div style="padding: 60px 16px 20px 16px; border-bottom: 1px solid #e5e7eb;">
            <h3 style="color: var(--text-dark); font-weight: 600; margin: 0 0 4px 0; font-size: 16px;">Selamat datang! 👋</h3>
            <p style="color: var(--text-gray); font-size: 12px; margin: 0;">
                <?php echo $_SESSION['nama_pegawai'] ?? 'User'; ?>
            </p>
        </div>

        <ul class="sidebar-menu">
            <li class="sidebar-section-title">Menu</li>
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li><a href="pemeriksaan.php" class="active"><i class="fas fa-file-medical"></i> Data Radiologi</a></li>

            <li class="sidebar-divider"></li>

            <li class="sidebar-section-title">Developer</li>
            <li><a href="api-docs.php"><i class="fas fa-code"></i> API Documentation</a></li>

            <li class="sidebar-divider"></li>

            <li><a href="../logout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </aside>

    <!-- Navbar Sticky -->
    <!-- Navbar Sticky -->
    <nav class="navbar navbar-sticky px-0">
        <div class="container-fluid px-3 px-md-4 navbar-content">
            <button class="btn btn-sm sidebar-toggle-btn" id="sidebarToggle" style="background: var(--primary-light); color: var(--primary-color); border: none; flex-shrink: 0;">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand" href="#">
                <img src="../assets/images/logo-ris.png?v=<?php echo time(); ?>" alt="Logo RIS" class="navbar-logo">
                <span class="navbar-text-lg">RIS RS Tk.III dr. Reksodiwiryo</span>
                <span class="navbar-text-md">RIS RS Tk.III</span>
                <span class="navbar-text-sm">RIS</span>
            </a>
            <div id="serverTimeContainer" class="navbar-time">
                <div id="serverDate" class="navbar-date">-</div>
                <div id="serverTime" class="navbar-clock">-</div>
            </div>
        </div>
    </nav>

    <div class="main-wrapper">
        <div class="container-fluid py-4">
            <!-- Flash Message -->
        <?php
        $flash = getFlashMessage();
        if ($flash):
        ?>
        <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo $flash['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="card mb-4 filter-sticky">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-filter"></i> Filter Data Pemeriksaan Radiologi
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label for="tgl1" class="form-label">Dari Tanggal</label>
                        <input type="date" class="form-control" id="tgl1" name="tgl1" value="<?php echo $tgl1; ?>">
                    </div>

                    <div class="col-md-3">
                        <label for="tgl2" class="form-label">Sampai Tanggal</label>
                        <input type="date" class="form-control" id="tgl2" name="tgl2" value="<?php echo $tgl2; ?>">
                    </div>

                    <div class="col-md-3">
                        <label for="cari" class="form-label">Pencarian Data</label>
                        <input type="text" class="form-control" id="cari" name="cari" placeholder="No. Rawat / No. RM / Nama Pasien" value="<?php echo $cari; ?>">
                    </div>

                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="fas fa-search"></i> Cari
                        </button>
                        <a href="pemeriksaan.php" class="btn btn-secondary flex-grow-1">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Data Section -->
        <div class="card main-content">
            <div class="card-header bg-light">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list"></i> Data Pemeriksaan Radiologi (<?php echo count($examinations); ?> dari <?php echo $totalRecords; ?>)
                    </h5>
                    <?php if ($totalRecords > 0): ?>
                    <small class="text-muted">
                        Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?> | Per Halaman: 15 data
                    </small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($examinations)): ?>
                <div class="alert alert-info m-3" role="alert">
                    <i class="fas fa-info-circle"></i> Tidak ada data pemeriksaan
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 10%;">No. Rawat</th>
                                <th style="width: 25%;">Data Pasien</th>
                                <th style="width: 15%;">Petugas</th>
                                <th style="width: 10%;">Tgl/Jam</th>
                                <th style="width: 15%;">Pemeriksaan</th>
                                <th style="width: 10%;">Dokter</th>
                                <th style="width: 15%;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $groupedData = [];
                            foreach ($examinations as $exam) {
                                $key = $exam['no_rawat'] . '_' . $exam['tgl_periksa'] . '_' . $exam['jam'];
                                if (!isset($groupedData[$key])) {
                                    $groupedData[$key] = [
                                        'header' => $exam,
                                        'items' => []
                                    ];
                                }
                                $groupedData[$key]['items'][] = $exam;
                            }

                            foreach ($groupedData as $groupKey => $group):
                                $header = $group['header'];
                                $items = $group['items'];
                                $kamarInfo = $kamarInfoCache[$header['no_rawat']] ?? ['label' => '', 'value' => ''];
                            ?>
                            <tr class="table-primary">
                                <td><strong><?php echo $header['no_rawat']; ?></strong></td>
                                <td>
                                    <strong><?php echo $header['nm_pasien']; ?></strong><br>
                                    <small class="text-muted">
                                        RM: <?php echo $header['no_rkm_medis']; ?><br>
                                        <?php
                                        if (!empty($header['noorder'])) {
                                            $hasTTD = isset($ttdStatusCache[$header['no_rawat']]);
                                            $blinkClass = $hasTTD ? '' : ' blinking-text';
                                            echo '<span class="noorder-text' . $blinkClass . '">No. Permintaan: ' . $header['noorder'] . '</span>';
                                        }
                                        ?>
                                    </small>
                                </td>
                                <td><?php echo $header['petugas_nama']; ?></td>
                                <td>
                                    <strong><?php echo formatTanggalTampil($header['tgl_periksa']); ?></strong><br>
                                    <small><?php echo $header['jam']; ?></small>
                                </td>
                                <td><?php echo $header['nm_perawatan']; ?></td>
                                <td><?php echo $header['nm_dokter']; ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-popup-viewer <?php echo !empty($header['hasil']) ? 'btn-success' : 'btn-warning'; ?>"
                                            onclick="openImagePopup('<?php echo $header['no_rawat']; ?>', '<?php echo $header['tgl_periksa']; ?>', '<?php echo $header['jam']; ?>', '<?php echo !empty($header['noorder']) ? $header['noorder'] : ''; ?>')"
                                            title="<?php echo !empty($header['hasil']) ? 'Expertise sudah tersimpan' : 'Belum ada expertise'; ?>">
                                        <i class="fas fa-eye"></i> View DICOM
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="7">
                                    <small class="text-muted">
                                        <?php if (!empty($kamarInfo['label']) && !empty($kamarInfo['value'])): ?>
                                        <strong style="color: #4D7C0F;"><?php echo $kamarInfo['label']; ?>:</strong> <?php echo $kamarInfo['value']; ?>
                                        <br>
                                        <?php endif; ?>
                                        <strong>Kode Perawatan:</strong> <?php echo implode(', ', array_map(function($item) { return $item['kd_jenis_prw']; }, $items)); ?>
                                        | <strong>Biaya:</strong> <?php echo formatMataUang(array_sum(array_map(function($item) { return $item['biaya']; }, $items))); ?>
                                        | <strong>Cara Bayar:</strong> <?php echo $header['png_jawab']; ?>
                                        <br>
                                        <strong>Diagnosa Klinis:</strong> <?php echo $header['diagnosa_klinis'] ?? 'N/A'; ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Pagination Controls -->
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="d-flex justify-content-between align-items-center p-3 border-top">
                    <div>
                        <small class="text-muted">
                            Menampilkan <?php echo count($examinations); ?> dari <?php echo $totalRecords; ?> data
                        </small>
                    </div>
                    <ul class="pagination mb-0">
                        <!-- Previous Button -->
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=1<?php echo !empty($tgl1) ? '&tgl1=' . urlencode($tgl1) : ''; ?><?php echo !empty($tgl2) ? '&tgl2=' . urlencode($tgl2) : ''; ?><?php echo !empty($cari) ? '&cari=' . urlencode($cari) : ''; ?>" title="Halaman Pertama">
                                <i class="fas fa-chevron-left"></i> Awal
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($tgl1) ? '&tgl1=' . urlencode($tgl1) : ''; ?><?php echo !empty($tgl2) ? '&tgl2=' . urlencode($tgl2) : ''; ?><?php echo !empty($cari) ? '&cari=' . urlencode($cari) : ''; ?>" title="Halaman Sebelumnya">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                        </li>
                        <?php endif; ?>

                        <!-- Page Numbers -->
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        if ($startPage > 1) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }

                        for ($i = $startPage; $i <= $endPage; $i++) {
                            $active = ($i === $page) ? 'active' : '';
                            echo '<li class="page-item ' . $active . '">
                                <a class="page-link" href="?page=' . $i . (!empty($tgl1) ? '&tgl1=' . urlencode($tgl1) : '') . (!empty($tgl2) ? '&tgl2=' . urlencode($tgl2) : '') . (!empty($cari) ? '&cari=' . urlencode($cari) : '') . '">' . $i . '</a>
                            </li>';
                        }

                        if ($endPage < $totalPages) {
                            echo '<li class="page-item"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <!-- Next Button -->
                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($tgl1) ? '&tgl1=' . urlencode($tgl1) : ''; ?><?php echo !empty($tgl2) ? '&tgl2=' . urlencode($tgl2) : ''; ?><?php echo !empty($cari) ? '&cari=' . urlencode($cari) : ''; ?>" title="Halaman Berikutnya">
                                Selanjutnya <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo !empty($tgl1) ? '&tgl1=' . urlencode($tgl1) : ''; ?><?php echo !empty($tgl2) ? '&tgl2=' . urlencode($tgl2) : ''; ?><?php echo !empty($cari) ? '&cari=' . urlencode($cari) : ''; ?>" title="Halaman Terakhir">
                                Akhir <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>

    <!-- Image Viewer Modal Popup -->
    <div class="modal fade" id="imageViewerModal" tabindex="-1" aria-labelledby="imageViewerLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-md" style="max-width: 95vw; margin: 10px auto;">
            <div class="modal-content" style="height: 92vh; display: flex; flex-direction: column;">
                <div class="modal-header" style="background: #f8f9fa; border-bottom: 1px solid #e5e7eb; flex-shrink: 0; padding: 8px 16px;">
                    <h5 class="modal-title" id="imageViewerLabel" style="font-size: 14px; margin: 0;">
                        <i class="fas fa-image"></i> DICOM Viewer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="transform: scale(0.8);"></button>
                </div>
                <div class="modal-body p-0" style="overflow: hidden; flex: 1;">
                    <iframe id="popupViewerFrame"
                            src=""
                            style="width: 100%; height: 100%; border: none; background: #f5f5f5;">
                    </iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p class="footer-text">
                &copy; <span id="year"></span> RIS RS Tk.III dr. Reksodiwiryo. All rights reserved.
            </p>
            <p class="footer-text footer-text-small">
                Sistem Informasi Radiologi |
                <a href="#" class="footer-link">Privacy Policy</a> |
                <a href="#" class="footer-link">Terms of Service</a>
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Set current year in footer
        document.getElementById('year').textContent = new Date().getFullYear();

        // Sidebar Toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainWrapper = document.querySelector('.main-wrapper');
        sidebarToggle?.addEventListener('click', function() {
            // Toggle hidden class untuk desktop dan mobile
            sidebar.classList.toggle('hidden');

            // Desktop mode: toggle sidebar-hidden class di main-wrapper
            if (window.innerWidth > 992) {
                mainWrapper.classList.toggle('sidebar-hidden');
            }

            // Tutup overlay saat toggle di mobile
            if (window.innerWidth <= 992) {
                sidebarOverlay.classList.remove('show');
            }

            // Save state to localStorage
            const isHidden = sidebar.classList.contains('hidden');
            localStorage.setItem('sidebarHidden', isHidden);
        });

        sidebarOverlay?.addEventListener('click', function() {
            // Close sidebar by adding hidden class
            sidebar.classList.add('hidden');
            sidebarOverlay.classList.remove('show');
            // Save state to localStorage
            localStorage.setItem('sidebarHidden', true);
        });

        // Close sidebar when clicking on a link
        // and show loading overlay for navigation links
        const sidebarLinks = document.querySelectorAll('.sidebar-menu a');
        const pageLoadingOverlay = document.getElementById('pageLoadingOverlay');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                // Jangan show loading untuk logout atau link eksternal
                const href = this.getAttribute('href');
                if (!href.includes('logout') && !href.startsWith('http')) {
                    e.preventDefault();
                    pageLoadingOverlay.classList.add('show');
                    setTimeout(() => {
                        window.location.href = href;
                    }, 300);
                }

                // Close sidebar di mobile
                if (window.innerWidth <= 992) {
                    sidebar.classList.add('hidden');
                    sidebarOverlay.classList.remove('show');
                }
            });
        });

        // Restore sidebar state from localStorage on page load
        window.addEventListener('load', function() {
            const isSidebarHidden = localStorage.getItem('sidebarHidden') === 'true';
            if (isSidebarHidden) {
                sidebar.classList.add('hidden');
                if (window.innerWidth > 992) {
                    mainWrapper.classList.add('sidebar-hidden');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                // Desktop view - remove overlay
                sidebarOverlay.classList.remove('show');
            } else {
                // Mobile view - ensure overlay removed
                sidebarOverlay.classList.remove('show');
            }
        });

        function openImagePopup(noRawat, tgl, jam, noorder) {
            // Build the popup viewer URL
            const popupUrl = 'viewer-popup.php?no_rawat=' + encodeURIComponent(noRawat) +
                           '&tgl=' + encodeURIComponent(tgl) +
                           '&jam=' + encodeURIComponent(jam) +
                           (noorder ? '&noorder=' + encodeURIComponent(noorder) : '');

            // Set iframe src
            document.getElementById('popupViewerFrame').src = popupUrl;

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('imageViewerModal'));
            modal.show();
        }

        // Server time and date display
        function updateServerTime() {
            const serverDate = document.getElementById('serverDate');
            const serverTime = document.getElementById('serverTime');

            if (!serverDate || !serverTime) return;

            const now = new Date();

            // Format date: Hari, DD Bulan YYYY
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

            const dayName = days[now.getDay()];
            const date = now.getDate().toString().padStart(2, '0');
            const month = months[now.getMonth()];
            const year = now.getFullYear();

            const dateStr = `${dayName}, ${date} ${month} ${year}`;

            // Format time: HH:MM:SS
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');

            const timeStr = `${hours}:${minutes}:${seconds}`;

            serverDate.textContent = dateStr;
            serverTime.textContent = timeStr;
        }

        // Update time immediately and then every second
        updateServerTime();
        setInterval(updateServerTime, 1000);
    </script>
</body>
</html>
