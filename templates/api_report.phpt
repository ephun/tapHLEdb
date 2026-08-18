<?php declare(strict_types=1);

// POST /api/report — token-authenticated JSON submission of a compatibility
// report, for tapHLE telemetry and coding agents. See API.md.
//
// This is a tapHLE addition. It deliberately reuses the same model functions as
// the web form (createApp/createVersion/createReport), so submissions are
// validated identically and land unapproved for moderator review — unless the
// token's identity is listed in API_AUTO_APPROVE_IDENTITIES, which the operator
// uses for their own agents. It never touches the session: authentication is by
// bearer token only.

namespace hikari_no_yume\touchHLE\app_compatibility_db;

require_once '../include/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    apiError(405, 'method_not_allowed', 'Use POST.');
}

$externalIdentity = apiAuthenticate(apiReadToken());
if ($externalIdentity === NULL) {
    header('WWW-Authenticate: Bearer');
    apiError(401, 'unauthorized', 'Provide a valid API token.');
}
$autoApprove = apiIdentityAutoApproves($externalIdentity);

$rawBody = \file_get_contents('php://input');
if ($rawBody === FALSE) {
    apiError(400, 'bad_request', 'Could not read the request body.');
}
// Generous enough for a ~200 KB base64 screenshot, small enough to bound abuse.
if (\strlen($rawBody) > 1000000) {
    apiError(413, 'payload_too_large', 'The request body is larger than 1 MB.');
}

$body = \json_decode($rawBody, TRUE, 8);
if (!\is_array($body)) {
    apiError(400, 'bad_json', 'The request body must be a JSON object.');
}

// config.php declares no namespace, so its constants are global; checking for a
// namespaced name here would always fail and silently ignore the configured cap.
$maxPending = \defined('API_MAX_PENDING_REPORTS') ? API_MAX_PENDING_REPORTS : 200;

beginTransaction();
$success = FALSE;
$failure = NULL;
$internalError = FALSE;
$appId = NULL;
$versionId = NULL;
$reportId = NULL;
$appCreated = FALSE;
$versionCreated = FALSE;

try {
    $userId = createOrGetUserId($externalIdentity, $externalIdentity);

    // The web form's one-pending-item rule is per user and would stall a shared
    // bot account immediately, so cap pending reports per token instead.
    if (\is_int($maxPending) && $maxPending > 0 && apiPendingReportCount($userId) >= $maxPending) {
        throw new ApiSubmissionError('too_many_pending');
    }

    // --- app: an explicit id, else an existing app with the same identity,
    // --- else a new (unapproved) app.
    if (isset($body['app_id'])) {
        if (!\is_int($body['app_id'])) {
            throw new ApiSubmissionError('app_id must be an integer');
        }
        $appId = $body['app_id'];
        if (getApp($appId) === NULL) {
            throw new ApiSubmissionError('app_id does not exist');
        }
    } else {
        $app = $body['app'] ?? NULL;
        if (!\is_array($app)) {
            throw new ApiSubmissionError('provide either app_id or an app object');
        }
        $appExtra = $app['extra'] ?? [];
        if (!\is_array($appExtra)) {
            throw new ApiSubmissionError('app.extra must be an object');
        }
        $appId = apiFindAppIdByIdentity($appExtra);
        if ($appId === NULL) {
            if (!apiRequiredExtraFieldsPresent(APP_EXTRA_FIELDS, $appExtra)) {
                throw new ApiSubmissionError('app.extra is missing a required field');
            }
            $app['created_by'] = $userId;
            $appId = createApp($app);
            if ($appId === NULL) {
                throw new ApiSubmissionError('app was rejected (check name and extra fields)');
            }
            $appCreated = TRUE;
        }
    }

    // --- version: an explicit id, else an existing version of this app with the
    // --- same name, else a new (unapproved) version.
    if (isset($body['version_id'])) {
        if (!\is_int($body['version_id'])) {
            throw new ApiSubmissionError('version_id must be an integer');
        }
        $versionId = $body['version_id'];
        $version = getVersion($versionId);
        if ($version === NULL) {
            throw new ApiSubmissionError('version_id does not exist');
        }
        if (isset($version['app_id']) && (int)$version['app_id'] !== $appId) {
            throw new ApiSubmissionError('version_id belongs to a different app');
        }
    } else {
        $version = $body['version'] ?? NULL;
        if (!\is_array($version)) {
            throw new ApiSubmissionError('provide either version_id or a version object');
        }
        $versionName = $version['name'] ?? NULL;
        if (!\is_string($versionName) || $versionName === '') {
            throw new ApiSubmissionError('version.name is required');
        }
        $versionExtra = $version['extra'] ?? [];
        if (!\is_array($versionExtra)) {
            throw new ApiSubmissionError('version.extra must be an object');
        }
        $versionId = apiFindVersionIdByName($appId, $versionName);
        if ($versionId === NULL) {
            if (!apiRequiredExtraFieldsPresent(VERSION_EXTRA_FIELDS, $versionExtra)) {
                throw new ApiSubmissionError('version.extra is missing a required field');
            }
            $version['app_id'] = $appId;
            $version['created_by'] = $userId;
            $versionId = createVersion($version);
            if ($versionId === NULL) {
                throw new ApiSubmissionError('version was rejected (check name and extra fields)');
            }
            $versionCreated = TRUE;
        }
    }

    // --- report: always new, always unapproved.
    $report = $body['report'] ?? NULL;
    if (!\is_array($report)) {
        throw new ApiSubmissionError('report object is required');
    }
    $reportExtra = $report['extra'] ?? [];
    if (!\is_array($reportExtra)) {
        throw new ApiSubmissionError('report.extra must be an object');
    }
    if (!apiRequiredExtraFieldsPresent(REPORT_EXTRA_FIELDS, $reportExtra)) {
        throw new ApiSubmissionError('report.extra is missing a required field');
    }
    if (!\is_int($report['rating'] ?? NULL)) {
        throw new ApiSubmissionError('report.rating must be an integer from 1 to 5');
    }
    // createReport() treats a screenshot of '' as "none", but an absent key
    // arrives as NULL and is rejected as a malformed data URL. The web form
    // always posts an empty string from a hidden input, so it never hits that;
    // a JSON client naturally omits the key entirely. Normalise so omitting it
    // means what it obviously should.
    if (($report['screenshot'] ?? NULL) === NULL) {
        $report['screenshot'] = '';
    }
    $report['version_id'] = $versionId;
    $report['created_by'] = $userId;
    $reportId = createReport($report);
    if ($reportId === NULL) {
        throw new ApiSubmissionError('report was rejected (check rating, extra fields and screenshot)');
    }

    // An identity the operator has marked as trusted publishes on arrival.
    //
    // The report alone is not enough: /api/apps only lists an app whose app row
    // *and* version row are approved as well, so approving just the report
    // would leave the result invisible and look like the feature was broken.
    // Anything this submission created is therefore approved with it. An app or
    // version that already existed is left alone — it is not this submission's
    // to publish, and approveApp()/approveVersion() ignore an already-approved
    // row anyway.
    //
    // This is inside the transaction on purpose: a failure here rolls the whole
    // submission back rather than leaving a published report under an
    // unapproved app.
    if ($autoApprove) {
        if ($appCreated) {
            approveApp($appId, $userId);
        }
        if ($versionCreated) {
            approveVersion($versionId, $userId);
        }
        approveReport($reportId, $userId);
    }

    $success = TRUE;
} catch (ApiSubmissionError $e) {
    // Only the caller's own mistakes: every throw above builds this type, and
    // its message is safe to hand back verbatim.
    $failure = $e->getMessage();
} catch (\Throwable $e) {
    // Everything else, including the PDOException that PDO's exception mode
    // raises for any database failure. Its message can carry SQLSTATE codes and
    // schema details, so it is never shown to the caller — only a flat 500.
    $internalError = TRUE;
} finally {
    // Note: no exit() inside the try, so this always runs. A failure to close
    // the transaction (e.g. the connection died mid-request) must not escape
    // and replace the JSON error below with a blank 500.
    try {
        if ($success) {
            commitTransaction();
        } else {
            rollbackTransaction();
        }
    } catch (\Throwable $e) {
        $success = FALSE;
        $internalError = TRUE;
    }
}

if (!$success) {
    if ($internalError) {
        apiError(500, 'internal_error', 'The submission could not be stored.');
    }
    if ($failure === 'too_many_pending') {
        apiError(429, 'too_many_pending', 'This token has too many reports awaiting moderation.');
    }
    apiError(400, 'invalid_submission', (string)$failure);
}

apiRespond(201, [
    'status' => $autoApprove ? 'approved' : 'pending_moderation',
    'app_id' => $appId,
    'app_created' => $appCreated,
    'version_id' => $versionId,
    'version_created' => $versionCreated,
    'report_id' => $reportId,
]);
