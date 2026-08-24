<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\api_client;
use local_schooleescore_bridge\local\circuit_breaker;
use local_schooleescore_bridge\local\field_mapping;
use local_schooleescore_bridge\local\queue_service;
use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Dispatch grade queue to SchooleesCore.
 */
class dispatch_grade_queue_task extends base_bridge_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_dispatch_grade_queue', 'local_schooleescore_bridge');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: dispatch_grade_queue_task is already running.');
            return;
        }

        try {
            $client = new api_client();
            $records = queue_service::claim_batch(50);
            $endpoint = api_client::PATH_GRADES;

            foreach ($records as $record) {
                // An open circuit says the endpoint is unwell, not that this row is
                // undeliverable - park it without spending one of its five attempts.
                if (circuit_breaker::is_open($endpoint)) {
                    queue_service::defer(
                        $record,
                        circuit_breaker::seconds_until_close($endpoint),
                        'Deferred: circuit open for grades endpoint.'
                    );
                    continue;
                }

                $traceid = bin2hex(random_bytes(16));
                $start = microtime(true);
                $payload = $this->build_grade_payload($client, $record, $failure);
                if (empty($payload)) {
                    // Mapping gaps resolve themselves once an admin adds the course
                    // map or the student appears in the feed, so keep retrying.
                    queue_service::mark_failure($record, 503, $failure);
                    sync_log_service::log([
                        'trace_id' => $traceid,
                        'job_name' => 'dispatch_grade_queue',
                        'entity_type' => 'grade',
                        'entity_key' => (string)$record->id,
                        'direction' => 'push',
                        'result' => 'failure',
                        'error_message' => $failure,
                    ]);
                    continue;
                }

                $response = $client->post_json($endpoint, $payload, (string)$record->idempotency_key);
                $durationms = (int)round((microtime(true) - $start) * 1000);

                if ((($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300)) {
                    circuit_breaker::record_result($endpoint, true);
                    queue_service::mark_sent($record);
                    sync_log_service::log([
                        'trace_id' => $traceid,
                        'job_name' => 'dispatch_grade_queue',
                        'entity_type' => 'grade',
                        'entity_key' => (string)$record->id,
                        'direction' => 'push',
                        'request_json' => $payload,
                        'response_json' => $response['body'] ?? null,
                        'http_status' => $response['status'] ?? null,
                        'result' => 'success',
                        'duration_ms' => $durationms,
                    ]);
                    continue;
                }

                if ($this->is_duplicate_grade_response($response) && $this->update_existing_grade($client, $payload)) {
                    circuit_breaker::record_result($endpoint, true);
                    queue_service::mark_sent($record);
                    sync_log_service::log([
                        'trace_id' => $traceid,
                        'job_name' => 'dispatch_grade_queue',
                        'entity_type' => 'grade',
                        'entity_key' => (string)$record->id,
                        'direction' => 'push',
                        'request_json' => $payload,
                        'response_json' => ['mode' => 'upsert', 'duplicate' => true],
                        'http_status' => 200,
                        'result' => 'success',
                        'duration_ms' => $durationms,
                    ]);
                    continue;
                }

                $error = $this->describe_error($response);
                circuit_breaker::record_result($endpoint, false);
                queue_service::mark_failure($record, $response['status'] ?? null, $error);
                sync_log_service::log([
                    'trace_id' => $traceid,
                    'job_name' => 'dispatch_grade_queue',
                    'entity_type' => 'grade',
                    'entity_key' => (string)$record->id,
                    'direction' => 'push',
                    'request_json' => $payload,
                    'response_json' => $response['body'] ?? null,
                    'http_status' => $response['status'] ?? null,
                    'result' => 'failure',
                    'error_message' => $error,
                    'duration_ms' => $durationms,
                ]);
            }
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Translate queued Moodle record into a SchooleesCore /grades payload.
     *
     * @param api_client $client
     * @param \stdClass $record
     * @param string|null $failure Set to a human-readable reason when the payload cannot be built.
     * @return array
     */
    private function build_grade_payload(api_client $client, \stdClass $record, ?string &$failure = null): array {
        global $DB;

        $failure = '';

        $usermap = $DB->get_record('local_ses_user_map', ['moodle_userid' => (int)$record->moodle_userid]);
        if (!$usermap) {
            $failure = 'No SchooleesCore user mapping for Moodle user ' . (int)$record->moodle_userid . '.';
            return [];
        }

        $coursemap = $DB->get_record('local_ses_course_map', [
            'moodle_courseid' => (int)$record->moodle_courseid,
            'sync_enabled' => 1,
        ]);
        if (!$coursemap) {
            $failure = 'No enabled course mapping for Moodle course ' . (int)$record->moodle_courseid . '.';
            return [];
        }

        // schooleescore_subject_id predates the rename of "subjects" to courses;
        // it holds what the API now calls course_id.
        $courseid = (int)$coursemap->schooleescore_subject_id;
        $sectionid = (int)$coursemap->schooleescore_section_id;
        if ($courseid <= 0) {
            $failure = 'Course mapping for Moodle course ' . (int)$record->moodle_courseid . ' has no SchooleesCore course id.';
            return [];
        }

        $studentid = $this->resolve_student_id($client, $usermap);
        if ($studentid <= 0) {
            $failure = 'Could not resolve the SchooleesCore student id for Moodle user ' . (int)$record->moodle_userid . '.';
            return [];
        }

        $offeringid = (int)($coursemap->schooleescore_course_offering_id ?? 0);
        $entry = $this->find_enrollment($client, $studentid, $courseid, $sectionid, $offeringid);
        if ($entry === null) {
            $failure = 'No SchooleesCore enrollment found for student ' . $studentid . ' on course ' . $courseid . '.';
            return [];
        }

        $academicyearid = (int)($entry['academic_year']['id'] ?? 0);
        $yearlevelid = (int)($entry['year_level']['id'] ?? 0);
        if ($academicyearid <= 0 || $yearlevelid <= 0) {
            $failure = 'Enrollment row is missing academic_year.id or year_level.id.';
            return [];
        }

        $gradeperiodid = (int)get_config('local_schooleescore_bridge', 'default_grade_period_id');
        if ($gradeperiodid <= 0) {
            // Legacy setting name, kept so upgraded sites keep working.
            $gradeperiodid = (int)get_config('local_schooleescore_bridge', 'default_grade_category_id');
        }
        if ($gradeperiodid <= 0) {
            $failure = 'No default grade period id is configured.';
            return [];
        }

        $gradevalue = $record->grade_final;
        if ($gradevalue === null) {
            $gradevalue = $record->grade_raw;
        }
        if ($gradevalue === null) {
            $failure = 'Queued row carries neither a final nor a raw grade.';
            return [];
        }
        $gradevalue = round((float)$gradevalue, 2);

        // The matched enrollment is authoritative for these, not the local map.
        $entryofferingid = (int)($entry['course_offering']['id'] ?? 0);
        $enrollmentid = (int)($entry['id'] ?? 0);

        $payload = [
            'grade_period_id' => $gradeperiodid,
            'academic_year_id' => $academicyearid,
            'year_level_id' => $yearlevelid,
            'student_id' => $studentid,
            'course_id' => $courseid,
            'grade_input' => (string)$gradevalue,
            'grade' => $gradevalue,
        ];
        if ($entryofferingid > 0) {
            $payload['course_offering_id'] = $entryofferingid;
        }
        if ($enrollmentid > 0) {
            $payload['enrollment_id'] = $enrollmentid;
        }

        return $payload;
    }

    /**
     * Resolve (and cache) the numeric SchooleesCore student id for a mapped user.
     *
     * The identity key column holds an id_number, not the remote primary key, so
     * casting it to an int yields a different student's id.
     *
     * @param api_client $client
     * @param \stdClass $usermap
     * @return int
     */
    private function resolve_student_id(api_client $client, \stdClass $usermap): int {
        global $DB;

        $cached = (int)($usermap->schooleescore_external_id ?? 0);
        if ($cached > 0) {
            return $cached;
        }

        $idnumber = trim((string)($usermap->schooleescore_student_no ?? ''));
        if ($idnumber === '') {
            $idnumber = trim((string)$usermap->schooleescore_user_id);
        }
        if ($idnumber === '') {
            return 0;
        }

        // id_number is an exact-match filter on /students; do not add other
        // filters here, the API ORs relation filters against the base query.
        $response = $client->get_json(api_client::PATH_STUDENTS, [
            'id_number' => $idnumber,
            'limit' => 1,
            'offset' => 0,
        ]);
        if (($response['status'] ?? 0) !== 200) {
            return 0;
        }

        $rows = api_client::extract_rows($response['body'] ?? null);
        $studentid = (int)($rows[0]['id'] ?? 0);
        if ($studentid <= 0) {
            return 0;
        }

        $usermap->schooleescore_external_id = (string)$studentid;
        $usermap->updatedat = time();
        $DB->update_record('local_ses_user_map', $usermap);

        return $studentid;
    }

    /**
     * Find the enrollment row a grade should be filed against.
     *
     * @param api_client $client
     * @param int $studentid
     * @param int $courseid
     * @param int $sectionid
     * @return array|null
     */
    private function find_enrollment(
        api_client $client,
        int $studentid,
        int $courseid,
        int $sectionid,
        int $offeringid = 0
    ): ?array {
        $params = [
            'student_id' => $studentid,
            'limit' => 100,
            'offset' => 0,
        ];
        // course_offering_id is a direct column filter, so it narrows the query
        // rather than being ORed in the way the relation filters (section_id) are.
        if ($offeringid > 0) {
            $params['course_offering_id'] = $offeringid;
        }
        $term = (string)get_config('local_schooleescore_bridge', 'default_term_code');
        if ($term !== '') {
            $params['academic_year_id'] = $term;
        }

        $response = $client->get_json(api_client::PATH_ENROLLMENTS, $params);
        if (($response['status'] ?? 0) !== 200) {
            return null;
        }

        $rows = api_client::extract_rows($response['body'] ?? null);
        if (empty($rows)) {
            return null;
        }

        $activevalue = field_mapping::cfg('map_enrollment_active_value', 'enrolled');
        $fallback = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($offeringid > 0) {
                if ((int)($row['course_offering']['id'] ?? 0) !== $offeringid) {
                    continue;
                }
            } else {
                $rowcourseid = (int)($row['course_offering']['course']['id'] ?? 0);
                if ($rowcourseid !== $courseid) {
                    continue;
                }
                if ($sectionid > 0 && (int)($row['course_offering']['section']['id'] ?? 0) !== $sectionid) {
                    continue;
                }
            }
            if ((string)($row['status'] ?? '') === $activevalue) {
                return $row;
            }
            $fallback = $fallback ?? $row;
        }

        return $fallback;
    }

    /**
     * @param array $response
     * @return bool
     */
    private function is_duplicate_grade_response(array $response): bool {
        if (($response['status'] ?? 0) !== 422) {
            return false;
        }

        $raw = strtolower((string)($response['raw'] ?? ''));
        if (strpos($raw, 'grade already exists') !== false) {
            return true;
        }

        $error = strtolower((string)($response['body']['error'] ?? ''));
        return strpos($error, 'grade already exists') !== false;
    }

    /**
     * Update an existing remote grade when create returns a duplicate response.
     *
     * @param api_client $client
     * @param array $payload
     * @return bool
     */
    private function update_existing_grade(api_client $client, array $payload): bool {
        $lookup = [
            'grade_period_id' => (int)($payload['grade_period_id'] ?? 0),
            'academic_year_id' => (int)($payload['academic_year_id'] ?? 0),
            'year_level_id' => (int)($payload['year_level_id'] ?? 0),
            'student_id' => (int)($payload['student_id'] ?? 0),
            'course_id' => (int)($payload['course_id'] ?? 0),
            'limit' => 1,
            'offset' => 0,
        ];
        if (!empty($payload['course_offering_id'])) {
            $lookup['course_offering_id'] = (int)$payload['course_offering_id'];
        }
        if (!empty($payload['enrollment_id'])) {
            $lookup['enrollment_id'] = (int)$payload['enrollment_id'];
        }

        $lookupresponse = $client->get_json(api_client::PATH_GRADES, $lookup);
        if (($lookupresponse['status'] ?? 0) !== 200) {
            return false;
        }

        $rows = api_client::extract_rows($lookupresponse['body'] ?? null);
        $gradeid = (int)($rows[0]['id'] ?? 0);
        if ($gradeid <= 0) {
            return false;
        }

        $updateresponse = $client->put_json(api_client::PATH_GRADES . '/' . $gradeid, [
            'grade_input' => (string)($payload['grade_input'] ?? ''),
            'grade' => $payload['grade'] ?? null,
        ]);

        return (($updateresponse['status'] ?? 0) >= 200 && ($updateresponse['status'] ?? 0) < 300);
    }

    /**
     * Build a log-friendly description of a failed response.
     *
     * @param array $response
     * @return string
     */
    private function describe_error(array $response): string {
        $error = 'HTTP ' . (int)($response['status'] ?? 0);

        $body = $response['body'] ?? null;
        if (is_array($body)) {
            foreach (['error', 'message', 'code'] as $key) {
                if (!empty($body[$key]) && is_scalar($body[$key])) {
                    $error .= ' ' . (string)$body[$key];
                    break;
                }
            }
            // Laravel validation failures carry the useful part under "errors".
            if (!empty($body['errors']) && is_array($body['errors'])) {
                $error .= ' ' . substr(json_encode($body['errors']), 0, 300);
            }
        }

        return $error;
    }
}
