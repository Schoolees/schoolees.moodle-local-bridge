<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Idempotency helper.
 */
class idempotency {
    /**
     * Build deterministic key for grade passback.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $gradeitemid
     * @param string $periodcode
     * @param float|null $gradefinal
     * @return string
     */
    public static function grade_key(int $courseid, int $userid, int $gradeitemid, string $periodcode, ?float $gradefinal): string {
        $parts = [
            $courseid,
            $userid,
            $gradeitemid,
            $periodcode,
            $gradefinal === null ? 'null' : sprintf('%.5F', $gradefinal),
        ];
        return hash('sha256', implode(':', $parts));
    }
}
