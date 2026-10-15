<?php

/**
 * The deep-link listener is registered under decidiq's own namespace.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\AppInfo;

use OCA\Decidiq\AppInfo\Registrar\AppHostRegistrar;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud builds a listener through the container of the app that owns its
 * namespace; under the OpenRegister class name OpenRegister's container built
 * it and could not supply `appId`.
 */
class DeepLinkListenerRegistrationTest extends TestCase {

	/**
	 * The listener and its factory share one name, and that name is decidiq's.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegisteredAndBuiltUnderDecidiqsNamespace(): void {
		$services  = [];
		$listeners = [];
		$context   = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name) use (&$services): void {
				$services[] = $name;
			}
		);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$listeners): void {
				$listeners[] = [$event, $listener];
			}
		);

		(new AppHostRegistrar())->register(context: $context);

		self::assertContains([DeepLinkRegistrationEvent::class, AppHostRegistrar::DEEP_LINK_LISTENER_SERVICE], $listeners);
		self::assertContains(AppHostRegistrar::DEEP_LINK_LISTENER_SERVICE, $services);
		self::assertStringStartsWith('OCA\\Decidiq\\', AppHostRegistrar::DEEP_LINK_LISTENER_SERVICE);
		foreach ($listeners as [$event, $listener]) {
			self::assertStringStartsNotWith('OCA\\OpenRegister\\', $listener, 'a listener named in OpenRegister\'s namespace is built by OpenRegister\'s container');
		}
	}//end testTheListenerIsRegisteredAndBuiltUnderDecidiqsNamespace()
}//end class
