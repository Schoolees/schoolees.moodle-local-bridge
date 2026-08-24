<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade script for local_schooleescore_bridge.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_schooleescore_bridge_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026021700) {
        upgrade_plugin_savepoint(true, 2026021700, 'local', 'schooleescore_bridge');
    }

    if ($oldversion < 2026021801) {
        set_config('identity_migration_done', '0', 'local_schooleescore_bridge');
        upgrade_plugin_savepoint(true, 2026021801, 'local', 'schooleescore_bridge');
    }

    if ($oldversion < 2026021805) {
        $table = new xmldb_table('local_ses_user_map');

        $field = new xmldb_field('profile_picture_url', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'last_synced_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('profile_picture_hash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'profile_picture_url');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('profile_picture_synced_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null,
            'profile_picture_hash');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026021805, 'local', 'schooleescore_bridge');
    }

    if ($oldversion < 2026082500) {
        // Cache the remote numeric record id separately from the identity key.
        // The identity key column holds an id_number since v0.1.3, so casting it
        // to an int (as the grade payload used to) produced a bogus student id.
        $table = new xmldb_table('local_ses_user_map');
        $field = new xmldb_field('schooleescore_external_id', XMLDB_TYPE_CHAR, '64', null, null, null, null,
            'schooleescore_student_no');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_ses_course_map');
        $field = new xmldb_field('schooleescore_course_offering_id', XMLDB_TYPE_CHAR, '64', null, null, null, null,
            'schooleescore_section_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // SchooleesCore renamed the enrollment feed and its status vocabulary.
        // Sites upgrading from <= v0.1.19 still carry the old defaults, and with
        // suspend_unenrolled_students on that would suspend every mapped student.
        $activevalue = (string)get_config('local_schooleescore_bridge', 'map_enrollment_active_value');
        if ($activevalue === '' || $activevalue === 'ongoing') {
            set_config('map_enrollment_active_value', 'enrolled', 'local_schooleescore_bridge');
        }

        // default_grade_period_id was read by the dispatcher but never settable.
        if (get_config('local_schooleescore_bridge', 'default_grade_period_id') === false) {
            $legacy = (int)get_config('local_schooleescore_bridge', 'default_grade_category_id');
            set_config('default_grade_period_id', (string)max($legacy, 0), 'local_schooleescore_bridge');
        }

        // Grade payloads built against the old contract can never succeed; let the
        // dispatcher rebuild them from the queued Moodle grade instead of retrying.
        $DB->set_field_select(
            'local_ses_grade_queue',
            'status',
            'pending',
            'status = :dead',
            ['dead' => 'dead']
        );
        $DB->set_field_select(
            'local_ses_grade_queue',
            'attempt_count',
            0,
            'status = :pending',
            ['pending' => 'pending']
        );

        upgrade_plugin_savepoint(true, 2026082500, 'local', 'schooleescore_bridge');
    }

    return true;
}
