<?php

use OCP\Util;

$appId = OCA\Decidiq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="decidesk-settings"></div>
