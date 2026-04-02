<?php
/**
 * Konfigurasi AI Provider untuk Analisa Radiologi
 *
 * Pilih salah satu provider di bawah.
 * Default: Groq (GRATIS, tidak perlu kartu kredit)
 *
 * Cara mendapatkan API Key Groq:
 * 1. Daftar di: https://console.groq.com
 * 2. Masuk ke menu "API Keys"
 * 3. Klik "Create API key"
 * 4. Salin key dan paste di bawah
 */

// ============================================================
//  GROQ (GRATIS - Rekomendasi Utama)
// ============================================================
define('AI_PROVIDER', 'groq'); // 'groq' atau 'gemini'
define('GROQ_API_KEY', '');    // <-- Paste API Key Groq di sini
define('GROQ_MODEL', 'meta-llama/llama-4-scout-17b-16e-instruct'); // Model vision gratis

// ============================================================
//  GEMINI (Opsional, butuh billing Google Cloud)
// ============================================================
define('GEMINI_API_KEY', '');
define('GEMINI_MODEL', 'gemini-2.0-flash-lite');

// ============================================================
//  PROMPT ANALISA RADIOLOGI (dipakai oleh semua provider)
// ============================================================
define('AI_MEDICAL_PROMPT', 'Anda adalah asisten radiologi profesional. Analisa gambar radiologi yang dilampirkan ini secara menyeluruh. Berikan:
1. TEMUAN: Deskripsi objektif dari gambar (posisi, densitas, bentuk, ukuran, dll)
2. KESAN (IMPRESSION): Penilaian klinis berdasarkan temuan
3. SARAN: Tindak lanjut yang direkomendasikan jika ada

Gunakan bahasa Indonesia yang formal dan profesional.

DISCLAIMER: Hasil analisa ini merupakan bantuan AI dan WAJIB ditelaah serta diverifikasi oleh dokter spesialis radiologi sebelum digunakan sebagai dasar diagnosis klinis.');
