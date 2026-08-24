# SchooleesCore Bridge (local_schooleescore_bridge)

Moodle local plugin for integrating Moodle with SchooleesCore (and similar SIS APIs) via configurable field mapping.

Current release: **v0.2.0** (beta; `$plugin->version = 2026082500`)

## Requirements
- Moodle **5.0+**
- SchooleesCore API access (or compatible API)

## Installation
### Option A: Install from ZIP
1. Site administration → Plugins → Install plugins
2. Upload the plugin ZIP
3. Complete the upgrade

### Option B: Install by copying the folder
Copy this plugin into your Moodle codebase at:

```text
<your-moodle-root>/local/schooleescore_bridge
```

Then run the Moodle upgrade (`/admin/index.php`).

### Release packaging (for Moodle.org)
- The plugin directory name must be `schooleescore_bridge`.
- The release ZIP root must contain that `schooleescore_bridge/` directory.
- Expected install path after extraction: `<moodle-root>/local/schooleescore_bridge`.

## Features

- Settings page with API credentials, field mapping, and feature toggles.
- Data schema for user mapping, course mapping, grade queue, payment cache, and sync logs.
- Scheduled tasks for user sync, enrollment status sync, course-mapping discovery, grade queue
  dispatch, identity key migration, and log cleanup.
- Event observer on `\core\event\user_graded` that enqueues grades for passback.
- Admin pages for dashboard, mappings, queue monitor, sync history, and a connection test.
- Course-mapping auto-discovery from the identifiers SchooleesCore's own Moodle export writes.
- Webhook endpoint with HMAC signature + timestamp validation.
- Optional signed SSO entry point (`sso.php`), off by default.
- Streamed, paginated pulls, so peak memory does not grow with the size of the school.
- Grade passback that falls back to updating the remote grade when a create hits the duplicate
  constraint, rather than reporting success and dropping the update.

## SchooleesCore API contract

The endpoints and payloads this plugin depends on, as of v0.2.0:

| Purpose | Call |
| --- | --- |
| Health check | `GET /status` (unauthenticated) |
| Token | `POST /auth` `{username, password}` -> `data.token`, `data.refresh_token`, `data.expires_at` |
| Token refresh | `POST /auth/refresh-token` `{refresh_token}` |
| Students | `GET /students?limit&offset` (exact filter: `id_number`) |
| Enrollments | `GET /enrollments?student_id&academic_year_id&course_offering_id&limit&offset` |
| Course offerings | `GET /course-offerings?limit&offset` |
| Grade create | `POST /grades` |
| Grade update | `PUT /grades/{id}` |

`POST /grades` requires `grade_period_id`, `academic_year_id`, `year_level_id`, `student_id`,
`course_id` and `grade_input`; `instructor_id`, `course_offering_id` and `enrollment_id` are optional.
A duplicate is reported as HTTP 422 with `error: "Grade already exists."`.

Do not send unrelated filters alongside an exact one: the API ORs relation filters against the base
query, so adding `academic_year_id` to a `/students?id_number=` lookup widens the result instead of
narrowing it.

The API service account needs `view_student`, `view_grade`, `create_grade` and `update_grade`.

### Course mapping

SchooleesCore's `GET /course-offerings/moodle-export` produces a Moodle course-upload CSV in which
every course carries `idnumber = subject_offering:<course_offering_id>`. If those courses were
created from that export, run **Discover mappings from Moodle course ID numbers** on the mappings
page (or let `sync_course_mappings_task` do it) and no manual mapping is needed.

## Known gaps

- **Payment gating does nothing.** `local_ses_payment_cache` is only ever read, never written, because
  SchooleesCore exposes no clearance endpoint. `enable_payment_gating` is off by default and
  `sync_payment_clearance_task` is disabled; leave both alone until the endpoint exists.
- **`local_ses_bridge_config` and `local_ses_enrollment_map` are unused.** They are part of the
  original multi-tenant/course-mapped design and no code reads or writes them. They are kept rather
  than dropped so no site loses data on upgrade.
- **Grade values must satisfy the remote grading scheme.** `POST /grades` validates `grade_input`
  against the configured scheme, so a raw Moodle 0-100 point score is rejected on a site using, say,
  a 1.00-5.00 scale. Grade transformation is not implemented.

## Configuration
Go to:

Site administration → Plugins → Local plugins → SchooleesCore Bridge → Settings

Key settings:
- API base URL + credentials/token
- Field mapping (dot-paths + fallbacks)
- Default password template for created users
- Optional profile picture sync (URL field mapping)
- Optional suspension of unenrolled students

## Development

Quick syntax check:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

The unit tests are Moodle `advanced_testcase` classes, so they run through Moodle's own PHPUnit harness from an installed site (they cannot run standalone):

```bash
# from your Moodle root, with the plugin installed at local/schooleescore_bridge
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/schooleescore_bridge/tests
```

Release notes are in `CHANGELOG.md`; contribution notes are in `CONTRIBUTING.md`. The numeric `$plugin->version` in `version.php` is what drives Moodle upgrades, and the `release` string beside it is the tag.

## Privacy
This plugin transfers user/enrollment/grade data between Moodle and the configured external system.
Review your institution’s privacy policy and ensure you only sync required fields.

## Third-party libraries
This plugin does not bundle third-party PHP/JS libraries.

## License
GPL v3 or later. See `LICENSE`.

- This scaffold follows Moodle plugin standards and should be installed under `local/schooleescore_bridge`.
- External contract-specific fields can be adjusted as final API details are confirmed.
