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

        $field = new xmldb_field('profile_picture_synced_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'profile_picture_hash');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026021805, 'local', 'schooleescore_bridge');
    }

    return true;
}
