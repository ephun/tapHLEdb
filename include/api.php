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

// Resolve a token to the identity and trust attached to that exact credential.
// Legacy token => identity string entries remain accepted and are never trusted.
function apiAuthenticateCredential(string $token): ?array {
    if (!\defined('API_TOKENS') || !\is_array(API_TOKENS)) {
        return NULL;
    }
    $matched = NULL;
    foreach (API_TOKENS as $configuredToken => $configuration) {
        $matches = \hash_equals((string)$configuredToken, $token);
        if (!$matches || $matched !== NULL) {
            continue;
        }
        if (\is_string($configuration)) {
            $matched = ['identity' => $configuration, 'trusted' => FALSE];
        } else if (\is_array($configuration) &&
            \is_string($configuration['identity'] ?? NULL) &&
            $configuration['identity'] !== '') {
            $matched = [
                'identity' => $configuration['identity'],
                'trusted' => ($configuration['trusted'] ?? FALSE) === TRUE,
            ];
        }
    }
    return $token === '' ? NULL : $matched;
}

// Backward-compatible identity-only helper for callers that do not need trust.
function apiAuthenticate(string $token): ?string {
    $credential = apiAuthenticateCredential($token);
    return $credential === NULL ? NULL : $credential['identity'];
}

function apiValidateReportSemantics(array $extra, int $rating): void {
    $sourceType = $extra['source_type'] ?? NULL;
    if ($sourceType !== 'agent' && $sourceType !== 'telemetry') {
        throw new ApiSubmissionError('token API source_type must be agent or telemetry');
    }
    if (!validateReportRatingSource($rating, $extra)) {
        throw new ApiSubmissionError('agents and telemetry are capped at 3 stars');
    }
    if (!validateVerificationFields($extra)) {
        throw new ApiSubmissionError(
            'release_verification requires release_version X.Y.Z; compatibility must omit it'
        );
    }
}

function apiApplyTrustedApproval(
    bool $trusted,
    int $userId,
    int $appId,
    bool $appCreated,
    int $versionId,
    bool $versionCreated,
    int $reportId
): void {
    if (!$trusted) {
        return;
    }
    $app = getApp($appId);
    if ($app === NULL) {
        throw new ApiSubmissionError('app disappeared before approval');
    }
    if ($app['approved'] === NULL) {
        if (!$appCreated && (int)$app['created_by'] !== $userId) {
            throw new ApiSubmissionError('trusted credential cannot approve another submitter app');
        }
        approveApp($appId, $userId);
    }

    $version = getVersion($versionId);
    if ($version === NULL) {
        throw new ApiSubmissionError('version disappeared before approval');
    }
    if ($version['approved'] === NULL) {
        if (!$versionCreated && (int)$version['created_by'] !== $userId) {
            throw new ApiSubmissionError('trusted credential cannot approve another submitter version');
        }
        approveVersion($versionId, $userId);
    }
    approveReport($reportId, $userId);
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
        SELECT apps.app_id AS app_id, apps.name AS name, apps.extra AS extra
        FROM apps
        WHERE apps.approved IS NOT NULL
        ORDER BY apps.name ASC;
    ');
    $platformRows = query('
        SELECT versions.app_id AS app_id, reports.rating AS rating, reports.extra AS extra
        FROM reports
        JOIN versions ON versions.version_id = reports.version_id
        JOIN apps ON apps.app_id = versions.app_id
        WHERE reports.approved IS NOT NULL AND versions.approved IS NOT NULL AND apps.approved IS NOT NULL;
    ');
    $ratingsByApp = [];
    foreach ($platformRows as $row) {
        $extra = json_decode((string)$row['extra'], TRUE);
        if (\is_array($extra) &&
            ($extra['verification_type'] ?? 'compatibility') === 'release_verification') {
            continue;
        }
        $platform = \is_array($extra) && \is_string($extra['platform'] ?? NULL)
            ? $extra['platform'] : 'Windows';
        $appId = (int)$row['app_id'];
        $rating = (int)$row['rating'];
        $previous = $ratingsByApp[$appId][$platform] ?? NULL;
        if ($previous === NULL || $rating > $previous) {
            $ratingsByApp[$appId][$platform] = $rating;
        }
    }
    $apps = [];
    foreach ($rows as $row) {
        $extra = json_decode((string)$row['extra'], TRUE);
        $appId = (int)$row['app_id'];
        $platformRatings = $ratingsByApp[$appId] ?? [];
        \ksort($platformRatings);
        $apps[] = [
            'app_id' => $appId,
            'name' => (string)$row['name'],
            'rating' => $platformRatings === [] ? NULL : max($platformRatings),
            'ratings_by_platform' => $platformRatings,
            'extra' => \is_array($extra) ? $extra : [],
            'url' => SITE_BASE_PATH . '/apps/' . $appId,
        ];
    }
    return $apps;
}

function apiListReleaseVerifications(string $releaseVersion, string $commit): array {
    if (\preg_match('/\A\d+\.\d+\.\d+\z/D', $releaseVersion) !== 1 ||
        \preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
        throw new ApiSubmissionError('release and commit must be exact');
    }
    $rows = query('
        SELECT reports.report_id, reports.created, reports.rating, reports.extra AS report_extra,
               versions.version_id, versions.name AS version_name, versions.extra AS version_extra,
               apps.app_id, apps.name AS app_name, apps.extra AS app_extra,
               users.external_user_id AS submitter_identity,
               EXISTS(SELECT 1 FROM report_screenshots WHERE report_screenshots.report_id = reports.report_id) AS has_screenshot
        FROM reports
        JOIN versions ON versions.version_id = reports.version_id
        JOIN apps ON apps.app_id = versions.app_id
        JOIN users ON users.user_id = reports.created_by
        WHERE reports.approved IS NOT NULL AND versions.approved IS NOT NULL AND apps.approved IS NOT NULL
          AND json_extract(reports.extra, \'$.verification_type\') = \'release_verification\'
          AND json_extract(reports.extra, \'$.release_version\') = :release_version
          AND json_extract(reports.extra, \'$.taphle_commit\') = :commit
        ORDER BY apps.name, versions.name, reports.report_id;
    ', [':release_version' => $releaseVersion, ':commit' => $commit]);
    $result = [];
    foreach ($rows as $row) {
        $report = json_decode((string)$row['report_extra'], TRUE);
        if (!\is_array($report) ||
            ($report['verification_type'] ?? NULL) !== 'release_verification' ||
            ($report['release_version'] ?? NULL) !== $releaseVersion ||
            ($report['taphle_commit'] ?? NULL) !== $commit) {
            continue;
        }
        $app = json_decode((string)$row['app_extra'], TRUE);
        $version = json_decode((string)$row['version_extra'], TRUE);
        $result[] = [
            'report_id' => (int)$row['report_id'],
            'created' => (string)$row['created'],
            'rating' => (int)$row['rating'],
            'app_id' => (int)$row['app_id'],
            'app_name' => (string)$row['app_name'],
            'bundle_identifier' => \is_array($app) ? ($app['bundle_identifier'] ?? NULL) : NULL,
            'version_id' => (int)$row['version_id'],
            'version_name' => (string)$row['version_name'],
            'bundle_version' => \is_array($version) ? ($version['bundle_version'] ?? NULL) : NULL,
            'submitter_identity' => (string)$row['submitter_identity'],
            'source_type' => $report['source_type'] ?? NULL,
            'source_name' => $report['source_name'] ?? NULL,
            'platform' => $report['platform'] ?? NULL,
            'architecture' => $report['architecture'] ?? NULL,
            'os_version' => $report['os_version'] ?? NULL,
            'taphle_commit' => $report['taphle_commit'],
            'artifact_sha256' => $report['artifact_sha256'] ?? NULL,
            'app_artifact_sha256' => $report['app_artifact_sha256'] ?? NULL,
            'build_provenance' => $report['build_provenance'] ?? NULL,
            'build_profile' => $report['build_profile'] ?? NULL,
            'frontier' => $report['frontier'] ?? NULL,
            'has_screenshot' => (bool)$row['has_screenshot'],
        ];
    }
    return $result;
}


function apiPendingLimitExceeded(bool $trusted, int $userId, mixed $maxPending): bool {
    return !$trusted && is_int($maxPending) && $maxPending > 0 &&
        apiPendingReportCount($userId) >= $maxPending;
}

function apiPendingReportCount(int $userId): int {
    $rows = query(
        'SELECT COUNT(*) AS count FROM reports WHERE created_by = :user_id AND approved IS NULL;',
        [':user_id' => $userId]
    );
    return $rows === [] ? 0 : (int)$rows[0]['count'];
}
