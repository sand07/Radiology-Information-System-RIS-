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

// Get parameters
$no_rawat = isset($_GET['no_rawat']) ? sanitize($_GET['no_rawat']) : '';
$tgl = isset($_GET['tgl']) ? sanitize($_GET['tgl']) : '';
$jam = isset($_GET['jam']) ? sanitize($_GET['jam']) : '';

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
            pr.kd_dokter,
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

$message = '';
$messageType = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hasil = isset($_POST['hasil']) ? sanitize($_POST['hasil']) : '';
    $no_foto = isset($_POST['no_foto']) ? sanitize($_POST['no_foto']) : $exam['no_foto'] ?? '';

    try {
        if (empty($hasil)) {
            // Delete hasil jika kosong (sesuai logic Java)
            $db->delete('hasil_radiologi', [
                'no_rawat' => $no_rawat,
                'tgl_periksa' => $tgl,
                'jam' => $jam
            ]);

            $message = 'Hasil radiologi telah dihapus';
            $messageType = 'warning';

            // Redirect after delete
            redirect('../pages/dashboard.php');
        } else {
            // Check if hasil already exists
            $existing = $db->fetch(
                "SELECT id FROM hasil_radiologi WHERE no_rawat = ? AND tgl_periksa = ? AND jam = ?",
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
                $message = 'Expertise berhasil disimpan';
                $messageType = 'success';

                // Refresh data
                $exam = $db->fetch(
                    "SELECT
                        pr.no_rawat,
                        rp.no_rkm_medis,
                        p.nm_pasien,
                        pr.tgl_periksa,
                        pr.jam,
                        d.nm_dokter,
                        pr.kd_dokter,
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
            } else {
                $message = 'Error: ' . $db->getLastError();
                $messageType = 'danger';
            }
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expertise Radiologi - RIS RS Tk.III dr. Reksodiwiryo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            background-color: #f8f9fa;
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

        .page-header {
            background: white;
            color: #1f2937;
            padding: 30px 20px;
            margin-bottom: 30px;
            border-bottom: 1px solid #e5e7eb;
        }

        .form-section {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 15px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
            letter-spacing: 0.3px;
        }

        .info-group {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .info-row strong {
            color: #495057;
            min-width: 150px;
        }

        .info-row span {
            color: #212529;
            font-weight: 500;
        }

        .textarea-group {
            margin-top: 20px;
        }

        .textarea-group label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 10px;
        }

        .textarea-group textarea {
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            resize: vertical;
            min-height: 300px;
        }

        .textarea-group textarea:focus {
            border-color: #4D7C0F;
            box-shadow: 0 0 0 3px rgba(77, 124, 15, 0.1);
            outline: none;
        }

        .button-group {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn {
            padding: 10px 20px;
            font-weight: 500;
        }

        .char-count {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }

        .existing-result {
            background: #f0fdf4;
            border-left: 4px solid #059669;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .existing-result .label {
            font-weight: 600;
            color: #166534;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .existing-result .content {
            background: white;
            padding: 10px;
            border-radius: 6px;
            color: #1f2937;
            font-size: 14px;
            line-height: 1.6;
            border: 1px solid #dcfce7;
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

        .btn-warning {
            background-color: #4D7C0F !important;
            border-color: #4D7C0F !important;
            color: white !important;
        }

        .btn-warning:hover {
            background-color: #3D5C0D !important;
            border-color: #3D5C0D !important;
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
    <!-- Navbar Sticky -->
    <nav class="navbar navbar-expand-lg navbar-sticky px-0">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="../pages/dashboard.php" style="font-size: 16px; font-weight: 600; color: #4D7C0F !important; margin-bottom: 0;">
                <i class="fas fa-arrow-left"></i> <span class="d-none d-sm-inline">Kembali</span>
            </a>
            <span class="d-none d-md-inline" style="font-size: 15px; font-weight: 600; color: #1f2937; margin-left: 20px;">
                Expertise Radiologi
            </span>
        </div>
    </nav>

    <div class="page-header">
        <div class="container">
            <h2 class="mb-0">
                <i class="fas fa-stethoscope"></i> Form Expertise / Interpretasi Radiologi
            </h2>
        </div>
    </div>

    <div class="container">
        <!-- Message -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <!-- Data Pasien -->
            <div class="section-title">
                <i class="fas fa-user"></i> Data Pasien
            </div>
            <div class="info-group">
                <div class="info-row">
                    <strong>Nama Pasien:</strong>
                    <span><?php echo $exam['nm_pasien']; ?></span>
                </div>
                <div class="info-row">
                    <strong>No. Rekam Medis:</strong>
                    <span><?php echo $exam['no_rkm_medis']; ?></span>
                </div>
                <div class="info-row">
                    <strong>No. Rawat:</strong>
                    <span><?php echo $exam['no_rawat']; ?></span>
                </div>
            </div>

            <!-- Data Pemeriksaan -->
            <div class="section-title">
                <i class="fas fa-stethoscope"></i> Data Pemeriksaan Radiologi
            </div>
            <div class="info-group">
                <div class="info-row">
                    <strong>Jenis Periksa:</strong>
                    <span><?php echo $exam['nm_perawatan']; ?></span>
                </div>
                <div class="info-row">
                    <strong>Tanggal Periksa:</strong>
                    <span><?php echo formatTanggalTampil($exam['tgl_periksa']); ?></span>
                </div>
                <div class="info-row">
                    <strong>Jam Periksa:</strong>
                    <span><?php echo $exam['jam']; ?></span>
                </div>
                <div class="info-row">
                    <strong>Dokter Pengirim:</strong>
                    <span><?php echo $exam['nm_dokter']; ?></span>
                </div>
                <div class="info-row">
                    <strong>Diagnosa Klinis:</strong>
                    <span><?php echo $exam['diagnosa_klinis'] ?? 'Tidak ada'; ?></span>
                </div>
            </div>

            <!-- Existing Result -->
            <?php if (!empty($exam['hasil'])): ?>
            <div class="existing-result">
                <div class="label">
                    <i class="fas fa-check-circle"></i> Hasil Expertise Sebelumnya
                </div>
                <div class="content">
                    <?php echo nl2br($exam['hasil']); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Form Input -->
            <form method="POST" action="">
                <div class="textarea-group">
                    <label for="hasil">
                        <i class="fas fa-edit"></i> Hasil Bacaan Radiologi (Expertise)
                    </label>
                    <textarea id="hasil" name="hasil" placeholder="Tulis hasil bacaan radiologi Anda di sini..." required><?php echo $exam['hasil'] ?? ''; ?></textarea>
                    <div class="char-count">
                        Karakter: <span id="charCount">0</span> / 5000
                    </div>
                </div>

                <div class="mb-3">
                    <label for="no_foto" class="form-label">No. Foto / ID Gambar (Opsional)</label>
                    <input type="text" class="form-control" id="no_foto" name="no_foto"
                           value="<?php echo $exam['no_foto'] ?? ''; ?>"
                           placeholder="Masukkan nomor foto atau ID gambar dari PACS">
                </div>

                <div class="button-group">
                    <a href="../pages/dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                    <?php if (!empty($exam['hasil'])): ?>
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Apakah Anda yakin ingin mengubah expertise ini?')">
                        <i class="fas fa-save"></i> Update Expertise
                    </button>
                    <?php else: ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Expertise
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const hasilTextarea = document.getElementById('hasil');
        const charCount = document.getElementById('charCount');
        const maxChars = 5000;

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
                charCount.style.color = '#6c757d';
            }
        });

        // Initialize char count on page load
        window.addEventListener('load', function() {
            hasilTextarea.dispatchEvent(new Event('input'));
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const hasil = hasilTextarea.value.trim();
            if (hasil.length === 0) {
                e.preventDefault();
                alert('Hasil expertise tidak boleh kosong');
                return false;
            }
            if (hasil.length < 10) {
                if (!confirm('Expertise terlalu singkat. Lanjutkan?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    </script>
</body>
</html>
