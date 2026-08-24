<?php
// Webhook receiver: no session, no cookies, no theme.
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

/**
 * Emit a JSON response and stop.
 *
 * @param int $status
 * @param array $body
 */
function local_schooleescore_bridge_respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$secret = (string)get_config('local_schooleescore_bridge', 'webhook_secret');
if ($secret === '') {
    // With no secret every HMAC reduces to a keyed-with-empty-string digest that
    // anyone can compute, so an unconfigured endpoint must refuse outright
    // rather than "validate" a signature nobody had to know a secret to produce.
    local_schooleescore_bridge_respond(503, ['status' => 'error', 'message' => 'webhook secret not configured']);
}

$payload = file_get_contents('php://input') ?: '';
$signature = (string)($_SERVER['HTTP_X_SCHOOLEESCORE_SIGNATURE'] ?? '');
$timestamp = (int)($_SERVER['HTTP_X_SCHOOLEESCORE_TIMESTAMP'] ?? 0);

if ($signature === '') {
    local_schooleescore_bridge_respond(401, ['status' => 'error', 'message' => 'missing signature']);
}

if (!$timestamp || abs(time() - $timestamp) > 300) {
    local_schooleescore_bridge_respond(400, ['status' => 'error', 'message' => 'stale timestamp']);
}

$expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
if (!hash_equals($expected, $signature)) {
    local_schooleescore_bridge_respond(401, ['status' => 'error', 'message' => 'invalid signature']);
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    local_schooleescore_bridge_respond(400, ['status' => 'error', 'message' => 'invalid json']);
}

if (($data['type'] ?? '') === 'grade.replay') {
    $queueid = (int)($data['queue_id'] ?? 0);
    if ($queueid > 0) {
        try {
            \local_schooleescore_bridge\local\queue_service::replay($queueid);
        } catch (\Throwable $exception) {
            // An unknown id used to escape as an uncaught dml exception, which
            // renders Moodle's HTML error page into a JSON webhook response.
            local_schooleescore_bridge_respond(404, ['status' => 'error', 'message' => 'unknown queue id']);
        }
    }
}

local_schooleescore_bridge_respond(202, ['status' => 'accepted']);
