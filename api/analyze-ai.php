<?php
/**
 * API Endpoint for AI Image Analysis
 * Supports: Groq (free) and Gemini
 */

session_start();
require_once '../config/Database.php';
require_once '../config/Gemini.php';
require_once 'orthanc.php';

header('Content-Type: application/json');

// Check login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$studyInstanceUID = isset($_GET['studyInstanceUID']) ? $_GET['studyInstanceUID'] : '';

if (empty($studyInstanceUID)) {
    echo json_encode(['success' => false, 'message' => 'StudyInstanceUID required']);
    exit();
}

// Validate provider config
$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'groq';
if ($provider === 'groq' && empty(GROQ_API_KEY)) {
    echo json_encode(['success' => false, 'message' => 'Groq API Key belum dikonfigurasi. Daftar gratis di https://console.groq.com lalu isi GROQ_API_KEY di config/Gemini.php']);
    exit();
}
if ($provider === 'gemini' && empty(GEMINI_API_KEY)) {
    echo json_encode(['success' => false, 'message' => 'Gemini API Key belum dikonfigurasi di config/Gemini.php']);
    exit();
}

try {
    $orthanc = new OrthancAPI();

    // 1. Find Study by StudyInstanceUID in Orthanc
    $searchUrl = ORTHANC_URL . '/tools/find';
    $query = ['Level' => 'Study', 'Query' => ['StudyInstanceUID' => $studyInstanceUID]];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($query),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => ORTHANC_USERNAME . ':' . ORTHANC_PASSWORD,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'message' => 'Koneksi Orthanc gagal: ' . $curlError]);
        exit();
    }

    $studyIds = json_decode($response, true);
    if (empty($studyIds) || !is_array($studyIds)) {
        echo json_encode(['success' => false, 'message' => 'Study tidak ditemukan di Orthanc.']);
        exit();
    }

    // 2. Get first instance from first series
    $studyId        = $studyIds[0];
    $studyDetails   = $orthanc->getStudyDetails($studyId);
    if (!$studyDetails['success'] || empty($studyDetails['data']['series'])) {
        echo json_encode(['success' => false, 'message' => 'Series tidak ditemukan di study.']);
        exit();
    }

    $seriesId      = $studyDetails['data']['series'][0];
    $seriesDetails = $orthanc->getSeriesDetails($seriesId);
    if (!$seriesDetails['success'] || empty($seriesDetails['data']['instances'])) {
        echo json_encode(['success' => false, 'message' => 'Instance tidak ditemukan di series.']);
        exit();
    }

    $instanceId = $seriesDetails['data']['instances'][0];

    // 3. Get JPEG preview from Orthanc
    $imageData = $orthanc->getDicomImage($instanceId);
    if (!$imageData || strlen($imageData) < 100) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil preview gambar dari Orthanc.']);
        exit();
    }

    $base64Image = base64_encode($imageData);

    // 4. Call AI Provider
    if ($provider === 'groq') {
        $result = callGroqAPI($base64Image);
    } else {
        $result = callGeminiAPI($base64Image);
    }

    echo json_encode($result);

} catch (Exception $e) {
    error_log('analyze-ai.php Exception: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System Error: ' . $e->getMessage()]);
}

// ============================================================
//  Groq API Call (OpenAI-compatible format with vision)
// ============================================================
function callGroqAPI($base64Image) {
    $payload = [
        'model'    => GROQ_MODEL,
        'messages' => [
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => AI_MEDICAL_PROMPT
                    ],
                    [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url' => 'data:image/jpeg;base64,' . $base64Image
                        ]
                    ]
                ]
            ]
        ],
        'max_tokens'  => 2048,
        'temperature' => 0.4,
    ];

    $payloadJson = json_encode($payload);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.groq.com/openai/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payloadJson,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => 'Koneksi ke Groq gagal: ' . $curlError];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        return ['success' => false, 'message' => 'Groq API Error (' . $httpCode . '): ' . $errMsg];
    }

    $text = $data['choices'][0]['message']['content'] ?? null;
    if (!$text) {
        return ['success' => false, 'message' => 'Tidak ada respon dari Groq API.'];
    }

    return ['success' => true, 'analysis' => $text, 'provider' => 'Groq (Llama 4 Vision)'];
}

// ============================================================
//  Gemini API Call
// ============================================================
function callGeminiAPI($base64Image) {
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent?key=" . GEMINI_API_KEY;

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => AI_MEDICAL_PROMPT],
                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $base64Image]]
                ]
            ]
        ],
        'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 2048],
    ];

    $payloadJson = json_encode($payload);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $geminiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payloadJson,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($payloadJson)],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => 'Koneksi ke Gemini gagal: ' . $curlError];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        return ['success' => false, 'message' => 'Gemini API Error (' . $httpCode . '): ' . $errMsg];
    }

    if (isset($data['promptFeedback']['blockReason'])) {
        return ['success' => false, 'message' => 'Diblokir safety filter: ' . $data['promptFeedback']['blockReason']];
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        return ['success' => false, 'message' => 'Tidak ada respon dari Gemini API.'];
    }

    return ['success' => true, 'analysis' => $text, 'provider' => 'Google Gemini'];
}
