<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

$app = getApp($appId);
$icon = getAppIcon($appId);
if ($app === NULL || ($app['approved'] === NULL && !signedInUserIsModerator(getSession())) || $icon === NULL) {
    show404();
}
header('Content-Type: ' . $icon['mime_type']);
header('Cache-Control: ' . ($app['approved'] === NULL ? 'private, no-store' : 'public, max-age=31536000'));
echo $icon['image'];
exit;
