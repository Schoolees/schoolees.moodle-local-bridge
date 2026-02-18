<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Local plugin callback and helper functions.
 *
 * @package    local_schooleescore_bridge
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend user navigation with status page when user can manage the bridge.
 *
 * @param global_navigation $nav
 */
function local_schooleescore_bridge_extend_navigation(global_navigation $nav): void {
    if (!has_capability('local/schooleescore_bridge:manage', context_system::instance())) {
        return;
    }

    $url = new moodle_url('/local/schooleescore_bridge/index.php');
    $nav->add(get_string('pluginname', 'local_schooleescore_bridge'), $url, navigation_node::TYPE_CUSTOM,
        null, 'local_schooleescore_bridge');
}

// NOTE: before_http_headers legacy callback removed (Moodle 5 hook migration).
