# Radiology Information System (RIS)

Aplikasi web-based Radiology Information System (RIS) untuk mengelola data pemeriksaan radiologi, integrasi dengan Orthanc PACS, dan dokumentasi hasil expertise radiologi.

## Fitur Utama

- **Manajemen Data Pemeriksaan Radiologi**: Tampilkan dan kelola data pemeriksaan radiologi pasien
- **Integrasi Orthanc PACS**: Koneksi REST API ke Orthanc untuk mengambil gambar radiologi
- **Viewer Gambar Radiologi**: Tampilkan gambar DICOM dengan fitur zoom dan pan
- **Expertise/Interpretasi**: Dokter dapat mengisi hasil bacaan radiologi dan menyimpan ke database
- **Filter Data**: Filter berdasarkan tanggal, nomor rawat, nomor rekam medis, dan pencarian teks
- **Sistem Login Terenkripsi**: Login dengan enkripsi AES untuk keamanan data

## Persyaratan Sistem

- PHP 7.4 atau lebih tinggi
- MySQL 5.7 atau MariaDB 10.3 atau lebih tinggi
- Apache/Nginx dengan support untuk .htaccess (opsional)
- Koneksi internet untuk mengakses Orthanc PACS
- Orthanc PACS berjalan dan dapat diakses

## Instalasi

### 1. Clone atau Download Project

```bash
cd C:\laragon\www\
git clone https://github.com/username/reksorad.git
# atau extract file zip ke folder reksorad
```

### 2. Setup Database

#### Membuat Database
```sql
CREATE DATABASE sikbackup2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

#### Membuat Tabel-tabel yang Diperlukan

```sql
USE sikbackup2;

-- Tabel User
CREATE TABLE user (
    id_user VARCHAR(50) PRIMARY KEY,
    password VARCHAR(255),
    nama VARCHAR(100),
    level VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Pasien
CREATE TABLE pasien (
    no_rkm_medis VARCHAR(20) PRIMARY KEY,
    nm_pasien VARCHAR(100),
    alamat TEXT,
    no_telp VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Dokter
CREATE TABLE dokter (
    kd_dokter VARCHAR(20) PRIMARY KEY,
    nm_dokter VARCHAR(100),
    spesialis VARCHAR(50),
    no_hp VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Petugas
CREATE TABLE petugas (
    nip VARCHAR(20) PRIMARY KEY,
    nama VARCHAR(100),
    jabatan VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Registrasi Periksa
CREATE TABLE reg_periksa (
    no_rawat VARCHAR(30) PRIMARY KEY,
    no_rkm_medis VARCHAR(20),
    kd_poli VARCHAR(20),
    kd_pj VARCHAR(10),
    tgl_registrasi DATE,
    FOREIGN KEY (no_rkm_medis) REFERENCES pasien(no_rkm_medis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Penjab (Penjamin Jawab)
CREATE TABLE penjab (
    kd_pj VARCHAR(10) PRIMARY KEY,
    png_jawab VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Jenis Perawatan Radiologi
CREATE TABLE jns_perawatan_radiologi (
    kd_jenis_prw VARCHAR(20) PRIMARY KEY,
    nm_perawatan VARCHAR(100),
    biaya DECIMAL(10, 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Pemeriksaan Radiologi
CREATE TABLE periksa_radiologi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    no_rawat VARCHAR(30),
    nip VARCHAR(20),
    kd_dokter VARCHAR(20),
    kd_jenis_prw VARCHAR(20),
    tgl_periksa DATE,
    jam TIME,
    dokter_perujuk VARCHAR(100),
    proyeksi TEXT,
    kV VARCHAR(20),
    mAS VARCHAR(20),
    FFD VARCHAR(20),
    BSF VARCHAR(20),
    inak VARCHAR(20),
    jml_penyinaran VARCHAR(20),
    dosis VARCHAR(50),
    biaya DECIMAL(10, 2),
    FOREIGN KEY (no_rawat) REFERENCES reg_periksa(no_rawat),
    FOREIGN KEY (nip) REFERENCES petugas(nip),
    FOREIGN KEY (kd_dokter) REFERENCES dokter(kd_dokter),
    FOREIGN KEY (kd_jenis_prw) REFERENCES jns_perawatan_radiologi(kd_jenis_prw)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Hasil Radiologi
CREATE TABLE hasil_radiologi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    no_rawat VARCHAR(30),
    tgl_periksa DATE,
    jam TIME,
    no_foto VARCHAR(50),
    hasil LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (no_rawat, tgl_periksa, jam),
    FOREIGN KEY (no_rawat) REFERENCES reg_periksa(no_rawat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Permintaan Radiologi
CREATE TABLE permintaan_radiologi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    noorder VARCHAR(50),
    no_rawat VARCHAR(30),
    tgl_hasil DATE,
    jam_hasil TIME,
    diagnosa_klinis TEXT,
    FOREIGN KEY (no_rawat) REFERENCES reg_periksa(no_rawat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Kamar Inap
CREATE TABLE kamar_inap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    no_rawat VARCHAR(30),
    kd_kamar VARCHAR(20),
    kd_bangsal VARCHAR(20),
    tgl_masuk DATE,
    tgl_keluar DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Bangsal
CREATE TABLE bangsal (
    kd_bangsal VARCHAR(20) PRIMARY KEY,
    nm_bangsal VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Kamar
CREATE TABLE kamar (
    kd_kamar VARCHAR(20) PRIMARY KEY,
    kd_bangsal VARCHAR(20),
    FOREIGN KEY (kd_bangsal) REFERENCES bangsal(kd_bangsal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Poliklinik
CREATE TABLE poliklinik (
    kd_poli VARCHAR(20) PRIMARY KEY,
    nm_poli VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel BHP Radiologi
CREATE TABLE beri_bhp_radiologi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    no_rawat VARCHAR(30),
    tgl_periksa DATE,
    jam TIME,
    kode_brng VARCHAR(20),
    kode_sat VARCHAR(10),
    jumlah INT,
    total DECIMAL(10, 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Barang
CREATE TABLE ipsrsbarang (
    kode_brng VARCHAR(20) PRIMARY KEY,
    nama_brng VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Insert User Default (dengan enkripsi AES)

```sql
-- Insert user dengan username: admin, password: admin123
-- Ganti 'nur' dan 'windi' sesuai konfigurasi di Database.php
INSERT INTO user (id_user, password, nama, level) VALUES
(AES_ENCRYPT('admin', 'nur'), AES_ENCRYPT('admin123', 'windi'), 'Administrator', 'admin');
```

### 3. Konfigurasi Database

Edit file `config/Database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');  // Kosong jika tidak ada password
define('DB_NAME', 'sikbackup2');
define('DB_PORT', '3306');
```

### 4. Konfigurasi Orthanc PACS

Edit file `config/Database.php`:

```php
define('ORTHANC_URL', 'http://localhost:8042');  // URL Orthanc
define('ORTHANC_USERNAME', '');  // Username jika ada authentication
define('ORTHANC_PASSWORD', '');  // Password jika ada authentication
```

### 5. Permission File

Pastikan folder berikut memiliki write permission:
```bash
chmod 755 uploads/
chmod 755 assets/
```

## Penggunaan

### Login

1. Buka browser dan akses: `http://localhost/reksorad/login.php`
2. Masukkan username dan password default: `admin` / `admin123`
3. Klik tombol "Masuk"

### Dashboard

1. Setelah login, Anda akan dibawa ke halaman Dashboard
2. Gunakan filter untuk mencari data pemeriksaan:
   - **Dari Tanggal / Sampai Tanggal**: Filter berdasarkan range tanggal
   - **No. Rawat**: Filter berdasarkan nomor rawat pasien
   - **No. RKM Medis**: Filter berdasarkan nomor rekam medis
   - **Pencarian**: Cari berdasarkan nama pasien, petugas, atau penjab
3. Klik tombol "Cari" untuk menampilkan hasil

### Melihat Gambar Radiologi

1. Di halaman Dashboard, klik tombol **"Gambar"** pada baris data
2. Halaman viewer akan menampilkan gambar radiologi (jika tersedia dari Orthanc)
3. Gunakan toolbar untuk:
   - **Zoom In**: Memperbesar gambar
   - **Zoom Out**: Memperkecil gambar
   - **Reset**: Kembalikan ke ukuran normal
   - **Download**: Unduh gambar

### Mengisi Expertise/Interpretasi

1. Di halaman Dashboard, klik tombol **"Expertise"** pada baris data
2. Halaman expertise akan menampilkan form untuk mengisi hasil bacaan
3. Isi field **"Hasil Bacaan Radiologi"** dengan interpretasi Anda
4. (Opsional) Isi field **"No. Foto"** jika ada
5. Klik tombol **"Simpan Expertise"** untuk menyimpan
6. Data akan tersimpan di database tabel `hasil_radiologi`

## Struktur Database

### Tabel Utama yang Digunakan

| Tabel | Deskripsi |
|-------|-----------|
| `periksa_radiologi` | Data pemeriksaan radiologi |
| `reg_periksa` | Registrasi pemeriksaan pasien |
| `pasien` | Data pasien |
| `dokter` | Data dokter |
| `petugas` | Data petugas radiologi |
| `hasil_radiologi` | Hasil expertise radiologi |
| `permintaan_radiologi` | Permintaan pemeriksaan radiologi |

## Integrasi Orthanc PACS

### Fitur API Orthanc yang Didukung

- **getPatients()**: Mendapatkan daftar patient dari Orthanc
- **getStudies()**: Mendapatkan daftar studies untuk seorang patient
- **getSeries()**: Mendapatkan daftar series dalam study
- **getDicomImage()**: Mengambil gambar DICOM dalam format preview
- **searchStudies()**: Mencari studies berdasarkan kriteria
- **testConnection()**: Test koneksi ke Orthanc

### Cara Menggunakan API

```php
require_once 'api/orthanc.php';

$orthanc = new OrthancAPI();

// Test koneksi
if ($orthanc->testConnection()) {
    echo "Koneksi Orthanc berhasil";
}

// Dapatkan daftar patient
$patients = $orthanc->getPatients();

// Dapatkan studies untuk patient
$studies = $orthanc->getStudies($patientId);
```

## Fitur Keamanan

1. **Enkripsi Password**: Menggunakan AES-256 untuk enkripsi username dan password
2. **Session Management**: Sistem login dengan session untuk keamanan akses
3. **SQL Injection Prevention**: Menggunakan prepared statement di semua query
4. **Input Validation**: Sanitasi semua input dari user
5. **HTTPS Ready**: Aplikasi siap untuk diakses via HTTPS

## Troubleshooting

### Koneksi Database Gagal
- Pastikan MySQL/MariaDB sudah running
- Cek konfigurasi di `config/Database.php`
- Verifikasi username dan password database

### Koneksi Orthanc Gagal
- Pastikan Orthanc sudah running
- Cek URL Orthanc di `config/Database.php`
- Test dengan mengakses URL Orthanc langsung di browser

### Gambar Tidak Tampil
- Verifikasi data gambar ada di database
- Cek koneksi ke Orthanc PACS
- Lihat console browser untuk error message

### Error Login
- Verifikasi username dan password di database
- Pastikan tabel `user` sudah ada
- Cek enkripsi key di `config/Database.php`

## API Endpoints (Future Enhancement)

Aplikasi ini dapat diperluas dengan REST API endpoints untuk integrasi dengan aplikasi lain:

```
GET  /api/examinations - Dapatkan daftar pemeriksaan
GET  /api/examinations/{id} - Dapatkan detail pemeriksaan
POST /api/expertise - Simpan expertise
GET  /api/expertise/{id} - Dapatkan expertise
```

## Performance Tips

1. **Cache**: Implementasikan caching untuk data yang jarang berubah
2. **Database Index**: Pastikan field yang sering dicari memiliki index
3. **Image Compression**: Compress gambar DICOM untuk mempercepat load time
4. **Pagination**: Implementasikan pagination untuk data besar

## Pengembangan Lebih Lanjut

Fitur yang dapat ditambahkan:

1. **Export Report**: Export data ke PDF/Excel
2. **Mobile App**: Aplikasi mobile untuk akses mobile
3. **Real-time Notification**: Notifikasi untuk expertise yang ditugaskan
4. **User Management**: Manajemen user dan role berbeda
5. **Audit Log**: Log semua aktivitas user
6. **Advanced Search**: Pencarian DICOM tags lebih advanced
7. **Backup Automatis**: Backup database otomatis
8. **Multi-language**: Support berbagai bahasa

## License

MIT License - Anda bebas menggunakan dan memodifikasi kode ini

## Support

Untuk pertanyaan atau masalah, silakan hubungi tim IT Anda atau buat issue di repository

## Changelog

### v1.0.0 (2026-01-21)
- Initial release
- Login system dengan enkripsi AES
- Dashboard dengan filter data
- Viewer gambar radiologi
- Expertise/interpretasi radiologi
- Integrasi Orthanc PACS API

---

**Dibuat dengan ❤️ untuk Sistem Informasi Radiologi**
