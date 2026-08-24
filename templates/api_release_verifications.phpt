<?php declare(strict_types=1);
namespace hikari_no_yume\touchHLE\app_compatibility_db;
require_once '../include/api.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    apiError(405, 'method_not_allowed', 'Use GET.');
}
$release = $_GET['release'] ?? '';
$commit = $_GET['commit'] ?? '';
if (!is_string($release) || !is_string($commit)) {
    apiError(400, 'invalid_query', 'release and commit must be strings.');
}
try {
    $verifications = apiListReleaseVerifications($release, $commit);
} catch (ApiSubmissionError $error) {
    apiError(400, 'invalid_query', $error->getMessage());
}
apiRespond(200, [
    'release' => $release,
    'commit' => $commit,
    'verifications' => $verifications,
    'count' => count($verifications),
]);
