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
    /** @var int Page size for the enrollment pull. */
    private const PAGE_SIZE = 500;

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

            $params = [];
            $term = (string)get_config('local_schooleescore_bridge', 'default_term_code');
            if ($term !== '') {
                $params['academic_year_id'] = $term;
            }

            $enrolledstudentids = [];
            $rowsreceived = 0;
            foreach ($client->each_page(api_client::PATH_ENROLLMENTS, $params, self::PAGE_SIZE) as [$rows, $response]) {
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

                $rowsreceived += count($rows);
                // Only the identity keys are kept, so a whole-population pull costs
                // a set of short strings rather than the decoded rows themselves.
                $this->collect_ongoing_student_ids($rows, $enrolledstudentids);
            }

            // A pull that returns nothing is far more likely to be a filter or
            // permission problem than a school with zero enrollments, and acting on
            // it would suspend every mapped student.
            if ($rowsreceived === 0) {
                sync_log_service::log([
                    'job_name' => 'sync_enrollments',
                    'entity_type' => 'enrollment',
                    'direction' => 'pull',
                    'http_status' => 200,
                    'result' => 'failure',
                    'error_message' => 'Enrollment feed returned no rows; refusing to apply suspensions.',
                    'response_json' => ['academic_year_id' => $term],
                ]);
                return;
            }

            $suspendunenrolled = ((int)get_config('local_schooleescore_bridge', 'suspend_unenrolled_students') === 1);
            $dryrun = ((int)get_config('local_schooleescore_bridge', 'sync_enrollments_dry_run') === 1);

            $processed = 0;
            $applied = 0;
            $wouldapply = 0;
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
                        if ($dryrun) {
                            // The setting has always been offered; honour it instead of
                            // suspending users while the UI claims nothing is applied.
                            $wouldapply++;
                        } else {
                            $user->suspended = $target;
                            $user->timemodified = time();
                            $DB->update_record('user', $user);
                            if ($target === 1) {
                                // A suspended account must not keep an open session.
                                \core\session\manager::kill_user_sessions($moodleuserid);
                            }
                            $applied++;
                        }
                    } else {
                        $skipped++;
                    }

                    if (!$dryrun) {
                        $map->sync_status = $shouldbeactive ? 'active' : 'orphaned';
                        $map->last_synced_at = time();
                        $map->updatedat = time();
                        $DB->update_record('local_ses_user_map', $map);
                    }
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
                    'dry_run' => $dryrun ? 1 : 0,
                    'suspend_unenrolled_students' => $suspendunenrolled ? 1 : 0,
                    'rows_received' => $rowsreceived,
                    'ongoing_students' => count($enrolledstudentids),
                    'processed' => $processed,
                    'applied' => $applied,
                    'would_apply' => $wouldapply,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ],
            ]);
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Add the external student ids of actively enrolled rows to the given set.
     *
     * @param array $rows
     * @param array $set
     */
    private function collect_ongoing_student_ids(array $rows, array &$set): void {
        $statuspath = field_mapping::cfg('map_enrollment_status_path', 'status');
        $activevalue = field_mapping::cfg('map_enrollment_active_value', 'enrolled');
        $studentkeypath = field_mapping::cfg('map_enrollment_student_key_path', 'student.id_number|student.id');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string)(field_mapping::get_by_path($row, $statuspath) ?? '');
            if (strcasecmp($status, $activevalue) !== 0) {
                continue;
            }
            $id = clean_param((string)(field_mapping::get_by_path($row, $studentkeypath) ?? ''), PARAM_RAW_TRIMMED);
            if ($id !== '') {
                $set[$id] = true;
            }
        }
    }
}
