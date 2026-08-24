# Changelog

This project uses Semantic Versioning for releases and tags (e.g. `v0.1.18`).
Moodle upgrades use the numeric `$plugin->version` in `version.php`.

## v0.2.0
Realignment with the current SchooleesCore API, plus the fixes that fell out of it.

### Fixed - blocking
- `classes/privacy/provider.php` did not implement `delete_data_for_all_users_in_context()`, which
  `\core_privacy\local\request\plugin\provider` requires. The class was therefore fatal to load.
- `db/events.php` subscribed to `\core\event\grade_updated`, which does not exist in Moodle core.
  The observer never ran, so the grade queue never filled and nothing was ever passed back.
  Now subscribes to `\core\event\user_graded`.

### Fixed - API contract drift
- `/students-enrolled` was retired by SchooleesCore in favour of `/enrollments`. Enrollment sync,
  grade payload building and the connection test all called the dead path.
- Enrollment statuses are now `enrolled` / `dropped` / `withdrawn`; the default active value was still
  `ongoing`. Combined with `suspend_unenrolled_students` (on by default) this suspended every mapped
  student. The upgrade rewrites the stale value.
- `/grades` payloads used `subject_id`, `teacher_id` and `strands_id`. The current contract is
  `course_id` (required), `instructor_id`, and optionally `course_offering_id` / `enrollment_id`.
- `student_id` was produced by casting the identity map key to an int. That key has held an
  *id_number* since v0.1.3, so `(int)"2026-00123"` sent grades to student 2026. The remote numeric id
  is now stored in `local_ses_user_map.schooleescore_external_id` and resolved via `/students?id_number=`.

### Added
- Course-mapping auto-discovery. SchooleesCore's Moodle course export stamps every course with
  `idnumber = subject_offering:<id>`; the new `sync_course_mappings_task` and the button on the
  mappings page read `/course-offerings` and map matching Moodle courses with no manual entry.
- `default_grade_period_id` setting. The dispatcher already read it but it had no UI, so the only way
  to set a grade period was the misnamed `default_grade_category_id`.
- `enable_sso` setting; `sso.php` is now off unless explicitly enabled.
- `/grades` reachability is included in the connection test.

### Fixed - security
- `webhook.php` accepted requests when no webhook secret was configured: with an empty key the
  expected HMAC is computable by anyone. It now refuses with 503. It also runs with
  `NO_MOODLE_COOKIES`, requires a non-empty signature header, and no longer leaks a Moodle HTML error
  page when a replay targets an unknown queue id.
- `sso.php` had the same empty-secret hole while logging users in, and is now additionally gated on
  `enable_sso`, rejects guest/unconfirmed accounts, and 401s instead of raising a raw DML exception.
- `logs.php` and `queue.php` rendered remote error text through `html_writer::table`, which does not
  escape cell contents - stored XSS in the admin views. Both are escaped now.
- `local/schooleescore_bridge:viewlogs` was declared `RISK_DATALOSS`; it is `RISK_PERSONAL`.

### Fixed - correctness
- `sync_enrollments_dry_run` has existed since v0.1.0 and was never read. The task suspended users
  while the setting claimed nothing was applied. It is now honoured.
- Enrollment sync refuses to apply suspensions when the feed returns zero rows, which is far more
  likely to be a permission or filter problem than a school with no enrollments.
- A grade that moves away from a value and back (90 -> 85 -> 90) hashed to an already-used
  idempotency key and was silently dropped, leaving SchooleesCore on the interim value.
- Queue rows abandoned mid-flight stayed `processing` forever and were never reclaimed.
- An open circuit consumed one of a row's five retry attempts, so five outages killed a healthy grade.
  Deferring no longer spends an attempt.
- `user_create_user()` failures retried with byte-identical data (the "policy safe" password was the
  same string). The retry now resolves the realistic cause, a duplicate email address.
- The Moodle user lookup was not scoped to the local MNet host, so it could match and rewrite a
  remote account.
- A duplicate identity key aborted the whole user sync run; it now fails that row only.
- Suspending a user no longer leaves their session open.
- The "Enabled" checkbox on the mappings page could never be turned off - an unchecked box is absent
  from the POST and the default was 1.
- `mappings.php` was not registered in `settings.php`, so nothing linked to it.

### Changed
- The circuit breaker moved from plugin config to an application cache (`db/caches.php`). It was
  writing a config row on every outbound request, purging Moodle's site-wide config cache each time.
- User and enrollment syncs stream API pages instead of buffering the whole population in memory.
- The API client retries once after a 401 with a cached token, instead of failing until expiry.
- `sync_payment_clearance_task` is disabled by default and no longer writes a failure row every 30
  minutes; SchooleesCore still exposes no clearance endpoint.
- Mappings can be deleted from the UI, and show their course offering id.

## v0.1.18
- Moodle Plugins Directory prep:
  - Added `LICENSE`, `CHANGELOG.md`, `CONTRIBUTING.md`, `pix/icon.svg`.
  - Updated `README.md` with correct install path + packaging notes.
  - Tightened `version.php` metadata (`supported`, `requires`, maturity/release).
- Profile picture sync:
  - Do not skip picture processing when `mdl_user.picture = 0` even if hash matches (forces first-time application).
- Field mapping + password template support (see earlier versions below) remains unchanged.

## v0.1.17
- Profile picture sync logging:
  - Always writes a `sync_user_picture` log row even when skipped (missing URL or hash match), to make debugging possible.

## v0.1.16
- Profile picture sync debugging:
  - `download_image()` now returns structured failure info and logs `http_status`, `content_type`, and byte size on failures.

## v0.1.15
- Profile picture sync robustness:
  - Added a verification step that re-reads `mdl_user.picture` after update and logs a failure if it did not persist.

## v0.1.14
- Moodle 5 hook migration:
  - Migrated legacy callback `before_http_headers` to hook subscription for `core\hook\output\before_http_headers` via `db/hooks.php`.

## v0.1.13
- Profile picture sync improvements:
  - Adds success logging for `sync_user_picture` including URL, content-type, bytes, hash, and picture revision.
  - Adds conversion fallback to JPG when Moodle cannot process the original format (e.g. WEBP).

## v0.1.12
- Profile picture sync fix:
  - Updates user picture using `user_update_user()` (instead of direct DB update) to ensure Moodle recognises a custom picture.

## v0.1.11
- Profile picture sync hardening:
  - Validates content-type is `image/*` before attempting to process.
  - Resets user caches after updating picture.

## v0.1.10
- Enrollment status sync:
  - Added setting `suspend_unenrolled_students` to control whether unenrolled students are suspended in Moodle.

## v0.1.9
- User sync enhancements:
  - Existing Moodle users now have selected fields updated from the API (email/firstname/lastname/idnumber) without changing passwords.

## v0.1.8
- Profile picture sync behaviour:
  - Re-downloads the profile picture even when the URL is unchanged (supports overwritten S3 objects at the same URL).

## v0.1.7
- Profile picture sync implementation:
  - Switched to Moodle’s `newicon` + `process_new_icon()` flow (more reliable in cron/task context).

## v0.1.6
- Profile picture sync (initial):
  - Added optional profile picture sync from a URL field (default `profile_picture`).
  - Added fields to `local_ses_user_map` to track last picture URL/hash/sync time.

## v0.1.5
- Generalised mapping:
  - Added configurable field mapping using dot-paths (with `|` fallbacks) for usernames, names, emails, and enrollment keys.
  - Added configurable default password template (placeholders like `{id_number}`, `{username}`, `{external_id}`, `{email}`).

## v0.1.4
- User creation fix:
  - Ensured new Moodle users get a real password hash at creation time.

## v0.1.3
- User matching change:
  - Stopped matching existing users by email; match/create is driven by the configured username key (default `id_number`).

## v0.1.2
- One-time migration tooling:
  - Added scheduled migration task to remap identity keys in `local_ses_user_map` (from API `id` to `id_number`).

## v0.1.1
- API alignment + stability fixes:
  - Aligned endpoints to local SchooleesCore references (`/status`, `/auth`, `/auth/refresh-token`, `/students`, `/students-enrolled`, `/grades`).
  - Fixed Moodle HTTP client usage by using `\curl` from `filelib.php`.
  - Simplified enrollment sync to status-only mode (no course mapping) and added admin safety guard (do not suspend site admins).

## v0.1.0
- Initial MVP scaffold:
  - Local plugin skeleton, DB schema, capabilities, admin settings, and scheduled tasks.
  - Connection test, sync history, and operational logging.
