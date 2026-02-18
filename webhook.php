<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_SCHOOLEESCORE_SIGNATURE'] ?? '';
$timestamp = (int)($_SERVER['HTTP_X_SCHOOLEESCORE_TIMESTAMP'] ?? 0);
$secret = (string)get_config('local_schooleescore_bridge', 'webhook_secret');

if (!$timestamp || abs(time() - $timestamp) > 300) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'stale timestamp']);
    exit;
}

$expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'invalid signature']);
    exit;
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid json']);
    exit;
}

if (($data['type'] ?? '') === 'grade.replay') {
    $queueid = (int)($data['queue_id'] ?? 0);
    if ($queueid > 0) {
        \local_schooleescore_bridge\local\queue_service::replay($queueid);
    }
}

http_response_code(202);
echo json_encode(['status' => 'accepted']);
