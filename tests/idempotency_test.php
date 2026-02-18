<?php
namespace local_schooleescore_bridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for idempotency helper.
 */
final class idempotency_test extends \advanced_testcase {
    /**
     * Ensure deterministic output with same inputs.
     */
    public function test_grade_key_is_deterministic(): void {
        $this->resetAfterTest();

        $first = \local_schooleescore_bridge\local\idempotency::grade_key(1, 2, 3, 'midterm', 89.12345);
        $second = \local_schooleescore_bridge\local\idempotency::grade_key(1, 2, 3, 'midterm', 89.12345);

        $this->assertSame($first, $second);
    }

    /**
     * Ensure grade changes alter key.
     */
    public function test_grade_key_changes_with_grade_value(): void {
        $this->resetAfterTest();

        $first = \local_schooleescore_bridge\local\idempotency::grade_key(1, 2, 3, 'midterm', 89.12345);
        $second = \local_schooleescore_bridge\local\idempotency::grade_key(1, 2, 3, 'midterm', 90.00000);

        $this->assertNotSame($first, $second);
    }
}
