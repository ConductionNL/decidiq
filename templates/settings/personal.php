<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Decidiq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-personal');
?>
<div id="decidesk-personal-settings"></div>
