<?php declare(strict_types=1);

// GET /api/apps — the public app list as JSON, for agents choosing what to work
// on. See API.md.
//
// This is a tapHLE addition. It needs no credential because it returns strictly
// what the public web page already shows: approved apps and their best approved
// rating. Nothing unapproved, no reports, no submitter identities.

namespace hikari_no_yume\touchHLE\app_compatibility_db;

require_once '../include/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    header('Allow: GET');
    apiError(405, 'method_not_allowed', 'Use GET.');
}

try {
    $apps = apiListApps();
} catch (\Throwable $e) {
    // PDO runs in exception mode; a database failure must not leak SQLSTATE text.
    apiError(500, 'internal_error', 'The app list could not be read.');
}

apiRespond(200, [
    'apps' => $apps,
    'count' => \count($apps),
]);
