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
require_once __DIR__.'/../vendor/autoload.php';

// Load test stubs for cross-app classes not available when the app is not installed.
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
    include_once __DIR__.'/Stubs/DeepLinkRegistrationEvent.php';
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
