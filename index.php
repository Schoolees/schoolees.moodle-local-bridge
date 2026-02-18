<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
require_capability('local/schooleescore_bridge:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/schooleescore_bridge/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_schooleescore_bridge'));
$PAGE->set_heading(get_string('pluginname', 'local_schooleescore_bridge'));

$queuecounts = \local_schooleescore_bridge\local\queue_service::get_queue_counts();

$rows = [
    get_string('pending', 'local_schooleescore_bridge') . ': ' . $queuecounts['pending'],
    get_string('failed', 'local_schooleescore_bridge') . ': ' . $queuecounts['failed'],
    get_string('dead', 'local_schooleescore_bridge') . ': ' . $queuecounts['dead'],
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bridge_dashboard', 'local_schooleescore_bridge'));
echo html_writer::alist($rows);
echo $OUTPUT->footer();
