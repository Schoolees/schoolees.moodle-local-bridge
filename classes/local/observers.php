<?php
namespace local_schooleescore_bridge\local;

use core\event\base;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers.
 */
class observers {
    /**
     * Queue grade passback entry when grade is updated.
     *
     * @param base $event
     */
    public static function grade_updated(base $event): void {
        global $DB;

        $data = $event->get_data();
        if (empty($data['relateduserid']) || empty($data['courseid'])) {
            return;
        }

        // user_graded carries itemid/finalgrade in "other"; fall back to the row
        // it points at, which is still the authority for the raw grade.
        $gradeitemid = (int)($data['other']['itemid'] ?? 0);
        $graderaw = null;
        $gradefinal = array_key_exists('finalgrade', $data['other'] ?? [])
            ? $data['other']['finalgrade']
            : null;

        if (!empty($data['objectid'])) {
            $gradegrade = $DB->get_record('grade_grades', ['id' => (int)$data['objectid']]);
            if ($gradegrade) {
                $gradeitemid = (int)$gradegrade->itemid;
                $graderaw = $gradegrade->rawgrade;
                $gradefinal = $gradegrade->finalgrade;
            }
        }

        if ($gradeitemid <= 0) {
            return;
        }

        $payload = [
            'trace_id' => bin2hex(random_bytes(16)),
            'moodle' => $data,
        ];

        queue_service::enqueue_grade([
            'moodle_grade_itemid' => $gradeitemid,
            'moodle_userid' => (int)$data['relateduserid'],
            'moodle_courseid' => (int)$data['courseid'],
            'grade_raw' => $graderaw,
            'grade_final' => $gradefinal,
            'grade_letter' => null,
            'grading_period_code' => 'default',
            'payload' => $payload,
        ]);
    }
}
