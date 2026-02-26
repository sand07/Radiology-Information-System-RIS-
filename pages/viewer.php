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
$user = getUser();

// Get parameters
$no_rawat = isset($_GET['no_rawat']) ? sanitize($_GET['no_rawat']) : '';
$tgl = isset($_GET['tgl']) ? sanitize($_GET['tgl']) : '';
$jam = isset($_GET['jam']) ? sanitize($_GET['jam']) : '';
$noorder = isset($_GET['noorder']) ? sanitize($_GET['noorder']) : '';

if (empty($no_rawat) || empty($tgl) || empty($jam)) {
    redirect('../pages/dashboard.php');
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
        redirect('../pages/dashboard.php');
    }
} catch (Exception $e) {
    redirect('../pages/dashboard.php');
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

// Initialize Orthanc API
$orthanc = new OrthancAPI();
$orthancConnected = $orthanc->testConnection();

// Build OHIF Viewer URL based on examination data
// OHIF akan query Orthanc untuk mencari images berdasarkan patient/study/series
$ohifUrl = null;
$studyInstanceUID = null;
$seriesInstanceUID = null;

if ($orthancConnected) {
    // Search patient di Orthanc menggunakan /tools/find endpoint
    // Logic: Priority 1 - AccessionNumber, Priority 2 - PatientID + StudyDate
    try {
        $searchUrl = ORTHANC_URL . '/tools/find';

        // Priority 1: Search by AccessionNumber (no_permintaan)
        if (!empty($noorder)) {
            $query = [
                'Level' => 'Study',
                'Query' => [
                    'AccessionNumber' => $noorder
                ]
            ];

            $result = performOrthancSearch($searchUrl, json_encode($query));

            if ($result && !empty($result)) {
                $studyId = $result[0]; // Get first study ID
                $studyData = getStudyInstanceUIDWithValidation($studyId, $noorder);

                if ($studyData && $studyData['isValid']) {
                    $studyInstanceUID = $studyData['studyInstanceUID'];
                    error_log('Study found and validated with AccessionNumber: ' . $noorder);
                } else {
                    // AccessionNumber tidak match atau tidak valid
                    error_log('AccessionNumber mismatch or invalid for: ' . $noorder);
                }
            }
        }

        // Priority 2: Fallback to PatientID + StudyDate if AccessionNumber search failed
        if (empty($studyInstanceUID) && !empty($exam['no_rkm_medis']) && !empty($exam['tgl_periksa'])) {
            // Format date to YYYYMMDD (DICOM format)
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
                // Ketika fallback ke PatientID + StudyDate, jika ada no_permintaan (noorder)
                // HARUS validate bahwa AccessionNumber di Orthanc match dengan database
                // Jika tidak match, jangan tampilkan gambar
                foreach ($result as $studyId) {
                    $studyData = getStudyInstanceUIDWithValidation($studyId, $noorder);

                    if ($studyData) {
                        // Jika ada no_permintaan, HARUS match (isValid = true)
                        // Jika tidak ada no_permintaan, langsung accept (isValid = true default)
                        if (!empty($noorder) && !$studyData['isValid']) {
                            // AccessionNumber tidak match, skip study ini
                            error_log('Study ' . $studyId . ' skipped: AccessionNumber mismatch. Expected: ' . $noorder . ', Got: ' . $studyData['accessionNumber']);
                            continue;
                        }

                        // Valid atau tidak ada no_permintaan untuk validate
                        $studyInstanceUID = $studyData['studyInstanceUID'];
                        error_log('Study found with PatientID/StudyDate fallback. AccessionNumber: ' . $studyData['accessionNumber']);
                        break; // Break loop setelah menemukan valid study
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Log error but don't stop execution
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
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('cURL error: ' . $error);
            return null;
        }

        if ($httpCode !== 200) {
            error_log('Orthanc error: HTTP ' . $httpCode . ' - ' . $response);
            return null;
        }

        $result = json_decode($response, true);
        return is_array($result) ? $result : null;

    } catch (Exception $e) {
        error_log('Error in performOrthancSearch: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get StudyInstanceUID dari Orthanc study ID dengan validasi AccessionNumber
 * Jika ada $expectedAccessionNumber, akan check apakah match dengan AccessionNumber di Orthanc
 *
 * @param string $studyId Orthanc internal study ID
 * @param string $expectedAccessionNumber Expected AccessionNumber (no_permintaan dari database)
 * @return array|null Array dengan keys: 'studyInstanceUID', 'accessionNumber', 'isValid'
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
                error_log('StudyInstanceUID not found in Orthanc response for study: ' . $studyId);
                return null;
            }

            // Jika ada expectedAccessionNumber, validate
            $isValid = true;
            if (!empty($expectedAccessionNumber)) {
                // Compare dengan normalize (trim dan case-insensitive)
                $expected = strtolower(trim($expectedAccessionNumber));
                $actual = strtolower(trim($accessionNumberFromOrthanc));

                $isValid = ($expected === $actual);

                if (!$isValid) {
                    error_log('AccessionNumber mismatch! Expected: ' . $expected . ', Got: ' . $actual);
                }
            }

            return [
                'studyInstanceUID' => $studyInstanceUID,
                'accessionNumber' => $accessionNumberFromOrthanc,
                'isValid' => $isValid
            ];
        }

        error_log('HTTP ' . $httpCode . ' when fetching study ' . $studyId);
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
    <title>Viewer Radiologi - RIS RS Tk.III dr. Reksodiwiryo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- OHIF Viewer CSS -->
    <link rel="stylesheet" href="https://unpkg.com/@cornerstonejs/core@3.8.0/dist/umd/cornerstone-core.css">
    <style>
        body {
            background-color: #f5f5f5;
            padding-top: 48px;
        }

        .navbar-sticky {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            z-index: 1020;
            background: white !important;
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            padding: 5px 0 !important;
            margin: 0 !important;
        }

        .viewer-container {
            display: flex;
            height: calc(100vh - 120px);
            background: #1a1a1a;
        }

        .viewer-canvas {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: auto;
        }

        .viewer-canvas img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .viewer-info {
            width: 350px;
            background: white;
            border-left: 1px solid #ddd;
            overflow-y: auto;
            padding: 20px;
        }

        .info-section {
            margin-bottom: 25px;
        }

        .info-label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 14px;
            color: #333;
            margin-bottom: 12px;
        }

        .toolbar {
            height: 50px;
            background: #2c3e50;
            color: white;
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .toolbar button {
            background: #34495e;
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .toolbar button:hover {
            background: #2c3e50;
        }

        .no-image {
            text-align: center;
            color: #999;
            padding: 50px 20px;
        }

        .expertise-status {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
            border-left: 4px solid;
        }

        .expertise-status.completed {
            background: #f0fdf4;
            color: #166534;
            border-left-color: #059669;
        }

        .expertise-status.pending {
            background: #fffbeb;
            color: #78350f;
            border-left-color: #d97706;
        }

        .expertise-textarea {
            width: 100%;
            min-height: 150px;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px;
            resize: vertical;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .expertise-textarea:focus {
            outline: none;
            border-color: #4D7C0F;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1);
        }

        .expertise-input {
            font-size: 13px;
            border-color: #e5e7eb;
        }

        .expertise-input:focus {
            outline: none;
            border-color: #4D7C0F !important;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1) !important;
        }

        .alert-expertise {
            padding: 10px 12px;
            font-size: 12px;
            border-radius: 6px;
            animation: slideIn 0.3s ease;
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
    </style>
</head>
<body>
    <!-- Navbar Sticky -->
    <nav class="navbar navbar-expand-lg navbar-sticky px-0">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="../pages/dashboard.php" style="font-size: 16px; font-weight: 600; color: #4D7C0F !important; margin-bottom: 0;">
                <i class="fas fa-arrow-left"></i> <span class="d-none d-sm-inline">Kembali</span>
            </a>
            <span class="d-none d-md-inline" style="font-size: 15px; font-weight: 600; color: #1f2937; margin-left: 20px;">
                Viewer - <?php echo substr($exam['nm_pasien'], 0, 30); ?>
            </span>
        </div>
    </nav>

    <div class="toolbar">
        <button onclick="goBack()" title="Kembali ke Dashboard">
            <i class="fas fa-arrow-left"></i> Kembali
        </button>
        <span style="flex: 1;"></span>
        <small style="color: #ccc; font-size: 11px;">
            OHIF Viewer powered by Orthanc PACS
        </small>
    </div>

    <div class="viewer-container">
        <div class="viewer-canvas" id="viewerCanvas">
            <?php if (!$orthancConnected): ?>
            <div class="no-image">
                <i class="fas fa-image" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                <p>Koneksi ke Orthanc PACS tidak tersedia</p>
                <small>Pastikan Orthanc berjalan di <?php echo ORTHANC_URL; ?></small>
            </div>
            <?php elseif (empty($studyInstanceUID)): ?>
            <div class="no-image">
                <i class="fas fa-image" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                <p>Tidak ada gambar radiologi untuk pemeriksaan ini</p>
                <small>
                    Cari berdasarkan:<br>
                    RM: <?php echo $exam['no_rkm_medis']; ?><br>
                    <?php echo !empty($noorder) ? 'No. Permintaan: ' . $noorder . '<br>' : ''; ?>
                    Tanggal: <?php echo $exam['tgl_periksa']; ?><br>
                    <br>
                    Pastikan patient dan tanggal pemeriksaan atau no. permintaan ada di Orthanc
                </small>
            </div>
            <?php else: ?>
            <!-- OHIF Viewer embedded -->
            <iframe id="ohifViewer"
                    src="<?php echo buildOhifUrl($studyInstanceUID, $seriesInstanceUID); ?>"
                    style="width: 100%; height: 100%; border: none;"></iframe>
            <?php endif; ?>
        </div>

        <div class="viewer-info">
            <!-- Status Expertise -->
            <?php if (!empty($exam['hasil'])): ?>
            <div class="expertise-status completed">
                <i class="fas fa-check-circle"></i> Expertise Sudah Disimpan
            </div>
            <?php else: ?>
            <div class="expertise-status pending">
                <i class="fas fa-clock"></i> Menunggu Expertise
            </div>
            <?php endif; ?>

            <!-- Data Pasien -->
            <div class="info-section">
                <div class="info-label">Informasi Pasien</div>
                <div class="info-value">
                    <strong><?php echo $exam['nm_pasien']; ?></strong><br>
                    <small class="text-muted">RM: <?php echo $exam['no_rkm_medis']; ?></small>
                </div>
            </div>

            <!-- Data Pemeriksaan -->
            <div class="info-section">
                <div class="info-label">Data Pemeriksaan</div>
                <div class="info-value">
                    <strong>No. Rawat:</strong> <?php echo $exam['no_rawat']; ?><br>
                    <strong>Tanggal:</strong> <?php echo formatTanggalTampil($exam['tgl_periksa']); ?><br>
                    <strong>Jam:</strong> <?php echo $exam['jam']; ?><br>
                    <strong>Jenis Periksa:</strong> <?php echo $exam['nm_perawatan']; ?><br>
                    <strong>Biaya:</strong> <?php echo formatMataUang($exam['biaya']); ?>
                </div>
            </div>

            <!-- Dokter & Diagnosa -->
            <div class="info-section">
                <div class="info-label">Dokter & Diagnosa</div>
                <div class="info-value">
                    <strong>Dokter:</strong> <?php echo $exam['nm_dokter']; ?><br>
                    <strong>Diagnosa Klinis:</strong><br>
                    <small><?php echo $exam['diagnosa_klinis'] ?? 'Tidak ada'; ?></small>
                </div>
            </div>

            <!-- Expertise Input Form -->
            <div class="info-section">
                <div class="info-label">
                    <i class="fas fa-stethoscope"></i> Hasil Bacaan Radiologi
                </div>
                <form id="expertiseForm" method="POST">
                    <textarea id="hasilTextarea" name="hasil" class="expertise-textarea"
                              placeholder="Tulis hasil bacaan radiologi Anda di sini..."
                              required><?php echo isset($exam['hasil']) ? htmlspecialchars($exam['hasil']) : ''; ?></textarea>

                    <div class="char-count" style="font-size: 11px; color: #999; margin-top: 8px; margin-bottom: 12px;">
                        Karakter: <span id="charCount">0</span> / 1000
                    </div>

                    <div class="info-label" style="margin-top: 12px; margin-bottom: 8px;">
                        No. Foto / ID Gambar (Opsional)
                    </div>
                    <input type="text" id="noFoto" name="no_foto" class="form-control form-control-sm expertise-input"
                           value="<?php echo isset($exam['no_foto']) ? htmlspecialchars($exam['no_foto']) : ''; ?>"
                           placeholder="Nomor foto dari PACS">

                    <button type="submit" class="btn btn-primary btn-sm w-100" style="margin-top: 12px;">
                        <i class="fas fa-save"></i> Simpan Expertise
                    </button>
                </form>

                <!-- Message Alert -->
                <div id="expertiseMessage" class="mt-3" style="display: none;"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function goBack() {
            window.location.href = 'dashboard.php';
        }

        // Character counter for expertise textarea
        const hasilTextarea = document.getElementById('hasilTextarea');
        const charCount = document.getElementById('charCount');
        const maxChars = 1000;

        if (hasilTextarea) {
            hasilTextarea.addEventListener('input', function() {
                let count = this.value.length;
                charCount.textContent = count;

                if (count >= maxChars) {
                    this.value = this.value.substring(0, maxChars);
                    charCount.textContent = maxChars;
                }

                if (count > maxChars * 0.8) {
                    charCount.style.color = '#ff6b6b';
                } else {
                    charCount.style.color = '#999';
                }
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
                    showExpertiseMessage('Hasil expertise tidak boleh kosong', 'danger');
                    return false;
                }

                if (hasil.length < 10) {
                    if (!confirm('Expertise terlalu singkat (kurang dari 10 karakter). Lanjutkan?')) {
                        return false;
                    }
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
                    showExpertiseMessage('Expertise berhasil disimpan', 'success');
                    // Update expertise status
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showExpertiseMessage('Error: ' + (data.message || 'Gagal menyimpan expertise'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showExpertiseMessage('Error: Gagal mengirim data ke server', 'danger');
            });
        }

        function showExpertiseMessage(message, type) {
            const messageDiv = document.getElementById('expertiseMessage');
            messageDiv.className = 'alert alert-' + type + ' alert-expertise';
            messageDiv.textContent = message;
            messageDiv.style.display = 'block';

            // Auto-hide after 5 seconds (except for errors)
            if (type !== 'danger') {
                setTimeout(() => {
                    messageDiv.style.display = 'none';
                }, 5000);
            }
        }

        // Log OHIF viewer loading
        document.addEventListener('DOMContentLoaded', function() {
            console.log('OHIF Viewer loaded');
        });
    </script>
</body>
</html>
