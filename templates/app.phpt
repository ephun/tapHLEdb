<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

$session = getSession();
$moderatorView = signedInUserIsModerator($session);
$showUnapproved = $moderatorView && (($_GET['show_unapproved'] ?? '0') === '1');
$developerView = $moderatorView || (($_GET['view'] ?? '') === 'developer');

$appInfo = getApp($appId);

if ($appInfo == NULL || (!$showUnapproved && $appInfo['approved'] === NULL)) {
    show404();
}

$breadcrumbs = ['Apps', $appInfo['name']];

require 'header.phpt';

?>

<h2>App</h2>

<img class="app-icon" src="<?=htmlspecialchars(url('/apps/' . $appId . '/icon'))?>" alt="<?=htmlspecialchars($appInfo['name'])?> icon">

<?php printApp($appInfo, $moderatorView); ?>

<p><a href="<?=htmlspecialchars(url('/apps/' . $appId . ($developerView ? '' : '?view=developer')))?>"><?=$developerView ? 'End-user view' : 'Developer view'?></a></p>

<h3>Versions</h3>

<?php if ($developerView): ?>
<?php listVersionsForApp($appId, $showUnapproved, $moderatorView); ?>
<?php else: ?>
<?php listEndUserVersionsForApp($appId); ?>
<?php endif; ?>
<br>
<?php printButtonForm([
    'action' => '/reports/new',
    'method' => 'get',
    'param_name' => 'app',
    'param_value' => (string)$appId,
    'label' => 'Submit report for a new version',
]); ?>

<?php if ($developerView): ?>
<h3>Reports</h3>

<?php listReportsForApp($appId, $showUnapproved, signedInUserIsModerator($session)); ?>

<h3>Developer notes</h3>
<?php listDeveloperNotesForApp($appId, $showUnapproved && $moderatorView); ?>

<h3>Legend</h3>
<?php printRatingsLegend(); ?>

<h3>Screenshots</h3>
<?php listReportScreenshotsForApp($appId, $showUnapproved, signedInUserIsModerator($session)); ?>
<?php endif; ?>

<?php if ($moderatorView): ?>
<h3>Admin duplicate tools</h3>
<form action="<?=htmlspecialchars(url('/apps/' . $appId . '/merge'))?>" method=post onsubmit="return confirm('Merge this entire app into the target?');">
<label>Merge this App into App ID <input type=number min=1 name=target_app required></label>
<input type=submit value="Merge duplicate App">
</form>
<form action="<?=htmlspecialchars(url('/versions/0/merge'))?>" method=post id=merge-version-form onsubmit="this.action='<?=htmlspecialchars(url('/versions/'))?>'+this.source_version.value+'/merge';return confirm('Merge the source version into the target?');">
<label>Source Version ID <input type=number min=1 name=source_version required></label>
<label>Target Version ID <input type=number min=1 name=target_version required></label>
<input type=submit value="Merge duplicate Version">
</form>
<?php endif; ?>

<?php

require 'footer.phpt';
