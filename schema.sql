CREATE TABLE IF NOT EXISTS apps (
    app_id      INTEGER PRIMARY KEY AUTOINCREMENT,
    created     DATETIME NOT NULL,
    created_by  INTEGER NOT NULL,
    approved    DATETIME,
    approved_by INTEGER,
    name        TEXT NOT NULL,
    extra       TEXT NOT NULL,      -- JSON
    FOREIGN KEY(created_by) REFERENCES users(user_id),
    FOREIGN KEY(approved_by) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS app_icons (
    app_id      INTEGER UNIQUE NOT NULL,
    mime_type   TEXT NOT NULL CHECK(mime_type IN ('image/png', 'image/jpeg')),
    image       BLOB NOT NULL,
    FOREIGN KEY(app_id) REFERENCES apps(app_id)
);

CREATE TABLE IF NOT EXISTS versions (
    version_id  INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id      INTEGER NOT NULL,
    created     DATETIME NOT NULL,
    created_by  INTEGER NOT NULL,
    approved    DATETIME,
    approved_by INTEGER,
    name        TEXT NOT NULL,
    extra       TEXT NOT NULL,      -- JSON
    FOREIGN KEY(app_id) REFERENCES apps(app_id),
    FOREIGN KEY(created_by) REFERENCES users(user_id),
    FOREIGN KEY(approved_by) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS reports (
    report_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    version_id  INTEGER NOT NULL,
    created     DATETIME NOT NULL,
    created_by  INTEGER NOT NULL,
    approved    DATETIME,
    approved_by INTEGER,
    rating      INTEGER NOT NULL CHECK(rating BETWEEN 0 AND 5), -- derived star count
    compatibility_state TEXT NOT NULL CHECK(compatibility_state IN (
        '?????', '*XXXX', '*????', '**XXX', '**???',
        '***XX', '***??', '****X', '****?', '*****'
    )),
    extra       TEXT NOT NULL,      -- JSON
    FOREIGN KEY(version_id) REFERENCES versions(version_id),
    FOREIGN KEY(created_by) REFERENCES users(user_id),
    FOREIGN KEY(approved_by) REFERENCES users(user_id)
);

-- separate table from reports so that SELECT * won't return many KBs of
-- unneeded data
CREATE TABLE IF NOT EXISTS report_screenshots (
    report_id   INTEGER UNIQUE NOT NULL,
    image       BLOB NOT NULL, -- always an JPEG binary blob
    FOREIGN KEY(report_id) REFERENCES reports(report_id)
);

CREATE TABLE IF NOT EXISTS users (
    user_id             INTEGER PRIMARY KEY AUTOINCREMENT,
    external_user_id    STRING UNIQUE NOT NULL, -- "service_name:xxxxxx"
    external_username   STRING NOT NULL         -- "service_name:xxxxxx"
);

CREATE TABLE IF NOT EXISTS developer_notes (
    note_id     INTEGER PRIMARY KEY AUTOINCREMENT,
    created     DATETIME NOT NULL,
    created_by  INTEGER NOT NULL,
    approved    DATETIME,
    approved_by INTEGER,
    app_id      INTEGER,
    version_id  INTEGER,
    report_id   INTEGER,
    body        TEXT NOT NULL,
    CHECK((app_id IS NOT NULL) + (version_id IS NOT NULL) + (report_id IS NOT NULL) = 1),
    FOREIGN KEY(created_by) REFERENCES users(user_id),
    FOREIGN KEY(approved_by) REFERENCES users(user_id),
    FOREIGN KEY(app_id) REFERENCES apps(app_id),
    FOREIGN KEY(version_id) REFERENCES versions(version_id),
    FOREIGN KEY(report_id) REFERENCES reports(report_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS apps_bundle_identifier_unique
ON apps(lower(json_extract(extra, '$.bundle_identifier')));

CREATE UNIQUE INDEX IF NOT EXISTS versions_build_artifact_unique
ON versions(app_id, json_extract(extra, '$.bundle_version'), lower(json_extract(extra, '$.app_artifact_sha256')));

CREATE INDEX IF NOT EXISTS reports_compatibility_state
ON reports(version_id, compatibility_state, approved);

CREATE UNIQUE INDEX IF NOT EXISTS reports_submitter_test_run_unique
ON reports(created_by, json_extract(extra, '$.test_run_id'));
