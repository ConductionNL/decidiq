<?php

/**
 * Sends a proposal along an approval route by starting one OpenRegister flow run.
 *
 * Pressing Send for approval used to write decidiq stage rows and project them
 * into tasks. A route sent now is compiled to a flow, stored once per route
 * version, and run with the decision as its subject: every step is an
 * OpenRegister task, and the flow writes the decision's route fields. No
 * decidiq stage row and no decidiq task is created for the run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Starts the flow run of a route for one decision.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
 */
class ApprovalRouteFlowStarter {
	/**
	 * The engine value of a decision whose route runs as a flow.
	 */
	public const ENGINE_FLOW = 'flow';

	/**
	 * The engine value of a decision whose route finishes on the old engine.
	 */
	public const ENGINE_LEGACY = 'legacy';

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteFlowCompiler $compiler Turns the route into a flow.
	 * @param ApprovalFlowGateway       $gateway  Stores the flow and queues the run.
	 * @param RegisterObjectStore       $store    Reads and writes the decision.
	 */
	public function __construct(
		private readonly ApprovalRouteFlowCompiler $compiler,
		private readonly ApprovalFlowGateway $gateway,
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * Send a decision along a route: one run, the decision as its subject.
	 *
	 * @param array<string, mixed> $route    The ApprovalRoute object.
	 * @param string               $decision The decision's uuid.
	 *
	 * @return array{run: string, flow: string} The run and the flow it runs.
	 *
	 * @throws RuntimeException When the decision is missing, is already on its route, or the route cannot run.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
	 */
	public function start(array $route, string $decision): array {
		$current = $this->store->find(schema: 'decision', uuid: $decision);
		if ($current === null) {
			throw new RuntimeException('The decision to send for approval was not found.');
		}

		$this->refuseWhileOpen(decision: $current, route: $route);

		// Compiled BEFORE anything is stored, so a route that cannot run is
		// refused without leaving a flow or a half-written decision behind.
		$document = $this->compiler->compile(route: $route);
		$flowId   = $this->gateway->publishedFlowFor(document: $document);
		$runId    = $this->gateway->startRun(flowId: $flowId, decision: $decision);

		$this->store->patch(
			schema: 'decision',
			data: [
				'approvalRun'          => $runId,
				'approvalRoute'        => (string)($route['id'] ?? ($route['@self']['id'] ?? '')),
				'approvalEngine'       => self::ENGINE_FLOW,
				'approvalRouteStatus'  => ApprovalRouteFlowCompiler::STATUS_RUNNING,
				'approvalCurrentOrder' => $this->firstOrder(route: $route),
			],
			uuid: $decision
		);

		return ['run' => $runId, 'flow' => $flowId];
	}//end start()

	/**
	 * Refuse a second send while the decision's route is still open.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param array<string, mixed> $route    The route asked for, to name the open step.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a step is open; the message names it.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
	 */
	public function refuseWhileOpen(array $decision, array $route): void {
		if ((string)($decision['approvalRouteStatus'] ?? '') !== ApprovalRouteFlowCompiler::STATUS_RUNNING) {
			return;
		}

		$order = (int)($decision['approvalCurrentOrder'] ?? 0);
		throw new RuntimeException(
			sprintf(
				'This proposal is already on its route: %s is still open. It can be sent again once the route has concluded.',
				$this->stepName(route: $route, order: $order)
			)
		);
	}//end refuseWhileOpen()

	/**
	 * How the refusal names an open step: its order and label.
	 *
	 * @param array<string, mixed> $route The route.
	 * @param int                  $order The open order; 0 when the decision does not say.
	 *
	 * @return string The name.
	 */
	private function stepName(array $route, int $order): string {
		if ($order < 1) {
			return 'a step';
		}

		$labels = [];
		foreach ((array)($route['steps'] ?? []) as $step) {
			if (is_array($step) === true && (int)($step['order'] ?? 0) === $order && trim((string)($step['label'] ?? '')) !== '') {
				$labels[] = trim((string)$step['label']);
			}
		}

		if ($labels === []) {
			return sprintf('step %d', $order);
		}

		return sprintf('step %d (%s)', $order, implode(', ', $labels));
	}//end stepName()

	/**
	 * The route's first order.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return int The order.
	 */
	private function firstOrder(array $route): int {
		$orders = [];
		foreach (array_values((array)($route['steps'] ?? [])) as $index => $step) {
			if (is_array($step) === true) {
				$order = (int)($step['order'] ?? 0);
				if ($order < 1) {
					$order = ($index + 1);
				}

				$orders[] = $order;
			}
		}

		return min($orders);
	}//end firstOrder()
}//end class
