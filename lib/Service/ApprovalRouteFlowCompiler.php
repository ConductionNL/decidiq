<?php

/**
 * Compiles an approval route template into an OpenRegister flow document.
 *
 * The route stays a template a secretary authors in decidiq; what runs is a
 * flow of `openregister.user-task` nodes, one per step. Steps that share an
 * order run side by side and join before the next order. Each step's outcome
 * leaves its node through a named exit: approved (and advised) to the next
 * order, rejected to the route's rejected end, returned to an earlier order
 * or, when the approver names none, back to the sender. Object-write nodes
 * keep the decision's route fields current, so no decidiq listener has to.
 *
 * The compiler only builds data. It creates no task, times nothing out and
 * completes nothing: OpenRegister runs the document.
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
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Turns an ApprovalRoute into a flow document OpenRegister can run.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */
class ApprovalRouteFlowCompiler {
	/**
	 * The node types the compiled document uses.
	 */
	public const TYPE_TRIGGER = 'openregister.trigger-manual';
	public const TYPE_TASK    = 'openregister.user-task';
	public const TYPE_WRITE   = 'openregister.object-write';
	public const TYPE_MERGE   = 'openregister.merge';
	public const TYPE_END     = 'openregister.end';

	/**
	 * The outcomes a step can conclude with.
	 */
	public const OUTCOME_APPROVED = 'approved';
	public const OUTCOME_ADVISED  = 'advised';
	public const OUTCOME_REJECTED = 'rejected';
	public const OUTCOME_RETURNED = 'returned';

	/**
	 * The route statuses the flow writes onto the decision.
	 */
	public const STATUS_RUNNING  = 'running';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_RETURNED = 'returned';

	/**
	 * Where the decision lives, for the object-write nodes.
	 */
	private const REGISTER = 'decidiq';
	private const SCHEMA   = 'decision';

	/**
	 * The answer key on a task's form that names the step to return to.
	 */
	public const RETURN_TO_ANSWER = 'returnToStep';

	/**
	 * The step key that names who stands in for the step's person while they are away.
	 *
	 * Set by the starter from the absence the person recorded, not by the route's author.
	 */
	public const SUBSTITUTE_KEY = 'substitute';

	/**
	 * Constructor.
	 *
	 * @param ApprovalRouteFlowCapabilities $capabilities What the installed OpenRegister offers.
	 */
	public function __construct(
		private readonly ApprovalRouteFlowCapabilities $capabilities,
	) {
	}//end __construct()

	/**
	 * The flow document for a route.
	 *
	 * @param array<string, mixed> $route The ApprovalRoute object.
	 *
	 * @return array<string, mixed> The flow: name, description, nodes, edges.
	 *
	 * @throws RuntimeException When the route cannot run as a flow; the message names the step and the reason.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-003-each-step-is-a-task-and-its-outcome-moves-the-route
	 */
	public function compile(array $route): array {
		$refusals = $this->refusals(route: $route);
		if ($refusals !== []) {
			throw new RuntimeException($refusals[0]);
		}

		$orders = $this->orders(route: $route);
		$nodes  = [['id' => 'start', 'type' => self::TYPE_TRIGGER, 'config' => []]];
		$edges  = [];

		$nodes = array_merge($nodes, $this->endNodes());
		$this->addEdge(edges: $edges, from: 'start', to: [self::orderEntry(order: array_key_first($orders))]);

		$orderNumbers = array_keys($orders);
		foreach ($orderNumbers as $position => $order) {
			$next    = self::nextTarget(orderNumbers: $orderNumbers, position: $position);
			$earlier = array_slice($orderNumbers, 0, $position);
			$this->compileOrder(
				order: new ApprovalFlowOrder(number: $order, steps: $orders[$order], next: $next, earlier: $earlier),
				nodes: $nodes,
				edges: $edges
			);
		}

		return [
			'name'        => $this->flowName(route: $route),
			'description' => sprintf('Compiled from approval route %s (%s).', self::routeId(route: $route), self::versionOf(route: $route)),
			'nodes'       => $nodes,
			'edges'       => $edges,
		];
	}//end compile()

	/**
	 * Why a route cannot run as a flow on this OpenRegister, one message per problem.
	 *
	 * Checked before anything is stored, for every step at once, so a refused
	 * route says everything that is wrong rather than the first thing.
	 *
	 * @param array<string, mixed> $route The ApprovalRoute object.
	 *
	 * @return list<string> The refusals; empty when the route compiles.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved
	 */
	public function refusals(array $route): array {
		$steps = $this->steps(route: $route);
		if ($steps === []) {
			return ['This route declares no steps, so there is nothing to send it along.'];
		}

		$refusals = new ApprovalRouteRefusals(capabilities: $this->capabilities);
		$found    = $refusals->ofRoute(route: $route);
		foreach ($steps as $index => $step) {
			$found = array_merge($found, $refusals->ofStep(step: $step, number: self::orderOf(step: $step, index: $index)));
		}

		return $found;
	}//end refusals()

	/**
	 * The key a compiled flow is stored under: the route and its version.
	 *
	 * A changed route is a new key, so a run that started keeps the flow it
	 * started on while new sends use the new one.
	 *
	 * @param array<string, mixed> $route The ApprovalRoute object.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks
	 */
	public function flowName(array $route): string {
		return sprintf('decidiq-approval-route:%s:%s', self::routeId(route: $route), self::versionOf(route: $route));
	}//end flowName()

	/**
	 * The item key a step's outcome lands under.
	 *
	 * One key per step, so parallel steps never overwrite each other's outcome.
	 *
	 * @param int $order The step's order.
	 * @param int $slot  The step's place within its order, from 1.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks
	 */
	public static function outcomeKey(int $order, int $slot): string {
		return sprintf('step_%d_%d', $order, $slot);
	}//end outcomeKey()

	/**
	 * The id of a step's task node.
	 *
	 * @param int $order The step's order.
	 * @param int $slot  The step's place within its order, from 1.
	 *
	 * @return string The node id.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks
	 */
	public static function stepNode(int $order, int $slot): string {
		return sprintf('step-%d-%d', $order, $slot);
	}//end stepNode()

	/**
	 * The id of the node a token enters an order through.
	 *
	 * @param int $order The order.
	 *
	 * @return string The node id.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks
	 */
	public static function orderEntry(int $order): string {
		return sprintf('order-%d', $order);
	}//end orderEntry()

	/**
	 * Compile one order: its entry write, its step nodes and, for a parallel order, the join.
	 *
	 * @param ApprovalFlowOrder                $order The order, its steps and where it leads.
	 * @param list<array<string, mixed>>       $nodes The nodes so far.
	 * @param list<array<string, mixed>>       $edges The edges so far.
	 *
	 * @return void
	 */
	private function compileOrder(ApprovalFlowOrder $order, array &$nodes, array &$edges): void {
		$entry   = self::orderEntry(order: $order->number);
		$nodes[] = $this->writeNode(id: $entry, fields: ['approvalRouteStatus' => self::STATUS_RUNNING, 'approvalCurrentOrder' => $order->number]);

		$stepIds = [];
		foreach (array_values($order->steps) as $offset => $step) {
			$slot      = ($offset + 1);
			$stepIds[] = self::stepNode(order: $order->number, slot: $slot);
			$nodes[]   = $this->taskNode(step: $step, order: $order, slot: $slot);
		}

		$this->addEdge(edges: $edges, from: $entry, to: $stepIds);

		// Approving moves on; for a parallel order, through a join that waits for every branch.
		$onward = $order->next;
		if (count($stepIds) > 1) {
			$join    = sprintf('order-%d-join', $order->number);
			$nodes[] = ['id' => $join, 'type' => self::TYPE_MERGE, 'join' => true, 'config' => []];
			$this->addEdge(edges: $edges, from: $join, to: [$order->next]);
			$onward = $join;
		}

		foreach ($stepIds as $stepId) {
			$this->addEdge(edges: $edges, from: $stepId, to: [$onward], exit: self::OUTCOME_APPROVED);
			$this->addEdge(edges: $edges, from: $stepId, to: ['route-rejected'], exit: self::OUTCOME_REJECTED);
			foreach ($order->earlier as $target) {
				$this->addEdge(edges: $edges, from: $stepId, to: [self::orderEntry(order: $target)], exit: self::returnExit(order: $target));
			}

			$this->addEdge(edges: $edges, from: $stepId, to: ['route-returned'], exit: self::OUTCOME_RETURNED);
		}
	}//end compileOrder()

	/**
	 * One step as a user-task node.
	 *
	 * @param array<string, mixed> $step  The route step.
	 * @param ApprovalFlowOrder    $order The order it belongs to.
	 * @param int                  $slot  Its place within the order, from 1.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-003-each-step-is-a-task-and-its-outcome-moves-the-route
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-005-a-steps-silence-has-the-outcome-the-route-declared-applied-once
	 */
	private function taskNode(array $step, ApprovalFlowOrder $order, int $slot): array {
		$key    = self::outcomeKey(order: $order->number, slot: $slot);
		$config = array_merge(
			[
				'title'      => self::titleOf(step: $step, order: $order->number),
				'outcomes'   => self::outcomesOf(step: $step),
				'outcomeKey' => $key,
			],
			self::performerOf(step: $step)
		);

		$dueAt = trim((string)($step['dueAt'] ?? ''));
		if ($dueAt !== '') {
			$config['dueAt'] = $dueAt;
		}

		$onSilence = trim((string)($step['onSilence'] ?? ''));
		if ($onSilence !== '' && $dueAt !== '') {
			$config['expiresAt'] = $dueAt;
			$config['onTimeout'] = $onSilence;
		}

		$config = array_merge($config, self::returnFormOf(earlier: $order->earlier));

		return [
			'id'     => self::stepNode(order: $order->number, slot: $slot),
			'type'   => self::TYPE_TASK,
			'label'  => self::titleOf(step: $step, order: $order->number),
			'config' => $config,
			'exits'  => self::exitsOf(key: $key, earlier: $order->earlier),
		];
	}//end taskNode()

	/**
	 * A step's exits, in the order the router tries them.
	 *
	 * A named return comes before the plain return, so "returned to step 1"
	 * goes to step 1 and only a return that names no step goes to the sender.
	 *
	 * @param string    $key     The step's outcome key.
	 * @param list<int> $earlier The orders before this one.
	 *
	 * @return list<array<string, mixed>> The exits.
	 */
	private static function exitsOf(string $key, array $earlier): array {
		$outcome = ['var' => 'json.' . $key . '.outcome'];
		$named   = ['var' => 'json.' . $key . '.answers.' . self::RETURN_TO_ANSWER];

		$exits = [
			['id' => self::OUTCOME_APPROVED, 'condition' => ['in' => [$outcome, [self::OUTCOME_APPROVED, self::OUTCOME_ADVISED]]]],
			['id' => self::OUTCOME_REJECTED, 'condition' => ['==' => [$outcome, self::OUTCOME_REJECTED]]],
		];
		foreach ($earlier as $target) {
			$exits[] = [
				'id'        => self::returnExit(order: $target),
				'condition' => ['and' => [['==' => [$outcome, self::OUTCOME_RETURNED]], ['==' => [$named, $target]]]],
			];
		}

		$exits[] = ['id' => self::OUTCOME_RETURNED, 'condition' => ['==' => [$outcome, self::OUTCOME_RETURNED]]];

		return $exits;
	}//end exitsOf()

	/**
	 * The concluding writes and ends: approved, rejected, returned to the sender.
	 *
	 * @return list<array<string, mixed>> The nodes.
	 */
	private function endNodes(): array {
		return [
			$this->writeNode(id: 'route-approved', fields: ['approvalRouteStatus' => self::STATUS_APPROVED, 'approvalCurrentOrder' => null]),
			$this->writeNode(id: 'route-rejected', fields: ['approvalRouteStatus' => self::STATUS_REJECTED, 'approvalCurrentOrder' => null]),
			$this->writeNode(id: 'route-returned', fields: ['approvalRouteStatus' => self::STATUS_RETURNED, 'approvalCurrentOrder' => null]),
			['id' => 'end-approved', 'type' => self::TYPE_END, 'config' => []],
			['id' => 'end-rejected', 'type' => self::TYPE_END, 'config' => []],
			['id' => 'end-returned', 'type' => self::TYPE_END, 'config' => []],
		];
	}//end endNodes()

	/**
	 * A write of the decision's route fields.
	 *
	 * The run's item is the decision, so the write matches it by its own id.
	 * The written object goes under `routeWrite`, leaving the outcomes on the
	 * item for the exits that read them.
	 *
	 * @param string               $id     The node id.
	 * @param array<string, mixed> $fields The fields to write.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function writeNode(string $id, array $fields): array {
		return [
			'id'     => $id,
			'type'   => self::TYPE_WRITE,
			'config' => [
				'register'  => self::REGISTER,
				'schema'    => self::SCHEMA,
				'operation' => 'update',
				'match'     => ['uuid' => '{{id}}'],
				'fields'    => $fields,
				'output'    => 'routeWrite',
			],
		];
	}//end writeNode()

	/**
	 * Append one edge.
	 *
	 * @param list<array<string, mixed>> $edges The edges so far.
	 * @param string                     $from  The source node.
	 * @param list<string>               $to    The target nodes; more than one forks.
	 * @param string|null                $exit  The source's exit this edge leaves through.
	 *
	 * @return void
	 */
	private function addEdge(array &$edges, string $from, array $to, ?string $exit = null): void {
		$edge = ['id' => $from . '>' . implode('+', $to), 'from' => $from, 'to' => $to];
		if (count($to) === 1) {
			$edge['to'] = $to[0];
		}

		if ($exit !== null) {
			$edge['id']      .= ':' . $exit;
			$edge['fromExit'] = $exit;
		}

		$edges[] = $edge;

		// The concluding writes lead to their ends exactly once.
		foreach ($to as $target) {
			self::linkEnd(edges: $edges, target: $target);
		}
	}//end addEdge()

	/**
	 * Add the edge from a concluding write to its end, the first time it is reached.
	 *
	 * @param list<array<string, mixed>> $edges  The edges so far.
	 * @param string                     $target The node just reached.
	 *
	 * @return void
	 */
	private static function linkEnd(array &$edges, string $target): void {
		if (str_starts_with($target, 'route-') === false) {
			return;
		}

		$end = 'end-' . substr($target, strlen('route-'));
		$id  = $target . '>' . $end;
		foreach ($edges as $edge) {
			if (($edge['id'] ?? '') === $id) {
				return;
			}
		}

		$edges[] = ['id' => $id, 'from' => $target, 'to' => $end];
	}//end linkEnd()

	/**
	 * The route's steps grouped by order, orders ascending.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return array<int, list<array<string, mixed>>> The steps per order.
	 */
	private function orders(array $route): array {
		$orders = [];
		foreach ($this->steps(route: $route) as $index => $step) {
			$orders[self::orderOf(step: $step, index: $index)][] = $step;
		}

		ksort($orders);

		return $orders;
	}//end orders()

	/**
	 * The route's steps that are steps.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return list<array<string, mixed>> The steps.
	 */
	private function steps(array $route): array {
		$steps = ($route['steps'] ?? []);
		if (is_array($steps) === false) {
			return [];
		}

		return array_values(array_filter($steps, 'is_array'));
	}//end steps()

	/**
	 * Where approving the last step of an order leads.
	 *
	 * @param list<int> $orderNumbers The orders, ascending.
	 * @param int       $position     This order's position among them.
	 *
	 * @return string The node id.
	 */
	private static function nextTarget(array $orderNumbers, int $position): string {
		if (isset($orderNumbers[($position + 1)]) === false) {
			return 'route-approved';
		}

		return self::orderEntry(order: $orderNumbers[($position + 1)]);
	}//end nextTarget()

	/**
	 * A step's order; a step without one falls back to its position.
	 *
	 * @param array<string, mixed> $step  The step.
	 * @param int                  $index Its zero-based position.
	 *
	 * @return int The order.
	 */
	private static function orderOf(array $step, int $index): int {
		$order = (int)($step['order'] ?? 0);
		if ($order > 0) {
			return $order;
		}

		return ($index + 1);
	}//end orderOf()

	/**
	 * The exit a return to an earlier order leaves through.
	 *
	 * @param int $order The order returned to.
	 *
	 * @return string The exit id.
	 */
	private static function returnExit(int $order): string {
		return sprintf('returned-to-%d', $order);
	}//end returnExit()

	/**
	 * What a step's task asks.
	 *
	 * @param array<string, mixed> $step  The step.
	 * @param int                  $order Its order.
	 *
	 * @return string The title.
	 */
	private static function titleOf(array $step, int $order): string {
		$label = trim((string)($step['label'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		return sprintf('Step %d', $order);
	}//end titleOf()

	/**
	 * The outcomes a step allows; an advice step can also advise.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return list<string> The outcomes.
	 */
	private static function outcomesOf(array $step): array {
		$outcomes = [self::OUTCOME_APPROVED, self::OUTCOME_REJECTED, self::OUTCOME_RETURNED];
		if ((string)($step['stageType'] ?? '') === 'advisory') {
			array_unshift($outcomes, self::OUTCOME_ADVISED);
		}

		return $outcomes;
	}//end outcomesOf()

	/**
	 * Who a step's task is for.
	 *
	 * A person is the assignee. A body or a role is a candidate role, which
	 * OpenRegister's performer resolver expands to the people who hold it.
	 * A person who is away with a substitute is a pool of the two of them.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array<string, string|list<string>> The performer keys of the task node.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-006-a-substitute-can-act-while-the-approver-is-away
	 */
	private static function performerOf(array $step): array {
		$actor = trim((string)($step['actor'] ?? ''));
		if ((string)($step['actorType'] ?? 'role') === 'person') {
			$substitute = trim((string)($step[self::SUBSTITUTE_KEY] ?? ''));
			if ($substitute !== '' && $substitute !== $actor) {
				// Both may act; whoever claims and completes the task first closes the step.
				return ['candidateUsers' => [$actor, $substitute]];
			}

			return ['assignee' => $actor];
		}

		return ['candidateRole' => $actor];
	}//end performerOf()

	/**
	 * The form a step's task offers to name the step a return goes to.
	 *
	 * Only a step with earlier steps gets one: the first step's only return is
	 * to the sender. The field is the decision's own `returnToStep` property,
	 * because OpenRegister validates a task form against its subject schema.
	 *
	 * @param list<int> $earlier The orders before the step.
	 *
	 * @return array<string, mixed> The form keys of the task node; empty for the first step.
	 *
	 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-004-a-return-re-opens-an-earlier-step-and-keeps-the-earlier-outcome
	 */
	private static function returnFormOf(array $earlier): array {
		if ($earlier === []) {
			return [];
		}

		return [
			'formKind'   => 'fields',
			'formSchema' => self::SCHEMA,
			'formFields' => [['field' => self::RETURN_TO_ANSWER, 'required' => false]],
		];
	}//end returnFormOf()

	/**
	 * The route's id.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return string The id.
	 */
	private static function routeId(array $route): string {
		return (string)($route['id'] ?? ($route['@self']['id'] ?? ($route['uuid'] ?? '')));
	}//end routeId()

	/**
	 * A short fingerprint of the route's steps: what changes when the route does.
	 *
	 * @param array<string, mixed> $route The route.
	 *
	 * @return string The version.
	 */
	private static function versionOf(array $route): string {
		return substr(hash('sha256', (string)json_encode($route['steps'] ?? [])), 0, 12);
	}//end versionOf()
}//end class
