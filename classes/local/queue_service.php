<?php
namespace local_schooleescore_bridge\local;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Grade queue operations.
 */
class queue_service {
    /** @var int A row left "processing" longer than this is treated as abandoned. */
    private const PROCESSING_TIMEOUT = 1800;

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

        $existing = $DB->get_record('local_ses_grade_queue', ['idempotency_key' => $idempotency]);
        if ($existing) {
            // Still in flight: this is a genuine duplicate event, collapse it.
            if (in_array($existing->status, ['pending', 'processing', 'failed'], true)) {
                return;
            }

            // Already delivered (or given up on). The key only covers the grade
            // value, so a grade that moves away and back again lands on the same
            // key - dropping it here left the remote holding the interim value.
            $existing->status = 'pending';
            $existing->attempt_count = 0;
            $existing->next_attempt_at = time();
            $existing->last_error = null;
            $existing->payload_json = json_encode($grade['payload'] ?? []);
            $existing->updatedat = time();
            $DB->update_record('local_ses_grade_queue', $existing);
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

        self::reclaim_stale();

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
     * Return rows abandoned mid-flight (cron killed, fatal, deploy) to the queue.
     *
     * Without this a row stuck in "processing" is never picked up again by
     * claim_batch(), so the grade silently stops syncing forever.
     */
    public static function reclaim_stale(): void {
        global $DB;

        $now = time();
        $DB->execute(
            "UPDATE {local_ses_grade_queue}
                SET status = :pending, next_attempt_at = :now, updatedat = :updated
              WHERE status = :processing
                AND updatedat < :cutoff",
            [
                'pending' => 'pending',
                'now' => $now,
                'updated' => $now,
                'processing' => 'processing',
                'cutoff' => $now - self::PROCESSING_TIMEOUT,
            ]
        );
    }

    /**
     * Push a claimed row back without spending one of its retry attempts.
     *
     * Used when the failure is ours rather than the record's - an open circuit
     * says nothing about whether this grade is deliverable.
     *
     * @param stdClass $record
     * @param int $delayseconds
     * @param string $reason
     */
    public static function defer(stdClass $record, int $delayseconds, string $reason = ''): void {
        global $DB;

        $record->status = 'pending';
        $record->next_attempt_at = time() + max($delayseconds, 60);
        $record->last_error = $reason !== '' ? $reason : $record->last_error;
        $record->updatedat = time();
        $DB->update_record('local_ses_grade_queue', $record);
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

        $statuses = ['pending', 'processing', 'failed', 'dead', 'sent'];
        $result = array_fill_keys($statuses, 0);
        foreach ($statuses as $status) {
            $result[$status] = $DB->count_records('local_ses_grade_queue', ['status' => $status]);
        }
        return $result;
    }
}
