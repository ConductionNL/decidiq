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

// Bootstrap Nextcloud only when the config file is readable (i.e., in a properly
// provisioned CI environment). Skip silently when running standalone — OCP stubs
// registered above are sufficient for unit-only test suites.
$ncBase   = __DIR__.'/../../../lib/base.php';
$ncConfig = __DIR__.'/../../../config/config.php';
if (file_exists($ncBase) === true && is_readable($ncConfig) === true) {
    include_once $ncBase;
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

if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
    require_once __DIR__.'/Stubs/ObjectService.php';
}

if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
    require_once __DIR__.'/Stubs/ObjectEntity.php';
}
