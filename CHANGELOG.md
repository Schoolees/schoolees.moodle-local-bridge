# Changelog

This project uses Semantic Versioning for releases and tags (e.g. `v0.1.18`).
Moodle upgrades use the numeric `$plugin->version` in `version.php`.

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
