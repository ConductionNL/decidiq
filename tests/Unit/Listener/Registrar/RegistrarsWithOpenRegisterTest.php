<?php

/**
 * The registrars when OpenRegister ships the classes they look for: the
 * filtered object-event subscription, and the task terminal event.
 *
 * Both classes are declared as stand-ins in a separate process, so the
 * declarations cannot leak into the tests that run without OpenRegister.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener\Registrar;

use OCA\Decidiq\Listener\ApprovalTaskDecisionListener;
use OCA\Decidiq\Listener\DocumentTypeFieldsGuardListener;
use OCA\Decidiq\Listener\Registrar\CrossAppEventRegistrar;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 * @covers \OCA\Decidiq\Listener\Registrar\CrossAppEventRegistrar
 * @uses   \OCA\Decidiq\AppInfo\OpenRegisterAutoloader
 * @uses   \OCA\Decidiq\Listener\Registrar\FilesEventRegistrar
 * @uses   \OCA\Decidiq\Listener\Registrar\FlowNodeRegistrar
 * @uses   \OCA\Decidiq\Listener\Registrar\TaskProcessingEventRegistrar
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RegistrarsWithOpenRegisterTest extends TestCase {

	/**
	 * Declare OpenRegister's ObjectEventSubscription as a stand-in recording its calls.
	 *
	 * @return void
	 */
	private static function declareSubscription(): void {
		eval(
			'namespace OCA\OpenRegister\Event; class ObjectEventSubscription {'
			. ' public static array $calls = [];'
			. ' public static function subscribe($dispatcher, string $event, string $listener, ?array $registers, ?array $schemas): void {'
			. ' self::$calls[] = [$event, $listener, $registers, $schemas]; } }'
		);
	}//end declareSubscription()

	public function testEveryListenerGoesThroughTheFilteredSubscription(): void {
		self::declareSubscription();
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('addServiceListener');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new ObjectListenerRegistrar(logger: $logger))->register($dispatcher);

		$calls = \OCA\OpenRegister\Event\ObjectEventSubscription::$calls;
		self::assertNotEmpty($calls);
		foreach (SaveGuardSubscriptions::ALL as $guard) {
			self::assertContains([$guard['event'], $guard['listener'], null, $guard['schemas']], $calls);
		}

		self::assertContains(
			['OCA\OpenRegister\Event\ObjectCreatingEvent', DocumentTypeFieldsGuardListener::class, null, ['digital-document']],
			$calls
		);
	}//end testEveryListenerGoesThroughTheFilteredSubscription()

	public function testTheApprovalTaskListenerIsRegisteredWhenOpenRegisterSendsTaskEvents(): void {
		eval('namespace OCA\OpenRegister\Event; class TaskTerminalEvent {}');
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new CrossAppEventRegistrar())->register($context);

		self::assertContains(['OCA\OpenRegister\Event\TaskTerminalEvent', ApprovalTaskDecisionListener::class], $registered);
	}//end testTheApprovalTaskListenerIsRegisteredWhenOpenRegisterSendsTaskEvents()

	public function testWithoutTaskEventsTheApprovalTaskListenerIsNotRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$registered): void {
				$registered[] = $listener;
			}
		);

		(new CrossAppEventRegistrar())->register($context);

		self::assertNotContains(ApprovalTaskDecisionListener::class, $registered);
	}//end testWithoutTaskEventsTheApprovalTaskListenerIsNotRegistered()
}//end class
