<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Payment gating helper.
 */
class payment_gate {
    /**
     * Checks whether user should be blocked for the current term.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function is_user_blocked_for_course(int $userid, int $courseid): bool {
        global $DB;

        if (!$userid || !$courseid) {
            return false;
        }

        $termcode = (string)get_config('local_schooleescore_bridge', 'default_term_code');
        if ($termcode === '') {
            return false;
        }

        $cache = $DB->get_record('local_ses_payment_cache', [
            'moodle_userid' => $userid,
            'term_code' => $termcode,
        ]);

        if (!$cache) {
            return false;
        }

        return $cache->clearance_status === 'restricted';
    }
}
