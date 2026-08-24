<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

// Set this first thing, in case an error happens while loading the includes.
ini_set('display_errors', '0');

// See getSession() remarks in util.php.
ini_set('session.auto_start', '0');

// Everything is more annoying without output buffering.
ob_start();

require_once '../config.php';

require_once '../include/util.php';
require_once '../include/queries.php';
require_once '../include/oauth.php';

initDb();

$path = explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0];
// Strip SITE_BASE_PATH so routes below always match against the app-relative
// path, regardless of whether the app is served at the domain root or a subpath.
// The match is anchored to a path boundary: with a base of '/compat', a request
// for '/compatibility/apps/1' is a different tree, not this app's '/ibility/…'.
if (SITE_BASE_PATH !== '' &&
    ($path === SITE_BASE_PATH || str_starts_with($path, SITE_BASE_PATH . '/'))) {
    $path = substr($path, strlen(SITE_BASE_PATH));
}
if ($path === '') {
    $path = '/';
}
if (!str_ends_with($path, '/')) {
    $path .= '/';
}

if ($path === '/') {
    require '../templates/home.phpt';
} else if ($path === '/apps/' || $path === '/versions/'|| $path === '/reports/') {
    // These might be their own sections at some point.
    redirect('/');
} else if (preg_match('#^/apps/(\d+)/$#', $path, $matches) === 1) {
    $appId = (int)$matches[1];
    require '../templates/app.phpt';
} else if (preg_match('#^/apps/(\d+)/approve/$#', $path, $matches) === 1) {
    $appId = (int)$matches[1];
    $objectKind = 'app';
    $moderationAction = 'approve';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/apps/(\d+)/delete/$#', $path, $matches) === 1) {
    $appId = (int)$matches[1];
    $objectKind = 'app';
    $moderationAction = 'delete';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/versions/(\d+)/approve/$#', $path, $matches) === 1) {
    $versionId = (int)$matches[1];
    $objectKind = 'version';
    $moderationAction = 'approve';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/versions/(\d+)/delete/$#', $path, $matches) === 1) {
    $versionId = (int)$matches[1];
    $objectKind = 'version';
    $moderationAction = 'delete';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/reports/(\d+)/approve/$#', $path, $matches) === 1) {
    $reportId = (int)$matches[1];
    $objectKind = 'report';
    $moderationAction = 'approve';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/reports/(\d+)/delete/$#', $path, $matches) === 1) {
    $reportId = (int)$matches[1];
    $objectKind = 'report';
    $moderationAction = 'delete';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/reports/(\d+)/reparent/$#', $path, $matches) === 1) {
    $reportId = (int)$matches[1];
    $objectKind = 'report';
    $moderationAction = 'reparent';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/reports/(\d+)/screenshot/delete/$#', $path, $matches) === 1) {
    $reportId = (int)$matches[1];
    $objectKind = 'report';
    $moderationAction = 'delete_screenshot';
    require '../templates/moderation_action.phpt';
} else if (preg_match('#^/reports/(\d+)/screenshot/$#', $path, $matches) === 1) {
    $reportId = (int)$matches[1];
    require '../templates/report_screenshot.phpt';
} else if ($path === '/reports/new/') {
    require '../templates/new_report.phpt';
} else if ($path === '/signin/') {
    require '../templates/signin.phpt';
} else if ($path === '/signin/github-oauth-callback/') {
    require '../templates/github_oauth_callback.phpt';
} else if ($path === '/signout/') {
    require '../templates/signout.phpt';
} else if ($path === '/api/report/') {
    // tapHLE addition: token-authenticated JSON report submission (API.md).
    require '../templates/api_report.phpt';
} else if ($path === '/api/apps/') {
    // tapHLE addition: public JSON app list, no credential needed (API.md).
    require '../templates/api_apps.phpt';
} else if ($path === '/api/release-verifications/') {
    // Approved release reconfirmations for an exact release and commit.
    require '../templates/api_release_verifications.phpt';
} else {
    show404();
}
