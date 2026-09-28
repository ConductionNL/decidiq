<?php

/**
 * FlowNodeRegistrar registers the flow listeners only when the engine exists.
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
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\AppInfo;

use OCA\Decidiq\AppInfo\Registrar\FlowNodeRegistrar;
use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCA\Decidiq\Flow\DecidiqFlowNodeListener;
use OCA\Decidiq\Listener\FlowDecisionConcludedListener;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The wiring is asserted from the CALLER: what Application::register() hands
 * the registration context. Application.php itself is excluded from unit
 * coverage (it extends the server's App), so the source check below pins that
 * it calls this registrar.
 *
 * @covers \OCA\Decidiq\AppInfo\Registrar\FlowNodeRegistrar
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
 */
class FlowNodeRegistrarTest extends TestCase {

	/**
	 * Run the registrar and collect what it registered.
	 *
	 * @param FlowNodeRegistrar $registrar The registrar
	 *
	 * @return array<int, array{0: string, 1: string}> [event, listener] pairs
	 */
	private function registrations(FlowNodeRegistrar $registrar): array {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$registrar->register(context: $context);

		return $registered;
	}//end registrations()

	public function testWithTheEngineBothListenersAreRegistered(): void {
		self::assertSame(
			[
				[RegisterFlowNodesEvent::class, DecidiqFlowNodeListener::class],
				[DecisionConcludedEvent::class, FlowDecisionConcludedListener::class],
			],
			$this->registrations(registrar: new FlowNodeRegistrar())
		);
	}//end testWithTheEngineBothListenersAreRegistered()

	public function testWithoutTheEngineNothingIsRegisteredAndNothingThrows(): void {
		self::assertSame(
			[],
			$this->registrations(registrar: new FlowNodeRegistrar(engineEvent: 'OCA\\OpenRegister\\Service\\Flow\\NoSuchEngineEvent'))
		);
	}//end testWithoutTheEngineNothingIsRegisteredAndNothingThrows()

	public function testTheApplicationCallsTheRegistrar(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		self::assertStringContainsString('(new FlowNodeRegistrar())->register(context: $context);', $source);
	}//end testTheApplicationCallsTheRegistrar()
}//end class
