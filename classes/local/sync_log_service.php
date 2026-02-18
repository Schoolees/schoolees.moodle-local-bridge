<?php
namespace local_schooleescore_bridge\local;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Sync log utility.
 */
class sync_log_service {
    /**
     * Write sync log entry.
     *
     * @param array $data
     */
    public static function log(array $data): void {
        global $DB;

        $record = new stdClass();
        $record->trace_id = (string)($data['trace_id'] ?? bin2hex(random_bytes(8)));
        $record->job_name = (string)($data['job_name'] ?? 'unknown');
        $record->entity_type = (string)($data['entity_type'] ?? 'unknown');
        $record->entity_key = (string)($data['entity_key'] ?? '');
        $record->direction = (string)($data['direction'] ?? 'push');
        $record->request_json = self::redact_json($data['request_json'] ?? null);
        $record->response_json = self::redact_json($data['response_json'] ?? null);
        $record->http_status = $data['http_status'] ?? null;
        $record->result = (string)($data['result'] ?? 'success');
        $record->error_code = (string)($data['error_code'] ?? '');
        $record->error_message = (string)($data['error_message'] ?? '');
        $record->duration_ms = $data['duration_ms'] ?? null;
        $record->createdat = time();

        $DB->insert_record('local_ses_sync_log', $record);
    }

    /**
     * Return recent records.
     *
     * @param int $limit
     * @return array
     */
    public static function recent(int $limit = 100): array {
        global $DB;

        return $DB->get_records('local_ses_sync_log', null, 'id DESC', '*', 0, $limit);
    }

    /**
     * Purge old records.
     *
     * @param int $olderthan
     */
    public static function purge_older_than(int $olderthan): void {
        global $DB;

        $DB->delete_records_select('local_ses_sync_log', 'createdat < :olderthan', ['olderthan' => $olderthan]);
    }

    /**
     * Mask sensitive fields before persisting logs.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function redact_json($value): ?string {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
        } else {
            $decoded = $value;
        }
        if (!is_array($decoded)) {
            return null;
        }

        $mask = static function (&$arr) use (&$mask): void {
            foreach ($arr as $key => &$item) {
                $lower = strtolower((string)$key);
                if (in_array($lower, ['email', 'token', 'client_secret', 'webhook_secret', 'authorization'], true)) {
                    $item = '***';
                    continue;
                }
                if (is_array($item)) {
                    $mask($item);
                }
            }
        };

        $mask($decoded);
        return json_encode($decoded);
    }
}
