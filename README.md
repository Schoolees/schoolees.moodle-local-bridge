# SchooleesCore Bridge (local_schooleescore_bridge)

Moodle local plugin for integrating Moodle with SchooleesCore (and similar SIS APIs) via configurable field mapping.

Current release: **v0.1.18**

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

## Features (MVP)

- Settings page with API credentials and payment gating flag.
- Data schema for user mapping, grade queue, payment cache, and sync logs.
- Scheduled tasks for user sync, enrollment status sync (no course mapping), queue dispatch, payment clearance sync, and log cleanup.
- Event observer to enqueue grades.
- Admin pages for dashboard, mappings, queue monitor, and sync history.
- Webhook endpoint with HMAC signature + timestamp validation.
- Configurable field mapping for usernames, names, emails, enrollment status keys, and profile pictures.

## Configuration
Go to:

Site administration → Plugins → Local plugins → SchooleesCore Bridge → Settings

Key settings:
- API base URL + credentials/token
- Field mapping (dot-paths + fallbacks)
- Default password template for created users
- Optional profile picture sync (URL field mapping)
- Optional suspension of unenrolled students

## Privacy
This plugin transfers user/enrollment/grade data between Moodle and the configured external system.
Review your institution’s privacy policy and ensure you only sync required fields.

## Third-party libraries
This plugin does not bundle third-party PHP/JS libraries.

## License
GPL v3 or later. See `LICENSE`.

- This scaffold follows Moodle plugin standards and should be installed under `local/schooleescore_bridge`.
- External contract-specific fields can be adjusted as final API details are confirmed.
