<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

require_once '../include/api.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    apiError(405, 'method_not_allowed', 'Use POST.');
}
$credential = apiAuthenticateCredential(apiReadToken());
if ($credential === NULL) {
    header('WWW-Authenticate: Bearer');
    apiError(401, 'unauthorized', 'Provide a valid API token.');
}
$body = json_decode((string)file_get_contents('php://input'), TRUE, 8);
if (!is_array($body)) {
    apiError(400, 'bad_json', 'The request body must be a JSON object.');
}

beginTransaction();
try {
    $userId = createOrGetUserId($credential['identity'], $credential['identity']);
    $app = $body['app'] ?? NULL;
    $version = $body['version'] ?? NULL;
    if (!is_array($app) || !is_array($version) || !is_array($app['extra'] ?? NULL) ||
        !is_array($version['extra'] ?? NULL)) {
        throw new ApiSubmissionError('app and version objects with extra objects are required');
    }
    if (!apiRequiredExtraFieldsPresent(APP_EXTRA_FIELDS, $app['extra']) ||
        !apiRequiredExtraFieldsPresent(VERSION_EXTRA_FIELDS, $version['extra'])) {
        throw new ApiSubmissionError('app or version is missing required metadata');
    }
    $appId = apiFindAppIdByIdentity($app['extra']);
    $appCreated = FALSE;
    if ($appId === NULL) {
        $app['created_by'] = $userId;
        $appId = createApp($app);
        if ($appId === NULL) {
            throw new ApiSubmissionError('app metadata or icon is invalid');
        }
        $appCreated = TRUE;
    }
    $versionId = apiFindVersionIdByBuild($appId, $version['extra'], $credential['trusted']);
    $versionCreated = FALSE;
    if ($versionId === NULL) {
        $version['app_id'] = $appId;
        $version['created_by'] = $userId;
        $versionId = createVersion($version);
        if ($versionId === NULL) {
            throw new ApiSubmissionError('version metadata is invalid');
        }
        $versionCreated = TRUE;
    }
    apiCorrectCanonicalMetadata($appId, $app, $versionId, $version, $credential['trusted']);
    if ($credential['trusted']) {
        $appRow = getApp($appId);
        $versionRow = getVersion($versionId);
        if ($appRow['approved'] === NULL && !$appCreated && (int)$appRow['created_by'] !== $userId) {
            throw new ApiSubmissionError('trusted credential cannot approve another submitter app');
        }
        if ($versionRow['approved'] === NULL && !$versionCreated && (int)$versionRow['created_by'] !== $userId) {
            throw new ApiSubmissionError('trusted credential cannot approve another submitter version');
        }
        approveApp($appId, $userId);
        approveVersion($versionId, $userId);
        if (getApp($appId)['approved'] === NULL) {
            throw new ApiSubmissionError('trusted credential cannot approve an app without an icon');
        }
    }
    commitTransaction();
} catch (ApiSubmissionError $error) {
    rollbackTransaction();
    apiError(400, 'invalid_submission', $error->getMessage());
} catch (\Throwable $error) {
    rollbackTransaction();
    apiError(500, 'internal_error', 'The catalog entry could not be stored.');
}

apiRespond(201, [
    'status'=>$credential['trusted'] ? 'approved' : 'pending_moderation',
    'app_id'=>$appId, 'app_created'=>$appCreated,
    'version_id'=>$versionId, 'version_created'=>$versionCreated,
    'compatibility_state'=>'?????',
]);
