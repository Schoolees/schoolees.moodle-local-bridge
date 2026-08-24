<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_schooleescore_bridge_dashboard');

$context = context_system::instance();
require_capability('local/schooleescore_bridge:manage', $context);

$queuecounts = \local_schooleescore_bridge\local\queue_service::get_queue_counts();

$table = new html_table();
$table->head = [get_string('status'), get_string('total')];
foreach ($queuecounts as $status => $count) {
    $table->data[] = [
        get_string_manager()->string_exists($status, 'local_schooleescore_bridge')
            ? get_string($status, 'local_schooleescore_bridge')
            : s($status),
        (int)$count,
    ];
}

$links = [
    html_writer::link(new moodle_url('/local/schooleescore_bridge/queue.php'),
        get_string('queue_monitor', 'local_schooleescore_bridge')),
    html_writer::link(new moodle_url('/local/schooleescore_bridge/logs.php'),
        get_string('sync_history', 'local_schooleescore_bridge')),
    html_writer::link(new moodle_url('/local/schooleescore_bridge/mappings.php'),
        get_string('mappings', 'local_schooleescore_bridge')),
    html_writer::link(new moodle_url('/local/schooleescore_bridge/connection.php'),
        get_string('connection_test', 'local_schooleescore_bridge')),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bridge_dashboard', 'local_schooleescore_bridge'));
echo html_writer::table($table);
echo html_writer::alist($links);
echo $OUTPUT->footer();
