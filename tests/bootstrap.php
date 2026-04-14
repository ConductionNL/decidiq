<?php

/**
 * PHPUnit bootstrap for Decidesk integration tests.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
// Also register OCP namespace from vendor for standalone runs (no Nextcloud server present).
$autoloader = require __DIR__.'/../vendor/autoload.php';
if (is_dir(__DIR__.'/../vendor/nextcloud/ocp/OCP') === true) {
    $autoloader->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
    $autoloader->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
}

// Bootstrap Nextcloud if not already done.
if (defined('OC_CONSOLE') === false) {
    if (file_exists(__DIR__.'/../../../lib/base.php') === true) {
        include_once __DIR__.'/../../../lib/base.php';
    }

    if (file_exists(__DIR__.'/../../../tests/autoload.php') === true) {
        include_once __DIR__.'/../../../tests/autoload.php';
    }

    \OC_App::loadApps();
    \OC_App::loadApp('decidesk');
    OC_Hook::clear();
}

// Load test stubs AFTER Nextcloud bootstrap so that OCP\EventDispatcher\Event
// (which the stub extends) is already resolvable — either via the Nextcloud
// autoloader (full NC environment) or via the vendor/nextcloud/ocp fallback
// registered above (standalone mode).
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
    include_once __DIR__.'/Stubs/DeepLinkRegistrationEvent.php';
}

if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
    include_once __DIR__.'/Stubs/ObjectService.php';
}

if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
    include_once __DIR__.'/Stubs/ObjectEntity.php';
}
