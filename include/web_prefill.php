<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

// Browser-prefill input is untrusted draft data. This parser deliberately has
// a closed allow-list and never accepts compatibility state, credentials,
// source identity/class, approval, or moderation fields.
final class WebPrefillError extends \Exception {
    public bool $notFound;

    public function __construct(string $message, bool $notFound = FALSE) {
        parent::__construct($message);
        $this->notFound = $notFound;
    }
}

function webPrefillScalar(array $input, string $key): ?string {
    if (!array_key_exists($key, $input)) {
        return NULL;
    }
    $value = $input[$key];
    if (!is_string($value) || $value === '' || !validateInputLength($value)) {
        throw new WebPrefillError("prefill value $key is invalid or too long");
    }
    return $value;
}

function webPrefillValidateConfiguredValue(string $key, string $value, array $definitions): void {
    $definition = $definitions[$key] ?? NULL;
    if (!is_array($definition)) {
        throw new WebPrefillError("prefill field $key is not configured");
    }
    if (isset($definition['options']) && !isset($definition['options'][$value])) {
        throw new WebPrefillError("prefill field $key has an unsupported value");
    }
    if (isset($definition['pattern']) &&
        (!is_string($definition['pattern']) || preg_match($definition['pattern'], $value) !== 1)) {
        throw new WebPrefillError("prefill field $key has an invalid format");
    }
}

function webPrefillSection(array $prefill, string $name, array $allowed): array {
    if (!array_key_exists($name, $prefill)) {
        return [];
    }
    if (!is_array($prefill[$name])) {
        throw new WebPrefillError("prefill section $name must be an object");
    }
    foreach ($prefill[$name] as $key => $_value) {
        if (!is_string($key) || !in_array($key, $allowed, TRUE)) {
            throw new WebPrefillError("unknown prefill field in $name");
        }
    }
    return $prefill[$name];
}

function parseWebReportPrefill(array $query): array {
    if (!array_key_exists('prefill', $query)) {
        return ['present' => FALSE, 'app' => [], 'version' => [], 'report' => []];
    }
    $prefill = $query['prefill'];
    if (!is_array($prefill) || ($prefill['v'] ?? NULL) !== '1') {
        throw new WebPrefillError('prefill[v] must be exactly 1');
    }
    foreach ($prefill as $key => $_value) {
        if (!in_array($key, ['v', 'app', 'version', 'report'], TRUE)) {
            throw new WebPrefillError('unknown prefill section');
        }
    }

    $appInput = webPrefillSection($prefill, 'app', ['bundle_identifier', 'display_name']);
    $versionInput = webPrefillSection($prefill, 'version', [
        'bundle_version', 'short_version', 'minimum_os_version', 'app_artifact_sha256',
    ]);
    $reportKeys = [
        'source_name', 'platform', 'architecture', 'os_version', 'taphle_commit',
        'artifact_sha256', 'app_artifact_sha256', 'build_provenance', 'build_profile',
        'taphle_release', 'release_channel', 'verification_type', 'release_version',
        'test_run_id', 'tested_at', 'result', 'evidence_description', 'crash_evidence',
        'visual_output', 'duration_seconds', 'execution_states', 'termination', 'logs',
        'cpu', 'gpu', 'device', 'frontier',
    ];
    $reportInput = webPrefillSection($prefill, 'report', $reportKeys);

    $app = ['extra' => []];
    $displayName = webPrefillScalar($appInput, 'display_name');
    if ($displayName !== NULL) {
        $app['name'] = $displayName;
    }
    $bundleId = webPrefillScalar($appInput, 'bundle_identifier');
    if ($bundleId !== NULL) {
        if (preg_match('/\A(?:[A-Za-z0-9-]+\.)*[A-Za-z0-9-]+\z/D', $bundleId) !== 1) {
            throw new WebPrefillError('bundle identifier has an invalid format');
        }
        webPrefillValidateConfiguredValue('bundle_identifier', $bundleId, APP_EXTRA_FIELDS);
        $app['extra']['bundle_identifier'] = $bundleId;
    }

    $version = ['extra' => []];
    foreach (['bundle_version', 'short_version', 'minimum_os_version', 'app_artifact_sha256'] as $key) {
        $value = webPrefillScalar($versionInput, $key);
        if ($value !== NULL) {
            webPrefillValidateConfiguredValue($key, $value, VERSION_EXTRA_FIELDS);
            $version['extra'][$key] = $value;
        }
    }
    if (isset($version['extra']['short_version'])) {
        $version['name'] = $version['extra']['short_version'];
    } else if (isset($version['extra']['bundle_version'])) {
        $version['name'] = $version['extra']['bundle_version'];
    }

    $report = ['extra' => []];
    foreach ($reportKeys as $key) {
        $value = webPrefillScalar($reportInput, $key);
        if ($value !== NULL) {
            webPrefillValidateConfiguredValue($key, $value, REPORT_EXTRA_FIELDS);
            $report['extra'][$key] = $value;
        }
    }
    $versionHash = $version['extra']['app_artifact_sha256'] ?? NULL;
    $reportHash = $report['extra']['app_artifact_sha256'] ?? NULL;
    if ($versionHash !== NULL && $reportHash !== NULL && $versionHash !== $reportHash) {
        throw new WebPrefillError('version and report app artifact hashes do not match');
    }
    if ($versionHash !== NULL) {
        $report['extra']['app_artifact_sha256'] = $versionHash;
    } else if ($reportHash !== NULL) {
        $version['extra']['app_artifact_sha256'] = $reportHash;
    }
    if (isset($report['extra']['tested_at']) && !validateUtcTimestamp($report['extra']['tested_at'])) {
        throw new WebPrefillError('test timestamp is not a real RFC 3339 UTC timestamp');
    }
    if (isset($report['extra']['taphle_release']) &&
        preg_match('/\A\d+\.\d+\.\d+\z/D', $report['extra']['taphle_release']) !== 1) {
        throw new WebPrefillError('tapHLE release has an invalid format');
    }
    if (isset($report['extra']['release_version']) &&
        preg_match('/\A\d+\.\d+\.\d+\z/D', $report['extra']['release_version']) !== 1) {
        throw new WebPrefillError('release version has an invalid format');
    }
    $verification = $report['extra']['verification_type'] ?? NULL;
    if ($verification === 'compatibility' && isset($report['extra']['release_version'])) {
        throw new WebPrefillError('compatibility prefill cannot include release_version');
    }
    if ($verification === 'release_verification') {
        if (($report['extra']['build_profile'] ?? 'release') !== 'release' ||
            ($report['extra']['release_channel'] ?? 'normal_release') !== 'normal_release') {
            throw new WebPrefillError('release verification requires a normal release-profile build');
        }
        if (isset($report['extra']['release_version'], $report['extra']['taphle_release']) &&
            $report['extra']['release_version'] !== $report['extra']['taphle_release']) {
            throw new WebPrefillError('release version does not match the tapHLE release');
        }
    }

    return ['present' => TRUE, 'app' => $app, 'version' => $version, 'report' => $report];
}

function webPrefillQueryId(array $query, string $key): ?int {
    if (!array_key_exists($key, $query)) {
        return NULL;
    }
    $value = $query[$key];
    if ((!is_string($value) && !is_int($value)) || preg_match('/\A[1-9]\d*\z/D', (string)$value) !== 1) {
        throw new WebPrefillError("$key must be a positive numeric ID");
    }
    return (int)$value;
}

function webPrefillExtra(array $record): array {
    $extra = json_decode((string)($record['extra'] ?? ''), TRUE);
    return is_array($extra) ? $extra : [];
}

function resolveWebReportPrefill(array $query): array {
    $draft = parseWebReportPrefill($query);
    $requestedAppId = webPrefillQueryId($query, 'app');
    $requestedVersionId = webPrefillQueryId($query, 'version');
    $appInfo = NULL;
    $versionInfo = NULL;

    if ($requestedVersionId !== NULL) {
        $versionInfo = getVersion($requestedVersionId);
        if ($versionInfo === NULL) {
            throw new WebPrefillError('version not found', TRUE);
        }
        $appInfo = getApp((int)$versionInfo['app_id']);
        if ($appInfo === NULL) {
            throw new WebPrefillError('version app not found', TRUE);
        }
        if ($requestedAppId !== NULL && $requestedAppId !== (int)$appInfo['app_id']) {
            throw new WebPrefillError('app and version IDs do not belong to the same hierarchy');
        }
    } else if ($requestedAppId !== NULL) {
        $appInfo = getApp($requestedAppId);
        if ($appInfo === NULL) {
            throw new WebPrefillError('app not found', TRUE);
        }
    } else if (isset($draft['app']['extra']['bundle_identifier'])) {
        $matchedId = apiFindAppIdByIdentity($draft['app']['extra']);
        if ($matchedId !== NULL) {
            $appInfo = getApp($matchedId);
        }
    }

    if ($appInfo !== NULL && isset($draft['app']['extra']['bundle_identifier'])) {
        $canonicalBundleId = webPrefillExtra($appInfo)['bundle_identifier'] ?? NULL;
        if (!is_string($canonicalBundleId) ||
            strcasecmp($canonicalBundleId, $draft['app']['extra']['bundle_identifier']) !== 0) {
            throw new WebPrefillError('app ID does not match the prefilled bundle identifier');
        }
    }

    if ($versionInfo === NULL && $appInfo !== NULL &&
        isset($draft['version']['extra']['bundle_version'], $draft['version']['extra']['app_artifact_sha256'])) {
        $matchedId = apiFindVersionIdByBuild((int)$appInfo['app_id'], $draft['version']['extra']);
        if ($matchedId !== NULL) {
            $versionInfo = getVersion($matchedId);
        }
    }
    if ($versionInfo !== NULL) {
        $canonical = webPrefillExtra($versionInfo);
        foreach (['bundle_version', 'app_artifact_sha256'] as $key) {
            if (isset($draft['version']['extra'][$key]) &&
                $draft['version']['extra'][$key] !== ($canonical[$key] ?? NULL)) {
                throw new WebPrefillError("version ID does not match prefilled $key");
            }
        }
        if (isset($canonical['app_artifact_sha256'])) {
            $draft['report']['extra']['app_artifact_sha256'] = $canonical['app_artifact_sha256'];
        }
    }

    return $draft + [
        'app_id' => $appInfo === NULL ? NULL : (int)$appInfo['app_id'],
        'app_info' => $appInfo,
        'version_id' => $versionInfo === NULL ? NULL : (int)$versionInfo['version_id'],
        'version_info' => $versionInfo,
    ];
}
