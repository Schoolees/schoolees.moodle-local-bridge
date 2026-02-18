<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
require_capability('local/schooleescore_bridge:viewlogs', $context);

$replayid = optional_param('replayid', 0, PARAM_INT);
if ($replayid) {
    require_sesskey();
    require_capability('local/schooleescore_bridge:replayqueue', $context);
    \local_schooleescore_bridge\local\queue_service::replay($replayid);
    redirect(new moodle_url('/local/schooleescore_bridge/queue.php'), get_string('queue_replayed', 'local_schooleescore_bridge'));
}

$status = optional_param('status', '', PARAM_ALPHA);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/schooleescore_bridge/queue.php', ['status' => $status]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('queue_monitor', 'local_schooleescore_bridge'));
$PAGE->set_heading(get_string('queue_monitor', 'local_schooleescore_bridge'));

$records = \local_schooleescore_bridge\local\queue_service::list_records($status);

$table = new html_table();
$table->head = [
    'ID',
    get_string('status'),
    get_string('attempts', 'local_schooleescore_bridge'),
    get_string('next_attempt', 'local_schooleescore_bridge'),
    get_string('error'),
    get_string('actions'),
];

foreach ($records as $record) {
    $action = '-';
    if ($record->status === 'dead') {
        $action = html_writer::link(
            new moodle_url('/local/schooleescore_bridge/queue.php', ['replayid' => $record->id, 'sesskey' => sesskey()]),
            get_string('replay', 'local_schooleescore_bridge')
        );
    }

    $table->data[] = [
        $record->id,
        s($record->status),
        (int)$record->attempt_count,
        $record->next_attempt_at ? userdate($record->next_attempt_at) : '-',
        shorten_text((string)$record->last_error, 120),
        $action,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('queue_monitor', 'local_schooleescore_bridge'));
echo html_writer::table($table);
echo $OUTPUT->footer();
