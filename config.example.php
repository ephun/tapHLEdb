<?php

// tapHLE app compatibility database configuration.
//
// Copy this file to config.php and fill in the GitHub OAuth secrets. config.php
// is git-ignored so real secrets never enter version control. Most fields are
// data-driven from this file; see the field format documented below.

// Human-readable name of the site, as plain text
const SITE_NAME = 'tapHLE app compatibility database';

// tapHLE's main site, linked at the top of every page. Set to NULL to hide.
const PARENT_SITE_NAME = 'tapHLE';
const PARENT_SITE_URL = 'https://github.com/ephun/tapHLE';

// URL of the privacy policy of the site (may be external).
// Make sure this doesn't contradict templates/signin.phpt!
// See privacy.example.html for an example of what this might look like.
const SITE_PRIVACY_POLICY = "/privacy.html";

// Path to the SQLite 3 database, relative to the htdocs directory
const SITE_DB_PATH = '../app_db.sqlite3';

// Base path this app is mounted under, no trailing slash (e.g. '/compatibility').
// Leave as '' when serving from the domain root. When set, the app strips this
// prefix from incoming request paths and prefixes it back onto all internal
// URLs it emits. Register your GitHub OAuth callback at
// https://<domain><SITE_BASE_PATH>/signin/github-oauth-callback to match, and
// update any external API clients to POST to <SITE_BASE_PATH>/api/report.
const SITE_BASE_PATH = '';

// Plain-text name and URL for the license that contributions are made under.
// Don't change this once contributions have been made! There is no tracking for
// license changes, so the wrong license will be displayed next to old
// contributions, which is a license violation in and of itself!
// CC BY 4.0 matches touchHLE's app database and keeps the data freely reusable
// with attribution.
const SITE_CONTENT_LICENSE_NAME = 'CC BY 4.0 International';
const SITE_CONTENT_LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';

// Compatibility ratings used in reports (1 to 5, larger is better). These match
// tapHLE's rating scale in the main repository's compatibility documentation.
const RATINGS = [
    1 => [
        'symbol' => '⭐️',
        'description' => 'Broken — the app does not reach usable content (e.g. it crashes before or during launch).',
    ],
    2 => [
        'symbol' => '⭐️⭐️',
        'description' => 'Starts — an intro or menu works, but gameplay does not.',
    ],
    3 => [
        'symbol' => '⭐️⭐️⭐️',
        'description' => 'In game — some gameplay works, but major problems remain.',
    ],
    4 => [
        'symbol' => '⭐️⭐️⭐️⭐️',
        'description' => 'Playable — the whole app can be used, with only small problems.',
    ],
    5 => [
        'symbol' => '⭐️⭐️⭐️⭐️⭐️',
        'description' => 'Fully working — everything important works.',
    ],
];

// Plain text shown when submitting a new app, report or version.
const GENERAL_GUIDANCE = "Every rating must come from an actual visible tapHLE run on the named host using the exact app and product hashes. Do not link to pirated content. Coding agents may confirm up to 3 stars; 4 and 5 require human testing.";

// Additional fields are stored in the JSON blob columns in the DB.
// Format: 'key' => ['name' => 'Human name', 'required' => TRUE?, 'options' => [...]?, 'at_end' => TRUE?].
// Fields with 'options' become multiple-choice; the option keys are stored.

// App-level identity comes from the app's own Info.plist (CFBundleIdentifier is
// the true identity; the app's display name is the built-in `name` field).
const APP_EXTRA_FIELDS = [
    'bundle_identifier' => [
        'name' => 'Bundle identifier',
        'required' => TRUE,
    ],
    'developer_publisher' => [
        'name' => 'Developer / publisher',
    ],
    'release_year' => [
        'name' => 'Release year',
    ],
];

const APP_GUIDANCE = "Name the app by its display name. The bundle identifier (e.g. com.example.game) is its true identity and comes from the app's Info.plist.";

// Which APP_EXTRA_FIELDS key is the app's true identity. The /api/report
// endpoint uses it to attach a submission to the existing app with the same
// identity instead of creating a duplicate, so repeated telemetry from the same
// app does not fill the database with copies. Set to NULL to disable that
// matching (every API submission without an explicit app_id then creates a new
// app). The display name is deliberately not used: two apps can share one.
const APP_IDENTITY_FIELD = 'bundle_identifier';

// Version identity, also from Info.plist. The built-in `name` field holds the
// user-facing version label (e.g. \"1.3.5\").
const VERSION_EXTRA_FIELDS = [
    'bundle_version' => [
        'name' => 'Bundle version (CFBundleVersion)',
        'required' => TRUE,
    ],
    'short_version' => [
        'name' => 'Short version (CFBundleShortVersionString)',
    ],
    'minimum_os_version' => [
        'name' => 'Minimum OS version',
    ],
];

const VERSION_GUIDANCE = "";

// Reports carry the complete host, product, app and producer provenance. Legacy
// rows remain readable because these fields live in the existing JSON extra
// column; the requirements apply to new submissions only.
const REPORT_EXTRA_FIELDS = [
    'source_type' => [
        'name' => 'Result producer',
        'required' => TRUE,
        'options' => ['human' => 'Human tester', 'agent' => 'Coding agent', 'telemetry' => 'Automatic telemetry'],
    ],
    'source_name' => ['name' => 'Producer name', 'required' => TRUE],
    'platform' => [
        'name' => 'Host platform',
        'required' => TRUE,
        'options' => ['Windows'=>'Windows','Linux'=>'Linux','macOS'=>'macOS','Android'=>'Android','iOS'=>'iOS'],
    ],
    'architecture' => ['name' => 'Host architecture', 'required' => TRUE],
    'os_version' => ['name' => 'Host OS version', 'required' => TRUE],
    'taphle_commit' => [
        'name' => 'Full tapHLE commit',
        'required' => TRUE,
        'pattern' => '/\A[0-9a-f]{40}\z/D',
    ],
    'artifact_sha256' => [
        'name' => 'Tested product SHA-256',
        'required' => TRUE,
        'pattern' => '/\A[0-9a-f]{64}\z/D',
    ],
    'app_artifact_sha256' => [
        'name' => 'Tested app artifact SHA-256',
        'required' => TRUE,
        'pattern' => '/\A[0-9a-f]{64}\z/D',
    ],
    'build_provenance' => ['name' => 'Build provenance', 'required' => TRUE],
    'build_profile' => [
        'name' => 'Build profile',
        'required' => TRUE,
        'options' => ['debug'=>'Debug','release'=>'Release'],
    ],
    'verification_type' => [
        'name' => 'Verification type',
        'required' => TRUE,
        'options' => ['compatibility'=>'Compatibility rating','release_verification'=>'Release reconfirmation'],
    ],
    'release_version' => ['name' => 'Release version (release reconfirmations only)'],
    'cpu' => ['name' => 'CPU'],
    'gpu' => ['name' => 'GPU'],
    'frontier' => ['name' => 'Current frontier (where it stops)', 'at_end' => TRUE],
];

const REPORT_GUIDANCE = "Record the exact visible run and complete provenance. Use compatibility only for rating history; use release reconfirmation only to qualify a named candidate without inventing another rating boundary.";

// Whether to allow attaching a screenshot to a report (JPEG, <=640px, ~150KB).
const REPORT_SCREENSHOTS_ALLOWED = TRUE;

// Moderators empowered to approve and delete reports. Format "github:<numeric
// user id>" (NOT the username). Find an id at https://api.github.com/users/<username>.
const MODERATOR_EXTERNAL_USER_IDS = [
    "github:48892512" => TRUE, // ephun
];

// Users exempt from the "one unapproved report at a time" limit.
const UNLIMITED_EXTERNAL_USER_IDS = [
    "github:48892512" => TRUE, // ephun
];

// GitHub OAuth keys. Register at https://github.com/settings/applications/new
// with callback "https://<your domain>/signin/github-oauth-callback". Use
// SEPARATE apps for testing and production. Never commit real values.
const GITHUB_CLIENT_ID = "REPLACE_WITH_GITHUB_CLIENT_ID";
const GITHUB_CLIENT_SECRET = "REPLACE_WITH_GITHUB_CLIENT_SECRET";

// API tokens for programmatic report submission. A legacy token => identity
// string remains accepted and always lands pending. New entries may be structured
// with an exact identity and a per-credential trusted flag. Only credentials the
// operator controls may set trusted=TRUE; such a token is a publish credential.
const API_TOKENS = [
    // 'REPLACE_WITH_A_LONG_RANDOM_TOKEN' => 'telemetry:taphle',
    // 'REPLACE_WITH_A_DIFFERENT_LONG_RANDOM_TOKEN' => [
    //     'identity' => 'agent:taphle-lead',
    //     'trusted' => TRUE,
    // ],
];

// How many unapproved reports one API token may have awaiting moderation before
// further submissions are refused with HTTP 429. The web form's stricter
// "one pending item per user" rule is not used for the API, because every
// submission from a token shares a single account and would stall immediately.
// Set to 0 to disable the cap.
const API_MAX_PENDING_REPORTS = 200;

// User-Agent header used when authenticating with the GitHub API.
const USER_AGENT = "tapHLE app compatibility database (https://taphle.ephun.net)";
