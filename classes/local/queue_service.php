<?php
namespace local_schooleescore_bridge\local;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Grade queue operations.
 */
class queue_service {
    /**
     * Enqueue grade passback record.
     *
     * @param array $grade
     */
    public static function enqueue_grade(array $grade): void {
        global $DB;

        $periodcode = (string)($grade['grading_period_code'] ?? 'default');
        $idempotency = idempotency::grade_key(
            (int)$grade['moodle_courseid'],
            (int)$grade['moodle_userid'],
            (int)$grade['moodle_grade_itemid'],
            $periodcode,
            isset($grade['grade_final']) ? (float)$grade['grade_final'] : null
        );

        if ($DB->record_exists('local_ses_grade_queue', ['idempotency_key' => $idempotency])) {
            return;
        }

        $record = new stdClass();
        $record->idempotency_key = $idempotency;
        $record->moodle_grade_itemid = (int)$grade['moodle_grade_itemid'];
        $record->moodle_userid = (int)$grade['moodle_userid'];
        $record->moodle_courseid = (int)$grade['moodle_courseid'];
        $record->grade_raw = $grade['grade_raw'] ?? null;
        $record->grade_final = $grade['grade_final'] ?? null;
        $record->grade_letter = $grade['grade_letter'] ?? null;
        $record->grading_period_code = $periodcode;
        $record->payload_json = json_encode($grade['payload']);
        $record->status = 'pending';
        $record->attempt_count = 0;
        $record->next_attempt_at = time();
        $record->last_error = null;
        $record->createdat = time();
        $record->updatedat = time();

        $DB->insert_record('local_ses_grade_queue', $record);
    }

    /**
     * Claim a batch for processing.
     *
     * @param int $limit
     * @return array
     */
    public static function claim_batch(int $limit = 50): array {
        global $DB;

        $now = time();
        $sql = "SELECT *
                  FROM {local_ses_grade_queue}
                 WHERE status IN (:pending, :failed)
                   AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
              ORDER BY id ASC";

        $records = $DB->get_records_sql($sql, ['pending' => 'pending', 'failed' => 'failed', 'now' => $now], 0, $limit);
        foreach ($records as $record) {
            $record->status = 'processing';
            $record->updatedat = $now;
            $DB->update_record('local_ses_grade_queue', $record);
        }

        return $records;
    }

    /**
     * Mark queue row as sent.
     *
     * @param stdClass $record
     */
    public static function mark_sent(stdClass $record): void {
        global $DB;

        $record->status = 'sent';
        $record->updatedat = time();
        $record->last_error = null;
        $DB->update_record('local_ses_grade_queue', $record);
    }

    /**
     * Mark queue row failure.
     *
     * @param stdClass $record
     * @param int|null $httpstatus
     * @param string $error
     */
    public static function mark_failure(stdClass $record, ?int $httpstatus, string $error): void {
        global $DB;

        $record->attempt_count = ((int)$record->attempt_count) + 1;
        $record->last_error = $error;
        $record->updatedat = time();

        if (!retry_policy::is_retryable($httpstatus) || retry_policy::should_mark_dead((int)$record->attempt_count)) {
            $record->status = 'dead';
            $record->next_attempt_at = null;
        } else {
            $record->status = 'failed';
            $record->next_attempt_at = retry_policy::next_attempt_at((int)$record->attempt_count, time());
        }

        $DB->update_record('local_ses_grade_queue', $record);
    }

    /**
     * Replay a dead queue item.
     *
     * @param int $id
     */
    public static function replay(int $id): void {
        global $DB;

        $record = $DB->get_record('local_ses_grade_queue', ['id' => $id], '*', MUST_EXIST);
        $record->status = 'pending';
        $record->attempt_count = 0;
        $record->next_attempt_at = time();
        $record->last_error = null;
        $record->updatedat = time();
        $DB->update_record('local_ses_grade_queue', $record);
    }

    /**
     * List queue records.
     *
     * @param string $status
     * @param int $limit
     * @return array
     */
    public static function list_records(string $status = '', int $limit = 200): array {
        global $DB;

        if ($status !== '') {
            return $DB->get_records('local_ses_grade_queue', ['status' => $status], 'id DESC', '*', 0, $limit);
        }

        return $DB->get_records('local_ses_grade_queue', null, 'id DESC', '*', 0, $limit);
    }

    /**
     * @return array
     */
    public static function get_queue_counts(): array {
        global $DB;

        $statuses = ['pending', 'failed', 'dead'];
        $result = ['pending' => 0, 'failed' => 0, 'dead' => 0];
        foreach ($statuses as $status) {
            $result[$status] = $DB->count_records('local_ses_grade_queue', ['status' => $status]);
        }
        return $result;
    }
}
