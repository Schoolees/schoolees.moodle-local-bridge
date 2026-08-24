<?php
namespace local_schooleescore_bridge\local;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');

/**
 * SchooleesCore API client wrapper.
 */
class api_client {
    /**
     * Endpoint paths, kept here so a contract change is a one-line edit.
     *
     * SchooleesCore retired /students-enrolled in favour of /enrollments; the
     * old path 404s, which silently emptied every enrollment pull.
     */
    public const PATH_STATUS = '/status';
    public const PATH_STUDENTS = '/students';
    public const PATH_ENROLLMENTS = '/enrollments';
    public const PATH_GRADES = '/grades';
    public const PATH_COURSE_OFFERINGS = '/course-offerings';

    /** @var string */
    private $baseurl;

    /** @var bool Guard so a 401 retry cannot recurse. */
    private $reauthenticating = false;

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
     * Put endpoint JSON data.
     *
     * @param string $path
     * @param array $payload
     * @param bool $authrequired
     * @return array
     */
    public function put_json(string $path, array $payload, bool $authrequired = true): array {
        return $this->request('PUT', $this->baseurl . $path, $payload, '', $authrequired);
    }

    /**
     * Extract row arrays from supported API response wrappers.
     *
     * @param mixed $body
     * @return array
     */
    public static function extract_rows($body): array {
        if (!is_array($body)) {
            return [];
        }
        if (!empty($body['data']) && is_array($body['data'])) {
            if (array_key_exists(0, $body['data'])) {
                return $body['data'];
            }
            if (!empty($body['data']['data']) && is_array($body['data']['data'])) {
                return $body['data']['data'];
            }
        }
        return [];
    }

    /**
     * Iterate an offset-paginated collection endpoint one page at a time.
     *
     * Yields [$rows, $response] per page so callers can react to a mid-run
     * failure without first buffering the whole population in memory.
     *
     * @param string $path
     * @param array $params
     * @param int $pagesize
     * @return \Generator
     */
    public function each_page(string $path, array $params = [], int $pagesize = 500): \Generator {
        $offset = 0;
        do {
            $pageparams = $params;
            $pageparams['limit'] = $pagesize;
            $pageparams['offset'] = $offset;

            $response = $this->get_json($path, $pageparams);
            if (($response['status'] ?? 0) !== 200) {
                yield [[], $response];
                return;
            }

            $rows = self::extract_rows($response['body'] ?? null);
            yield [$rows, $response];

            $offset += $pagesize;
        } while (count($rows) >= $pagesize);
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
        } else if ($method === 'PUT') {
            $body = $curl->put($url, json_encode($payload), $options);
        } else {
            $body = $curl->get($url, [], $options);
        }
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);

        // A cached token can be revoked server side long before it expires. Drop
        // it and try once more, otherwise every job fails until the clock runs out.
        if ($status === 401 && $authrequired && !$this->reauthenticating) {
            $this->reauthenticating = true;
            try {
                $this->forget_tokens();
                return $this->request($method, $url, $payload, $idempotencykey, true);
            } finally {
                $this->reauthenticating = false;
            }
        }

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
        set_config('api_refresh_expires_at', (string)$this->to_epoch($data['refresh_token_expires_at'] ?? null),
            'local_schooleescore_bridge');
    }

    /**
     * Discard cached tokens so the next call re-runs the credential flow.
     */
    private function forget_tokens(): void {
        set_config('api_access_token', '', 'local_schooleescore_bridge');
        set_config('api_access_expires_at', '0', 'local_schooleescore_bridge');
        set_config('api_refresh_token', '', 'local_schooleescore_bridge');
        set_config('api_refresh_expires_at', '0', 'local_schooleescore_bridge');
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
