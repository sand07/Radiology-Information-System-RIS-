<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/Database.php';
require_once '../includes/functions.php';
require_once '../includes/session-security.php';

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

$apiUrl = $baseUrl . '/api/handlers.php';

$endpoints = [
    [
        'group' => 'Authentication',
        'items' => [
            [
                'method' => 'POST',
                'action' => 'login',
                'title' => 'Login',
                'desc' => 'Autentikasi user dan dapatkan JWT token. Endpoint ini tidak memerlukan token.',
                'auth' => false,
                'params' => [],
                'body' => '{"username": "string", "password": "string"}',
                'response' => '{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIs...",
    "user_id": "1234567890",
    "nama_pegawai": "Dr. Ahmad"
  }
}'
            ],
            [
                'method' => 'POST',
                'action' => 'logout',
                'title' => 'Logout',
                'desc' => 'Destroy session server-side.',
                'auth' => true,
                'params' => [],
                'body' => null,
                'response' => '{"success": true, "message": "Logout berhasil"}'
            ],
        ]
    ],
    [
        'group' => 'Dashboard',
        'items' => [
            [
                'method' => 'GET',
                'action' => 'get_dashboard_stats',
                'title' => 'Dashboard Statistics',
                'desc' => 'Statistik pasien, pemeriksaan, dan expertise hari ini.',
                'auth' => true,
                'params' => [],
                'body' => null,
                'response' => '{
  "success": true,
  "data": {
    "pasien_hari_ini": 5,
    "pemeriksaan_hari_ini": 8,
    "total_pemeriksaan": 1234,
    "total_pasien": 567,
    "expertise_selesai": 6,
    "expertise_pending": 2,
    "expertise_percent": 75
  }
}'
            ],
            [
                'method' => 'GET',
                'action' => 'get_server_info',
                'title' => 'Server Info',
                'desc' => 'Informasi memory server, versi PHP, dan waktu server.',
                'auth' => true,
                'params' => [],
                'body' => null,
                'response' => '{
  "success": true,
  "data": {
    "memory": {
      "total_mb": 16384,
      "used_mb": 8192,
      "free_mb": 8192,
      "percent": 50
    },
    "php_version": "8.2.4",
    "server_time": "2026-02-19 10:30:00"
  }
}'
            ],
            [
                'method' => 'GET',
                'action' => 'get_system_status',
                'title' => 'System Status',
                'desc' => 'Status koneksi Database dan Orthanc PACS, termasuk total studies.',
                'auth' => true,
                'params' => [],
                'body' => null,
                'response' => '{
  "success": true,
  "data": {
    "database": {"connected": true, "host": "192.168.100.108"},
    "orthanc": {
      "connected": true,
      "url": "http://192.168.100.119:8042",
      "total_studies": 450,
      "total_worklist": 450
    }
  }
}'
            ],
            [
                'method' => 'GET',
                'action' => 'get_recent_exams',
                'title' => 'Recent Examinations',
                'desc' => 'Pemeriksaan radiologi terbaru.',
                'auth' => true,
                'params' => [
                    ['name' => 'limit', 'type' => 'int', 'required' => false, 'default' => '5', 'desc' => 'Jumlah data (max 50)']
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": [
    {
      "no_rawat": "2026/02/19/000001",
      "nm_pasien": "Ahmad Suryadi",
      "no_rkm_medis": "000123",
      "tgl_periksa": "2026-02-19",
      "jam": "08:30:00",
      "nm_perawatan": "Rontgen Thorax PA",
      "hasil": "Cor dan pulmo dalam batas normal...",
      "status": "Selesai"
    }
  ]
}'
            ],
        ]
    ],
    [
        'group' => 'Examinations',
        'items' => [
            [
                'method' => 'GET',
                'action' => 'get_examinations',
                'title' => 'List Pemeriksaan',
                'desc' => 'Daftar pemeriksaan radiologi dengan filter tanggal, pencarian, dan pagination.',
                'auth' => true,
                'params' => [
                    ['name' => 'tgl1', 'type' => 'date', 'required' => false, 'default' => 'hari ini', 'desc' => 'Tanggal mulai (YYYY-MM-DD)'],
                    ['name' => 'tgl2', 'type' => 'date', 'required' => false, 'default' => 'hari ini', 'desc' => 'Tanggal akhir (YYYY-MM-DD)'],
                    ['name' => 'search', 'type' => 'string', 'required' => false, 'default' => '-', 'desc' => 'Cari by no_rawat / no_rkm_medis / nama pasien'],
                    ['name' => 'page', 'type' => 'int', 'required' => false, 'default' => '1', 'desc' => 'Halaman'],
                    ['name' => 'limit', 'type' => 'int', 'required' => false, 'default' => '15', 'desc' => 'Data per halaman (max 100)'],
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": [{"no_rawat": "...", "nm_pasien": "...", "status_expertise": "Selesai", ...}],
  "page": 1,
  "limit": 15,
  "total_records": 45,
  "total_pages": 3
}'
            ],
            [
                'method' => 'GET',
                'action' => 'get_examination_detail',
                'title' => 'Detail Pemeriksaan',
                'desc' => 'Detail lengkap satu pemeriksaan radiologi (pasien, dokter, hasil, dll).',
                'auth' => true,
                'params' => [
                    ['name' => 'no_rawat', 'type' => 'string', 'required' => true, 'default' => '-', 'desc' => 'Nomor rawat'],
                    ['name' => 'tgl', 'type' => 'date', 'required' => true, 'default' => '-', 'desc' => 'Tanggal periksa'],
                    ['name' => 'jam', 'type' => 'time', 'required' => true, 'default' => '-', 'desc' => 'Jam periksa'],
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": {
    "no_rawat": "2026/02/19/000001",
    "no_rkm_medis": "000123",
    "nm_pasien": "Ahmad Suryadi",
    "tgl_periksa": "2026-02-19",
    "jam": "08:30:00",
    "nm_dokter": "dr. Budi, Sp.Rad",
    "nm_perawatan": "Rontgen Thorax PA",
    "diagnosa_klinis": "Batuk lama",
    "hasil": "Cor dan pulmo dalam batas normal...",
    "status_expertise": "Selesai"
  }
}'
            ],
            [
                'method' => 'GET',
                'action' => 'get_examination_location',
                'title' => 'Lokasi Pasien',
                'desc' => 'Info kamar inap / bangsal atau poliklinik untuk no_rawat.',
                'auth' => true,
                'params' => [
                    ['name' => 'no_rawat', 'type' => 'string', 'required' => true, 'default' => '-', 'desc' => 'Nomor rawat'],
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": {"label": "Kamar", "value": "VIP-01, Bangsal Melati"}
}'
            ],
        ]
    ],
    [
        'group' => 'Patients',
        'items' => [
            [
                'method' => 'GET',
                'action' => 'get_patient_data',
                'title' => 'Data Pasien',
                'desc' => 'Informasi pasien berdasarkan nomor rekam medis.',
                'auth' => true,
                'params' => [
                    ['name' => 'no_rkm_medis', 'type' => 'string', 'required' => true, 'default' => '-', 'desc' => 'Nomor rekam medis'],
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": {
    "no_rkm_medis": "000123",
    "nm_pasien": "Ahmad Suryadi",
    "alamat": "Jl. Sudirman No. 10",
    "no_telp": "08123456789"
  }
}'
            ],
        ]
    ],
    [
        'group' => 'Expertise',
        'items' => [
            [
                'method' => 'POST',
                'action' => 'save_expertise',
                'title' => 'Simpan Expertise',
                'desc' => 'Simpan atau update hasil bacaan radiologi. Jika hasil kosong, data akan dihapus.',
                'auth' => true,
                'params' => [],
                'body' => '{
  "no_rawat": "2026/02/19/000001",
  "tgl_periksa": "2026-02-19",
  "jam": "08:30:00",
  "hasil": "Cor dan pulmo dalam batas normal...",
  "no_foto": "F001"
}',
                'response' => '{"success": true, "message": "Expertise berhasil disimpan"}'
            ],
            [
                'method' => 'DELETE',
                'action' => 'delete_expertise',
                'title' => 'Hapus Expertise',
                'desc' => 'Hapus hasil radiologi yang sudah disimpan.',
                'auth' => true,
                'params' => [
                    ['name' => 'no_rawat', 'type' => 'string', 'required' => true, 'default' => '-', 'desc' => 'Nomor rawat'],
                    ['name' => 'tgl_periksa', 'type' => 'date', 'required' => true, 'default' => '-', 'desc' => 'Tanggal periksa'],
                    ['name' => 'jam', 'type' => 'time', 'required' => true, 'default' => '-', 'desc' => 'Jam periksa'],
                ],
                'body' => null,
                'response' => '{"success": true, "message": "Hasil radiologi berhasil dihapus"}'
            ],
        ]
    ],
    [
        'group' => 'Viewer / DICOM',
        'items' => [
            [
                'method' => 'GET',
                'action' => 'get_viewer_study',
                'title' => 'Get Viewer Study',
                'desc' => 'Cari DICOM study di Orthanc PACS. Prioritas pencarian: (1) AccessionNumber, (2) PatientID + StudyDate. Return URL OHIF Viewer.',
                'auth' => true,
                'params' => [
                    ['name' => 'no_rawat', 'type' => 'string', 'required' => true, 'default' => '-', 'desc' => 'Nomor rawat'],
                    ['name' => 'tgl', 'type' => 'date', 'required' => true, 'default' => '-', 'desc' => 'Tanggal periksa'],
                    ['name' => 'jam', 'type' => 'time', 'required' => false, 'default' => '-', 'desc' => 'Jam periksa (opsional, jika tidak diisi akan ambil data pertama yang cocok)'],
                    ['name' => 'noorder', 'type' => 'string', 'required' => false, 'default' => 'dari DB', 'desc' => 'AccessionNumber / No. Order (opsional, otomatis dari DB)'],
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": {
    "study_instance_uid": "1.2.840.113619.2.55.3...",
    "ohif_url": "http://192.168.100.119:8042/ohif/viewer?StudyInstanceUIDs=...",
    "orthanc_connected": true
  }
}'
            ],
        ]
    ],
    [
        'group' => 'Master Data',
        'items' => [
            [
                'method' => 'GET',
                'action' => 'get_doctors',
                'title' => 'List Dokter',
                'desc' => 'Daftar dokter radiologi.',
                'auth' => true,
                'params' => [
                    ['name' => 'search', 'type' => 'string', 'required' => false, 'default' => '-', 'desc' => 'Cari nama dokter'],
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": [
    {"kd_dokter": "D001", "nm_dokter": "dr. Budi, Sp.Rad"},
    {"kd_dokter": "D002", "nm_dokter": "dr. Siti, Sp.Rad"}
  ]
}'
            ],
            [
                'method' => 'GET',
                'action' => 'get_radiology_services',
                'title' => 'List Layanan Radiologi',
                'desc' => 'Daftar jenis perawatan/layanan radiologi dan biayanya.',
                'auth' => true,
                'params' => [
                    ['name' => 'search', 'type' => 'string', 'required' => false, 'default' => '-', 'desc' => 'Cari nama layanan'],
                ],
                'body' => null,
                'response' => '{
  "success": true,
  "data": [
    {"kd_jenis_prw": "R001", "nm_perawatan": "Rontgen Thorax PA", "biaya": "150000"},
    {"kd_jenis_prw": "R002", "nm_perawatan": "CT Scan Kepala", "biaya": "850000"}
  ]
}'
            ],
        ]
    ],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation - RIS</title>
    <link rel="icon" type="image/png" href="../assets/images/logo-ris.png?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary-color: #4D7C0F;
            --primary-light: #EBF5D4;
            --primary-dark: #3D5C0D;
            --text-dark: #212529;
            --text-gray: #6c757d;
            --border-light: #dee2e6;
            --bg-light: #f8f9fa;
            --sidebar-bg: #ffffff;
            --sidebar-hover: #f5f6fa;
        }

        body {
            padding-top: 44px;
            background: var(--bg-light);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 13px;
        }

        /* Navbar */
        .navbar-sticky {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1020;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 0 !important; min-height: 50px;
        }
        .navbar-content { display: flex; align-items: center; justify-content: space-between; padding: 6px 12px !important; min-height: 50px; }
        .navbar-brand { font-weight: 700; color: white !important; display: flex; align-items: center; gap: 6px; font-size: 14px; }
        .navbar-logo { height: 32px; margin-right: 6px; }

        /* Sidebar */
        .sidebar {
            position: fixed; top: 50px; left: 0; width: 260px; height: calc(100vh - 50px);
            background: var(--sidebar-bg); border-right: 1px solid var(--border-light);
            box-shadow: 1px 0 3px rgba(0,0,0,0.08); z-index: 1000; overflow-y: auto;
            transition: transform 0.3s ease;
        }
        .sidebar.hidden { transform: translateX(-100%); }
        .sidebar-menu { list-style: none; padding: 12px 0; margin: 0; }
        .sidebar-menu a {
            display: flex; align-items: center; gap: 12px; padding: 12px 16px;
            color: var(--text-dark); text-decoration: none; font-size: 13px; font-weight: 500;
            transition: all 0.2s ease; border-left: 3px solid transparent;
        }
        .sidebar-menu a:hover { background: var(--sidebar-hover); color: var(--primary-color); border-left-color: var(--primary-color); }
        .sidebar-menu a.active { background: var(--primary-light); color: var(--primary-color); border-left-color: var(--primary-color); font-weight: 600; }
        .sidebar-menu i { font-size: 16px; width: 18px; text-align: center; }
        .sidebar-divider { height: 1px; background: var(--border-light); margin: 8px 0; }
        .sidebar-section-title { padding: 10px 16px 6px; font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: 0.5px; list-style: none; }

        /* Main */
        .main-wrapper { margin-left: 260px; transition: margin-left 0.3s ease; }
        .main-wrapper.sidebar-hidden { margin-left: 0; }
        .sidebar-toggle-btn { background: transparent !important; color: white !important; border: none !important; font-size: 18px !important; padding: 8px 12px !important; margin-right: 12px !important; }

        @media (max-width: 992px) {
            .sidebar { width: 280px; transform: translateX(-100%); }
            .sidebar:not(.hidden) { transform: translateX(0); box-shadow: 2px 0 16px rgba(0,0,0,0.15); }
            .main-wrapper { margin-left: 0; }
        }

        /* API Docs Styles */
        .api-base-url {
            background: #1e293b; color: #e2e8f0; padding: 14px 18px; border-radius: 8px;
            font-family: 'Courier New', monospace; font-size: 13px; word-break: break-all;
        }
        .api-base-url span { color: #86efac; }

        .endpoint-group-title {
            font-size: 18px; font-weight: 700; color: var(--text-dark); margin: 32px 0 16px;
            padding-bottom: 8px; border-bottom: 2px solid var(--primary-color);
            display: flex; align-items: center; gap: 8px;
        }

        .endpoint-card {
            background: white; border: 1px solid #e5e7eb; border-radius: 8px;
            margin-bottom: 16px; overflow: hidden; transition: box-shadow 0.2s;
        }
        .endpoint-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }

        .endpoint-header {
            padding: 14px 18px; cursor: pointer; display: flex; align-items: center; gap: 12px;
            background: #fafbfc; border-bottom: 1px solid #e5e7eb; transition: background 0.2s;
        }
        .endpoint-header:hover { background: #f0f4f8; }
        .endpoint-header.collapsed .endpoint-chevron { transform: rotate(0deg); }

        .endpoint-chevron { transition: transform 0.2s; transform: rotate(90deg); color: #9ca3af; font-size: 12px; }

        .method-badge {
            font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 4px;
            text-transform: uppercase; letter-spacing: 0.5px; font-family: 'Courier New', monospace;
            flex-shrink: 0; min-width: 60px; text-align: center;
        }
        .method-get { background: #dbeafe; color: #1d4ed8; }
        .method-post { background: #dcfce7; color: #15803d; }
        .method-delete { background: #fee2e2; color: #b91c1c; }

        .endpoint-action {
            font-family: 'Courier New', monospace; font-size: 13px; color: var(--text-dark); font-weight: 600;
        }
        .endpoint-title { font-size: 13px; color: var(--text-gray); margin-left: auto; }
        .auth-badge { font-size: 10px; padding: 2px 6px; border-radius: 3px; background: #fef3c7; color: #92400e; font-weight: 600; flex-shrink: 0; }
        .no-auth-badge { background: #d1fae5; color: #065f46; }

        .endpoint-body { padding: 18px; display: none; }
        .endpoint-body.show { display: block; }

        .endpoint-desc { color: var(--text-gray); font-size: 13px; margin-bottom: 16px; line-height: 1.6; }

        .section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-gray); margin-bottom: 8px; }

        .params-table { width: 100%; font-size: 12px; border-collapse: collapse; }
        .params-table th { background: #f9fafb; padding: 8px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; color: var(--text-gray); border-bottom: 1px solid #e5e7eb; }
        .params-table td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .params-table code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 12px; color: #0f172a; }
        .param-required { color: #dc2626; font-weight: 600; font-size: 10px; }
        .param-optional { color: #9ca3af; font-size: 10px; }

        .code-block {
            background: #1e293b; color: #e2e8f0; padding: 14px 16px; border-radius: 6px;
            font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.6;
            overflow-x: auto; white-space: pre; position: relative;
        }
        .code-block .copy-btn {
            position: absolute; top: 8px; right: 8px; background: rgba(255,255,255,0.1);
            border: none; color: #94a3b8; font-size: 12px; padding: 4px 8px; border-radius: 4px;
            cursor: pointer; transition: all 0.2s;
        }
        .code-block .copy-btn:hover { background: rgba(255,255,255,0.2); color: white; }

        .try-it-section { margin-top: 16px; padding: 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; }
        .try-it-section .btn-try {
            background: var(--primary-color); color: white; border: none; padding: 6px 16px;
            border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.2s;
        }
        .try-it-section .btn-try:hover { background: var(--primary-dark); }
        .try-result { margin-top: 10px; display: none; }
        .try-result.show { display: block; }

        /* TOC */
        .toc { position: sticky; top: 70px; }
        .toc a { display: block; padding: 5px 12px; font-size: 12px; color: var(--text-gray); text-decoration: none; border-left: 2px solid transparent; transition: all 0.2s; }
        .toc a:hover { color: var(--primary-color); border-left-color: var(--primary-color); }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; }
        .sidebar-overlay.show { display: block; }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div style="padding: 60px 16px 20px 16px; border-bottom: 1px solid #e5e7eb;">
            <h3 style="color: var(--text-dark); font-weight: 600; margin: 0 0 4px 0; font-size: 16px;">Selamat datang! 👋</h3>
            <p style="color: var(--text-gray); font-size: 12px; margin: 0;">
                <?php echo $_SESSION['nama_pegawai'] ?? 'User'; ?>
            </p>
        </div>

        <ul class="sidebar-menu">
            <li class="sidebar-section-title">Menu</li>
            <li><a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li><a href="pemeriksaan.php"><i class="fas fa-file-medical"></i> Data Radiologi</a></li>

            <li class="sidebar-divider"></li>

            <li class="sidebar-section-title">Developer</li>
            <li><a href="api-docs.php" class="active"><i class="fas fa-code"></i> API Documentation</a></li>

            <li class="sidebar-divider"></li>

            <li><a href="../logout.php" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </aside>

    <!-- Navbar -->
    <nav class="navbar navbar-sticky px-0">
        <div class="container-fluid px-3 px-md-4 navbar-content">
            <button class="btn btn-sm sidebar-toggle-btn" id="sidebarToggle" style="background: var(--primary-light); color: var(--primary-color); border: none;">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand" href="#">
                <img src="../assets/images/logo-ris.png?v=<?php echo time(); ?>" alt="Logo RIS" class="navbar-logo">
                <span>RIS RS Tk.III dr. Reksodiwiryo</span>
            </a>
        </div>
    </nav>

    <div class="main-wrapper">
        <div class="container-fluid py-4" style="max-width: 1100px;">
            <!-- Header -->
            <div style="margin-bottom: 24px;">
                <h2 style="font-weight: 700; color: var(--text-dark); margin: 0 0 6px;">
                    <i class="fas fa-code" style="color: var(--primary-color);"></i> API Documentation
                </h2>
                <p style="color: var(--text-gray); font-size: 13px; margin: 0;">
                    Dokumentasi lengkap REST API untuk Radiology Information System (RIS).
                    Semua endpoint mengembalikan response JSON.
                </p>
            </div>

            <!-- Base URL -->
            <div style="margin-bottom: 24px;">
                <div class="section-label">Base URL</div>
                <div class="api-base-url">
                    <span><?php echo htmlspecialchars($apiUrl); ?></span>?action=<span>{action}</span>
                </div>
            </div>

            <!-- Auth Info -->
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; margin-bottom: 24px;">
                <div class="section-label" style="margin-bottom: 12px;"><i class="fas fa-shield-alt"></i> Authentication</div>
                <p style="font-size: 13px; color: var(--text-dark); margin-bottom: 10px;">
                    API ini mendukung <strong>2 metode autentikasi</strong>:
                </p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="background: #f8fafc; padding: 12px; border-radius: 6px; border-left: 3px solid var(--primary-color);">
                        <strong style="font-size: 12px;">1. JWT Bearer Token</strong>
                        <div class="code-block" style="margin-top: 8px; font-size: 11px; padding: 10px;">Authorization: Bearer eyJhbGci...</div>
                    </div>
                    <div style="background: #f8fafc; padding: 12px; border-radius: 6px; border-left: 3px solid #6366f1;">
                        <strong style="font-size: 12px;">2. PHP Session (Cookie)</strong>
                        <div style="margin-top: 8px; font-size: 12px; color: var(--text-gray);">
                            Otomatis jika sudah login via browser.
                        </div>
                    </div>
                </div>
                <p style="font-size: 12px; color: var(--text-gray); margin: 12px 0 0;">
                    Panggil <code>POST ?action=login</code> untuk mendapatkan JWT token. Token berlaku 24 jam.
                </p>
            </div>

            <!-- Endpoints -->
            <?php foreach ($endpoints as $group): ?>
            <div class="endpoint-group-title" id="group-<?php echo strtolower(str_replace([' ', '/'], '-', $group['group'])); ?>">
                <?php echo htmlspecialchars($group['group']); ?>
                <span style="font-size: 12px; font-weight: 400; color: var(--text-gray);">(<?php echo count($group['items']); ?>)</span>
            </div>

            <?php foreach ($group['items'] as $i => $ep): ?>
            <?php
                $methodClass = 'method-' . strtolower($ep['method']);
                $epId = $ep['action'];
            ?>
            <div class="endpoint-card" id="ep-<?php echo $epId; ?>">
                <div class="endpoint-header collapsed" onclick="toggleEndpoint('<?php echo $epId; ?>')">
                    <i class="fas fa-chevron-right endpoint-chevron" id="chevron-<?php echo $epId; ?>"></i>
                    <span class="method-badge <?php echo $methodClass; ?>"><?php echo $ep['method']; ?></span>
                    <span class="endpoint-action">?action=<?php echo $ep['action']; ?></span>
                    <span class="endpoint-title"><?php echo htmlspecialchars($ep['title']); ?></span>
                    <?php if ($ep['auth']): ?>
                        <span class="auth-badge"><i class="fas fa-lock"></i> Auth</span>
                    <?php else: ?>
                        <span class="auth-badge no-auth-badge"><i class="fas fa-lock-open"></i> Public</span>
                    <?php endif; ?>
                </div>
                <div class="endpoint-body" id="body-<?php echo $epId; ?>">
                    <div class="endpoint-desc"><?php echo htmlspecialchars($ep['desc']); ?></div>

                    <?php if (!empty($ep['params'])): ?>
                    <div class="section-label">Query Parameters</div>
                    <table class="params-table" style="margin-bottom: 16px;">
                        <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Default</th><th>Keterangan</th></tr></thead>
                        <tbody>
                        <?php foreach ($ep['params'] as $p): ?>
                        <tr>
                            <td><code><?php echo $p['name']; ?></code></td>
                            <td><?php echo $p['type']; ?></td>
                            <td><?php echo $p['required'] ? '<span class="param-required">Ya</span>' : '<span class="param-optional">Tidak</span>'; ?></td>
                            <td><?php echo $p['default']; ?></td>
                            <td><?php echo htmlspecialchars($p['desc']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if ($ep['body']): ?>
                    <div class="section-label">Request Body (JSON)</div>
                    <div class="code-block" style="margin-bottom: 16px;">
                        <button class="copy-btn" onclick="copyCode(this)"><i class="fas fa-copy"></i></button><?php echo htmlspecialchars($ep['body']); ?>
                    </div>
                    <?php endif; ?>

                    <div class="section-label">Response</div>
                    <div class="code-block">
                        <button class="copy-btn" onclick="copyCode(this)"><i class="fas fa-copy"></i></button><?php echo htmlspecialchars($ep['response']); ?>
                    </div>

                    <!-- Try It -->
                    <?php if ($ep['method'] === 'GET'): ?>
                    <div class="try-it-section">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <i class="fas fa-play-circle" style="color: var(--primary-color);"></i>
                            <strong style="font-size: 12px;">Try It</strong>
                        </div>
                        <?php foreach ($ep['params'] as $p): ?>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                            <label style="font-size: 11px; font-weight: 600; min-width: 100px;"><?php echo $p['name']; ?>:</label>
                            <input type="text" class="try-param" data-ep="<?php echo $epId; ?>" data-name="<?php echo $p['name']; ?>"
                                   placeholder="<?php echo $p['default']; ?>"
                                   style="flex: 1; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px;">
                        </div>
                        <?php endforeach; ?>
                        <button class="btn-try" onclick="tryEndpoint('<?php echo $epId; ?>', '<?php echo $ep['action']; ?>')">
                            <i class="fas fa-paper-plane"></i> Send Request
                        </button>
                        <div class="try-result" id="result-<?php echo $epId; ?>">
                            <div class="code-block" id="result-code-<?php echo $epId; ?>" style="margin-top: 8px; max-height: 300px; overflow: auto;">Loading...</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>

            <!-- Error Codes -->
            <div class="endpoint-group-title" id="group-error-codes">
                Error Codes
            </div>
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; margin-bottom: 32px;">
                <table class="params-table">
                    <thead><tr><th>HTTP Code</th><th>Arti</th><th>Kapan Terjadi</th></tr></thead>
                    <tbody>
                        <tr><td><code>200</code></td><td>OK</td><td>Request berhasil</td></tr>
                        <tr><td><code>400</code></td><td>Bad Request</td><td>Parameter tidak lengkap / action tidak valid</td></tr>
                        <tr><td><code>401</code></td><td>Unauthorized</td><td>Token tidak valid / belum login</td></tr>
                        <tr><td><code>404</code></td><td>Not Found</td><td>Data tidak ditemukan</td></tr>
                        <tr><td><code>405</code></td><td>Method Not Allowed</td><td>HTTP method salah (misal GET ke endpoint POST)</td></tr>
                        <tr><td><code>500</code></td><td>Internal Server Error</td><td>Error di server / database</td></tr>
                        <tr><td><code>503</code></td><td>Service Unavailable</td><td>Database tidak terhubung</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle endpoint
        function toggleEndpoint(id) {
            const body = document.getElementById('body-' + id);
            const chevron = document.getElementById('chevron-' + id);
            const header = chevron.closest('.endpoint-header');

            body.classList.toggle('show');
            header.classList.toggle('collapsed');

            if (body.classList.contains('show')) {
                chevron.style.transform = 'rotate(90deg)';
            } else {
                chevron.style.transform = 'rotate(0deg)';
            }
        }

        // Copy code
        function copyCode(btn) {
            const block = btn.parentElement;
            const text = block.textContent.replace(btn.textContent, '').trim();
            navigator.clipboard.writeText(text).then(() => {
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500);
            });
        }

        // Try endpoint
        function tryEndpoint(epId, action) {
            const params = document.querySelectorAll('.try-param[data-ep="' + epId + '"]');
            let url = '<?php echo $apiUrl; ?>?action=' + action;

            params.forEach(p => {
                if (p.value.trim()) {
                    url += '&' + p.dataset.name + '=' + encodeURIComponent(p.value.trim());
                }
            });

            const resultDiv = document.getElementById('result-' + epId);
            const resultCode = document.getElementById('result-code-' + epId);
            resultDiv.classList.add('show');
            resultCode.textContent = 'Loading...';

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    resultCode.textContent = JSON.stringify(data, null, 2);
                })
                .catch(err => {
                    resultCode.textContent = 'Error: ' + err.message;
                });
        }

        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainWrapper = document.querySelector('.main-wrapper');

        sidebarToggle?.addEventListener('click', () => {
            sidebar.classList.toggle('hidden');
            if (window.innerWidth > 992) mainWrapper.classList.toggle('sidebar-hidden');
            if (window.innerWidth <= 992) sidebarOverlay.classList.remove('show');
            localStorage.setItem('sidebarHidden', sidebar.classList.contains('hidden'));
        });

        sidebarOverlay?.addEventListener('click', () => {
            sidebar.classList.add('hidden');
            sidebarOverlay.classList.remove('show');
        });

        // Restore sidebar state
        window.addEventListener('load', () => {
            if (localStorage.getItem('sidebarHidden') === 'true') {
                sidebar.classList.add('hidden');
                if (window.innerWidth > 992) mainWrapper.classList.add('sidebar-hidden');
            }
        });

        // Open endpoint from URL hash
        if (window.location.hash) {
            const epId = window.location.hash.replace('#', '');
            const body = document.getElementById('body-' + epId);
            if (body) {
                toggleEndpoint(epId);
                document.getElementById('ep-' + epId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    </script>

    <script src="../assets/js/activity-tracker.js"></script>
</body>
</html>
