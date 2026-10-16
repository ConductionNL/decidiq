<?php

/**
 * Decidiq flow node listener.
 *
 * @category Flow
 * @package  OCA\Decidiq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contributes decidiq's nodes to OpenRegister's flow engine.
 *
 * ADR-065: OpenRegister owns the engine and a leaf app contributes what it can
 * do. Deciding is decidiq's, so the node that asks for a decision is decidiq's.
 *
 * Nodes are RESOLVED here rather than injected, so a node whose dependencies or
 * interfaces an older OpenRegister cannot satisfy is logged and left out of the
 * catalogue instead of taking this listener, and every other contribution to
 * the same event, down with it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
 */
class DecidiqFlowNodeListener implements IEventListener {

	/**
	 * The nodes decidiq contributes, in catalogue order.
	 *
	 * @var array<int, class-string>
	 */
	private const NODES = [
		DecidiqRequestDecisionNode::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves each node
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register decidiq's nodes on the catalogue.
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowNodesEvent) === false) {
			return;
		}

		foreach (self::NODES as $class) {
			try {
				$node = $this->container->get($class);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Decidiq: could not construct a flow node; it will not be offered',
					['node' => $class, 'error' => $e->getMessage()]
				);
				continue;
			}

			if (($node instanceof IFlowNode) === false) {
				continue;
			}

			$event->registerNode(node: $node);
		}//end foreach
	}//end handle()
}//end class
