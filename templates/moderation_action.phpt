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
