<?php
/**
 * Orthanc Proxy - Forward requests ke Orthanc dengan authentication
 * Proxy ini handle semua request OHIF Viewer dan forward ke Orthanc
 * dengan credentials dari config
 */

require_once '../config/Database.php';

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Get request path and query
$requestPath = isset($_GET['p']) ? $_GET['p'] : '';

if (empty($requestPath)) {
    http_response_code(400);
    exit('Missing path parameter');
}

// Build Orthanc URL
$orthancUrl = ORTHANC_URL . '/' . ltrim($requestPath, '/');

// Forward query parameters (except 'p')
$queryParams = $_GET;
unset($queryParams['p']);

if (!empty($queryParams)) {
    $orthancUrl .= '?' . http_build_query($queryParams);
}

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $orthancUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// Set HTTP Basic Auth from config
curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USERNAME . ':' . ORTHANC_PASSWORD);

// Forward request headers
$headerList = [];
$forwardHeaders = ['Accept', 'Accept-Language', 'Content-Type', 'User-Agent'];

foreach ($forwardHeaders as $header) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    if (isset($_SERVER[$key])) {
        $headerList[] = $header . ': ' . $_SERVER[$key];
    }
}

if (!empty($headerList)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerList);
}

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($method === 'POST' || $method === 'PUT') {
        $body = file_get_contents('php://input');
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
}

// Execute request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlError = curl_error($ch);
curl_close($ch);

// Set HTTP response code
http_response_code($httpCode);

// Forward Content-Type header
if ($contentType) {
    header('Content-Type: ' . $contentType);
}

// Add CORS headers for mobile compatibility
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Access-Control-Max-Age: 86400');

// Add cache headers for static content
if (strpos($contentType, 'application/json') === false) {
    header('Cache-Control: public, max-age=3600');
} else {
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

// Handle errors
if ($response === false) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => 'Proxy error: ' . ($curlError ?: 'Unknown error'),
        'url' => $orthancUrl
    ]);
    exit;
}

// Output response
echo $response;
?>

