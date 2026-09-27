<?php declare(strict_types=1);

namespace hikari_no_yume\touchHLE\app_compatibility_db;

$session = getSession();

// The list of all unapproved reports is only for moderators.
$showUnapproved = signedInUserIsModerator($session) && (($_GET['show_unapproved'] ?? '0') === '1');

require 'header.phpt';

?>

<h2>Apps</h2>
<?php listApps($showUnapproved); ?>
<?php if (signedInUserIsModerator($session)): ?>
<h3>Trusted submitter credentials</h3>
<p>Credential secrets and trust are managed in <code>config.php</code>. Secrets are never displayed here.</p>
<?php
$credentialRows = [];
foreach (\defined('API_TOKENS') && is_array(API_TOKENS) ? API_TOKENS : [] as $configuration) {
    $credentialRows[] = [
        'identity' => is_array($configuration) ? ($configuration['identity'] ?? '') : $configuration,
        'trusted' => is_array($configuration) && ($configuration['trusted'] ?? FALSE) === TRUE ? 'Yes' : 'No',
    ];
}
printTable(['identity'=>['name'=>'Identity'],'trusted'=>['name'=>'Auto-approve own submissions']], $credentialRows);
?>
<?php endif; ?>
<br>
<form action="<?=htmlspecialchars(url('/reports/new'))?>" method=get>
<input type=submit value="Submit report for a new app">
</form>

<?php

require 'footer.phpt';
