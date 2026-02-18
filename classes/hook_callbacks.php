<?php
namespace local_schooleescore_bridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for local_schooleescore_bridge.
 */
class hook_callbacks {
    /**
     * Enforces optional payment/clearance gating at course access.
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $COURSE, $USER;

        if ((defined('CLI_SCRIPT') && CLI_SCRIPT) || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)) {
            return;
        }
        if (empty($COURSE->id) || isguestuser() || is_siteadmin()) {
            return;
        }
        if (!get_config('local_schooleescore_bridge', 'enable_payment_gating')) {
            return;
        }
        if ($COURSE->id == SITEID || !isloggedin()) {
            return;
        }

        $blocked = \local_schooleescore_bridge\local\payment_gate::is_user_blocked_for_course((int)$USER->id, (int)$COURSE->id);
        if ($blocked) {
            throw new \moodle_exception('payment_gating_blocked', 'local_schooleescore_bridge');
        }
    }
}

