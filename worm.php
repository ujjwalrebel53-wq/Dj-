<?php
/**
 * worm.php — Simple WormGPT API
 * Upload to ~/www/worm.php on AlwaysData
 * Use: https://rebelai.alwaysdata.net/worm.php?q=your question
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$api = 'https://wormgpt.freeapihub.workers.dev/chat';

// GET ya POST se question lo
$q = $_GET['q'] ?? $_POST['q'] ?? '';
if ($q === '') {
    $input = json_decode(file_get_contents('php://input'), true);
    $q = is_array($input) ? ($input['q'] ?? $input['message'] ?? '') : '';
}

if (trim($q) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'q parameter required', 'usage' => '?q=your question']);
    exit;
}

$url = $api . '?q=' . urlencode($q);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Request failed', 'detail' => $error]);
    exit;
}

$data = json_decode($response, true);
if ($code >= 400 || !is_array($data)) {
    http_response_code($code ?: 500);
    echo json_encode(['error' => 'API error', 'raw' => $response]);
    exit;
}

echo json_encode([
    'status' => $data['status'] ?? 'success',
    'question' => $q,
    'reply' => $data['reply'] ?? '',
], JSON_UNESCAPED_UNICODE);
