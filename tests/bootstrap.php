<?php

/**
 * PHPUnit bootstrap for Decidesk integration tests.
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
// Also register OCP namespace from vendor for standalone runs (no Nextcloud server present).
$autoloader = require __DIR__ . '/../vendor/autoload.php';
// OpenRegister test stubs are registered here (test-time only), NOT via composer
// autoload-dev — a dev-built vendor would otherwise bake these OCA\OpenRegister\*
// stubs into the runtime classmap and shadow the real classes (openregister#2036).
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
if (is_dir(__DIR__ . '/../vendor/nextcloud/ocp/OCP') === true) {
	$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
	$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
}

// Bootstrap Nextcloud only when the config file is readable (i.e., in a properly
// provisioned CI environment). Skip silently in standalone mode — OCP stubs are
// sufficient for unit-only test suites.
$ncBase = __DIR__ . '/../../../lib/base.php';
$ncConfig = __DIR__ . '/../../../config/config.php';
if (defined('OC_CONSOLE') === false && is_readable($ncConfig) === true) {
	if (file_exists($ncBase) === true) {
		include_once $ncBase;
	}

	if (file_exists(__DIR__ . '/../../../tests/autoload.php') === true) {
		include_once __DIR__ . '/../../../tests/autoload.php';
	}

	\OC_App::loadApps();
	\OC_App::loadApp('decidesk');
	OC_Hook::clear();
}

// Load test stubs AFTER Nextcloud bootstrap so that OCP\EventDispatcher\Event
// (which the stub extends) is already resolvable — either via the Nextcloud
// autoloader (full NC environment) or via the vendor/nextcloud/ocp fallback
// registered above (standalone mode).
// The stubs are also registered via autoload-dev PSR-4 in composer.json so that
// Composer's autoloader can find them without needing Nextcloud to be bootstrapped.
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
	include_once __DIR__ . '/Stubs/Event/DeepLinkRegistrationEvent.php';
}

// ObjectService, ObjectEntity, Register and Schema need no include_once: the
// PSR-4 root registered above resolves them to tests/Stubs/Service/ and
// tests/Stubs/Db/ whenever the real OpenRegister app is absent, and to the real
// app when it is present. See tests/Stubs/Service/ObjectService.php for the
// signature-parity contract these stubs are held to (#399).
if (class_exists(\OCA\OpenRegister\Service\CalendarEventService::class) === false) {
	include_once __DIR__ . '/Stubs/OpenRegisterServices.php';
}

// Hand-written ObjectService doubles extend this base so PHP itself checks them
// against OpenRegister's published contract (ADR-084). It lives outside
// tests/Stubs/ deliberately: that directory is PSR-4-mapped to the
// OCA\OpenRegister namespace and would shadow the real app.
$autoloader->addPsr4('OCA\\Decidesk\\Tests\\Doubles\\', __DIR__ . '/Doubles/');
