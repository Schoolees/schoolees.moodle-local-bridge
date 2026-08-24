<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Lightweight circuit breaker backed by an application cache.
 */
class circuit_breaker {
    /** @var int Rolling window used to compute the error rate. */
    private const WINDOW_SECONDS = 300;

    /** @var int How long the circuit stays open once tripped. */
    private const OPEN_SECONDS = 600;

    /** @var int Minimum samples before the error rate means anything. */
    private const MIN_SAMPLES = 10;

    /** @var float Error rate above which the circuit opens. */
    private const ERROR_RATE = 0.5;

    /**
     * Returns true when endpoint circuit is open.
     *
     * @param string $endpoint
     * @return bool
     */
    public static function is_open(string $endpoint): bool {
        $until = (int)self::cache()->get(self::open_key($endpoint));
        return $until > time();
    }

    /**
     * Seconds until the circuit closes again, or 0 when it is already closed.
     *
     * @param string $endpoint
     * @return int
     */
    public static function seconds_until_close(string $endpoint): int {
        $until = (int)self::cache()->get(self::open_key($endpoint));
        return max(0, $until - time());
    }

    /**
     * Record a success/failure outcome and open circuit when threshold is met.
     *
     * @param string $endpoint
     * @param bool $success
     */
    public static function record_result(string $endpoint, bool $success): void {
        $cache = self::cache();
        $key = self::history_key($endpoint);

        $entries = $cache->get($key);
        if (!is_array($entries)) {
            $entries = [];
        }

        $now = time();
        $entries[] = ['t' => $now, 's' => $success ? 1 : 0];

        $entries = array_values(array_filter($entries, static function($entry) use ($now): bool {
            return is_array($entry) && !empty($entry['t']) && ((int)$entry['t']) >= ($now - self::WINDOW_SECONDS);
        }));

        $cache->set($key, $entries);

        if (count($entries) < self::MIN_SAMPLES) {
            return;
        }

        $failures = 0;
        foreach ($entries as $entry) {
            if (empty($entry['s'])) {
                $failures++;
            }
        }

        if (($failures / count($entries)) > self::ERROR_RATE) {
            $cache->set(self::open_key($endpoint), $now + self::OPEN_SECONDS);
        }
    }

    /**
     * Close the circuit and forget the sample window (used by the admin UI/tests).
     *
     * @param string $endpoint
     */
    public static function reset(string $endpoint): void {
        $cache = self::cache();
        $cache->delete(self::open_key($endpoint));
        $cache->delete(self::history_key($endpoint));
    }

    /**
     * @return \cache_application|\cache_session|\cache_store
     */
    private static function cache() {
        return \cache::make('local_schooleescore_bridge', 'circuitbreaker');
    }

    /**
     * @param string $endpoint
     * @return string
     */
    private static function history_key(string $endpoint): string {
        return 'hist_' . substr(sha1($endpoint), 0, 12);
    }

    /**
     * @param string $endpoint
     * @return string
     */
    private static function open_key(string $endpoint): string {
        return 'open_' . substr(sha1($endpoint), 0, 12);
    }
}
