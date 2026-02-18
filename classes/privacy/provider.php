<?php
namespace local_schooleescore_bridge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_ses_user_map', [
            'moodle_userid' => 'privacy:metadata:local_ses_user_map:moodle_userid',
            'schooleescore_user_id' => 'privacy:metadata:local_ses_user_map:schooleescore_user_id',
            'schooleescore_student_no' => 'privacy:metadata:local_ses_user_map:schooleescore_student_no',
        ], 'privacy:metadata:local_ses_user_map');

        $collection->add_database_table('local_ses_enrollment_map', [
            'moodle_userid' => 'privacy:metadata:local_ses_enrollment_map:moodle_userid',
            'moodle_courseid' => 'privacy:metadata:local_ses_enrollment_map:moodle_courseid',
        ], 'privacy:metadata:local_ses_enrollment_map');

        $collection->add_database_table('local_ses_grade_queue', [
            'moodle_userid' => 'privacy:metadata:local_ses_grade_queue:moodle_userid',
            'payload_json' => 'privacy:metadata:local_ses_grade_queue:payload_json',
        ], 'privacy:metadata:local_ses_grade_queue');

        return $collection;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $context = \context_system::instance();

        if (!in_array($context->id, $contextlist->get_contextids(), true)) {
            return;
        }

        $data = [
            'user_map' => array_values($DB->get_records('local_ses_user_map', ['moodle_userid' => $userid])),
            'enrollments' => array_values($DB->get_records('local_ses_enrollment_map', ['moodle_userid' => $userid])),
            'grade_queue' => array_values($DB->get_records('local_ses_grade_queue', ['moodle_userid' => $userid])),
        ];

        writer::with_context($context)->export_data([get_string('pluginname', 'local_schooleescore_bridge')], (object)$data);
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids(), true)) {
            return;
        }

        $DB->delete_records('local_ses_user_map', ['moodle_userid' => $userid]);
        $DB->delete_records('local_ses_enrollment_map', ['moodle_userid' => $userid]);
        $DB->delete_records('local_ses_grade_queue', ['moodle_userid' => $userid]);
        $DB->delete_records('local_ses_payment_cache', ['moodle_userid' => $userid]);
    }
}
