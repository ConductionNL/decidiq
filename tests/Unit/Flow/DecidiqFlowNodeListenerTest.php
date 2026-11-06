<?php

/**
 * DecidiqFlowNodeListener puts decidiq.request-decision in the engine's catalogue.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Flow
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

namespace OCA\Decidiq\Tests\Unit\Flow;

use OCA\Decidiq\Flow\DecidiqFlowNodeListener;
use OCA\Decidiq\Flow\DecidiqRequestDecisionNode;
use OCA\Decidiq\Service\FlowDecisionService;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Asserted through the catalogue, not through the listener's internals: the
 * REAL registration event carries a registry, and the test reads the node back
 * out of it by the id a flow document names.
 *
 * @covers \OCA\Decidiq\Flow\DecidiqFlowNodeListener
 * @uses   \OCA\Decidiq\Flow\DecidiqRequestDecisionNode
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
 */
class DecidiqFlowNodeListenerTest extends TestCase {

	public function testTheEngineCatalogueHoldsTheNode(): void {
		$node = new DecidiqRequestDecisionNode(
			$this->getMockBuilder(FlowDecisionService::class)->disableOriginalConstructor()->onlyMethods(['raise', 'readState'])->getMock(),
			$this->createMock(IL10N::class),
			new NullLogger()
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DecidiqRequestDecisionNode::class)->willReturn($node);

		$registry = new FlowNodeRegistry();
		(new DecidiqFlowNodeListener($container, new NullLogger()))->handle(new RegisterFlowNodesEvent($registry));

		self::assertSame($node, $registry->get('decidiq.request-decision'));
	}//end testTheEngineCatalogueHoldsTheNode()

	public function testANodeThatCannotBeBuiltIsLeftOutWithoutThrowing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('interface not found'));

		$registry = new FlowNodeRegistry();
		(new DecidiqFlowNodeListener($container, new NullLogger()))->handle(new RegisterFlowNodesEvent($registry));

		self::assertSame([], $registry->all());
	}//end testANodeThatCannotBeBuiltIsLeftOutWithoutThrowing()

	public function testAnyOtherEventIsIgnored(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		(new DecidiqFlowNodeListener($container, new NullLogger()))->handle(new Event());
	}//end testAnyOtherEventIsIgnored()
}//end class
