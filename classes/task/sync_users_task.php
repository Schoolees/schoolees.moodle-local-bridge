<?php
namespace local_schooleescore_bridge\task;

use local_schooleescore_bridge\local\api_client;
use local_schooleescore_bridge\local\field_mapping;
use local_schooleescore_bridge\local\sync_log_service;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/gdlib.php');

/**
 * Pull users from SchooleesCore and map to Moodle users.
 */
class sync_users_task extends base_bridge_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_users', 'local_schooleescore_bridge');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        global $DB;

        if (!$this->acquire_lock()) {
            mtrace('local_schooleescore_bridge: sync_users_task is already running.');
            return;
        }

        try {
            $client = new api_client();
            $rows = [];
            $limit = 500;
            $offset = 0;
            do {
                $response = $client->get_json('/students', ['limit' => $limit, 'offset' => $offset]);
                if (($response['status'] ?? 0) !== 200) {
                    sync_log_service::log([
                        'job_name' => 'sync_users',
                        'entity_type' => 'user',
                        'direction' => 'pull',
                        'response_json' => $response['body'] ?? null,
                        'http_status' => $response['status'] ?? null,
                        'result' => 'failure',
                        'error_message' => 'Failed to fetch users.',
                    ]);
                    return;
                }

                $batch = $this->extract_rows($response['body'] ?? null);
                if (!empty($batch)) {
                    $rows = array_merge($rows, $batch);
                }
                $offset += $limit;
            } while (!empty($batch) && count($batch) >= $limit);

            if (empty($rows)) {
                sync_log_service::log([
                    'job_name' => 'sync_users',
                    'entity_type' => 'user',
                    'direction' => 'pull',
                    'http_status' => 200,
                    'result' => 'success',
                    'response_json' => ['processed' => 0, 'note' => 'No user rows returned from API.'],
                ]);
                return;
            }

            $processed = 0;
            $created = 0;
            $mapped = 0;
            $failed = 0;
            foreach ($rows as $user) {
                $processed++;
                $createdthis = false;
                $moodleuser = $this->find_or_create_moodle_user($user, $createdthis);
                if (!$moodleuser) {
                    $failed++;
                    continue;
                }
                if ($createdthis) {
                    $created++;
                }

                $now = time();
                $identitykey = clean_param((string)field_mapping::get_by_path(
                    $user,
                    field_mapping::cfg('map_user_username_path', 'id_number')
                ), PARAM_RAW_TRIMMED);
                $externalid = clean_param((string)field_mapping::get_by_path(
                    $user,
                    field_mapping::cfg('map_user_external_id_path', 'id')
                ), PARAM_RAW_TRIMMED);
                if ($identitykey === '') {
                    $identitykey = $externalid;
                }

                $map = $DB->get_record('local_ses_user_map', ['moodle_userid' => (int)$moodleuser->id]);
                if ($map) {
                    $map->schooleescore_user_id = $identitykey;
                    $map->schooleescore_student_no = (string)field_mapping::get_by_path(
                        $user,
                        field_mapping::cfg('map_user_idnumber_path', 'id_number')
                    );
                    $map->user_type = (string)(field_mapping::get_by_path(
                        $user,
                        field_mapping::cfg('map_user_type_path', 'user_type')
                    ) ?? 'student');
                    $map->sync_status = 'active';
                    $map->last_synced_at = $now;
                    $map->updatedat = $now;
                    $DB->update_record('local_ses_user_map', $map);
                } else {
                    $record = (object)[
                        'moodle_userid' => (int)$moodleuser->id,
                        'schooleescore_user_id' => $identitykey,
                        'schooleescore_student_no' => (string)field_mapping::get_by_path(
                            $user,
                            field_mapping::cfg('map_user_idnumber_path', 'id_number')
                        ),
                        'user_type' => (string)(field_mapping::get_by_path(
                            $user,
                            field_mapping::cfg('map_user_type_path', 'user_type')
                        ) ?? 'student'),
                        'sync_status' => 'active',
                        'last_synced_at' => $now,
                        'createdat' => $now,
                        'updatedat' => $now,
                    ];
                    $record->id = (int)$DB->insert_record('local_ses_user_map', $record);
                    $map = $record;
                }
                $mapped++;

                // Optional: profile picture sync (non-fatal).
                try {
                    $this->sync_profile_picture($moodleuser, $map, $user);
                } catch (\Throwable $e) {
                    // Keep user sync resilient; log detail separately.
                    sync_log_service::log([
                        'job_name' => 'sync_user_picture',
                        'entity_type' => 'user',
                        'entity_key' => (string)$moodleuser->id,
                        'direction' => 'pull',
                        'result' => 'failure',
                        'error_message' => 'Profile picture sync failed: ' . $e->getMessage(),
                    ]);
                }
            }

            sync_log_service::log([
                'job_name' => 'sync_users',
                'entity_type' => 'user',
                'direction' => 'pull',
                'http_status' => 200,
                'result' => $failed > 0 ? 'partial' : 'success',
                'response_json' => [
                    'processed' => $processed,
                    'created' => $created,
                    'mapped' => $mapped,
                    'failed' => $failed,
                ],
            ]);
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Find existing Moodle user by id_number/username, or create one if missing.
     *
     * @param array $user
     * @param bool $created
     * @return \stdClass|null
     */
    private function find_or_create_moodle_user(array $user, bool &$created = false): ?\stdClass {
        global $DB, $CFG;

        $email = clean_param((string)field_mapping::get_by_path(
            $user,
            field_mapping::cfg('map_user_email_path', 'email_address|email')
        ), PARAM_EMAIL);
        $usernamekey = clean_param((string)field_mapping::get_by_path(
            $user,
            field_mapping::cfg('map_user_username_path', 'id_number')
        ), PARAM_RAW_TRIMMED);
        $idnumber = clean_param((string)field_mapping::get_by_path(
            $user,
            field_mapping::cfg('map_user_idnumber_path', 'id_number')
        ), PARAM_RAW_TRIMMED);
        $externalid = clean_param((string)field_mapping::get_by_path(
            $user,
            field_mapping::cfg('map_user_external_id_path', 'id')
        ), PARAM_RAW_TRIMMED);
        $firstname = trim((string)(field_mapping::get_by_path(
            $user,
            field_mapping::cfg('map_user_firstname_path', 'first_name|firstname')
        ) ?? ''));
        $lastname = trim((string)(field_mapping::get_by_path(
            $user,
            field_mapping::cfg('map_user_lastname_path', 'last_name|lastname')
        ) ?? ''));

        $created = false;
        $moodleuser = null;

        // Primary identity key maps to Moodle username.
        if ($usernamekey !== '') {
            $moodleuser = $DB->get_record('user', ['username' => $usernamekey]);
        }
        if ($moodleuser) {
            if ((int)$moodleuser->deleted === 1) {
                $moodleuser->deleted = 0;
                $moodleuser->suspended = 0;
                $moodleuser->timemodified = time();
                $DB->update_record('user', $moodleuser);
            }

            // Keep Moodle user fields in sync with API for existing accounts.
            $this->sync_existing_user_fields($moodleuser, [
                'email' => $email,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'idnumber' => $idnumber !== '' ? $idnumber : ($usernamekey !== '' ? $usernamekey : ''),
            ]);

            return $moodleuser;
        }

        $baseusername = $usernamekey !== '' ? $usernamekey : ($idnumber !== '' ? $idnumber : ('ext_' . $externalid));
        $username = $this->unique_username($baseusername);
        if ($email === '') {
            $stub = preg_replace('/[^a-z0-9]/', '', strtolower($username));
            if ($stub === '') {
                $stub = 'student';
            }
            $email = $stub . '@schooleescore.local';
        }

        if ($firstname === '') {
            $firstname = 'Student';
        }
        if ($lastname === '') {
            $lastname = 'Unknown';
        }
        if ($firstname === '') {
            $firstname = 'Student';
        }
        if ($lastname === '') {
            $lastname = 'Unknown';
        }

        $newuser = new \stdClass();
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        $newuser->username = $username;
        $newuser->password = $this->build_initial_password($idnumber, $username, $externalid, $email);
        $newuser->firstname = $firstname;
        $newuser->lastname = $lastname;
        $newuser->email = $email;
        $newuser->idnumber = $idnumber !== '' ? $idnumber : ($usernamekey !== '' ? $usernamekey : $externalid);
        $newuser->city = '';
        $newuser->country = '';
        $newuser->timecreated = time();
        $newuser->timemodified = time();

        try {
            // Set a real Moodle password hash during user creation.
            $newuserid = user_create_user($newuser, true, false);
        } catch (\Throwable $exception) {
            $newuser->password = $this->build_policy_safe_password($idnumber, $username, $externalid, $email);
            try {
                $newuserid = user_create_user($newuser, true, false);
            } catch (\Throwable $exception2) {
                sync_log_service::log([
                    'job_name' => 'sync_users',
                    'entity_type' => 'user',
                    'entity_key' => $externalid,
                    'direction' => 'pull',
                    'result' => 'failure',
                    'error_message' => 'Failed creating Moodle user: ' . $exception2->getMessage(),
                ]);
                return null;
            }
        }
        $created = true;
        return $DB->get_record('user', ['id' => (int)$newuserid, 'deleted' => 0]);
    }

    /**
     * Update selected Moodle user fields from API values (non-destructive: skips empty inputs).
     *
     * @param \stdClass $moodleuser
     * @param array $values
     */
    private function sync_existing_user_fields(\stdClass $moodleuser, array $values): void {
        global $DB;

        $update = new \stdClass();
        $update->id = (int)$moodleuser->id;
        $dirty = false;

        $email = (string)($values['email'] ?? '');
        if ($email !== '' && $email !== (string)($moodleuser->email ?? '')) {
            $update->email = $email;
            $dirty = true;
        }
        $firstname = trim((string)($values['firstname'] ?? ''));
        if ($firstname !== '' && $firstname !== (string)($moodleuser->firstname ?? '')) {
            $update->firstname = $firstname;
            $dirty = true;
        }
        $lastname = trim((string)($values['lastname'] ?? ''));
        if ($lastname !== '' && $lastname !== (string)($moodleuser->lastname ?? '')) {
            $update->lastname = $lastname;
            $dirty = true;
        }
        $idnumber = (string)($values['idnumber'] ?? '');
        if ($idnumber !== '' && $idnumber !== (string)($moodleuser->idnumber ?? '')) {
            $update->idnumber = $idnumber;
            $dirty = true;
        }

        if (!$dirty) {
            return;
        }

        // Avoid password changes on existing users.
        user_update_user($update, false, true);

        // Refresh local copy for subsequent logic.
        $fresh = $DB->get_record('user', ['id' => (int)$moodleuser->id], '*', MUST_EXIST);
        foreach (['email', 'firstname', 'lastname', 'idnumber'] as $f) {
            if (property_exists($fresh, $f)) {
                $moodleuser->$f = $fresh->$f;
            }
        }
    }

    /**
     * Ensure username uniqueness for user creation.
     *
     * @param string $base
     * @return string
     */
    private function unique_username(string $base): string {
        global $DB;

        $candidate = clean_param($base, PARAM_USERNAME);
        if ($candidate === '') {
            $candidate = 'student';
        }

        if (!$DB->record_exists('user', ['username' => $candidate, 'deleted' => 0])) {
            return $candidate;
        }

        for ($i = 1; $i <= 9999; $i++) {
            $test = $candidate . '_' . $i;
            if (!$DB->record_exists('user', ['username' => $test, 'deleted' => 0])) {
                return $test;
            }
        }

        return $candidate . '_' . time();
    }

    /**
     * Build fallback password that usually passes strict policies.
     *
     * @param string $idnumber
     * @param string $username
     * @return string
     */
    private function build_policy_safe_password(string $idnumber, string $username, string $externalid = '', string $email = ''): string {
        return $this->build_initial_password($idnumber, $username, $externalid, $email);
    }

    /**
     * Build initial password for newly created users.
     *
     * @param string $idnumber
     * @param string $username
     * @param string $externalid
     * @param string $email
     * @return string
     */
    private function build_initial_password(string $idnumber, string $username, string $externalid = '', string $email = ''): string {
        $template = field_mapping::cfg('default_password_template', '~!@Adsco{id_number}');
        if (trim($template) === '') {
            $template = '~!@Adsco{id_number}';
        }
        return field_mapping::render_template($template, [
            'id_number' => $idnumber,
            'username' => $username,
            'external_id' => $externalid,
            'email' => $email,
        ]);
    }

    /**
     * Sync Moodle user profile picture from a URL.
     *
     * @param \stdClass $moodleuser
     * @param \stdClass $map
     * @param array $row
     */
    private function sync_profile_picture(\stdClass $moodleuser, \stdClass $map, array $row): void {
        global $DB;

        if ((int)get_config('local_schooleescore_bridge', 'sync_profile_pictures') !== 1) {
            return;
        }

        $url = trim((string)(field_mapping::get_by_path(
            $row,
            field_mapping::cfg('map_user_picture_url_path', 'profile_picture')
        ) ?? ''));
        if ($url === '') {
            sync_log_service::log([
                'job_name' => 'sync_user_picture',
                'entity_type' => 'user',
                'entity_key' => (string)$moodleuser->id,
                'direction' => 'pull',
                'result' => 'success',
                'error_message' => 'No profile picture URL found in API payload for this user (skipped).',
                'response_json' => [
                    'configured_path' => field_mapping::cfg('map_user_picture_url_path', 'profile_picture'),
                ],
            ]);
            return;
        }

        $image = $this->download_image($url);
        if (empty($image['ok'])) {
            sync_log_service::log([
                'job_name' => 'sync_user_picture',
                'entity_type' => 'user',
                'entity_key' => (string)$moodleuser->id,
                'direction' => 'pull',
                'result' => 'failure',
                'http_status' => (int)($image['http_status'] ?? 0),
                'error_message' => (string)($image['error'] ?? 'Profile picture download failed.'),
                'response_json' => [
                    'url' => $url,
                    'content_type' => $image['content_type'] ?? null,
                    'bytes' => $image['bytes_len'] ?? null,
                ],
            ]);
            return;
        }

        $hash = sha1($image['bytes']);
        $currentpicture = (int)$DB->get_field('user', 'picture', ['id' => (int)$moodleuser->id], MUST_EXIST);
        if (!empty($map->profile_picture_hash) && $map->profile_picture_hash === $hash && $currentpicture > 0) {
            // Content is the same; only update stored URL.
            $map->profile_picture_url = $url;
            $map->profile_picture_synced_at = time();
            $map->updatedat = time();
            $DB->update_record('local_ses_user_map', $map);
            sync_log_service::log([
                'job_name' => 'sync_user_picture',
                'entity_type' => 'user',
                'entity_key' => (string)$moodleuser->id,
                'direction' => 'pull',
                'result' => 'success',
                'error_message' => 'Profile picture unchanged (hash match, skipped).',
                'http_status' => (int)($image['http_status'] ?? 200),
                'response_json' => [
                    'url' => $url,
                    'content_type' => $image['content_type'] ?? null,
                    'bytes' => $image['bytes_len'] ?? null,
                    'hash' => $hash,
                    'current_user_picture' => $currentpicture,
                ],
            ]);
            return;
        }

        $context = \context_user::instance((int)$moodleuser->id, MUST_EXIST);
        $fs = get_file_storage();

        // Use the same flow as core OAuth2 picture updates: store bytes in 'newicon', then process_new_icon().
        $fs->delete_area_files($context->id, 'user', 'newicon');
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'user',
            'filearea' => 'newicon',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'image',
        ];
        $fs->create_file_from_string($filerecord, $image['bytes']);

        $iconfiles = $fs->get_area_files($context->id, 'user', 'newicon', false, 'itemid', false);
        $iconfile = reset($iconfiles);
        if (!$iconfile) {
            $fs->delete_area_files($context->id, 'user', 'newicon');
            throw new \moodle_exception('Failed to stage profile picture.');
        }

        $tmp = $iconfile->copy_content_to_temp();
        if (!$tmp) {
            $fs->delete_area_files($context->id, 'user', 'newicon');
            throw new \moodle_exception('Failed to copy staged profile picture to temp.');
        }

        $newpicture = (int)process_new_icon($context, 'user', 'icon', 0, $tmp);
        if (empty($newpicture)) {
            // Moodle core icon processing supports GIF/JPG/PNG. Try to convert other formats (e.g. WEBP) to JPG.
            $converted = $this->convert_image_to_jpg_temp($tmp);
            if ($converted) {
                $newpicture = (int)process_new_icon($context, 'user', 'icon', 0, $converted);
                @unlink($converted);
            }
        }
        @unlink($tmp);
        $fs->delete_area_files($context->id, 'user', 'newicon');

        if (empty($newpicture)) {
            sync_log_service::log([
                'job_name' => 'sync_user_picture',
                'entity_type' => 'user',
                'entity_key' => (string)$moodleuser->id,
                'direction' => 'pull',
                'result' => 'failure',
                'error_message' => 'Profile picture could not be processed (supported: JPG/PNG/GIF; attempted conversion).',
                'response_json' => [
                    'url' => $url,
                    'content_type' => $image['content_type'] ?? null,
                    'bytes' => $image['bytes_len'] ?? null,
                ],
            ]);
            return;
        }

        // Update user record using core API so Moodle stops using Gravatar fallback.
        $updateuser = (object)[
            'id' => (int)$moodleuser->id,
            'picture' => $newpicture,
        ];
        user_update_user($updateuser, false, true);
        \core_user::reset_caches();

        $persisted = (int)$DB->get_field('user', 'picture', ['id' => (int)$moodleuser->id], MUST_EXIST);
        if ($persisted !== $newpicture) {
            sync_log_service::log([
                'job_name' => 'sync_user_picture',
                'entity_type' => 'user',
                'entity_key' => (string)$moodleuser->id,
                'direction' => 'pull',
                'result' => 'failure',
                'error_message' => 'Profile picture processed but did not persist on user record.',
                'response_json' => [
                    'url' => $url,
                    'content_type' => $image['content_type'] ?? null,
                    'bytes' => $image['bytes_len'] ?? null,
                    'hash' => $hash,
                    'picture_rev_expected' => $newpicture,
                    'picture_rev_actual' => $persisted,
                ],
            ]);
            return;
        }

        // Persist sync marker.
        $map->profile_picture_url = $url;
        $map->profile_picture_hash = $hash;
        $map->profile_picture_synced_at = time();
        $map->updatedat = time();
        $DB->update_record('local_ses_user_map', $map);

        sync_log_service::log([
            'job_name' => 'sync_user_picture',
            'entity_type' => 'user',
            'entity_key' => (string)$moodleuser->id,
            'direction' => 'pull',
            'result' => 'success',
            'http_status' => (int)($image['http_status'] ?? 200),
            'response_json' => [
                'url' => $url,
                'content_type' => $image['content_type'] ?? null,
                'bytes' => $image['bytes_len'] ?? null,
                'hash' => $hash,
                'picture_rev' => $newpicture,
            ],
        ]);
    }

    /**
     * Download an image from a URL and return bytes + metadata.
     *
     * @param string $url
     * @return array
     */
    private function download_image(string $url): array {
        $curl = new \curl();
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_TIMEOUT' => 20,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];
        $bytes = $curl->get($url, [], $options);
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);
        $contenttype = (string)($info['content_type'] ?? '');
        $byteslen = ($bytes === false || $bytes === null) ? 0 : strlen((string)$bytes);

        if ($status < 200 || $status >= 300 || $bytes === false || $bytes === null) {
            return [
                'ok' => false,
                'http_status' => $status,
                'content_type' => $contenttype,
                'bytes_len' => $byteslen,
                'error' => 'Profile picture fetch failed (HTTP ' . $status . ').',
            ];
        }

        // Ensure the response is actually an image.
        if (stripos($contenttype, 'image/') !== 0) {
            return [
                'ok' => false,
                'http_status' => $status,
                'content_type' => $contenttype,
                'bytes_len' => $byteslen,
                'error' => 'Profile picture fetch returned non-image content-type: ' . $contenttype,
            ];
        }
        // Moodle icon processing does not support SVG.
        if (stripos($contenttype, 'image/svg') !== false) {
            return [
                'ok' => false,
                'http_status' => $status,
                'content_type' => $contenttype,
                'bytes_len' => $byteslen,
                'error' => 'Profile picture is SVG; Moodle icon processing does not support SVG.',
            ];
        }
        $filename = $this->guess_filename($url, $contenttype);

        // Safety: avoid huge downloads (10MB).
        if (strlen((string)$bytes) > 10 * 1024 * 1024) {
            return [
                'ok' => false,
                'http_status' => $status,
                'content_type' => $contenttype,
                'bytes_len' => $byteslen,
                'error' => 'Profile picture too large (> 10MB).',
            ];
        }

        return [
            'ok' => true,
            'bytes' => (string)$bytes,
            'filename' => $filename,
            'http_status' => $status,
            'content_type' => $contenttype,
            'bytes_len' => strlen((string)$bytes),
        ];
    }

    /**
     * Convert an image file to a JPG temp file if possible (e.g. WEBP).
     *
     * @param string $filepath
     * @return string|null Path to new temp JPG file.
     */
    private function convert_image_to_jpg_temp(string $filepath): ?string {
        if (!is_file($filepath)) {
            return null;
        }
        $bytes = @file_get_contents($filepath);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if (!$im) {
            return null;
        }
        $out = tempnam(sys_get_temp_dir(), 'sesicon_');
        if (!$out) {
            @imagedestroy($im);
            return null;
        }
        $outjpg = $out . '.jpg';
        @unlink($out);
        $ok = @imagejpeg($im, $outjpg, 90);
        @imagedestroy($im);
        if (!$ok || !is_file($outjpg)) {
            @unlink($outjpg);
            return null;
        }
        return $outjpg;
    }

    /**
     * Guess a safe filename based on URL and/or content type.
     *
     * @param string $url
     * @param string $contenttype
     * @return string
     */
    private function guess_filename(string $url, string $contenttype): string {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = $path ? basename($path) : '';
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$base);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'profile';
        }

        if (strpos($base, '.') === false) {
            $ext = 'jpg';
            $ct = strtolower($contenttype);
            if (strpos($ct, 'png') !== false) {
                $ext = 'png';
            } else if (strpos($ct, 'gif') !== false) {
                $ext = 'gif';
            } else if (strpos($ct, 'webp') !== false) {
                $ext = 'webp';
            }
            $base .= '.' . $ext;
        }

        return $base;
    }

    /**
     * Extract user rows from API response that may be paginated/resource-wrapped.
     *
     * @param mixed $body
     * @return array
     */
    private function extract_rows($body): array {
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
}
