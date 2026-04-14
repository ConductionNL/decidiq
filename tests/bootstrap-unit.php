<?php

/**
 * PHPUnit bootstrap for Decidesk unit tests.
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

// Include Composer's autoloader and register OCP namespace for standalone test runs.
$autoloader = require __DIR__.'/../vendor/autoload.php';
if (is_dir(__DIR__.'/../vendor/nextcloud/ocp/OCP') === true) {
    $autoloader->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
    $autoloader->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
}

// Bootstrap Nextcloud when a full server environment is available.
// The base.php include is wrapped in a try/catch so that unit tests can
// run in standalone mode (e.g. a bare container without an installed NC).
if (file_exists(__DIR__.'/../../../lib/base.php') === true) {
    try {
        include_once __DIR__.'/../../../lib/base.php';
    } catch (\Throwable $e) {
        // NC not fully installed — unit tests continue with vendor stubs only.
    }
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__.'/../../../tests/lib/';
if (is_dir($serverTestsLib) === true) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Test\\', $serverTestsLib);
    $loader->register(true);
}

// Load test stubs for cross-app classes not available as Composer dependencies
// (e.g. OCA\OpenRegister classes that are only present when the app is installed).
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
    require_once __DIR__.'/Stubs/DeepLinkRegistrationEvent.php';
}

// OpenRegister service stubs — loaded when running without a live NC+OpenRegister install.
if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false
    || class_exists(\OCA\OpenRegister\Service\CalendarEventService::class) === false
    || class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false
) {
    require_once __DIR__.'/Stubs/OpenRegisterServices.php';
}
