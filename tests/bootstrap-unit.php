<?php

/**
 * PHPUnit bootstrap for Decidesk unit tests.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__.'/../vendor/autoload.php';

// Unit tests must NOT load NC's base.php for the following reasons:
//  1. base.php triggers OC::init() which instantiates the DI container and
//     wires Settings\Manager — on NC 34 + PHP 8.4, the #[\Override] attribute
//     on Manager::getAdminDelegatedSettings() causes an E_COMPILE_ERROR when
//     the OCP\Settings\IManager stub loaded from an older nextcloud/ocp vendor
//     is missing that method.  E_COMPILE_ERROR cannot be caught by try/catch.
//  2. Unit tests are designed to run in isolation with stub classes; the full
//     NC bootstrap is only needed for integration tests.
//
// Instead, load only NC's own Composer autoloader so that OCP\* and OC\*
// namespaces resolve to the installed server's public API files — this gives
// us proper interfaces (IRequest, JSONResponse, etc.) without triggering the
// framework initialisation that causes the fatal.
$ncComposerAutoload = __DIR__.'/../../../lib/composer/autoload.php';
if (file_exists($ncComposerAutoload) === true) {
    require_once $ncComposerAutoload;
} elseif (is_dir(__DIR__.'/../vendor/nextcloud/ocp/OCP') === true) {
    // Standalone mode (no NC server): fall back to vendored OCP stubs.
    $autoloader->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
    $autoloader->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
}

// Register Test\ namespace for NC test classes (only when NC server is present
// and its base.php has already been loaded — not needed for unit tests).
// Intentionally omitted here: unit tests do not extend NC\Tests\TestCase.

// Load test stubs for cross-app classes not available as Composer dependencies
// (e.g. OCA\OpenRegister classes that are only present when the app is installed).
// The stubs are also registered via autoload-dev PSR-4 in composer.json so that
// Composer's autoloader can find them without needing Nextcloud to be bootstrapped.
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
    require_once __DIR__.'/Stubs/Event/DeepLinkRegistrationEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectCreatedEvent::class) === false) {
    require_once __DIR__.'/Stubs/Event/ObjectCreatedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectCreatingEvent::class) === false) {
    require_once __DIR__.'/Stubs/Event/ObjectCreatingEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectUpdatedEvent::class) === false) {
    require_once __DIR__.'/Stubs/Event/ObjectUpdatedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
    require_once __DIR__.'/Stubs/Service/ObjectService.php';
}

if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
    require_once __DIR__.'/Stubs/Db/ObjectEntity.php';
}

if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
    require_once __DIR__.'/Stubs/ObjectService.php';
}

if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
    require_once __DIR__.'/Stubs/ObjectEntity.php';
}

// OpenRegister service stubs — loaded when running without a live NC+OpenRegister install.
if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false
    || class_exists(\OCA\OpenRegister\Service\CalendarEventService::class) === false
    || class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false
) {
    require_once __DIR__.'/Stubs/OpenRegisterServices.php';
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// This allows DecideskToolProvider unit tests to run in standalone CI environments.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__.'/Stubs/Mcp/IMcpToolProvider.php';
}
