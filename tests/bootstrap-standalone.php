<?php

/**
 * Standalone PHPUnit bootstrap for unit tests without Nextcloud server.
 *
 * Loads Composer autoloader and registers OCP namespace from stubs.
 * Use when the Nextcloud server environment is not writable.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

define('PHPUNIT_RUN', 1);

$autoloader = require __DIR__.'/../vendor/autoload.php';
if (is_dir(__DIR__.'/../vendor/nextcloud/ocp/OCP') === true) {
    $autoloader->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
    $autoloader->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
}

// Load test stubs for cross-app classes.
if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
    require_once __DIR__.'/Stubs/DeepLinkRegistrationEvent.php';
}
