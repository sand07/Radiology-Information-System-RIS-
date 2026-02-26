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

/**
 * Build OHIF Viewer URL
 */
function buildOhifUrl($studyInstanceUID, $seriesInstanceUID = null) {
    if (empty($studyInstanceUID)) {
        return null;
    }

    $baseUrl = ORTHANC_URL;
    $ohifUrl = $baseUrl . '/ohif/viewer?StudyInstanceUIDs=' . urlencode($studyInstanceUID);

    return $ohifUrl;
}

$db = new Database();

// Get parameters
$no_rawat = isset($_GET['no_rawat']) ? sanitize($_GET['no_rawat']) : '';
$tgl = isset($_GET['tgl']) ? sanitize($_GET['tgl']) : '';
$jam = isset($_GET['jam']) ? sanitize($_GET['jam']) : '';
$noorder = isset($_GET['noorder']) ? sanitize($_GET['noorder']) : '';

if (empty($no_rawat) || empty($tgl) || empty($jam)) {
    exit('Invalid parameters');
}

// Get examination details
try {
    $exam = $db->fetch(
        "SELECT
            pr.no_rawat,
            rp.no_rkm_medis,
            p.nm_pasien,
            pr.tgl_periksa,
            pr.jam,
            d.nm_dokter,
            jpr.nm_perawatan,
            pr.biaya,
            perr.diagnosa_klinis,
            hr.no_foto,
            hr.hasil
        FROM periksa_radiologi pr
        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
        INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON pr.kd_dokter = d.kd_dokter
        INNER JOIN jns_perawatan_radiologi jpr ON pr.kd_jenis_prw = jpr.kd_jenis_prw
        LEFT JOIN hasil_radiologi hr ON pr.no_rawat = hr.no_rawat AND pr.tgl_periksa = hr.tgl_periksa AND pr.jam = hr.jam
        LEFT JOIN permintaan_radiologi perr ON pr.no_rawat = perr.no_rawat AND pr.tgl_periksa = perr.tgl_hasil AND pr.jam = perr.jam_hasil
        WHERE pr.no_rawat = ? AND pr.tgl_periksa = ? AND pr.jam = ?",
        [$no_rawat, $tgl, $jam]
    );

    if (!$exam) {
        exit('Pemeriksaan tidak ditemukan');
    }
} catch (Exception $e) {
    exit('Error: ' . $e->getMessage());
}

// Handle AJAX expertise form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_expertise') {
    header('Content-Type: application/json');

    $hasil = isset($_POST['hasil']) ? sanitize($_POST['hasil']) : '';
    $no_foto = isset($_POST['no_foto']) ? sanitize($_POST['no_foto']) : $exam['no_foto'] ?? '';

    try {
        if (empty($hasil)) {
            // Delete hasil jika kosong
            $db->delete('hasil_radiologi', [
                'no_rawat' => $no_rawat,
                'tgl_periksa' => $tgl,
                'jam' => $jam
            ]);
            echo json_encode(['success' => true, 'message' => 'Hasil radiologi telah dihapus']);
            exit();
        }

        // Check if hasil already exists
        $existing = $db->fetch(
            "SELECT no_rawat FROM hasil_radiologi WHERE no_rawat = ? AND tgl_periksa = ? AND jam = ?",
            [$no_rawat, $tgl, $jam]
        );

        if ($existing) {
            // Update existing
            $result = $db->update(
                'hasil_radiologi',
                [
                    'hasil' => $hasil,
                    'no_foto' => $no_foto
                ],
                [
                    'no_rawat' => $no_rawat,
                    'tgl_periksa' => $tgl,
                    'jam' => $jam
                ]
            );
        } else {
            // Insert new
            $result = $db->insert(
                'hasil_radiologi',
                [
                    'no_rawat' => $no_rawat,
                    'tgl_periksa' => $tgl,
                    'jam' => $jam,
                    'no_foto' => $no_foto,
                    'hasil' => $hasil
                ]
            );
        }

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Expertise berhasil disimpan']);
        } else {
            echo json_encode(['success' => false, 'message' => $db->getLastError()]);
        }
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// Handle AJAX signature upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_signature') {
    header('Content-Type: application/json');

    try {
        // Validate image data
        if (!isset($_POST['signature_data'])) {
            echo json_encode(['success' => false, 'message' => 'Data signature tidak ditemukan']);
            exit();
        }

        $signature_data = $_POST['signature_data'];

        // Get absolute path to signatures directory
        $base_dir = dirname(dirname(__FILE__));
        $signatures_dir = $base_dir . '/assets/signatures/';

        // Create signatures directory if it doesn't exist
        if (!is_dir($signatures_dir)) {
            if (!mkdir($signatures_dir, 0755, true)) {
                echo json_encode(['success' => false, 'message' => 'Gagal membuat folder signatures']);
                exit();
            }
        }

        // Extract base64 data
        if (strpos($signature_data, 'data:image') !== false) {
            list($type, $data) = explode(';', $signature_data);
            list(, $data) = explode(',', $data);
            $data = base64_decode($data, true);
        } else {
            $data = base64_decode($signature_data, true);
        }

        if (!$data || strlen($data) == 0) {
            echo json_encode(['success' => false, 'message' => 'Gagal decode base64 atau data kosong']);
            exit();
        }

        error_log("Signature data size: " . strlen($data) . " bytes");

        // Generate filename with datetime
        $timestamp = date('YmdHis');
        $filename = 'ttd_' . $no_rawat . '_' . $timestamp . '.png';
        $filepath = $signatures_dir . $filename;
        $url_image = 'assets/signatures/' . $filename;

        // Save image file
        $bytes_written = file_put_contents($filepath, $data);
        if ($bytes_written === false) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file gambar di ' . $filepath]);
            exit();
        }

        // Save to database - gunakan tanggal dari GET parameter
        $tanggal = $tgl . ' ' . $jam;

        // Validasi format
        if (!strtotime($tanggal)) {
            // Fallback ke datetime sekarang jika format tidak valid
            $tanggal = date('Y-m-d H:i:s');
        }

        try {
            // Cek apakah sudah ada data dengan no_rawat yang sama (untuk hari ini)
            $existing = $db->fetch(
                "SELECT tanggal FROM expertise_ttd_dokter WHERE no_rawat = ? AND DATE(tanggal) = DATE(?)",
                [$no_rawat, $tanggal]
            );

            if ($existing) {
                // Delete record lama
                $deleteOk = $db->delete('expertise_ttd_dokter', [
                    'no_rawat' => $no_rawat,
                    'tanggal' => $existing['tanggal']
                ]);

                if (!$deleteOk) {
                    error_log("Delete failed: " . $db->getLastError());
                }
            }

            // Insert record baru - include noorder field
            $result = $db->insert(
                'expertise_ttd_dokter',
                [
                    'no_rawat' => $no_rawat,
                    'tanggal' => $tanggal,
                    'noorder' => $noorder ?? '',
                    'url_image' => $url_image
                ]
            );

            if ($result !== false) {
                echo json_encode(['success' => true, 'message' => 'TTD berhasil disimpan', 'url' => $url_image]);
            } else {
                $errMsg = $db->getLastError();
                error_log("Insert TTD failed for $no_rawat: " . $errMsg);
                echo json_encode(['success' => false, 'message' => 'Database Error: ' . $errMsg]);
            }
        } catch (Exception $e) {
            error_log("Exception in save_signature: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

// Get templates for expertise
$templates = [];
try {
    $templates = $db->fetchAll("SELECT no_template, nama_pemeriksaan, template_hasil_radiologi FROM template_hasil_radiologi ORDER BY nama_pemeriksaan") ?? [];
} catch (Exception $e) {
    // Handle error silently
}

// Initialize Orthanc API
$orthanc = new OrthancAPI();
$orthancConnected = $orthanc->testConnection();

// Build OHIF Viewer URL
$ohifUrl = null;
$studyInstanceUID = null;
$seriesInstanceUID = null;

if ($orthancConnected) {
    try {
        $searchUrl = ORTHANC_URL . '/tools/find';

        // Priority 1: Search by AccessionNumber
        if (!empty($noorder)) {
            $query = [
                'Level' => 'Study',
                'Query' => [
                    'AccessionNumber' => $noorder
                ]
            ];

            $result = performOrthancSearch($searchUrl, json_encode($query));

            if ($result && !empty($result)) {
                $studyId = $result[0];
                $studyData = getStudyInstanceUIDWithValidation($studyId, $noorder);

                if ($studyData && $studyData['isValid']) {
                    $studyInstanceUID = $studyData['studyInstanceUID'];
                }
            }
        }

        // Priority 2: Fallback to PatientID + StudyDate
        if (empty($studyInstanceUID) && !empty($exam['no_rkm_medis']) && !empty($exam['tgl_periksa'])) {
            $formattedDate = str_replace('-', '', $exam['tgl_periksa']);

            $query = [
                'Level' => 'Study',
                'Query' => [
                    'PatientID' => $exam['no_rkm_medis'],
                    'StudyDate' => $formattedDate
                ]
            ];

            $result = performOrthancSearch($searchUrl, json_encode($query));

            if ($result && !empty($result)) {
                foreach ($result as $studyId) {
                    $studyData = getStudyInstanceUIDWithValidation($studyId, $noorder);

                    if ($studyData) {
                        if (!empty($noorder) && !$studyData['isValid']) {
                            continue;
                        }

                        $studyInstanceUID = $studyData['studyInstanceUID'];
                        break;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error searching Orthanc: ' . $e->getMessage());
    }
}

/**
 * Perform search pada Orthanc /tools/find endpoint
 */
function performOrthancSearch($url, $query) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USERNAME . ':' . ORTHANC_PASSWORD);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return is_array($result) ? $result : null;
        }

        return null;

    } catch (Exception $e) {
        error_log('Error in performOrthancSearch: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get StudyInstanceUID dari Orthanc study ID dengan validasi AccessionNumber
 */
function getStudyInstanceUIDWithValidation($studyId, $expectedAccessionNumber = null) {
    try {
        $url = ORTHANC_URL . '/studies/' . $studyId;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USERNAME . ':' . ORTHANC_PASSWORD);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $studyInstanceUID = $data['MainDicomTags']['StudyInstanceUID'] ?? null;
            $accessionNumberFromOrthanc = $data['MainDicomTags']['AccessionNumber'] ?? '';

            if (empty($studyInstanceUID)) {
                return null;
            }

            $isValid = true;
            if (!empty($expectedAccessionNumber)) {
                $expected = strtolower(trim($expectedAccessionNumber));
                $actual = strtolower(trim($accessionNumberFromOrthanc));
                $isValid = ($expected === $actual);
            }

            return [
                'studyInstanceUID' => $studyInstanceUID,
                'accessionNumber' => $accessionNumberFromOrthanc,
                'isValid' => $isValid
            ];
        }

        return null;

    } catch (Exception $e) {
        error_log('Error in getStudyInstanceUIDWithValidation: ' . $e->getMessage());
        return null;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Viewer Popup</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .viewer-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            width: 100%;
        }

        .viewer-toolbar {
            height: 50px;
            background: #2c3e50;
            color: white;
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            z-index: 100;
        }

        .toolbar-title {
            font-weight: 500;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .toolbar-patient-name {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .toolbar-exam-info {
            font-size: 15px;
            color: #ddd;
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .toolbar-separator {
            color: #666;
        }

        .toolbar-exam-label {
            white-space: nowrap;
        }

        .toolbar-exam-label strong {
            margin-left: 4px;
            font-weight: 600;
        }

        .toolbar-spacer {
            flex: 1;
        }

        .toolbar-info {
            font-size: 13px;
            color: #aaa;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .viewer-selector {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }

        .viewer-selector select {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            color: #333;
            font-size: 12px;
            cursor: pointer;
            transition: border-color 0.2s ease;
        }

        .viewer-selector select:hover {
            border-color: #4D7C0F;
        }

        .viewer-selector select:focus {
            outline: none;
            border-color: #4D7C0F;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1);
        }

        @media (max-width: 1200px) {
            .toolbar-title {
                font-size: 14px;
                gap: 15px;
            }

            .toolbar-exam-info {
                font-size: 14px;
                gap: 15px;
            }
        }

        @media (max-width: 992px) {
            .toolbar-title {
                font-size: 13px;
                gap: 12px;
            }

            .toolbar-exam-info {
                font-size: 13px;
                gap: 12px;
            }

            .toolbar-info {
                font-size: 12px;
                gap: 12px;
            }

            .viewer-selector select {
                font-size: 11px;
                padding: 5px 8px;
            }
        }

        @media (max-width: 768px) {
            .viewer-toolbar {
                height: auto;
                padding: 10px 15px;
                flex-wrap: wrap;
                gap: 10px;
            }

            .toolbar-title {
                font-size: 12px;
                gap: 8px;
                flex-wrap: wrap;
                width: 100%;
            }

            .toolbar-patient-name {
                flex-basis: 100%;
            }

            .toolbar-separator {
                display: none;
            }

            .toolbar-exam-info {
                font-size: 12px;
                gap: 8px;
                flex-wrap: wrap;
            }

            .toolbar-spacer {
                display: none;
            }

            .toolbar-info {
                font-size: 11px;
                width: 100%;
                flex-direction: column;
                gap: 8px;
            }

            .viewer-selector {
                font-size: 11px;
            }

            .viewer-selector select {
                font-size: 10px;
                padding: 4px 6px;
            }
        }

        .viewer-main {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        .viewer-canvas {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: auto;
            background: #1a1a1a;
        }

        .viewer-canvas img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .viewer-sidebar {
            width: 380px;
            background: white;
            border-left: 1px solid #e5e7eb;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 1200px) {
            .viewer-sidebar {
                width: 350px;
            }
        }

        @media (max-width: 992px) {
            .viewer-sidebar {
                width: 320px;
            }
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 992px) {
            .sidebar-content {
                padding: 15px;
            }
        }

        .sidebar-label {
            font-size: 12px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }

        @media (max-width: 992px) {
            .sidebar-label {
                font-size: 11px;
                margin-bottom: 8px;
            }
        }

        .sidebar-section {
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        @media (max-width: 992px) {
            .sidebar-section {
                margin-bottom: 15px;
            }
        }

        .expertise-textarea {
            width: 100%;
            flex: 1;
            min-height: 250px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px;
            resize: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        @media (max-width: 992px) {
            .expertise-textarea {
                min-height: 180px;
                font-size: 12px;
            }
        }

        @media (max-width: 768px) {
            .expertise-textarea {
                min-height: 120px;
            }
        }

        .expertise-textarea:focus {
            outline: none;
            border-color: #4D7C0F;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1);
        }

        .char-count {
            font-size: 11px;
            color: #999;
            margin-top: 6px;
            margin-bottom: 0;
            flex-shrink: 0;
        }

        @media (max-width: 992px) {
            .char-count {
                font-size: 10px;
                margin-top: 4px;
            }
        }

        .form-group-footer {
            flex-shrink: 0;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }

        .form-control-sm {
            font-size: 13px;
            padding: 0.5rem 0.7rem;
            margin-bottom: 10px;
        }

        .form-control-sm:focus {
            outline: none;
            border-color: #4D7C0F !important;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1) !important;
        }

        @media (max-width: 992px) {
            .form-control-sm {
                font-size: 12px;
                padding: 0.4rem 0.6rem;
                margin-bottom: 8px;
            }
        }

        .save-btn {
            width: 100%;
            padding: 0.6rem;
            font-size: 13px;
            margin-top: 0;
            flex-shrink: 0;
        }

        @media (max-width: 992px) {
            .save-btn {
                padding: 0.5rem;
                font-size: 12px;
            }
        }

        .message-alert {
            padding: 12px 14px;
            font-size: 12px;
            border-radius: 6px;
            animation: slideIn 0.3s ease;
            margin-top: 12px;
        }

        @media (max-width: 992px) {
            .message-alert {
                padding: 10px 12px;
                font-size: 11px;
                margin-top: 10px;
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .no-image {
            text-align: center;
            color: #999;
            padding: 50px 20px;
        }

        .no-image i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .no-image p {
            font-size: 14px;
            margin-bottom: 10px;
        }

        .no-image small {
            font-size: 12px;
            color: #777;
        }

        @media (max-height: 400px) {
            .viewer-toolbar {
                height: 40px;
                padding: 0 15px;
                font-size: 12px;
            }
        }

        @media (max-width: 768px) {
            .viewer-main {
                flex-direction: column;
            }

            .viewer-sidebar {
                width: 100%;
                border-left: none;
                border-top: 1px solid #e5e7eb;
                max-height: 50%;
            }

            .viewer-canvas {
                flex: 1;
            }

            .sidebar-content {
                padding: 12px;
            }

            .expertise-textarea {
                min-height: 140px;
                padding: 10px;
                font-size: 12px;
            }

            .sidebar-section {
                margin-bottom: 12px;
            }

            .form-group-footer {
                margin-top: 10px;
                padding-top: 10px;
            }
        }

        /* Button Styling Override */
        .btn-primary {
            background-color: #4D7C0F !important;
            border-color: #4D7C0F !important;
            color: white !important;
        }

        .btn-primary:hover {
            background-color: #3D5C0D !important;
            border-color: #3D5C0D !important;
        }

        .btn-primary:focus, .btn-primary:active {
            background-color: #3D5C0D !important;
            border-color: #3D5C0D !important;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.25) !important;
        }

        .btn-secondary {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
            color: white !important;
        }

        .btn-secondary:hover {
            background-color: #5a6268 !important;
            border-color: #5a6268 !important;
        }
    </style>
</head>
<body>
    <div class="viewer-container">
        <!-- Toolbar -->
        <div class="viewer-toolbar">
            <div class="toolbar-title">
                <div class="toolbar-patient-name">
                    <i class="fas fa-user"></i>
                    <span><?php echo htmlspecialchars(substr($exam['nm_pasien'], 0, 40)); ?></span>
                </div>
                <span class="toolbar-separator">|</span>
                <div class="toolbar-exam-info">
                    <?php if (!empty($exam['nm_perawatan'])): ?>
                    <span class="toolbar-exam-label">
                        Nama Pemeriksaan:&nbsp;<strong><?php echo htmlspecialchars($exam['nm_perawatan']); ?></strong>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($exam['diagnosa_klinis'])): ?>
                    <span class="toolbar-separator">|</span>
                    <span class="toolbar-exam-label">
                        Diagnosa:&nbsp;<strong><?php echo htmlspecialchars(substr($exam['diagnosa_klinis'], 0, 50)); ?></strong>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="toolbar-spacer"></div>
            <div class="toolbar-info">
                <div class="viewer-selector">
                    <label for="viewerTypeSelect" style="white-space: nowrap; color: #ccc;">Viewer:</label>
                    <select id="viewerTypeSelect">
                        <option value="ohif">OHIF Viewer</option>
                        <option value="segmentation">OHIF Segmentation</option>
                        <option value="hangingProtocol">OHIF Hanging Protocol</option>
                        <option value="stone">Stone Viewer</option>
                    </select>
                </div>
                <div style="white-space: nowrap;">
                    <?php echo formatTanggalTampil($exam['tgl_periksa']); ?> • <?php echo $exam['jam']; ?>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="viewer-main">
            <!-- Viewer Canvas -->
            <div class="viewer-canvas">
                <?php if (!$orthancConnected): ?>
                <div class="no-image">
                    <i class="fas fa-image"></i>
                    <p>Koneksi ke Orthanc PACS tidak tersedia</p>
                    <small>Pastikan Orthanc berjalan di <?php echo ORTHANC_URL; ?></small>
                </div>
                <?php elseif (empty($studyInstanceUID)): ?>
                <div class="no-image">
                    <i class="fas fa-image"></i>
                    <p>Tidak ada gambar untuk pemeriksaan ini</p>
                    <small>
                        RM: <?php echo $exam['no_rkm_medis']; ?><br>
                        <?php echo !empty($noorder) ? 'No. Permintaan: ' . $noorder . '<br>' : ''; ?>
                        Tanggal: <?php echo $exam['tgl_periksa']; ?>
                    </small>
                </div>
                <?php else: ?>
                <!-- OHIF Viewer embedded -->
                <iframe id="ohifViewer"
                        src="<?php echo buildOhifUrl($studyInstanceUID, $seriesInstanceUID); ?>"
                        style="width: 100%; height: 100%; border: none;"></iframe>
                <?php endif; ?>
            </div>

            <!-- Sidebar with Expertise Form -->
            <div class="viewer-sidebar">
                <div class="sidebar-content">
                    <!-- Expertise Section -->
                    <form id="expertiseForm" class="sidebar-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <div class="sidebar-label"><i class="fas fa-stethoscope"></i> Expertise</div>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal" style="font-size: 11px; padding: 4px 8px;">
                                <i class="fas fa-list"></i> Template Hasil
                            </button>
                        </div>

                        <!-- Textarea - takes up all available space -->
                        <textarea id="hasilTextarea" name="hasil" class="expertise-textarea"
                                  placeholder="Tulis hasil bacaan radiologi..."
                                  required><?php echo isset($exam['hasil']) ? htmlspecialchars($exam['hasil']) : ''; ?></textarea>

                        <!-- Character Count -->
                        <div class="char-count">
                            Karakter: <span id="charCount">0</span>
                        </div>

                        <!-- Photo ID and Save Button Group -->
                        <div class="form-group-footer">
                            <label class="sidebar-label" style="margin-bottom: 8px;">Foto ID (Opsional)</label>
                            <input type="text" id="noFoto" name="no_foto" class="form-control form-control-sm"
                                   value="<?php echo isset($exam['no_foto']) ? htmlspecialchars($exam['no_foto']) : ''; ?>"
                                   placeholder="ID gambar">

                            <button type="submit" class="btn btn-primary btn-sm save-btn">
                                <i class="fas fa-save"></i> Simpan
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-top: 8px; width: 100%;" data-bs-toggle="modal" data-bs-target="#signatureModal">
                                <i class="fas fa-pen"></i> TTD (Tanda Tangan)
                            </button>

                            <div id="expertiseMessage" style="display: none;"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Template Modal -->
    <div class="modal fade" id="templateModal" tabindex="-1" aria-labelledby="templateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="templateModalLabel"><i class="fas fa-list"></i> Template Hasil Radiologi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Search Input -->
                    <div class="mb-3">
                        <input type="text" class="form-control" id="templateSearch" placeholder="Cari template...">
                    </div>

                    <!-- Template List -->
                    <div id="templateList" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($templates)): ?>
                            <?php foreach ($templates as $template): ?>
                                <div class="template-item" data-template="<?php echo htmlspecialchars($template['template_hasil_radiologi']); ?>" data-name="<?php echo htmlspecialchars($template['nama_pemeriksaan']); ?>" style="cursor: pointer; padding: 12px; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 8px; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#f5f5f5'; this.style.borderColor='#4D7C0F';" onmouseout="this.style.backgroundColor='white'; this.style.borderColor='#e0e0e0';">
                                    <div style="font-weight: 600; color: #212529; margin-bottom: 4px;"><?php echo htmlspecialchars($template['nama_pemeriksaan']); ?></div>
                                    <div style="font-size: 12px; color: #6c757d; line-height: 1.4;"><?php echo htmlspecialchars(substr($template['template_hasil_radiologi'], 0, 100)) . (strlen($template['template_hasil_radiologi']) > 100 ? '...' : ''); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">Tidak ada template tersedia</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Signature Modal -->
    <div class="modal fade" id="signatureModal" tabindex="-1" aria-labelledby="signatureModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="signatureModalLabel"><i class="fas fa-pen"></i> Tanda Tangan Digital</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size: 13px; color: #666; margin-bottom: 12px;">Silakan tanda tangan di area berikut:</p>
                    <canvas id="signaturePad"
                            style="border: 2px solid #d1d5db; border-radius: 4px; background: white; cursor: crosshair; width: 100%; height: 280px; display: block; touch-action: none;">
                    </canvas>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="clearSignature">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                    <button type="button" class="btn btn-primary" id="saveSignature">
                        <i class="fas fa-save"></i> Simpan TTD
                    </button>
                </div>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Store DICOM viewer data
        const orthancData = {
            studyInstanceUID: '<?php echo $studyInstanceUID; ?>',
            seriesInstanceUID: '<?php echo $seriesInstanceUID; ?>',
            orthancUrl: '<?php echo ORTHANC_URL; ?>'
        };

        // Build OHIF Segmentation URL
        function buildSegmentationUrl(studyInstanceUID) {
            if (orthancData.orthancUrl && studyInstanceUID) {
                return orthancData.orthancUrl + '/ohif/segmentation?StudyInstanceUIDs=' + encodeURIComponent(studyInstanceUID);
            }
            return null;
        }

        // Build OHIF Hanging Protocol URL
        function buildHangingProtocolUrl(studyInstanceUID) {
            if (orthancData.orthancUrl && studyInstanceUID) {
                return orthancData.orthancUrl + '/ohif/viewer?hangingprotocolId=mprAnd3DVolumeViewport&StudyInstanceUIDs=' + encodeURIComponent(studyInstanceUID);
            }
            return null;
        }

        // Build Stone Viewer URL
        function buildStoneViewerUrl(studyInstanceUID) {
            if (orthancData.orthancUrl && studyInstanceUID) {
                return orthancData.orthancUrl + '/stone-webviewer/index.html?study=' + encodeURIComponent(studyInstanceUID);
            }
            return null;
        }

        // Handle viewer type selection
        const viewerTypeSelect = document.getElementById('viewerTypeSelect');
        const ohifViewer = document.getElementById('ohifViewer');

        if (viewerTypeSelect && ohifViewer) {
            viewerTypeSelect.addEventListener('change', function(e) {
                const viewerType = e.target.value;
                let newUrl = '';

                if (viewerType === 'ohif') {
                    newUrl = '<?php echo buildOhifUrl($studyInstanceUID, $seriesInstanceUID); ?>';
                } else if (viewerType === 'segmentation') {
                    newUrl = buildSegmentationUrl(orthancData.studyInstanceUID);
                } else if (viewerType === 'hangingProtocol') {
                    newUrl = buildHangingProtocolUrl(orthancData.studyInstanceUID);
                } else if (viewerType === 'stone') {
                    newUrl = buildStoneViewerUrl(orthancData.studyInstanceUID);
                }

                if (newUrl) {
                    ohifViewer.src = newUrl;

                    // Auto-close Stone Viewer modal
                    if (viewerType === 'stone') {
                        setTimeout(() => {
                            try {
                                const iframeDoc = ohifViewer.contentDocument || ohifViewer.contentWindow.document;
                                if (iframeDoc) {
                                    // Find Close button by text content
                                    const buttons = iframeDoc.querySelectorAll('button');
                                    const closeBtn = Array.from(buttons).find(btn =>
                                        btn.textContent.trim().toLowerCase() === 'close'
                                    );
                                    if (closeBtn) {
                                        closeBtn.click();
                                        console.log('Stone Viewer modal closed automatically');
                                    }
                                }
                            } catch (err) {
                                console.log('Could not auto-close modal:', err);
                            }
                        }, 2000);
                    }
                }
            });
        }

        // Character counter for expertise textarea
        const hasilTextarea = document.getElementById('hasilTextarea');
        const charCount = document.getElementById('charCount');

        if (hasilTextarea) {
            hasilTextarea.addEventListener('input', function() {
                let count = this.value.length;
                charCount.textContent = count;
            });

            // Initialize char count on page load
            window.addEventListener('load', function() {
                hasilTextarea.dispatchEvent(new Event('input'));
            });
        }

        // Handle expertise form submission
        const expertiseForm = document.getElementById('expertiseForm');
        if (expertiseForm) {
            expertiseForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const hasil = hasilTextarea.value.trim();
                const noFoto = document.getElementById('noFoto').value.trim();

                // Validation
                if (hasil.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Perhatian',
                        text: 'Hasil expertise tidak boleh kosong',
                        confirmButtonColor: '#4D7C0F'
                    });
                    return false;
                }

                // Submit form with AJAX
                submitExpertiseForm(hasil, noFoto);
            });
        }

        function submitExpertiseForm(hasil, noFoto) {
            const formData = new FormData();
            formData.append('action', 'save_expertise');
            formData.append('hasil', hasil);
            formData.append('no_foto', noFoto);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: 'Expertise berhasil disimpan',
                        confirmButtonColor: '#4D7C0F',
                        timer: 1500,
                        timerProgressBar: true
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: data.message || 'Gagal menyimpan expertise',
                        confirmButtonColor: '#4D7C0F'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Gagal mengirim data ke server',
                    confirmButtonColor: '#4D7C0F'
                });
            });
        }

        // Template functionality
        const templateSearch = document.getElementById('templateSearch');
        const templateList = document.getElementById('templateList');
        const templateItems = document.querySelectorAll('.template-item');

        // Search templates
        if (templateSearch) {
            templateSearch.addEventListener('input', function() {
                const searchValue = this.value.toLowerCase();
                templateItems.forEach(item => {
                    const name = item.getAttribute('data-name').toLowerCase();
                    if (name.includes(searchValue)) {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }

        // Handle template selection
        templateItems.forEach(item => {
            item.addEventListener('click', function() {
                const template = this.getAttribute('data-template');
                const hasilTextarea = document.getElementById('hasilTextarea');

                if (hasilTextarea) {
                    hasilTextarea.value = template;
                    hasilTextarea.dispatchEvent(new Event('input')); // Trigger character counter update

                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('templateModal'));
                    if (modal) {
                        modal.hide();
                    }

                    // Focus on textarea
                    hasilTextarea.focus();
                }
            });
        });

        // Signature Pad functionality
        let canvas, ctx, isDrawing = false, lastX = 0, lastY = 0;

        // Initialize signature pad
        function initSignaturePad() {
            canvas = document.getElementById('signaturePad');
            if (!canvas) return;

            ctx = canvas.getContext('2d');

            // Set canvas resolution
            const displayWidth = canvas.clientWidth;
            const displayHeight = canvas.clientHeight;
            canvas.width = displayWidth * window.devicePixelRatio;
            canvas.height = displayHeight * window.devicePixelRatio;
            ctx.scale(window.devicePixelRatio, window.devicePixelRatio);

            // Set drawing properties
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#000000';

            // Remove old event listeners
            canvas.removeEventListener('mousedown', startDrawing);
            canvas.removeEventListener('mousemove', draw);
            canvas.removeEventListener('mouseup', stopDrawing);
            canvas.removeEventListener('mouseout', stopDrawing);
            canvas.removeEventListener('touchstart', handleTouch);
            canvas.removeEventListener('touchmove', handleTouch);
            canvas.removeEventListener('touchend', stopDrawing);

            // Add new event listeners
            canvas.addEventListener('mousedown', startDrawing);
            canvas.addEventListener('mousemove', draw);
            canvas.addEventListener('mouseup', stopDrawing);
            canvas.addEventListener('mouseout', stopDrawing);
            canvas.addEventListener('touchstart', handleTouch);
            canvas.addEventListener('touchmove', handleTouch);
            canvas.addEventListener('touchend', stopDrawing);
        }

        function startDrawing(e) {
            isDrawing = true;
            const rect = canvas.getBoundingClientRect();
            lastX = (e.clientX - rect.left) / window.devicePixelRatio;
            lastY = (e.clientY - rect.top) / window.devicePixelRatio;
        }

        function draw(e) {
            if (!isDrawing) return;

            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left) / window.devicePixelRatio;
            const y = (e.clientY - rect.top) / window.devicePixelRatio;

            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(x, y);
            ctx.stroke();

            lastX = x;
            lastY = y;
        }

        function stopDrawing() {
            isDrawing = false;
        }

        function handleTouch(e) {
            e.preventDefault();
            const touch = e.touches[0];
            const mouseEvent = new MouseEvent(e.type === 'touchstart' ? 'mousedown' : 'mousemove', {
                clientX: touch.clientX,
                clientY: touch.clientY
            });
            canvas.dispatchEvent(mouseEvent);
        }

        // Clear signature button
        const clearBtn = document.getElementById('clearSignature');
        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (ctx) {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                }
            });
        }

        // Save signature button
        const saveBtn = document.getElementById('saveSignature');
        if (saveBtn) {
            saveBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (canvas) {
                    // Check if canvas has drawing
                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const data = imageData.data;
                    let hasDrawing = false;

                    // Check if there's any non-white pixel
                    for (let i = 0; i < data.length; i += 4) {
                        if (data[i + 3] > 0) { // Check alpha channel
                            hasDrawing = true;
                            break;
                        }
                    }

                    if (!hasDrawing) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Peringatan',
                            text: 'Silakan tanda tangan terlebih dahulu sebelum menyimpan',
                            confirmButtonColor: '#4D7C0F'
                        });
                        return;
                    }

                    // Get signature as base64
                    const signature_data = canvas.toDataURL('image/png');
                    console.log('Signature data length:', signature_data.length);

                    // Send to server
                    const formData = new FormData();
                    formData.append('action', 'save_signature');
                    formData.append('signature_data', signature_data);

                    Swal.fire({
                        title: 'Menyimpan TTD...',
                        allowOutsideClick: false,
                        didOpen: (toast) => {
                            Swal.showLoading();
                        }
                    });

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        return response.text();
                    })
                    .then(text => {
                        console.log('Response text:', text);
                        try {
                            const data = JSON.parse(text);

                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: 'TTD berhasil disimpan',
                                    confirmButtonColor: '#4D7C0F',
                                    timer: 1500,
                                    timerProgressBar: true
                                }).then(() => {
                                    // Close modal
                                    const modal = bootstrap.Modal.getInstance(document.getElementById('signatureModal'));
                                    if (modal) {
                                        modal.hide();
                                    }
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: data.message || 'Gagal menyimpan TTD',
                                    confirmButtonColor: '#4D7C0F'
                                });
                            }
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error Parse JSON',
                                text: 'Response: ' + text.substring(0, 200),
                                confirmButtonColor: '#4D7C0F'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Gagal mengirim data ke server: ' + error.message,
                            confirmButtonColor: '#4D7C0F'
                        });
                    });
                }
            });
        }

        // Initialize signature pad when modal is shown
        const signatureModal = document.getElementById('signatureModal');
        if (signatureModal) {
            signatureModal.addEventListener('shown.bs.modal', function() {
                setTimeout(initSignaturePad, 100);
            });
        }

        // Initialize on page load
        initSignaturePad();
    </script>
</body>
</html>
