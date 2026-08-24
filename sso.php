<?php
require_once(__DIR__ . '/../../config.php');

// Signed hand-off from SchooleesCore. Off unless an admin turns it on: it logs a
// user in on the strength of a shared secret alone, so it must not be reachable
// on a site that never asked for it.
if (!get_config('local_schooleescore_bridge', 'enable_sso')) {
    throw new moodle_exception('sso_disabled', 'local_schooleescore_bridge');
}

$secret = (string)get_config('local_schooleescore_bridge', 'webhook_secret');
if ($secret === '') {
    $secret = (string)get_config('local_schooleescore_bridge', 'client_secret');
}
if ($secret === '') {
    // An empty key makes the expected digest public knowledge - anyone could
    // sign a request for any mapped user id.
    throw new moodle_exception('sso_no_secret', 'local_schooleescore_bridge');
}

$useridexternal = required_param('ses_user_id', PARAM_TEXT);
$timestamp = required_param('ts', PARAM_INT);
$signature = required_param('sig', PARAM_ALPHANUMEXT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

if (abs(time() - $timestamp) > 300) {
    throw new moodle_exception('invalidrequest', 'error');
}

$expected = hash_hmac('sha256', $useridexternal . ':' . $timestamp, $secret);
if (!hash_equals($expected, $signature)) {
    throw new moodle_exception('invalidlogin');
}

$map = $DB->get_record('local_ses_user_map', ['schooleescore_user_id' => $useridexternal]);
if (!$map) {
    $map = $DB->get_record('local_ses_user_map', ['schooleescore_student_no' => $useridexternal]);
}
if (!$map) {
    throw new moodle_exception('invalidlogin');
}

$user = $DB->get_record('user', [
    'id' => $map->moodle_userid,
    'deleted' => 0,
    'suspended' => 0,
    'confirmed' => 1,
]);
if (!$user || isguestuser($user)) {
    throw new moodle_exception('invalidlogin');
}

complete_user_login($user);
\core\session\manager::apply_concurrent_login_limit($user->id, session_id());

$destination = $returnurl ? new moodle_url($returnurl) : new moodle_url('/my/');
redirect($destination);
