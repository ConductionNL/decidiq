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

// Include Composer's autoloader and register OCP namespace for standalone test runs.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the test namespace so shared test-only helpers (traits, fixtures)
// under tests/ can be autoloaded by the classes that use them. PHPUnit itself
// only loads files matching *Test.php, so a helper in tests/Unit/Support/ is
// otherwise invisible. Registered here rather than via composer autoload-dev
// for the same reason as the stubs below: a dev-built vendor bakes autoload-dev
// into the runtime classmap.
$autoloader->addPsr4('OCA\\Decidesk\\Tests\\', __DIR__ . '/');

// Register the OpenRegister test-stub namespace at test time ONLY. These stubs are
// deliberately NOT registered via composer autoload-dev: a dev-built vendor bakes
// autoload-dev into the runtime classmap, and OCA\OpenRegister\* stubs then shadow
// the REAL OpenRegister classes instance-wide (see openregister#2036 / hermiq#21).
// Registering here keeps them available for phpunit without ever entering the app's
// runtime autoloader. Loading stays lazy, so OCP is fully registered before any stub
// (which extends OCP\...\Event) is actually loaded.
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');

// Register OCP\ and NCU\ namespaces.
// vendor/nextcloud/ocp/OCP is a symlink to the live NC server (/var/www/html/lib/public)
// that resolves on a deployed instance but is broken in the bare php:8.3-cli CI container.
// In CI we fall back to vendor/nextcloud/ocp/OCP.bak which holds the shipped stubs.
// This MUST happen before any class_exists() call that may trigger autoloading of
// stub classes that extend OCP\EventDispatcher\Event etc.
$ocpDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
$ocpBakDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP.bak';
$ncuDir = __DIR__ . '/../vendor/nextcloud/ocp/NCU';
if (is_dir($ocpDir) === true) {
	$autoloader->addPsr4('OCP\\', $ocpDir . '/');
	$autoloader->addPsr4('NCU\\', $ncuDir . '/');
} elseif (is_dir($ocpBakDir) === true) {
	// CI environment — broken symlink, use the shipped backup stubs.
	$autoloader->addPsr4('OCP\\', $ocpBakDir . '/');
	$autoloader->addPsr4('NCU\\', $ncuDir . '/');
}

// Bootstrap Nextcloud when a full server environment is available.
// The base.php include is wrapped in a try/catch so that unit tests can
// run in standalone mode (e.g. a bare container without an installed NC).
if (file_exists(__DIR__ . '/../../../lib/base.php') === true) {
	try {
		include_once __DIR__ . '/../../../lib/base.php';
	} catch (\Throwable $e) {
		// NC not fully installed — unit tests continue with vendor stubs only.
	}
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib) === true) {
	$loader = new \Composer\Autoload\ClassLoader();
	$loader->addPsr4('Test\\', $serverTestsLib);
	$loader->register(true);
}

// Load test stubs for cross-app classes not available as Composer dependencies
// (e.g. OCA\OpenRegister classes that are only present when the app is installed).
// The stubs are also registered via autoload-dev PSR-4 in composer.json so that
// Composer's autoloader can find them without needing Nextcloud to be bootstrapped.
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/DeepLinkRegistrationEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectCreatedEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/ObjectCreatedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectCreatingEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/ObjectCreatingEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectUpdatedEvent::class) === false) {
	require_once __DIR__ . '/Stubs/Event/ObjectUpdatedEvent.php';
}

// ObjectService, ObjectEntity, Register and Schema need no require_once: the
// PSR-4 root registered above resolves them to tests/Stubs/Service/ and
// tests/Stubs/Db/ whenever the real OpenRegister app is absent, and to the real
// app when it is present. There used to be four more require_once branches here
// pointing at a second, LOOSER copy of each stub; the guards could never be
// true, so the copies were dead while still reading as the contract (#399).
//
// CalendarEventService is different: PSR-4 would look for
// tests/Stubs/Service/CalendarEventService.php, which does not exist, so the
// class really is missing without OpenRegister and must be required explicitly.
if (class_exists(\OCA\OpenRegister\Service\CalendarEventService::class) === false) {
	require_once __DIR__ . '/Stubs/OpenRegisterServices.php';
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// This allows DecideskToolProvider unit tests to run in standalone CI environments.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
	require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}
