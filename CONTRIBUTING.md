# Contributing

## Repo Layout
This repository is the plugin directory.

Expected install path in Moodle:

`<moodle-root>/local/schooleescore_bridge`

## Release Packaging (Moodle Plugins Directory)
When packaging for Moodle.org:
- The plugin directory name must be `schooleescore_bridge`.
- The release ZIP root must contain the `schooleescore_bridge/` directory (not `local_schooleescore_bridge/`).
- Expected install path after extraction: `<moodle-root>/local/schooleescore_bridge`.

## Development Notes
- Keep secrets out of the repository.
- Prefer Moodle core APIs (`user_create_user`, `user_update_user`, lock API, scheduled tasks).
- Add new settings via `settings.php` + language strings in `lang/en/local_schooleescore_bridge.php`.

## Testing
- PHP lint:
  - `find . -name '*.php' -print0 | xargs -0 -n1 php -l`
- Run Moodle scheduled tasks in your environment and confirm results in Sync history.

