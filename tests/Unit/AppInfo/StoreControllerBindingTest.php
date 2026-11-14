<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The store route is bound through Bootstrap::aliasStoreController(LeafAdoption).
 *
 * The call used to sit inside a catch(\Throwable): when openregister #9078
 * changed the signature, the binding was skipped without a trace and
 * /api/store/items answered HTTP 500. This test fails on the old call.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\AppInfo;

use OCA\Decidiq\AppInfo\Registrar\AppHostRegistrar;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the store-controller binding of the app host registrar.
 */
class StoreControllerBindingTest extends TestCase {

	public function testTheStoreControllerIsBoundWithADecidiqAdoption(): void {
		if (property_exists(Bootstrap::class, 'storeAdoptions') === false) {
			self::markTestSkipped('The real OpenRegister Bootstrap is loaded; this test uses the recording stand-in.');
		}

		Bootstrap::$storeAdoptions = [];

		$bind = new ReflectionMethod(AppHostRegistrar::class, 'bindStoreController');
		$bind->invoke(new AppHostRegistrar(), $this->createMock(IRegistrationContext::class));

		self::assertCount(1, Bootstrap::$storeAdoptions);
		self::assertSame('decidiq', Bootstrap::$storeAdoptions[0]->appId);
		self::assertSame(
			'OCA\\Decidiq\\Controller',
			Bootstrap::$storeAdoptions[0]->namespace(option: 'controllerNamespace', suffix: 'Controller')
		);
	}//end testTheStoreControllerIsBoundWithADecidiqAdoption()
}//end class
