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

$db = new Database();
$user = getUser();

// Jika user tidak ada, set default
if (!$user) {
    $user = ['id_user' => 'User'];
}

// Function to get server memory info
function getServerMemory() {
    $memory = [];

    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: Get memory info from PHP
        $memory['total_mb'] = 0;
        $memory['used_mb'] = 0;
        $memory['free_mb'] = 0;
        $memory['percent'] = 0;

        // Try to get from system
        @exec('wmic OS get TotalVisibleMemorySize /Value', $output);
        $totalMemory = 0;
        foreach ($output as $line) {
            if (strpos($line, 'TotalVisibleMemorySize') === 0) {
                $totalMemory = (int)str_replace('TotalVisibleMemorySize=', '', $line);
            }
        }

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
        // Linux: Read from /proc/meminfo
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            $meminfo = explode("\n", $meminfo);

            $memTotal = 0;
            $memFree = 0;

            foreach ($meminfo as $line) {
                if (strpos($line, 'MemTotal') === 0) {
                    $memTotal = (int)str_word_count($line, 1)[1];
                }
                if (strpos($line, 'MemAvailable') === 0) {
                    $memFree = (int)str_word_count($line, 1)[1];
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

    return $memory;
}

$serverMemory = getServerMemory();

// Get dashboard statistics
$stats = [
    'pasien_hari_ini' => 0,
    'expertise_selesai' => 0,
    'expertise_pending' => 0,
    'expertise_percent' => 0,
    'total_pemeriksaan' => 0,
    'pemeriksaan_hari_ini' => 0,
    'orthanc_connected' => false,
    'total_pasien' => 0,
    'total_imaging_orthanc' => 0,
    'total_worklist_orthanc' => 0,
];

if ($db->isConnected()) {
    try {
        // Total pasien UNIK hari ini (berdasarkan tanggal pemeriksaan)
        $today = date('Y-m-d');
        $result = $db->fetch("SELECT COUNT(DISTINCT rp.no_rkm_medis) as total
                             FROM periksa_radiologi pr
                             INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                             WHERE DATE(pr.tgl_periksa) = ?", [$today]);
        $stats['pasien_hari_ini'] = $result['total'] ?? 0;

        // Total pemeriksaan hari ini
        $result = $db->fetch("SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
                             FROM periksa_radiologi pr
                             WHERE DATE(pr.tgl_periksa) = ?", [$today]);
        $stats['pemeriksaan_hari_ini'] = $result['total'] ?? 0;

        // Total pemeriksaan keseluruhan
        $result = $db->fetch("SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
                             FROM periksa_radiologi pr");
        $stats['total_pemeriksaan'] = $result['total'] ?? 0;

        // Total pasien keseluruhan
        $result = $db->fetch("SELECT COUNT(DISTINCT rp.no_rkm_medis) as total
                             FROM periksa_radiologi pr
                             INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat");
        $stats['total_pasien'] = $result['total'] ?? 0;

        // Total expertise yang sudah disimpan (hari ini)
        $result = $db->fetch("SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
                             FROM periksa_radiologi pr
                             INNER JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
                             AND pr.tgl_periksa = hr.tgl_periksa
                             AND pr.jam = hr.jam
                             WHERE DATE(pr.tgl_periksa) = ?", [$today]);
        $stats['expertise_selesai'] = $result['total'] ?? 0;

        // Total expertise yang pending (pemeriksaan tanpa hasil) - hari ini
        $result = $db->fetch("SELECT COUNT(DISTINCT pr.no_rawat, pr.tgl_periksa, pr.jam) as total
                             FROM periksa_radiologi pr
                             LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
                             AND pr.tgl_periksa = hr.tgl_periksa
                             AND pr.jam = hr.jam
                             WHERE DATE(pr.tgl_periksa) = ?
                             AND hr.no_rawat IS NULL", [$today]);
        $stats['expertise_pending'] = $result['total'] ?? 0;

        // Calculate expertise percentage (based on today's data)
        $total_today = $stats['expertise_selesai'] + $stats['expertise_pending'];
        if ($total_today > 0) {
            $stats['expertise_percent'] = round(($stats['expertise_selesai'] / $total_today) * 100);
        }

    } catch (Exception $e) {
        // Keep default values
    }
}

// Check Orthanc connection and get total studies
require_once '../api/orthanc.php';
$orthanc = new OrthancAPI();
$stats['orthanc_connected'] = $orthanc->testConnection();

// Get total imaging studies and worklist from Orthanc
if ($stats['orthanc_connected']) {
    try {
        $stats['total_imaging_orthanc'] = $orthanc->getTotalStudies();
        $stats['total_worklist_orthanc'] = $orthanc->getTotalWorklist();
    } catch (Exception $e) {
        $stats['total_imaging_orthanc'] = 0;
        $stats['total_worklist_orthanc'] = 0;
    }
}

// Get recent examinations
$recent_exams = [];
if ($db->isConnected()) {
    try {
        $query = "SELECT
                    pr.no_rawat,
                    p.nm_pasien,
                    pr.tgl_periksa,
                    pr.jam,
                    jpr.nm_perawatan,
                    hr.hasil,
                    CASE WHEN hr.no_foto IS NOT NULL THEN 'Selesai' ELSE 'Pending' END as status
                 FROM periksa_radiologi pr
                 INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                 INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                 INNER JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
                 LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat
                 ORDER BY pr.tgl_periksa DESC, pr.jam DESC
                 LIMIT 5";

        $recent_exams = $db->fetchAll($query, []) ?? [];
    } catch (Exception $e) {
        // Keep empty array
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - RIS RS Tk.III dr. Reksodiwiryo</title>
    <link rel="icon" type="image/png" href="../assets/images/logo-ris.png?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        /* Sidebar Hidden State */
        .sidebar.hidden {
            transform: translateX(-100%);
        }

        .main-wrapper.sidebar-hidden {
            margin-left: 0;
        }

        /* Dashboard Stats - Modern Design */
        .stat-card {
            background: white;
            border: none;
            border-radius: 12px;
            padding: 28px 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            height: 340px;
            display: flex;
            flex-direction: column;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-dark));
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            transform: translateY(-4px);
        }

        .stat-card-icon {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 16px;
            background: linear-gradient(135deg, rgba(77, 124, 15, 0.15) 0%, rgba(77, 124, 15, 0.05) 100%);
        }

        .stat-card-title {
            font-size: 13px;
            color: var(--text-gray);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-card-value {
            font-size: 40px;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1;
            margin-bottom: 12px;
        }

        .stat-card-subtitle {
            font-size: 12px;
            color: var(--text-gray);
            line-height: 1.5;
            margin-top: auto;
        }

        /* Expertise Status Layout */
        .expertise-stats {
            display: flex;
            gap: 16px;
            margin: 12px 0;
            flex: 0 0 auto;
        }

        .expertise-stat {
            flex: 1;
            background: rgba(0, 0, 0, 0.02);
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .expertise-stat-number {
            font-size: 28px;
            font-weight: 700;
            display: block;
            margin-bottom: 4px;
        }

        .expertise-stat-label {
            font-size: 11px;
            color: var(--text-gray);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .expertise-selesai .expertise-stat-number {
            color: var(--success-color);
        }

        .expertise-pending .expertise-stat-number {
            color: var(--warning-color);
        }

        /* Connection Status */
        .connection-status {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 12px 0;
            flex: 0 0 auto;
        }

        .status-indicator {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .status-indicator.online {
            background: #10b981;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
        }

        .status-indicator.offline {
            background: #ef4444;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
            animation: none;
        }

        .status-text {
            font-size: 14px;
            font-weight: 600;
        }

        .status-text.online {
            color: #10b981;
        }

        .status-text.offline {
            color: #ef4444;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
            }
            50% {
                box-shadow: 0 0 20px rgba(16, 185, 129, 0.8);
            }
            100% {
                box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
            }
        }

        /* Quick Stats */
        .quick-stat {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 100px;
        }

        .quick-stat:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .quick-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .quick-stat-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .quick-stat-title {
            font-size: 12px;
            color: var(--text-gray);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            line-height: 1.2;
        }

        .quick-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.1;
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
            color: var(--text-gray);
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
        .table-primary {
            background-color: var(--primary-light) !important;
        }

        .table-primary > th,
        .table-primary > td {
            border-color: var(--primary-color) !important;
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
                padding: 20px;
                height: 320px;
            }

            .stat-card-value {
                font-size: 32px;
            }

            .stat-card-icon {
                width: 60px;
                height: 60px;
                font-size: 28px;
            }
        }

        @media (max-width: 768px) {
            .stat-card {
                padding: 20px;
                height: 300px;
            }

            .stat-card-value {
                font-size: 28px;
            }

            .stat-card-icon {
                width: 50px;
                height: 50px;
                font-size: 24px;
                margin-bottom: 12px;
            }

            .stat-card-title {
                font-size: 12px;
            }

            .expertise-stats {
                gap: 8px;
            }

            .expertise-stat-number {
                font-size: 24px;
            }

            .expertise-stat-label {
                font-size: 10px;
            }

            .quick-stat {
                min-height: 90px;
                padding: 16px;
            }

            .quick-stat-icon {
                width: 45px;
                height: 45px;
                font-size: 18px;
            }

            .quick-stat-value {
                font-size: 20px;
            }
        }

        @media (max-width: 576px) {
            .stat-card {
                padding: 16px;
                height: 280px;
            }

            .stat-card-value {
                font-size: 24px;
            }

            .stat-card-icon {
                width: 45px;
                height: 45px;
                font-size: 20px;
            }

            .stat-card-title {
                font-size: 11px;
                gap: 4px;
            }

            .stat-card-subtitle {
                font-size: 11px;
            }

            .expertise-stats {
                gap: 6px;
            }

            .expertise-stat-number {
                font-size: 20px;
            }

            .status-text {
                font-size: 12px;
            }

            .quick-stat {
                padding: 14px;
                min-height: 85px;
            }

            .quick-stat-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .quick-stat-value {
                font-size: 18px;
            }

            .quick-stat-title {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
    <!-- Loading Overlay -->
    <div class="page-loading-overlay" id="pageLoadingOverlay">
        <div class="spinner-box">
            <div class="spinner"></div>
            <div class="loading-text">Memuat...</div>
        </div>
    </div>

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
            <li><a href="dashboard.php" class="active"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li><a href="pemeriksaan.php"><i class="fas fa-file-medical"></i> Data Radiologi</a></li>

            <li class="sidebar-divider"></li>

            <li class="sidebar-section-title">Developer</li>
            <li><a href="api-docs.php"><i class="fas fa-code"></i> API Documentation</a></li>

            <li class="sidebar-divider"></li>

            <li><a href="../logout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </aside>

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
            <!-- Dashboard Detail Title -->
            <div style="margin-bottom: 28px;">
                <h2 style="color: var(--text-dark); font-weight: 600; margin: 0; font-size: 28px;">Dashboard Detail!</h2>
            </div>

            <!-- Main Stats Row -->
            <div class="row g-4 mb-4">
                <!-- Card 1: Server Memory -->
                <div class="col-lg-4">
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <i class="fas fa-memory"></i>
                        </div>
                        <div class="stat-card-title">
                            <i class="fas fa-microchip"></i> Server Memory
                        </div>
                        <div style="margin: 16px 0;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 12px; font-weight: 600; color: var(--text-gray);">RAM Usage</span>
                                <span style="font-size: 16px; font-weight: 700; color: var(--primary-color);"><?php echo $serverMemory['percent']; ?>%</span>
                            </div>
                            <div style="width: 100%; height: 6px; background: rgba(0,0,0,0.05); border-radius: 3px; overflow: hidden;">
                                <div style="width: <?php echo $serverMemory['percent']; ?>%; height: 100%; background: linear-gradient(90deg, var(--primary-color), var(--primary-dark)); transition: width 0.5s ease;"></div>
                            </div>
                            <div style="margin-top: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <div style="background: rgba(0,0,0,0.02); padding: 8px; border-radius: 6px;">
                                    <div style="font-size: 11px; color: var(--text-gray); text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Used</div>
                                    <div style="font-size: 16px; font-weight: 700; color: var(--primary-color);"><?php echo $serverMemory['used_mb']; ?> MB</div>
                                </div>
                                <div style="background: rgba(0,0,0,0.02); padding: 8px; border-radius: 6px;">
                                    <div style="font-size: 11px; color: var(--text-gray); text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Total</div>
                                    <div style="font-size: 16px; font-weight: 700; color: var(--text-dark);"><?php echo $serverMemory['total_mb']; ?> MB</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Expertise Status -->
                <div class="col-lg-4">
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <i class="fas fa-file-medical-alt"></i>
                        </div>
                        <div class="stat-card-title">
                            <i class="fas fa-stethoscope"></i> Status Expertise
                        </div>
                        <div style="margin: 16px 0;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 12px; font-weight: 600; color: var(--text-gray);">Completion Rate</span>
                                <span style="font-size: 16px; font-weight: 700; color: var(--primary-color);"><?php echo $stats['expertise_percent']; ?>%</span>
                            </div>
                            <div style="width: 100%; height: 6px; background: rgba(0,0,0,0.05); border-radius: 3px; overflow: hidden;">
                                <div style="width: <?php echo $stats['expertise_percent']; ?>%; height: 100%; background: linear-gradient(90deg, var(--primary-color), var(--primary-dark)); transition: width 0.5s ease;"></div>
                            </div>
                        </div>
                        <div class="expertise-stats">
                            <div class="expertise-stat expertise-selesai">
                                <span class="expertise-stat-number"><?php echo $stats['expertise_selesai']; ?></span>
                                <span class="expertise-stat-label">✓ Selesai</span>
                            </div>
                            <div class="expertise-stat expertise-pending">
                                <span class="expertise-stat-number"><?php echo $stats['expertise_pending']; ?></span>
                                <span class="expertise-stat-label">⏳ Pending</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 3: System Status -->
                <div class="col-lg-4">
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="stat-card-title">
                            <i class="fas fa-network-wired"></i> System Status
                        </div>
                        <div style="margin: 12px 0;">
                            <!-- PACS Connection -->
                            <div style="margin-bottom: 16px; padding: 12px; background: rgba(0,0,0,0.02); border-radius: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 12px; font-weight: 600; color: var(--text-gray);">PACS Orthanc</span>
                                    <span class="status-indicator <?php echo $stats['orthanc_connected'] ? 'online' : 'offline'; ?>"></span>
                                </div>
                                <div style="margin-top: 6px;">
                                    <span class="status-text <?php echo $stats['orthanc_connected'] ? 'online' : 'offline'; ?>" style="font-size: 12px;">
                                        <?php echo $stats['orthanc_connected'] ? '✓ Online' : '✗ Offline'; ?>
                                    </span>
                                </div>
                            </div>
                            <!-- Database Connection -->
                            <div style="padding: 12px; background: rgba(0,0,0,0.02); border-radius: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 12px; font-weight: 600; color: var(--text-gray);">Database</span>
                                    <span class="status-indicator online" style="background: #10b981; box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);"></span>
                                </div>
                                <div style="margin-top: 6px;">
                                    <span class="status-text online" style="font-size: 12px;">✓ Online</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="row g-3 mb-4">
                <div class="col-md-6 col-lg-3">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background: rgba(77, 124, 15, 0.1); color: var(--primary-color);">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-title">Pemeriksaan Hari Ini</div>
                            <div class="quick-stat-value"><?php echo number_format($stats['pemeriksaan_hari_ini']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-title">Pasien Hari Ini</div>
                            <div class="quick-stat-value"><?php echo number_format($stats['pasien_hari_ini']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background: rgba(34, 197, 94, 0.1); color: #22c55e;">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-title">Total Worklist</div>
                            <div class="quick-stat-value"><?php echo number_format($stats['total_worklist_orthanc']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                            <i class="fas fa-image"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-title">Total Study</div>
                            <div class="quick-stat-value"><?php echo number_format($stats['total_imaging_orthanc']); ?></div>
                        </div>
                    </div>
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

    <!-- Session Activity Tracker -->
    <script src="../assets/js/activity-tracker.js"></script>
</body>
</html>
