<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');

/**
 * SchooleesCore API client wrapper.
 */
class api_client {
    /** @var string */
    private $baseurl;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->baseurl = rtrim((string)get_config('local_schooleescore_bridge', 'api_base_url'), '/');
    }

    /**
     * Get endpoint JSON data.
     *
     * @param string $path
     * @param array $params
     * @param bool $authrequired
     * @return array
     */
    public function get_json(string $path, array $params = [], bool $authrequired = true): array {
        $url = $this->baseurl . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request('GET', $url, [], '', $authrequired);
    }

    /**
     * Post endpoint JSON data.
     *
     * @param string $path
     * @param array $payload
     * @param string $idempotencykey
     * @param bool $authrequired
     * @return array
     */
    public function post_json(string $path, array $payload, string $idempotencykey = '', bool $authrequired = true): array {
        return $this->request('POST', $this->baseurl . $path, $payload, $idempotencykey, $authrequired);
    }

    /**
     * Request helper.
     *
     * @param string $method
     * @param string $url
     * @param array $payload
     * @param string $idempotencykey
     * @param bool $authrequired
     * @return array
     */
    private function request(
        string $method,
        string $url,
        array $payload = [],
        string $idempotencykey = '',
        bool $authrequired = true
    ): array {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Bridge-Version: 1',
        ];
        if ($authrequired) {
            $headers[] = 'Authorization: Bearer ' . $this->resolve_access_token();
        }
        if ($idempotencykey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencykey;
        }

        $curl = new \curl();
        $options = [
            'CURLOPT_CUSTOMREQUEST' => $method,
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];

        if ($method === 'POST') {
            $body = $curl->post($url, json_encode($payload), $options);
        } else {
            $body = $curl->get($url, [], $options);
        }
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);
        $decoded = json_decode((string)$body, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => $body,
        ];
    }

    /**
     * Resolve access token from config or credential flow.
     *
     * @return string
     */
    private function resolve_access_token(): string {
        $username = (string)get_config('local_schooleescore_bridge', 'client_id');
        $secret = (string)get_config('local_schooleescore_bridge', 'client_secret');

        if ($username === '') {
            return $secret;
        }

        $now = time();
        $token = (string)get_config('local_schooleescore_bridge', 'api_access_token');
        $expiresat = (int)get_config('local_schooleescore_bridge', 'api_access_expires_at');
        if ($token !== '' && $expiresat > ($now + 30)) {
            return $token;
        }

        $refreshtoken = (string)get_config('local_schooleescore_bridge', 'api_refresh_token');
        $refreshexpiresat = (int)get_config('local_schooleescore_bridge', 'api_refresh_expires_at');
        if ($refreshtoken !== '' && $refreshexpiresat > ($now + 30)) {
            $refreshresponse = $this->request('POST', $this->baseurl . '/auth/refresh-token', [
                'refresh_token' => $refreshtoken,
            ], '', false);
            if (($refreshresponse['status'] ?? 0) === 200 && !empty($refreshresponse['body']['data']['token'])) {
                $this->cache_tokens($refreshresponse['body']['data']);
                return (string)$refreshresponse['body']['data']['token'];
            }
        }

        $authresponse = $this->request('POST', $this->baseurl . '/auth', [
            'username' => $username,
            'password' => $secret,
        ], '', false);
        if (($authresponse['status'] ?? 0) === 200 && !empty($authresponse['body']['data']['token'])) {
            $this->cache_tokens($authresponse['body']['data']);
            return (string)$authresponse['body']['data']['token'];
        }

        return '';
    }

    /**
     * Persist auth tokens in plugin config.
     *
     * @param array $data
     */
    private function cache_tokens(array $data): void {
        set_config('api_access_token', (string)($data['token'] ?? ''), 'local_schooleescore_bridge');
        set_config('api_access_expires_at', (string)$this->to_epoch($data['expires_at'] ?? null), 'local_schooleescore_bridge');
        set_config('api_refresh_token', (string)($data['refresh_token'] ?? ''), 'local_schooleescore_bridge');
        set_config('api_refresh_expires_at', (string)$this->to_epoch($data['refresh_token_expires_at'] ?? null), 'local_schooleescore_bridge');
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function to_epoch($value): int {
        if (empty($value)) {
            return 0;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime((string)$value);
        return $ts ? $ts : 0;
    }
}
