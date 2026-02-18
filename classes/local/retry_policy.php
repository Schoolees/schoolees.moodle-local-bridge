<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Retry policy for outbound requests.
 */
class retry_policy {
    /** @var int[] */
    private const DELAYS = [60, 300, 900, 3600, 10800];

    /**
     * Determine if status code is retryable.
     *
     * @param int|null $status
     * @return bool
     */
    public static function is_retryable(?int $status): bool {
        if ($status === null) {
            return true;
        }
        if (in_array($status, [408, 409, 425, 429], true)) {
            return true;
        }
        return $status >= 500;
    }

    /**
     * Next attempt epoch time with jitter.
     *
     * @param int $attemptcount 1-based attempt number after failure.
     * @param int|null $now
     * @return int
     */
    public static function next_attempt_at(int $attemptcount, ?int $now = null): int {
        $now = $now ?? time();
        $index = min(max($attemptcount - 1, 0), count(self::DELAYS) - 1);
        $base = self::DELAYS[$index];
        $jitter = random_int(0, 30);
        return $now + $base + $jitter;
    }

    /**
     * @param int $attemptcount
     * @return bool
     */
    public static function should_mark_dead(int $attemptcount): bool {
        return $attemptcount >= 5;
    }
}
