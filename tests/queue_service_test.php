<?php
namespace local_schooleescore_bridge;

use local_schooleescore_bridge\local\queue_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for grade queue bookkeeping.
 */
final class queue_service_test extends \advanced_testcase {
    /**
     * Build the argument array enqueue_grade() expects.
     *
     * @param float|null $final
     * @return array
     */
    private function grade(?float $final): array {
        return [
            'moodle_grade_itemid' => 7,
            'moodle_userid' => 11,
            'moodle_courseid' => 3,
            'grade_raw' => $final,
            'grade_final' => $final,
            'grade_letter' => null,
            'grading_period_code' => 'default',
            'payload' => ['moodle' => []],
        ];
    }

    /**
     * A repeated event for a grade still in flight is collapsed.
     */
    public function test_duplicate_pending_event_is_collapsed(): void {
        global $DB;
        $this->resetAfterTest();

        queue_service::enqueue_grade($this->grade(90.0));
        queue_service::enqueue_grade($this->grade(90.0));

        $this->assertSame(1, $DB->count_records('local_ses_grade_queue'));
    }

    /**
     * A grade that moves away and back must be pushed again.
     *
     * The idempotency key only covers the grade value, so 90 -> 85 -> 90 lands
     * back on the first key; treating that as a duplicate left SchooleesCore
     * holding 85 forever.
     */
    public function test_returning_to_a_sent_value_requeues_it(): void {
        global $DB;
        $this->resetAfterTest();

        queue_service::enqueue_grade($this->grade(90.0));
        $first = $DB->get_record('local_ses_grade_queue', ['moodle_userid' => 11], '*', MUST_EXIST);
        queue_service::mark_sent($first);

        queue_service::enqueue_grade($this->grade(85.0));
        queue_service::enqueue_grade($this->grade(90.0));

        $reopened = $DB->get_record('local_ses_grade_queue', ['id' => $first->id], '*', MUST_EXIST);
        $this->assertSame('pending', $reopened->status);
        $this->assertSame(0, (int)$reopened->attempt_count);
        $this->assertSame(2, $DB->count_records('local_ses_grade_queue'));
    }

    /**
     * Rows abandoned mid-flight come back to the queue.
     */
    public function test_stale_processing_rows_are_reclaimed(): void {
        global $DB;
        $this->resetAfterTest();

        queue_service::enqueue_grade($this->grade(90.0));
        $record = $DB->get_record('local_ses_grade_queue', ['moodle_userid' => 11], '*', MUST_EXIST);
        $DB->set_field('local_ses_grade_queue', 'status', 'processing', ['id' => $record->id]);
        $DB->set_field('local_ses_grade_queue', 'updatedat', time() - DAYSECS, ['id' => $record->id]);

        $claimed = queue_service::claim_batch();

        $this->assertArrayHasKey($record->id, $claimed);
    }

    /**
     * Deferring does not spend a retry attempt.
     */
    public function test_defer_keeps_the_attempt_count(): void {
        global $DB;
        $this->resetAfterTest();

        queue_service::enqueue_grade($this->grade(90.0));
        $record = $DB->get_record('local_ses_grade_queue', ['moodle_userid' => 11], '*', MUST_EXIST);

        queue_service::defer($record, 300, 'circuit open');

        $deferred = $DB->get_record('local_ses_grade_queue', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$deferred->attempt_count);
        $this->assertSame('pending', $deferred->status);
        $this->assertGreaterThan(time(), (int)$deferred->next_attempt_at);
    }
}
