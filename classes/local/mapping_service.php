<?php
namespace local_schooleescore_bridge\local;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Course mapping management.
 */
class mapping_service {
    /** @var string Prefix SchooleesCore stamps on the idnumber of an exported course. */
    public const IDNUMBER_PREFIX = 'subject_offering:';

    /**
     * Upsert course mapping row.
     *
     * @param int $courseid
     * @param string $subjectid SchooleesCore course id (courses.id).
     * @param string|null $sectionid
     * @param string $aycode
     * @param string|null $semcode
     * @param bool $syncenabled
     * @param string|null $offeringid SchooleesCore course_offerings.id, when known.
     */
    public static function upsert_course_mapping(
        int $courseid,
        string $subjectid,
        ?string $sectionid,
        string $aycode,
        ?string $semcode,
        bool $syncenabled,
        ?string $offeringid = null
    ): void {
        global $DB;

        // Normalise so '' and null cannot create two rows for the same term.
        $semcode = ($semcode === null || $semcode === '') ? null : $semcode;
        $sectionid = ($sectionid === null || $sectionid === '') ? null : $sectionid;
        $offeringid = ($offeringid === null || $offeringid === '') ? null : $offeringid;

        $existing = $DB->get_record('local_ses_course_map', [
            'moodle_courseid' => $courseid,
            'academic_year_code' => $aycode,
            'semester_code' => $semcode,
        ]);

        $now = time();
        if ($existing) {
            $existing->schooleescore_subject_id = $subjectid;
            $existing->schooleescore_section_id = $sectionid;
            $existing->schooleescore_course_offering_id = $offeringid ?? $existing->schooleescore_course_offering_id;
            $existing->sync_enabled = $syncenabled ? 1 : 0;
            $existing->updatedat = $now;
            $DB->update_record('local_ses_course_map', $existing);
            return;
        }

        $record = new stdClass();
        $record->moodle_courseid = $courseid;
        $record->schooleescore_subject_id = $subjectid;
        $record->schooleescore_section_id = $sectionid;
        $record->schooleescore_course_offering_id = $offeringid;
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

    /**
     * Delete a mapping row.
     *
     * @param int $id
     */
    public static function delete_course_mapping(int $id): void {
        global $DB;

        $DB->delete_records('local_ses_course_map', ['id' => $id]);
    }

    /**
     * Build course mappings from the identifiers SchooleesCore already exports.
     *
     * SchooleesCore's Moodle course export stamps every course with
     * idnumber = "subject_offering:<course_offering_id>" and a deterministic
     * shortname, so a site that imported that CSV needs no manual mapping at all.
     *
     * @param api_client|null $client
     * @return array Counts keyed matched/created/skipped/unmatched.
     */
    public static function autodiscover(?api_client $client = null): array {
        global $DB;

        $client = $client ?? new api_client();
        $result = ['offerings' => 0, 'matched' => 0, 'skipped' => 0, 'unmatched' => 0, 'error' => ''];

        // Index the remote offerings by both identifiers the export writes.
        $byidnumber = [];
        $byshortname = [];
        foreach ($client->each_page(api_client::PATH_COURSE_OFFERINGS, [], 200) as [$rows, $response]) {
            if (($response['status'] ?? 0) !== 200) {
                $result['error'] = 'HTTP ' . (int)($response['status'] ?? 0) . ' from ' . api_client::PATH_COURSE_OFFERINGS . '.';
                return $result;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $result['offerings']++;
                $idnumber = trim((string)($row['moodle_idnumber'] ?? ''));
                if ($idnumber !== '') {
                    $byidnumber[$idnumber] = $row;
                }
                $shortname = trim((string)($row['moodle_shortname'] ?? ''));
                if ($shortname !== '') {
                    $byshortname[$shortname] = $row;
                }
            }
        }

        if (empty($byidnumber) && empty($byshortname)) {
            $result['error'] = 'No course offerings returned by SchooleesCore.';
            return $result;
        }

        $courses = $DB->get_records_select('course', 'id <> :siteid', ['siteid' => SITEID], 'id ASC',
            'id, shortname, idnumber');
        foreach ($courses as $course) {
            $idnumber = trim((string)$course->idnumber);
            $shortname = trim((string)$course->shortname);

            $row = $byidnumber[$idnumber] ?? ($byshortname[$shortname] ?? null);
            if ($row === null) {
                $result['unmatched']++;
                continue;
            }

            $remotecourseid = (string)(int)($row['course']['id'] ?? 0);
            if ($remotecourseid === '0') {
                $result['skipped']++;
                continue;
            }

            $aycode = (string)($row['academic_year']['id'] ?? ($row['section']['academic_year_id'] ?? ''));
            if ($aycode === '') {
                $result['skipped']++;
                continue;
            }

            self::upsert_course_mapping(
                (int)$course->id,
                $remotecourseid,
                (string)(int)($row['section']['id'] ?? 0) ?: null,
                $aycode,
                (string)($row['semester']['id'] ?? '') ?: null,
                true,
                (string)(int)($row['id'] ?? 0) ?: null
            );
            $result['matched']++;
        }

        return $result;
    }
}
