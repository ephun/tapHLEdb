<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

$report = getReport($reportId);
$session = getSession();
if ($report === NULL ||
    !canViewReportScreenshot($session, $report)) {
    show404();
    exit;
}

$screenshotImage = getReportScreenshotImage($reportId);
if ($screenshotImage === NULL) {
    show404();
    exit;
} else {
    header('Content-Type: image/jpeg');
    header('Cache-Control: ' . reportScreenshotCacheControl($report));
    echo $screenshotImage;
    exit;
}
