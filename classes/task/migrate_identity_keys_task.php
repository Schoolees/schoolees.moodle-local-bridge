<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\api_client;
use local_schooleescore_bridge\local\field_mapping;
use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();

/**
 * One-time migration: move map identity key from external id to id_number.
 */
class migrate_identity_keys_task extends base_bridge_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_migrate_identity_keys', 'local_schooleescore_bridge');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        global $DB;

        if ((int)get_config('local_schooleescore_bridge', 'identity_migration_done') === 1) {
            mtrace('local_schooleescore_bridge: identity migration already completed.');
            return;
        }

        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: migrate_identity_keys_task is already running.');
            return;
        }

        try {
            $client = new api_client();
            $idmap = $this->build_identity_map($client);
            if (empty($idmap)) {
                sync_log_service::log([
                    'job_name' => 'migrate_identity_keys',
                    'entity_type' => 'user',
                    'direction' => 'pull',
                    'result' => 'failure',
                    'error_message' => 'No identity pairs found from /students response.',
                ]);
                return;
            }

            $processed = 0;
            $updated = 0;
            $skipped = 0;
            $conflicts = 0;
            $maps = $DB->get_records('local_ses_user_map');
            foreach ($maps as $map) {
                $processed++;
                $oldkey = (string)$map->schooleescore_user_id;
                if ($oldkey === '' || !isset($idmap[$oldkey])) {
                    $skipped++;
                    continue;
                }

                $newkey = $idmap[$oldkey];
                if ($newkey === '' || $newkey === $oldkey) {
                    $skipped++;
                    continue;
                }

                $exists = $DB->record_exists_select(
                    'local_ses_user_map',
                    'schooleescore_user_id = :newkey AND id <> :id',
                    ['newkey' => $newkey, 'id' => (int)$map->id]
                );
                if ($exists) {
                    $conflicts++;
                    if (empty($map->schooleescore_student_no)) {
                        $map->schooleescore_student_no = $newkey;
                        $map->updatedat = time();
                        $DB->update_record('local_ses_user_map', $map);
                    }
                    continue;
                }

                $map->schooleescore_user_id = $newkey;
                $map->schooleescore_student_no = $newkey;
                $map->updatedat = time();
                $DB->update_record('local_ses_user_map', $map);
                $updated++;
            }

            set_config('identity_migration_done', '1', 'local_schooleescore_bridge');
            sync_log_service::log([
                'job_name' => 'migrate_identity_keys',
                'entity_type' => 'user',
                'direction' => 'pull',
                'http_status' => 200,
                'result' => $conflicts > 0 ? 'partial' : 'success',
                'response_json' => [
                    'processed' => $processed,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'conflicts' => $conflicts,
                ],
            ]);
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Build map of external id => id_number from /students response.
     *
     * @param api_client $client
     * @return array
     */
    private function build_identity_map(api_client $client): array {
        $result = [];
        $sourcepath = field_mapping::cfg('map_user_external_id_path', 'id');
        $targetpath = field_mapping::cfg('map_user_username_path', 'id_number');

        $limit = 500;
        $offset = 0;
        do {
            $response = $client->get_json('/students', ['limit' => $limit, 'offset' => $offset]);
            if (($response['status'] ?? 0) !== 200) {
                sync_log_service::log([
                    'job_name' => 'migrate_identity_keys',
                    'entity_type' => 'user',
                    'direction' => 'pull',
                    'http_status' => $response['status'] ?? null,
                    'response_json' => $response['body'] ?? null,
                    'result' => 'failure',
                    'error_message' => 'Failed to fetch users for identity migration.',
                ]);
                return [];
            }

            $rows = $this->extract_rows($response['body'] ?? null);
            foreach ($rows as $row) {
                $oldid = clean_param((string)(field_mapping::get_by_path($row, $sourcepath) ?? ''), PARAM_RAW_TRIMMED);
                $idnumber = clean_param((string)(field_mapping::get_by_path($row, $targetpath) ?? ''), PARAM_RAW_TRIMMED);
                if ($oldid !== '' && $idnumber !== '') {
                    $result[$oldid] = $idnumber;
                }
            }
            $offset += $limit;
        } while (!empty($rows) && count($rows) >= $limit);

        return $result;
    }

    /**
     * Extract row list from wrapped API response.
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
}
