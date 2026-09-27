tapHLEdb compatibility model v2 migration
==========================================

This schema is intentionally not an in-place reinterpretation. Legacy 1–5
ratings do not say whether higher levels were untested (`❓`) or tested and
failed (`❌`), so converting them would invent evidence.

Before deployment
-----------------

1. Put the site in maintenance/read-only mode and stop PHP workers that can
   write the SQLite database.
2. Copy the deployed `config.php` somewhere safe and add the new field
   definitions and `RELEASE_REQUIRED_PLATFORMS` from `config.example.php`.
3. Run the existing test suite against the exact PHP/SQLite build used in
   production.
4. Run the explicit reset from the repository root:

   ```sh
   php migrations/reset_catalog_v2.php \
     --database=/absolute/path/app_db.sqlite3 \
     --confirm-reset=RESET-ACTIVE-CATALOG
   ```

The command refuses relative/missing databases and creates three timestamped
files beside the live database: a byte-for-byte `.bak`, a readable JSON export
(binary evidence is base64 encoded), and the original rollback `.sqlite3`. It
then activates a fresh v2 catalog while retaining the `users` table. It does
not import any App, Version, Report, screenshot, note, or rating.

Repopulation and rollback
-------------------------

Repopulate only reviewed Apps/Versions through `POST /api/catalog`, including
the extracted bundle ID, build, exact app hash, and icon. These entries appear
as `❓❓❓❓❓` until an approved normal-release compatibility report exists.

To roll back: stop writers, move the v2 database aside, move the timestamped
`.pre-v2-*.sqlite3` file back to the configured database path, restore the old
code/config, then restart PHP. Keep the `.bak` and JSON export until the new
catalog has been independently verified and backed up.
