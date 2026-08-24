<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
require_capability('local/schooleescore_bridge:manage', $context);

$pageurl = new moodle_url('/local/schooleescore_bridge/mappings.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$subjectid = optional_param('subjectid', '', PARAM_TEXT);
$sectionid = optional_param('sectionid', '', PARAM_TEXT);
$offeringid = optional_param('offeringid', '', PARAM_TEXT);
$aycode = optional_param('academic_year_code', '', PARAM_TEXT);
$semcode = optional_param('semester_code', '', PARAM_TEXT);
// A cleared checkbox is simply absent from the POST, so defaulting to 1 made
// "Enabled" impossible to turn off. The paired hidden field carries the 0.
$syncenabled = optional_param('sync_enabled', 0, PARAM_BOOL);
$save = optional_param('save', 0, PARAM_BOOL);
$deleteid = optional_param('deleteid', 0, PARAM_INT);
$discover = optional_param('discover', 0, PARAM_BOOL);

if ($save && $courseid && $subjectid && $aycode) {
    require_sesskey();
    \local_schooleescore_bridge\local\mapping_service::upsert_course_mapping(
        $courseid,
        $subjectid,
        $sectionid,
        $aycode,
        $semcode,
        $syncenabled,
        $offeringid
    );
    redirect($pageurl, get_string('mapping_saved', 'local_schooleescore_bridge'));
}

if ($deleteid) {
    require_sesskey();
    \local_schooleescore_bridge\local\mapping_service::delete_course_mapping($deleteid);
    redirect($pageurl, get_string('mapping_deleted', 'local_schooleescore_bridge'));
}

$discoverresult = null;
if ($discover) {
    require_sesskey();
    $discoverresult = \local_schooleescore_bridge\local\mapping_service::autodiscover();
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('mappings', 'local_schooleescore_bridge'));
$PAGE->set_heading(get_string('mappings', 'local_schooleescore_bridge'));

$mappings = \local_schooleescore_bridge\local\mapping_service::list_course_mappings();
$courses = get_courses('all', 'c.fullname ASC', 'c.id, c.fullname');

$table = new html_table();
$table->head = [
    get_string('course'),
    get_string('subject_id', 'local_schooleescore_bridge'),
    get_string('section_id', 'local_schooleescore_bridge'),
    get_string('course_offering_id', 'local_schooleescore_bridge'),
    get_string('term', 'local_schooleescore_bridge'),
    get_string('enabled', 'local_schooleescore_bridge'),
    get_string('actions'),
];
foreach ($mappings as $mapping) {
    $table->data[] = [
        format_string($mapping->fullname),
        s($mapping->schooleescore_subject_id),
        s((string)$mapping->schooleescore_section_id),
        s((string)$mapping->schooleescore_course_offering_id),
        s($mapping->academic_year_code . '/' . $mapping->semester_code),
        $mapping->sync_enabled ? get_string('yes') : get_string('no'),
        html_writer::link(
            new moodle_url($pageurl, ['deleteid' => $mapping->id, 'sesskey' => sesskey()]),
            get_string('delete')
        ),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mappings', 'local_schooleescore_bridge'));
echo html_writer::tag('p', get_string('mappings_help', 'local_schooleescore_bridge'));

if ($discoverresult !== null) {
    if ($discoverresult['error'] !== '') {
        echo $OUTPUT->notification(s($discoverresult['error']), \core\output\notification::NOTIFY_ERROR);
    } else {
        echo $OUTPUT->notification(
            get_string('mappings_discovered', 'local_schooleescore_bridge', (object)$discoverresult),
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo html_writer::tag('p', get_string('mappings_discover_help', 'local_schooleescore_bridge'));
echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['discover' => 1, 'sesskey' => sesskey()]),
    get_string('mappings_discover', 'local_schooleescore_bridge')
);

$courseoptions = [];
foreach ($courses as $course) {
    if ((int)$course->id === SITEID) {
        continue;
    }
    $courseoptions[(int)$course->id] = format_string($course->fullname);
}

$formfields = [];
$formfields[] = html_writer::label(get_string('course'), 'id_courseid');
$formfields[] = html_writer::select($courseoptions, 'courseid', 0, ['' => get_string('choosedots')], ['id' => 'id_courseid']);
$formfields[] = html_writer::empty_tag('br');
$formfields[] = html_writer::label(get_string('subject_id', 'local_schooleescore_bridge'), 'id_subjectid');
$formfields[] = html_writer::empty_tag('input',
    ['type' => 'text', 'name' => 'subjectid', 'id' => 'id_subjectid', 'required' => 'required']);
$formfields[] = html_writer::empty_tag('br');
$formfields[] = html_writer::label(get_string('section_id', 'local_schooleescore_bridge'), 'id_sectionid');
$formfields[] = html_writer::empty_tag('input', ['type' => 'text', 'name' => 'sectionid', 'id' => 'id_sectionid']);
$formfields[] = html_writer::empty_tag('br');
$formfields[] = html_writer::label(get_string('course_offering_id', 'local_schooleescore_bridge'), 'id_offeringid');
$formfields[] = html_writer::empty_tag('input', ['type' => 'text', 'name' => 'offeringid', 'id' => 'id_offeringid']);
$formfields[] = html_writer::empty_tag('br');
$formfields[] = html_writer::label(get_string('academic_year_code', 'local_schooleescore_bridge'), 'id_aycode');
$formfields[] = html_writer::empty_tag('input',
    ['type' => 'text', 'name' => 'academic_year_code', 'id' => 'id_aycode', 'required' => 'required']);
$formfields[] = html_writer::empty_tag('br');
$formfields[] = html_writer::label(get_string('semester_code', 'local_schooleescore_bridge'), 'id_semcode');
$formfields[] = html_writer::empty_tag('input', ['type' => 'text', 'name' => 'semester_code', 'id' => 'id_semcode']);
$formfields[] = html_writer::empty_tag('br');
$formfields[] = html_writer::label(get_string('enabled', 'local_schooleescore_bridge'), 'id_syncenabled');
$formfields[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sync_enabled', 'value' => 0]);
$formfields[] = html_writer::checkbox('sync_enabled', 1, true, '', ['id' => 'id_syncenabled']);
$formfields[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'save', 'value' => 1]);
$formfields[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$formfields[] = html_writer::empty_tag('br');
$formfields[] = html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('savechanges')]);

echo html_writer::tag('h3', get_string('add_mapping', 'local_schooleescore_bridge'));
echo html_writer::tag('form', implode('', $formfields), ['method' => 'post', 'action' => $pageurl]);
echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url('/admin/settings.php', ['section' => 'local_schooleescore_bridge_settings']),
    get_string('settings', 'local_schooleescore_bridge'));
echo $OUTPUT->footer();
