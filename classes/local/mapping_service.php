<?php
namespace local_schooleescore_bridge\local;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Course mapping management.
 */
class mapping_service {
    /**
     * Upsert course mapping row.
     *
     * @param int $courseid
     * @param string $subjectid
     * @param string|null $sectionid
     * @param string $aycode
     * @param string|null $semcode
     * @param bool $syncenabled
     */
    public static function upsert_course_mapping(
        int $courseid,
        string $subjectid,
        ?string $sectionid,
        string $aycode,
        ?string $semcode,
        bool $syncenabled
    ): void {
        global $DB;

        $existing = $DB->get_record('local_ses_course_map', [
            'moodle_courseid' => $courseid,
            'academic_year_code' => $aycode,
            'semester_code' => $semcode,
        ]);

        $now = time();
        if ($existing) {
            $existing->schooleescore_subject_id = $subjectid;
            $existing->schooleescore_section_id = $sectionid;
            $existing->sync_enabled = $syncenabled ? 1 : 0;
            $existing->updatedat = $now;
            $DB->update_record('local_ses_course_map', $existing);
            return;
        }

        $record = new stdClass();
        $record->moodle_courseid = $courseid;
        $record->schooleescore_subject_id = $subjectid;
        $record->schooleescore_section_id = $sectionid;
        $record->academic_year_code = $aycode;
        $record->semester_code = $semcode;
        $record->sync_enabled = $syncenabled ? 1 : 0;
        $record->createdat = $now;
        $record->updatedat = $now;
        $DB->insert_record('local_ses_course_map', $record);
    }

    /**
     * @return array
     */
    public static function list_course_mappings(): array {
        global $DB;

        $sql = "SELECT cm.*, c.fullname
                  FROM {local_ses_course_map} cm
                  JOIN {course} c ON c.id = cm.moodle_courseid
              ORDER BY c.fullname ASC";
        return $DB->get_records_sql($sql);
    }
}
