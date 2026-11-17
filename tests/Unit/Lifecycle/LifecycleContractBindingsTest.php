<?php

/**
 * The service aliases the lifecycle contracts are registered under.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Lifecycle
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

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use OCA\Decidiq\Kernel\Contracts\DecisionTransitionGuardInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingTransitionGuardInterface;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplatePolicyResolverInterface;
use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Lifecycle\LifecycleContractBindings;
use OCA\Decidiq\Lifecycle\MeetingTransitionGuard;
use OCA\Decidiq\Lifecycle\ProcessTemplatePolicyResolver;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LifecycleContractBindings.
 *
 * @covers \OCA\Decidiq\Lifecycle\LifecycleContractBindings
 */
class LifecycleContractBindingsTest extends TestCase {

	/**
	 * Every lifecycle contract is aliased once, to the class that implements it.
	 *
	 * @return void
	 */
	public function testEveryContractIsAliasedToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(3))
			->method('registerServiceAlias')
			->willReturnCallback(
				static function (string $alias, string $target) use (&$aliases): void {
					$aliases[$alias] = $target;
				}
			);

		(new LifecycleContractBindings())->register($context);

		self::assertSame(
			[
				DecisionTransitionGuardInterface::class => DecisionTransitionGuard::class,
				MeetingTransitionGuardInterface::class => MeetingTransitionGuard::class,
				ProcessTemplatePolicyResolverInterface::class => ProcessTemplatePolicyResolver::class,
			],
			$aliases
		);
	}//end testEveryContractIsAliasedToItsImplementation()

	/**
	 * Each aliased class really implements the contract it is bound to.
	 *
	 * @return void
	 */
	public function testEachImplementationFulfilsItsContract(): void {
		self::assertTrue(is_subclass_of(DecisionTransitionGuard::class, DecisionTransitionGuardInterface::class));
		self::assertTrue(is_subclass_of(MeetingTransitionGuard::class, MeetingTransitionGuardInterface::class));
		self::assertTrue(is_subclass_of(ProcessTemplatePolicyResolver::class, ProcessTemplatePolicyResolverInterface::class));
	}//end testEachImplementationFulfilsItsContract()
}//end class
