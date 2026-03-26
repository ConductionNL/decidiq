<?php

use OCP\Util;

$appId = OCA\Decidesk\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="decidesk-settings" data-version="<?php p($_['version'] ?? ''); ?>"></div>
