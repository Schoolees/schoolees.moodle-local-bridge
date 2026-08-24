# SchooleesCore Bridge (local_schooleescore_bridge)

Moodle local plugin for integrating Moodle with SchooleesCore (and similar SIS APIs) via configurable field mapping.

Current release: **v0.1.19** (beta; `$plugin->version = 2026021901`)

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

- Settings page with API credentials and payment gating flag.
- Data schema for user mapping, grade queue, payment cache, and sync logs.
- Scheduled tasks for user sync, enrollment status sync (no course mapping), grade queue dispatch, payment clearance sync, identity key migration, and log cleanup.
- Event observer to enqueue grades.
- Admin pages for dashboard, mappings, queue monitor, and sync history.
- Webhook endpoint with HMAC signature + timestamp validation.
- Signed SSO entry point (`sso.php`): HMAC over the external user id and a timestamp, valid for five minutes.
- Configurable field mapping for usernames, names, emails, enrollment status keys, and profile pictures.
- Paginated enrollment pulls, so a population larger than one API page cannot leave valid users looking unenrolled.
- Grade passback that falls back to updating the remote grade when a create hits the duplicate constraint, rather than reporting success and dropping the update.

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

The unit tests are Moodle `advanced_testcase` classes, so they run through Moodle's own PHPUnit harness from an installed site:

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
