<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Lightweight circuit breaker backed by plugin config.
 */
class circuit_breaker {
    /** @var int */
    private const WINDOW_SECONDS = 300;

    /** @var int */
    private const OPEN_SECONDS = 600;

    /**
     * Returns true when endpoint circuit is open.
     *
     * @param string $endpoint
     * @return bool
     */
    public static function is_open(string $endpoint): bool {
        $key = self::open_key($endpoint);
        $until = (int)get_config('local_schooleescore_bridge', $key);
        return $until > time();
    }

    /**
     * Record a success/failure outcome and open circuit when threshold is met.
     *
     * @param string $endpoint
     * @param bool $success
     */
    public static function record_result(string $endpoint, bool $success): void {
        $key = self::history_key($endpoint);
        $raw = (string)get_config('local_schooleescore_bridge', $key);
        $entries = json_decode($raw, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        $now = time();
        $entries[] = [
            't' => $now,
            's' => $success ? 1 : 0,
        ];

        $entries = array_values(array_filter($entries, static function(array $entry) use ($now): bool {
            return !empty($entry['t']) && ((int)$entry['t']) >= ($now - self::WINDOW_SECONDS);
        }));

        set_config($key, json_encode($entries), 'local_schooleescore_bridge');

        if (count($entries) < 10) {
            return;
        }

        $failures = 0;
        foreach ($entries as $entry) {
            if (empty($entry['s'])) {
                $failures++;
            }
        }

        $errorrate = $failures / count($entries);
        if ($errorrate > 0.5) {
            set_config(self::open_key($endpoint), (string)($now + self::OPEN_SECONDS), 'local_schooleescore_bridge');
        }
    }

    /**
     * @param string $endpoint
     * @return string
     */
    private static function history_key(string $endpoint): string {
        return 'cb_hist_' . substr(sha1($endpoint), 0, 12);
    }

    /**
     * @param string $endpoint
     * @return string
     */
    private static function open_key(string $endpoint): string {
        return 'cb_open_' . substr(sha1($endpoint), 0, 12);
    }
}
