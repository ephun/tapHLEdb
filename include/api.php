<?php declare(strict_types=1);

// Helpers for the token-authenticated JSON API (see templates/api_report.phpt
// and API.md). This file is a tapHLE addition; keeping it separate from
// upstream's util.php/queries.php keeps the diff against
// hikari-no-yume/app-compatibility-db small and easy to merge.

namespace hikari_no_yume\touchHLE\app_compatibility_db;

// A submission the caller got wrong, carrying the message shown to them.
//
// This deliberately extends \Exception and not \RuntimeException: PDOException
// *is* a RuntimeException, so catching \RuntimeException to mean "the caller's
// input was invalid" would also swallow every database failure and report it to
// the caller as a 400 with the raw SQLSTATE text. Having our own type means the
// distinction cannot rot back in.
final class ApiSubmissionError extends \Exception {}

// Emit a JSON response and stop. Never leaks which token matched.
function apiRespond(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    if ($json === FALSE) {
        // Only reachable if a caller passes something unencodable (e.g. a string
        // that is not valid UTF-8). Send a valid body rather than an empty one.
        $json = '{"error":"internal_error"}';
    }
    echo $json, "\n";
    exit;
}

function apiError(int $status, string $error, string $detail = ''): void {
    $body = ['error' => $error];
    if ($detail !== '') {
        $body['detail'] = $detail;
    }
    apiRespond($status, $body);
}

// Read the bearer token from the request.
//
// nginx/PHP-FPM does not always forward the Authorization header, so an
// X-Api-Key header is accepted as well. See API.md.
function apiReadToken(): string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // Unqualified on purpose: util.php polyfills str_starts_with() in this
    // namespace for PHP 7.4, and a leading backslash would bypass the polyfill
    // and fatal there.
    if ($auth !== '' && str_starts_with($auth, 'Bearer ')) {
        return \substr($auth, 7);
    }
    return $_SERVER['HTTP_X_API_KEY'] ?? '';
}

// Resolve a token to the external identity it acts as, e.g. "telemetry:taphle".
// Uses a constant-time comparison and always checks every configured token so
// the time taken does not reveal which one matched. Returns NULL if no match.
function apiAuthenticate(string $token): ?string {
    // config.php declares no namespace, so its constants are global. A config.php
    // predating this fork's additions has no API_TOKENS at all; treat that as
    // "the API is not configured" (401) rather than letting an undefined
    // constant fatal into a blank 500.
    if (!\defined('API_TOKENS') || !\is_array(API_TOKENS)) {
        return NULL;
    }
    $matched = NULL;
    foreach (API_TOKENS as $configuredToken => $externalIdentity) {
        if (\hash_equals((string)$configuredToken, $token) && $matched === NULL) {
            $matched = (string)$externalIdentity;
        }
    }
    if ($token === '') {
        return NULL;
    }
    return $matched;
}

// Whether this identity's submissions are approved on arrival. Defaults to NO
// for everyone, including when the constant is absent: a config.php predating
// this addition must not silently start publishing without review.
function apiIdentityAutoApproves(string $externalIdentity): bool {
    if (!\defined('API_AUTO_APPROVE_IDENTITIES') ||
        !\is_array(API_AUTO_APPROVE_IDENTITIES)) {
        return FALSE;
    }
    foreach (API_AUTO_APPROVE_IDENTITIES as $configuredIdentity) {
        if ((string)$configuredIdentity === $externalIdentity) {
            return TRUE;
        }
    }
    return FALSE;
}

// validateExtraFields() only rejects a *present* required field that is empty;
// it cannot see a field that was omitted entirely. The web form enforces
// presence with HTML "required", so the API must do the same check itself.
function apiRequiredExtraFieldsPresent(array /*<array>*/ $extraFields, array $extraInput): bool {
    foreach ($extraFields as $fieldKey => $fieldInfo) {
        if (($fieldInfo['required'] ?? FALSE) !== TRUE) {
            continue;
        }
        $value = $extraInput[$fieldKey] ?? NULL;
        if (!\is_string($value) || $value === "") {
            return FALSE;
        }
    }
    return TRUE;
}

// Find an existing app by the configured identity field (e.g. the app's
// CFBundleIdentifier), so repeated telemetry does not create duplicate apps.
// Returns NULL when there is no identity field configured or no match.
function apiFindAppIdByIdentity(array $extra): ?int {
    // config.php declares no namespace, so its constants are global. Checking
    // for a namespaced name here would always fail and silently disable dedup.
    $identityField = \defined('APP_IDENTITY_FIELD') ? APP_IDENTITY_FIELD : NULL;
    if (!\is_string($identityField) || $identityField === '') {
        return NULL;
    }
    // Only allow a key that is actually a configured app field, so the
    // json_extract path can never be attacker-controlled.
    if (!isset(APP_EXTRA_FIELDS[$identityField])) {
        return NULL;
    }
    $value = $extra[$identityField] ?? NULL;
    if (!\is_string($value) || $value === '') {
        return NULL;
    }
    try {
        $rows = query(
            'SELECT app_id FROM apps WHERE json_extract(extra, :path) = :value ORDER BY app_id LIMIT 1;',
            [':path' => '$."' . $identityField . '"', ':value' => $value]
        );
    } catch (\Throwable $e) {
        // json_extract needs SQLite's JSON1 extension. If this build lacks it,
        // degrade to "no match" (a new app is created) rather than failing the
        // whole submission; duplicates are a moderation problem, not data loss.
        return NULL;
    }
    if ($rows === []) {
        return NULL;
    }
    return (int)$rows[0]['app_id'];
}

// Find an existing version of an app by its name (e.g. "1.3.5").
function apiFindVersionIdByName(int $appId, string $name): ?int {
    if ($name === '') {
        return NULL;
    }
    $rows = query(
        'SELECT version_id FROM versions WHERE app_id = :app_id AND name = :name ORDER BY version_id LIMIT 1;',
        [':app_id' => $appId, ':name' => $name]
    );
    if ($rows === []) {
        return NULL;
    }
    return (int)$rows[0]['version_id'];
}

// The public app list, for GET /api/apps: one row per approved app with its best
// approved rating, or NULL when it has no approved report yet.
//
// This is the read half of the agent workflow. It answers "which apps are worst
// off" and "which apps exist at all", so an agent can pick work without being
// told what to work on. It deliberately exposes only what the public web page
// already shows: approved rows only, no reports, no submitter identities, and no
// frontier — where an app stops is the app note's job, not the database's.
function apiListApps(): array {
    $rows = query('
        SELECT
            apps.app_id AS app_id,
            apps.name AS name,
            apps.extra AS extra,
            MAX(reports.rating) AS best_rating
        FROM
            apps
        LEFT JOIN
                versions
            ON
                versions.app_id = apps.app_id AND versions.approved IS NOT NULL
        LEFT JOIN
                reports
            ON
                reports.version_id = versions.version_id AND
                reports.approved IS NOT NULL
        WHERE
            apps.approved IS NOT NULL
        GROUP BY
            apps.app_id
        ORDER BY
            apps.name ASC
        ;
    ');

    $apps = [];
    foreach ($rows as $row) {
        $extra = json_decode((string)$row['extra'], TRUE);
        $apps[] = [
            'app_id' => (int)$row['app_id'],
            'name' => (string)$row['name'],
            'rating' => $row['best_rating'] === NULL ? NULL : (int)$row['best_rating'],
            'extra' => \is_array($extra) ? $extra : [],
            'url' => SITE_BASE_PATH . '/apps/' . (int)$row['app_id'],
        ];
    }
    return $apps;
}

// Abuse guard. The web form's one-pending-item rule is per user and would stall
// a shared bot account after its first submission, so the API instead caps how
// many unapproved reports a single token may have waiting for moderation.
function apiPendingReportCount(int $userId): int {
    $rows = query(
        'SELECT COUNT(*) AS count FROM reports WHERE created_by = :user_id AND approved IS NULL;',
        [':user_id' => $userId]
    );
    if ($rows === []) {
        return 0;
    }
    return (int)$rows[0]['count'];
}
