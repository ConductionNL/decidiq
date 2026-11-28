<?php

use OCP\Util;

$appId = OCA\Decidiq\AppInfo\Application::APP_ID;
Util::addStyle($appId, 'nextcloud-vue');
Util::addScript($appId, $appId . '-main');
?>
<div id="content"></div>
