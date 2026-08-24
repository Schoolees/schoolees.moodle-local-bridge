<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$context = context_system::instance();
require_login();
require_capability('local/schooleescore_bridge:viewlogs', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/schooleescore_bridge/logs.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('sync_history', 'local_schooleescore_bridge'));
$PAGE->set_heading(get_string('sync_history', 'local_schooleescore_bridge'));

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$total = $DB->count_records('local_ses_sync_log');
$logs = $DB->get_records('local_ses_sync_log', null, 'id DESC', '*', $page * $perpage, $perpage);

$table = new html_table();
$table->head = [
    get_string('time'),
    get_string('job', 'local_schooleescore_bridge'),
    get_string('entity', 'local_schooleescore_bridge'),
    get_string('status'),
    get_string('trace_id', 'local_schooleescore_bridge'),
    get_string('http_status', 'local_schooleescore_bridge'),
    get_string('error'),
];

foreach ($logs as $log) {
    $table->data[] = [
        userdate($log->createdat),
        s($log->job_name),
        s($log->entity_type),
        s($log->result),
        s($log->trace_id),
        s((string)$log->http_status),
        // html_writer::table does not escape cells, and this text comes back
        // from the remote API - it must not be trusted as markup.
        s(shorten_text((string)$log->error_message, 120)),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('sync_history', 'local_schooleescore_bridge'));
echo html_writer::table($table);
echo $OUTPUT->paging_bar(
    $total,
    $page,
    $perpage,
    new moodle_url('/local/schooleescore_bridge/logs.php')
);
echo $OUTPUT->footer();
