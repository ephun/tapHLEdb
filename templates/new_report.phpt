<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

require_once '../include/api.php';
require_once '../include/web_prefill.php';

$session = getSession();
if ($session === NULL) {
    $query = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
    $returnTo = '/reports/new' . ($query === '' ? '' : '?' . $query);
    redirect('/signin?return_to=' . rawurlencode($returnTo));
    exit;
}

// To try to avoid a flood of reports, let's rate limit users so they can't
// submit new ones until their existing ones have been approved.
if ((UNLIMITED_EXTERNAL_USER_IDS[$session['external_user_id']] ?? FALSE) === FALSE) {
    $userId = getUserId($session['external_user_id']);
    if ($userId !== NULL && userHasUnapprovedItems($userId)) {
        header('HTTP/1.1 429 Too Many Requests');
        echo 'In order to assist moderation, there is currently a limit of one unapproved report per user. As your previous report is still pending moderation, you are not able to submit a new one. Please check back later. We apologise for the inconvenience.';
        exit;
    }
}

// Every report has to be connected to a version, and every version has to be
// connected to an app. Each of these objects has to be created separately in
// the database, and each is moderated separately. If there were separate forms
// for each, submitting a report for a new app would require far too many steps!
// So the form supports three modes:
// - /reports/new               => New app, new version, new report
// - /reports/new?app=123       => Existing app, new version, new report
// - /reports/new?version=123   => Existing app, existing version, new report

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Transactions are used to make this easier to follow: it's okay if we've
    // inserted one object before we discover a problem with the next, it can
    // be rolled back.

    beginTransaction();
    $success = FALSE;

    try {
        $userId = createOrGetUserId($session['external_user_id'], $session['external_username']);

        $app = $_POST['app'] ?? NULL;
        if (is_string($app)) {
            // Existing app.
            try {
                $appId = webPrefillQueryId(['app' => $app], 'app');
            } catch (WebPrefillError $e) {
                exit400();
            }
            if (getApp($appId) === NULL) {
                // It's unlikely this would happen accidentally, so there's no
                // need for a pretty error page.
                exit400();
            }
        } else if (is_array($app)) {
            // New app.
            $app['created_by'] = $userId;
            $appExtra = $app['extra'] ?? NULL;
            $appId = is_array($appExtra) ? apiFindAppIdByIdentity($appExtra) : NULL;
            if ($appId === NULL) {
                $appId = createApp($app);
                if ($appId === NULL) {
                    exit400();
                }
            }
        } else {
            exit400();
        }

        $version = $_POST['version'] ?? NULL;
        if (is_string($version)) {
            // Existing version.
            try {
                $versionId = webPrefillQueryId(['version' => $version], 'version');
            } catch (WebPrefillError $e) {
                exit400();
            }
            $existingVersion = getVersion($versionId);
            if ($existingVersion === NULL || (int)$existingVersion['app_id'] !== $appId) {
                exit400();
            }
        } else if (is_array($version)) {
            // New version.
            $version['app_id'] = $appId;
            $version['created_by'] = $userId;
            $versionExtra = $version['extra'] ?? NULL;
            $versionId = is_array($versionExtra) ? apiFindVersionIdByBuild($appId, $versionExtra) : NULL;
            if ($versionId === NULL) {
                $versionId = createVersion($version);
                if ($versionId === NULL) {
                    exit400();
                }
            }
        } else {
            exit400();
        }

        $report = $_POST['report'] ?? NULL;
        if (is_array($report)) {
            // New report.
            $report['version_id'] = $versionId;
            $report['created_by'] = $userId;
            $report['extra'] = is_array($report['extra'] ?? NULL) ? $report['extra'] : [];
            $report['extra']['source_class'] = 'human';
            $report['extra']['source_identity'] = $session['external_user_id'];
            unset($report['extra']['source_subtype']);
            $reportId = createReport($report);
            if ($reportId === NULL) {
                exit400();
            }
        } else {
            exit400();
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
    exit;
}

try {
    $resolvedPrefill = resolveWebReportPrefill($_GET);
} catch (WebPrefillError $e) {
    if ($e->notFound) {
        show404();
    }
    exit400();
}
$appId = $resolvedPrefill['app_id'];
$appInfo = $resolvedPrefill['app_info'];
$versionId = $resolvedPrefill['version_id'];
$versionInfo = $resolvedPrefill['version_info'];
$appPrefill = $resolvedPrefill['app'];
$versionPrefill = $resolvedPrefill['version'];
$reportPrefill = $resolvedPrefill['report'];

$breadcrumbs = [];
if ($appInfo !== NULL) {
    $breadcrumbs[] = 'Apps';
    $breadcrumbs[] = $appInfo['name'];
}
if ($versionInfo !== NULL) {
    $breadcrumbs[] = 'Versions';
    $breadcrumbs[] = $versionInfo['name'];
}
$breadcrumbs[] = 'Reports';
$breadcrumbs[] = 'Submit new';

require 'header.phpt';

?>

<h2>Submit a new report</h2>

<?php if ($resolvedPrefill['present']): ?>
<p><strong>Review imported draft metadata before submitting.</strong> Prefill data is supplied by the client, remains editable for new records, and does not select a compatibility state or bypass moderation.</p>
<?php endif; ?>

<p>Thank you for choosing to contribute. Before submitting your contribution, please note that:</p>

<ul>
<li>If approved, your contribution will be <strong>public</strong>, and it will be <strong>publicly attributed to your GitHub username.</strong>
<li>Your contribution may be rejected by a moderator, especially if it does not comply with the guidelines below.
<li>By submitting a contribution, you license it under the terms of <a href="<?=htmlspecialchars(SITE_CONTENT_LICENSE_URL)?>"><?=htmlspecialchars(SITE_CONTENT_LICENSE_NAME)?></a>.
</ul>

<p><?=htmlspecialchars(GENERAL_GUIDANCE)?></p>

<form action="<?=htmlspecialchars(url('/reports/new'))?>" method=post>
<fieldset>
<legend>New compatibility report</legend>

<p><span class=required>*</span> indicates a required field.</p>
<fieldset>
<legend>App</legend>

<?php if ($appInfo !== NULL): ?>
<label>
<input type=radio disabled checked>This report is for an existing app:
<select disabled><option selected><?=htmlspecialchars($appInfo['name'])?></option></select>
<?php /* Hidden field because disabled fields aren't included in requests. */ ?>
<input type=hidden name=app value="<?=htmlspecialchars((string)$appId)?>">
</label>
<label>
<input type=radio disabled>This report is for a new app.
</label>
<?php else: ?>
<label>
<input type=radio disabled>This report is for an existing app.
</label>
<label>
<input type=radio disabled checked>This report is for a new app:
</label>
<fieldset>
<legend>New app</legend>
<p>Before submitting a report for a new app, <strong>please check the <a href="<?=htmlspecialchars(url('/apps'))?>">list of existing apps</a>.</strong></p>
<p><?=htmlspecialchars(APP_GUIDANCE)?></p>
<?php printAppForm($appPrefill); ?>
</fieldset>
<?php endif; ?>

</fieldset>

<fieldset>
<legend>Version</legend>

<?php if ($versionInfo !== NULL): ?>
<label>
<input type=radio disabled checked>This report is for an existing version:
<select disabled><option selected><?=htmlspecialchars($versionInfo['name'])?></option></select>
<?php /* Hidden field because disabled fields aren't included in requests. */ ?>
<input type=hidden name=version value="<?=htmlspecialchars((string)$versionId)?>">
</label>
<label>
<input type=radio disabled>This report is for a new version.
</label>
<?php else: ?>
<label>
<input type=radio disabled>This report is for an existing version.
</label>
<label>
<input type=radio disabled checked>This report is for a new version:
</label>
<fieldset>
<legend>New version</legend>
<?php if ($appInfo !== NULL): ?>
<p>Before submitting a report for a new version, <strong>please check the <a href="<?=htmlspecialchars(url('/apps/' . $appId))?>">list of existing versions</a>.</strong></p>
<?php endif; ?>
<p><?=htmlspecialchars(VERSION_GUIDANCE)?></p>
<?php printVersionForm($versionPrefill); ?>
</fieldset>
<?php endif; ?>

</fieldset>

<fieldset>
<legend>Report</legend>
<p><?=htmlspecialchars(REPORT_GUIDANCE)?></p>
<?php printReportForm($reportPrefill); ?>
</fieldset>

<input type=submit value="Submit report">

</fieldset>
</form>

<?php

require 'footer.phpt';
