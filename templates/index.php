<?php

use OCP\Util;

$appId = OCA\Decidiq\AppInfo\Application::APP_ID;
// The library stylesheet, linked so the browser keeps it parsed between loads.
Util::addStyle($appId, 'nextcloud-vue');
Util::addScript($appId, $appId . '-main');
?>
<div id="content"></div>
