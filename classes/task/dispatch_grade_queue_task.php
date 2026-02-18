<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\api_client;
use local_schooleescore_bridge\local\circuit_breaker;
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
            $endpoint = '/grades';

            foreach ($records as $record) {
                if (circuit_breaker::is_open($endpoint)) {
                    queue_service::mark_failure($record, 503, 'Circuit open for grades endpoint.');
                    continue;
                }

                $traceid = bin2hex(random_bytes(16));
                $start = microtime(true);
                $payload = $this->build_grade_payload($client, $record);
                if (empty($payload)) {
                    queue_service::mark_failure($record, 422, 'Missing payload mapping fields for /grades endpoint.');
                    continue;
                }
                $response = $client->post_json($endpoint, $payload, (string)$record->idempotency_key);
                $durationms = (int)round((microtime(true) - $start) * 1000);

                $isduplicate = ($response['status'] ?? 0) === 422 &&
                    strpos((string)($response['raw'] ?? ''), 'Grade already exists') !== false;
                if ((($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300) || $isduplicate) {
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

                $error = 'HTTP ' . (int)($response['status'] ?? 0);
                if (!empty($response['body']['code'])) {
                    $error .= ' ' . $response['body']['code'];
                }
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
     * Translate queued Moodle record into SchooleesCore /grades payload.
     *
     * @param api_client $client
     * @param \stdClass $record
     * @return array
     */
    private function build_grade_payload(api_client $client, \stdClass $record): array {
        global $DB;

        $usermap = $DB->get_record('local_ses_user_map', ['moodle_userid' => (int)$record->moodle_userid]);
        $coursemap = $DB->get_record('local_ses_course_map', ['moodle_courseid' => (int)$record->moodle_courseid, 'sync_enabled' => 1]);
        if (!$usermap || !$coursemap) {
            return [];
        }

        $studentid = (int)$usermap->schooleescore_user_id;
        $subjectid = (int)$coursemap->schooleescore_subject_id;
        $sectionid = (int)$coursemap->schooleescore_section_id;
        if ($studentid <= 0 || $subjectid <= 0) {
            return [];
        }

        $enrollparams = [
            'student_id' => $studentid,
            'limit' => 1,
            'offset' => 0,
        ];
        if ($sectionid > 0) {
            $enrollparams['section_id'] = $sectionid;
        }
        $term = (string)get_config('local_schooleescore_bridge', 'default_term_code');
        if ($term !== '') {
            $enrollparams['academic_year_id'] = $term;
        }
        $enrollresponse = $client->get_json('/students-enrolled', $enrollparams);
        if (($enrollresponse['status'] ?? 0) !== 200 || empty($enrollresponse['body']['data'][0])) {
            return [];
        }
        $entry = $enrollresponse['body']['data'][0];

        $academicyearid = (int)($entry['academic_year']['id'] ?? 0);
        $yearlevelid = (int)($entry['year_level']['id'] ?? 0);
        $strandsid = (int)($entry['strands']['id'] ?? 0);
        if ($academicyearid <= 0 || $yearlevelid <= 0) {
            return [];
        }

        $gradecategoryid = (int)get_config('local_schooleescore_bridge', 'default_grade_category_id');
        if ($gradecategoryid <= 0) {
            $gradecategoryid = 1;
        }

        $gradevalue = $record->grade_final;
        if ($gradevalue === null) {
            $gradevalue = $record->grade_raw;
        }
        if ($gradevalue === null) {
            $gradevalue = 0;
        }

        return [
            'grade_category_id' => $gradecategoryid,
            'academic_year_id' => $academicyearid,
            'year_level_id' => $yearlevelid,
            'strands_id' => $strandsid > 0 ? $strandsid : null,
            'student_id' => $studentid,
            'teacher_id' => null,
            'subject_id' => $subjectid,
            'grade' => round((float)$gradevalue, 2),
        ];
    }
}
