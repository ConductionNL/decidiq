<?php

use OCP\Util;

$appId = OCA\Decidesk\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="decidesk-settings"></div>
