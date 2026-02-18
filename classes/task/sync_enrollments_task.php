<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\api_client;
use local_schooleescore_bridge\local\field_mapping;
use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Sync student enrollment presence to Moodle user active/suspended state.
 */
class sync_enrollments_task extends base_bridge_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_enrollments', 'local_schooleescore_bridge');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        global $DB;

        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: sync_enrollments_task is already running.');
            return;
        }

        try {
            $client = new api_client();
            $params = [
                'limit' => 1000,
                'offset' => 0,
            ];
            $term = (string)get_config('local_schooleescore_bridge', 'default_term_code');
            if ($term !== '') {
                $params['academic_year_id'] = $term;
            }
            $response = $client->get_json('/students-enrolled', $params);
            if (($response['status'] ?? 0) !== 200) {
                sync_log_service::log([
                    'job_name' => 'sync_enrollments',
                    'entity_type' => 'enrollment',
                    'direction' => 'pull',
                    'http_status' => $response['status'] ?? null,
                    'response_json' => $response['body'] ?? null,
                    'result' => 'failure',
                    'error_message' => 'Failed to fetch enrollments.',
                ]);
                return;
            }

            $rows = $this->extract_rows($response['body'] ?? null);
            $enrolledstudentids = $this->build_ongoing_student_set($rows);
            $suspendunenrolled = ((int)get_config('local_schooleescore_bridge', 'suspend_unenrolled_students') === 1);

            $processed = 0;
            $applied = 0;
            $skipped = 0;
            $errors = 0;
            $maps = $DB->get_records('local_ses_user_map', ['user_type' => 'student']);
            foreach ($maps as $map) {
                $processed++;
                $moodleuserid = (int)$map->moodle_userid;
                $externalid = (string)$map->schooleescore_user_id;
                $shouldbeactive = isset($enrolledstudentids[$externalid]);
                $shouldsuspend = $suspendunenrolled ? !$shouldbeactive : false;

                // Safety guard: never change suspension state for site admins.
                if (is_siteadmin($moodleuserid)) {
                    $skipped++;
                    continue;
                }

                $user = $DB->get_record('user', ['id' => $moodleuserid, 'deleted' => 0]);
                if (!$user) {
                    $skipped++;
                    continue;
                }

                try {
                    // If suspend is disabled, only unsuspend active/enrolled students.
                    $target = $shouldbeactive ? 0 : ($shouldsuspend ? 1 : (int)$user->suspended);
                    if ((int)$user->suspended !== $target) {
                        $user->suspended = $target;
                        $DB->update_record('user', $user);
                        $applied++;
                    } else {
                        $skipped++;
                    }

                    $map->sync_status = $shouldbeactive ? 'active' : 'orphaned';
                    $map->last_synced_at = time();
                    $map->updatedat = time();
                    $DB->update_record('local_ses_user_map', $map);
                } catch (\Throwable $exception) {
                    $errors++;
                    sync_log_service::log([
                        'job_name' => 'sync_enrollments',
                        'entity_type' => 'enrollment',
                        'entity_key' => (string)$moodleuserid,
                        'direction' => 'pull',
                        'result' => 'failure',
                        'error_message' => 'User status sync failed: ' . $exception->getMessage(),
                    ]);
                }
            }

            sync_log_service::log([
                'job_name' => 'sync_enrollments',
                'entity_type' => 'enrollment',
                'direction' => 'pull',
                    'http_status' => 200,
                    'result' => $errors > 0 ? 'partial' : 'success',
                    'response_json' => [
                        'mode' => 'status_only_no_course_mapping',
                        'suspend_unenrolled_students' => $suspendunenrolled ? 1 : 0,
                        'rows_received' => count($rows),
                        'ongoing_students' => count($enrolledstudentids),
                        'processed' => $processed,
                        'applied' => $applied,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ],
            ]);
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Extract rows from API response body.
     *
     * @param mixed $body
     * @return array
     */
    private function extract_rows($body): array {
        if (!is_array($body)) {
            return [];
        }
        if (!empty($body['data']) && is_array($body['data'])) {
            if (array_key_exists(0, $body['data'])) {
                return $body['data'];
            }
            if (!empty($body['data']['data']) && is_array($body['data']['data'])) {
                return $body['data']['data'];
            }
        }
        return [];
    }

    /**
     * Build set of external student ids with ongoing enrollment.
     *
     * @param array $rows
     * @return array
     */
    private function build_ongoing_student_set(array $rows): array {
        $set = [];
        $statuspath = field_mapping::cfg('map_enrollment_status_path', 'status');
        $activevalue = field_mapping::cfg('map_enrollment_active_value', 'ongoing');
        $studentkeypath = field_mapping::cfg('map_enrollment_student_key_path', 'student.id_number|student.id');

        foreach ($rows as $row) {
            $status = (string)(field_mapping::get_by_path($row, $statuspath) ?? '');
            if ($status !== $activevalue) {
                continue;
            }
            $id = clean_param((string)(field_mapping::get_by_path($row, $studentkeypath) ?? ''), PARAM_RAW_TRIMMED);
            if ($id !== '') {
                $set[$id] = true;
            }
        }
        return $set;
    }
}
