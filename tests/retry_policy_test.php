<?php
namespace local_schooleescore_bridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for retry policy.
 */
final class retry_policy_test extends \advanced_testcase {
    /**
     * Retryable statuses should be true.
     */
    public function test_retryable_statuses(): void {
        $this->assertTrue(\local_schooleescore_bridge\local\retry_policy::is_retryable(408));
        $this->assertTrue(\local_schooleescore_bridge\local\retry_policy::is_retryable(429));
        $this->assertTrue(\local_schooleescore_bridge\local\retry_policy::is_retryable(500));
        $this->assertFalse(\local_schooleescore_bridge\local\retry_policy::is_retryable(422));
    }

    /**
     * Dead letter threshold is five attempts.
     */
    public function test_dead_threshold(): void {
        $this->assertFalse(\local_schooleescore_bridge\local\retry_policy::should_mark_dead(4));
        $this->assertTrue(\local_schooleescore_bridge\local\retry_policy::should_mark_dead(5));
    }

    /**
     * Backoff must move into the future.
     */
    public function test_next_attempt_moves_forward(): void {
        $base = 1700000000;
        $next = \local_schooleescore_bridge\local\retry_policy::next_attempt_at(1, $base);
        $this->assertGreaterThan($base, $next);
    }
}
