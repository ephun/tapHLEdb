<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

$session = getSession();
if (!signedInUserIsModerator($session)) {
    show404();
}

if ($objectKind === 'app') {
    if (getApp($appId) == NULL) {
        show404();
        exit;
    }
} else if ($objectKind === 'version') {
    $versionInfo = getVersion($versionId);
    if ($versionInfo == NULL) {
        show404();
        exit;
    }
    $appId = (int)$versionInfo['app_id'];
} else if ($objectKind === 'report') {
    $reportInfo = getReport($reportId);
    if ($reportInfo == NULL) {
        show404();
        exit;
    }
    $appId = (int)$reportInfo['app_id'];
    $versionId = (int)$reportInfo['version_id'];
} else if ($objectKind === 'note') {
    $noteInfo = getDeveloperNote($noteId);
    if ($noteInfo === NULL) { show404(); exit; }
    if ($noteInfo['app_id'] !== NULL) $appId = (int)$noteInfo['app_id'];
    else if ($noteInfo['version_id'] !== NULL) $appId = (int)getVersion((int)$noteInfo['version_id'])['app_id'];
    else $appId = (int)getReport((int)$noteInfo['report_id'])['app_id'];
} else {
    throw new Error;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: POST');
    exit;
}

beginTransaction();
$success = FALSE;

try {
    if ($moderationAction === 'approve') {
        $userId = createOrGetUserId($session['external_user_id'], $session['external_username']);
        $up = (($_GET['up'] ?? '0') === '1');
        if ($objectKind === 'app') {
            approveApp($appId, $userId);
        } else if ($objectKind === 'version') {
            approveVersion($versionId, $userId);
            if ($up) {
                // These queries do nothing if the item is already approved.
                approveApp($appId, $userId);
            }
        } else if ($objectKind === 'report') {
            approveReport($reportId, $userId);
            if ($up) {
                // These queries do nothing if the item is already approved.
                approveVersion($versionId, $userId);
                approveApp($appId, $userId);
            }
        } else if ($objectKind === 'note') {
            approveDeveloperNote($noteId, $userId);
        } else {
            throw new Error;
        }
    } else if ($moderationAction === 'delete') {
        if ($objectKind === 'app') {
            deleteApp($appId);
        } else if ($objectKind === 'version') {
            deleteVersion($versionId);
        } else if ($objectKind === 'report') {
            deleteReport($reportId);
        } else if ($objectKind === 'note') {
            deleteDeveloperNote($noteId);
        } else {
            throw new Error;
        }
    } else if ($moderationAction === 'delete_screenshot') {
        if ($objectKind === 'report') {
            deleteReportScreenshot($reportId);
        } else {
            throw new Error;
        }
    } else if ($moderationAction === 'reparent') {
        if ($objectKind === 'report') {
            if (!isset($_POST['version'])) {
                throw new Error;
            }
            $versionId = (int)($_POST['version'] ?? 0);
            if (getVersion($versionId) === NULL) {
                show404();
                exit;
            }
            reparentReport($reportId, $versionId);
        } else {
            throw new Error;
        }
    } else if ($moderationAction === 'merge') {
        if ($objectKind === 'app') {
            $targetId = (int)($_POST['target_app'] ?? 0);
            if ($targetId === $appId || getApp($targetId) === NULL) exit400();
            mergeAppInto($appId, $targetId);
            $appId = $targetId;
        } else if ($objectKind === 'version') {
            $targetId = (int)($_POST['target_version'] ?? 0);
            if ($targetId === $versionId || getVersion($targetId) === NULL) exit400();
            if (!mergeVersionInto($versionId, $targetId)) exit400();
            $versionId = $targetId;
            $appId = (int)getVersion($targetId)['app_id'];
        } else {
            throw new Error;
        }
    } else {
        throw new Error;
    }

    $success = TRUE;
} finally {
    if ($success) {
        commitTransaction();
    } else {
        rollbackTransaction();
    }
}

redirect('/apps/' . $appId . '?show_unapproved=1');
