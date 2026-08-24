<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
require_capability('local/schooleescore_bridge:manage', $context);

$test = optional_param('test', 0, PARAM_BOOL);
$result = null;

if ($test) {
    require_sesskey();
    $client = new \local_schooleescore_bridge\local\api_client();
    $ok = static function(array $response): bool {
        $status = (int)($response['status'] ?? 0);
        return $status >= 200 && $status < 300;
    };

    $statusresponse = $client->get_json(\local_schooleescore_bridge\local\api_client::PATH_STATUS, [], false);
    $authresponse = $client->get_json(
        \local_schooleescore_bridge\local\api_client::PATH_STUDENTS, ['limit' => 1, 'offset' => 0], true);
    $enrollresponse = $client->get_json(
        \local_schooleescore_bridge\local\api_client::PATH_ENROLLMENTS, ['limit' => 1, 'offset' => 0], true);
    $gradesresponse = $client->get_json(
        \local_schooleescore_bridge\local\api_client::PATH_GRADES, ['limit' => 1, 'offset' => 0], true);

    $result = [
        'status_http' => (int)($statusresponse['status'] ?? 0),
        'status_ok' => $ok($statusresponse),
        'auth_http' => (int)($authresponse['status'] ?? 0),
        'auth_ok' => $ok($authresponse),
        'enroll_http' => (int)($enrollresponse['status'] ?? 0),
        'enroll_ok' => $ok($enrollresponse),
        'grades_http' => (int)($gradesresponse['status'] ?? 0),
        'grades_ok' => $ok($gradesresponse),
    ];
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/schooleescore_bridge/connection.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('connection_test', 'local_schooleescore_bridge'));
$PAGE->set_heading(get_string('connection_test', 'local_schooleescore_bridge'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connection_test', 'local_schooleescore_bridge'));
echo html_writer::tag('p', get_string('connection_test_desc', 'local_schooleescore_bridge'));

echo $OUTPUT->single_button(
    new moodle_url('/local/schooleescore_bridge/connection.php', ['test' => 1, 'sesskey' => sesskey()]),
    get_string('run_connection_test', 'local_schooleescore_bridge')
);

if ($result !== null) {
    $statusmessage = get_string('connection_result_status', 'local_schooleescore_bridge', $result['status_http']);
    $statustype = $result['status_ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR;
    echo $OUTPUT->notification($statusmessage, $statustype);

    $authmessage = get_string('connection_result_auth', 'local_schooleescore_bridge', $result['auth_http']);
    $authtype = $result['auth_ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR;
    echo $OUTPUT->notification($authmessage, $authtype);

    $enrollmessage = get_string('connection_result_enroll', 'local_schooleescore_bridge', $result['enroll_http']);
    $enrolltype = $result['enroll_ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR;
    echo $OUTPUT->notification($enrollmessage, $enrolltype);

    $grademessage = get_string('connection_result_grades', 'local_schooleescore_bridge', $result['grades_http']);
    $gradetype = $result['grades_ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR;
    echo $OUTPUT->notification($grademessage, $gradetype);

    if (!$result['auth_ok']) {
        echo $OUTPUT->notification(get_string('connection_result_auth_help', 'local_schooleescore_bridge'),
            \core\output\notification::NOTIFY_WARNING);
    }
    if (!$result['enroll_ok']) {
        echo $OUTPUT->notification(get_string('connection_result_enroll_help', 'local_schooleescore_bridge'),
            \core\output\notification::NOTIFY_WARNING);
    }
    if (!$result['grades_ok']) {
        echo $OUTPUT->notification(get_string('connection_result_grades_help', 'local_schooleescore_bridge'),
            \core\output\notification::NOTIFY_WARNING);
    }
}

echo $OUTPUT->footer();
