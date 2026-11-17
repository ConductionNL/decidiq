<?php

/**
 * Tests: an approval route compiles to a flow of OpenRegister user tasks.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ApprovalRouteFlowCapabilities;
use OCA\Decidiq\Service\ApprovalRouteFlowCompiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\ApprovalRouteFlowCompiler
 * @uses   \OCA\Decidiq\Service\ApprovalRouteFlowCapabilities
 * @uses   \OCA\Decidiq\Service\ApprovalFlowOrder
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */
class ApprovalRouteFlowCompilerTest extends TestCase {

	/**
	 * The compiler against an OpenRegister that offers return edges only.
	 *
	 * @var ApprovalRouteFlowCompiler
	 */
	private ApprovalRouteFlowCompiler $compiler;

	protected function setUp(): void {
		$this->compiler = new ApprovalRouteFlowCompiler(
			new ApprovalRouteFlowCapabilities([ApprovalRouteFlowCapabilities::RETURN_EDGE])
		);
	}//end setUp()

	/**
	 * "Raadscyclus Zuiddrecht": four steps, one per order.
	 *
	 * @return array<string, mixed>
	 */
	private static function raadscyclus(): array {
		return [
			'id' => 'route-1',
			'name' => 'Raadscyclus Zuiddrecht',
			'steps' => [
				['order' => 1, 'stageType' => 'advisory', 'actorType' => 'role', 'actor' => 'team-mobiliteit', 'label' => 'Team Mobiliteit'],
				['order' => 2, 'stageType' => 'decisive', 'actorType' => 'body', 'actor' => 'college', 'label' => 'College van B&W'],
				['order' => 3, 'stageType' => 'advisory', 'actorType' => 'body', 'actor' => 'commissie-ruimte', 'label' => 'Commissie Ruimte'],
				['order' => 4, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'wouter', 'label' => 'Gemeenteraad'],
			],
		];
	}//end raadscyclus()

	/**
	 * A route where two steps share order 2.
	 *
	 * @return array<string, mixed>
	 */
	private static function parallel(): array {
		return [
			'id' => 'route-2',
			'steps' => [
				['order' => 1, 'stageType' => 'preparatory', 'actorType' => 'person', 'actor' => 'sanne', 'label' => 'Ambtelijk advies'],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'wethouder', 'label' => 'Wethouder'],
				['order' => 2, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'controller', 'label' => 'Concerncontroller'],
				['order' => 3, 'stageType' => 'decisive', 'actorType' => 'body', 'actor' => 'college', 'label' => 'College'],
			],
		];
	}//end parallel()

	/**
	 * @param array<string, mixed> $flow The flow.
	 * @param string               $id   The node id.
	 *
	 * @return array<string, mixed> The node.
	 */
	private static function node(array $flow, string $id): array {
		foreach ($flow['nodes'] as $node) {
			if ($node['id'] === $id) {
				return $node;
			}
		}

		self::fail('No node ' . $id);
	}//end node()

	/**
	 * Where a node's exit leads.
	 *
	 * @param array<string, mixed> $flow The flow.
	 * @param string               $from The node.
	 * @param string|null          $exit The exit, or null for an unconditioned edge.
	 *
	 * @return list<string> The targets.
	 */
	private static function targets(array $flow, string $from, ?string $exit = null): array {
		$targets = [];
		foreach ($flow['edges'] as $edge) {
			if ($edge['from'] === $from && ($edge['fromExit'] ?? null) === $exit) {
				$targets = array_merge($targets, (array)$edge['to']);
			}
		}

		return $targets;
	}//end targets()

	/**
	 * @param array<string, mixed> $flow The flow.
	 *
	 * @return list<string> The user-task node ids, in document order.
	 */
	private static function taskIds(array $flow): array {
		$ids = [];
		foreach ($flow['nodes'] as $node) {
			if ($node['type'] === ApprovalRouteFlowCompiler::TYPE_TASK) {
				$ids[] = $node['id'];
			}
		}

		return $ids;
	}//end taskIds()

	/**
	 * Scenario: a four-step route compiles.
	 */
	public function testAFourStepRouteCompilesToFourUserTasksInOrder(): void {
		$flow = $this->compiler->compile(self::raadscyclus());

		$this->assertSame(['step-1-1', 'step-2-1', 'step-3-1', 'step-4-1'], self::taskIds($flow));
		$this->assertSame('Team Mobiliteit', self::node($flow, 'step-1-1')['config']['title']);
		$this->assertSame(['order-1'], self::targets($flow, 'start'));
		$this->assertSame(['step-1-1'], self::targets($flow, 'order-1'));
		$this->assertSame(['order-2'], self::targets($flow, 'step-1-1', 'approved'));
		$this->assertSame(['order-3'], self::targets($flow, 'step-2-1', 'approved'));
		$this->assertSame(['order-4'], self::targets($flow, 'step-3-1', 'approved'));
		$this->assertSame(['route-approved'], self::targets($flow, 'step-4-1', 'approved'));
		$this->assertSame(['end-approved'], self::targets($flow, 'route-approved'));
		$this->assertSame('openregister.trigger-manual', self::node($flow, 'start')['type']);
	}//end testAFourStepRouteCompilesToFourUserTasksInOrder()

	/**
	 * Each node lists the outcomes its step allows; an advice step can also advise.
	 */
	public function testEachNodeListsTheOutcomesItsStepAllows(): void {
		$flow = $this->compiler->compile(self::raadscyclus());

		$this->assertSame(['advised', 'approved', 'rejected', 'returned'], self::node($flow, 'step-1-1')['config']['outcomes']);
		$this->assertSame(['approved', 'rejected', 'returned'], self::node($flow, 'step-2-1')['config']['outcomes']);
	}//end testEachNodeListsTheOutcomesItsStepAllows()

	/**
	 * A person is the assignee; a body or a role is a candidate role.
	 */
	public function testThePerformerFollowsTheActorType(): void {
		$flow = $this->compiler->compile(self::raadscyclus());

		$this->assertSame('wouter', self::node($flow, 'step-4-1')['config']['assignee']);
		$this->assertArrayNotHasKey('candidateRole', self::node($flow, 'step-4-1')['config']);
		$this->assertSame('college', self::node($flow, 'step-2-1')['config']['candidateRole']);
		$this->assertSame('team-mobiliteit', self::node($flow, 'step-1-1')['config']['candidateRole']);
	}//end testThePerformerFollowsTheActorType()

	/**
	 * Scenario: two steps with the same order run side by side, and order 3 waits for both.
	 */
	public function testTwoStepsWithTheSameOrderRunSideBySideAndJoin(): void {
		$flow = $this->compiler->compile(self::parallel());

		$this->assertSame(['step-2-1', 'step-2-2'], self::targets($flow, 'order-2'));
		$this->assertSame(['order-2-join'], self::targets($flow, 'step-2-1', 'approved'));
		$this->assertSame(['order-2-join'], self::targets($flow, 'step-2-2', 'approved'));

		$join = self::node($flow, 'order-2-join');
		$this->assertTrue($join['join']);
		$this->assertSame(['order-3'], self::targets($flow, 'order-2-join'));

		// The two branches keep their own outcome, so neither overwrites the other.
		$this->assertSame('step_2_1', self::node($flow, 'step-2-1')['config']['outcomeKey']);
		$this->assertSame('step_2_2', self::node($flow, 'step-2-2')['config']['outcomeKey']);
	}//end testTwoStepsWithTheSameOrderRunSideBySideAndJoin()

	/**
	 * Approving or advising takes the approved exit; the exit reads this step's own outcome.
	 */
	public function testApprovingAndAdvisingShareTheOnwardExit(): void {
		$exits = self::node($this->compiler->compile(self::raadscyclus()), 'step-1-1')['exits'];

		$this->assertSame('approved', $exits[0]['id']);
		$this->assertSame(['in' => [['var' => 'json.step_1_1.outcome'], ['approved', 'advised']]], $exits[0]['condition']);
	}//end testApprovingAndAdvisingShareTheOnwardExit()

	/**
	 * Scenario: rejecting concludes the route as rejected, and no later step is reached.
	 */
	public function testRejectingConcludesTheRoute(): void {
		$flow = $this->compiler->compile(self::raadscyclus());

		$this->assertSame(['route-rejected'], self::targets($flow, 'step-2-1', 'rejected'));
		$this->assertSame(['end-rejected'], self::targets($flow, 'route-rejected'));
		$this->assertSame('rejected', self::node($flow, 'route-rejected')['config']['fields']['approvalRouteStatus']);
	}//end testRejectingConcludesTheRoute()

	/**
	 * Scenario: the committee returns the proposal to step 1; a return naming no step goes to the sender.
	 */
	public function testAReturnReopensTheStepTheApproverNames(): void {
		$flow = $this->compiler->compile(self::raadscyclus());

		$this->assertSame(['order-1'], self::targets($flow, 'step-3-1', 'returned-to-1'));
		$this->assertSame(['order-2'], self::targets($flow, 'step-3-1', 'returned-to-2'));
		$this->assertSame(['route-returned'], self::targets($flow, 'step-3-1', 'returned'));
		$this->assertSame([], self::targets($flow, 'step-3-1', 'returned-to-3'), 'a step cannot return to itself or later');

		$exits = array_column(self::node($flow, 'step-3-1')['exits'], 'id');
		$this->assertSame(['approved', 'rejected', 'returned-to-1', 'returned-to-2', 'returned'], $exits, 'a named return is tried before the plain one');

		$named = self::node($flow, 'step-3-1')['exits'][2]['condition'];
		$this->assertSame(
			['and' => [['==' => [['var' => 'json.step_3_1.outcome'], 'returned']], ['==' => [['var' => 'json.step_3_1.answers.returnToStep'], 1]]]],
			$named
		);
	}//end testAReturnReopensTheStepTheApproverNames()

	/**
	 * The first step has no earlier step, so its only return is to the sender.
	 */
	public function testTheFirstStepReturnsOnlyToTheSender(): void {
		$exits = array_column(self::node($this->compiler->compile(self::raadscyclus()), 'step-1-1')['exits'], 'id');

		$this->assertSame(['approved', 'rejected', 'returned'], $exits);
	}//end testTheFirstStepReturnsOnlyToTheSender()

	/**
	 * Entering an order writes the decision's route fields; the write matches the run's decision.
	 */
	public function testEnteringAnOrderWritesTheDecisionsRouteFields(): void {
		$write = self::node($this->compiler->compile(self::raadscyclus()), 'order-3');

		$this->assertSame('openregister.object-write', $write['type']);
		$this->assertSame('update', $write['config']['operation']);
		$this->assertSame(['uuid' => '{{id}}'], $write['config']['match']);
		$this->assertSame(['approvalRouteStatus' => 'running', 'approvalCurrentOrder' => 3], $write['config']['fields']);
		$this->assertSame('decision', $write['config']['schema']);
	}//end testEnteringAnOrderWritesTheDecisionsRouteFields()

	/**
	 * Every edge endpoint is a declared node, and every node except the ends has a way out.
	 */
	public function testTheDocumentIsClosed(): void {
		$flow = $this->compiler->compile(self::parallel());
		$ids  = array_column($flow['nodes'], 'id');

		$this->assertSame(count($ids), count(array_unique($ids)), 'node ids are unique');
		$edgeIds = array_column($flow['edges'], 'id');
		$this->assertSame(count($edgeIds), count(array_unique($edgeIds)), 'edge ids are unique');
		foreach ($flow['edges'] as $edge) {
			$this->assertContains($edge['from'], $ids);
			foreach ((array)$edge['to'] as $to) {
				$this->assertContains($to, $ids);
			}
		}

		foreach ($flow['nodes'] as $node) {
			if ($node['type'] === ApprovalRouteFlowCompiler::TYPE_END) {
				continue;
			}

			$this->assertNotSame([], array_filter($flow['edges'], static fn (array $e): bool => $e['from'] === $node['id']), $node['id'] . ' has a way out');
		}

		// Every exit a task declares has an edge.
		foreach ($flow['nodes'] as $node) {
			foreach (($node['exits'] ?? []) as $exit) {
				$this->assertNotSame([], self::targets($flow, $node['id'], $exit['id']), $node['id'] . ' exit ' . $exit['id']);
			}
		}
	}//end testTheDocumentIsClosed()

	/**
	 * The flow is keyed on the route and its version: a changed route is a new key.
	 */
	public function testTheFlowIsKeyedOnTheRouteAndItsVersion(): void {
		$route   = self::raadscyclus();
		$changed = $route;
		$changed['steps'][1]['actor'] = 'burgemeester';

		$this->assertStringStartsWith('decidiq-approval-route:route-1:', $this->compiler->flowName($route));
		$this->assertSame($this->compiler->flowName($route), $this->compiler->compile($route)['name']);
		$this->assertNotSame($this->compiler->flowName($route), $this->compiler->flowName($changed));
	}//end testTheFlowIsKeyedOnTheRouteAndItsVersion()

	/**
	 * A step with a due date carries it; silence is only declared with a deadline to time out on.
	 */
	public function testADueStepCarriesItsDeadline(): void {
		$route = self::raadscyclus();
		$route['steps'][0]['dueAt'] = '2026-10-14';

		$this->assertSame('2026-10-14', self::node($this->compiler->compile($route), 'step-1-1')['config']['dueAt']);
		$this->assertArrayNotHasKey('expiresAt', self::node($this->compiler->compile($route), 'step-1-1')['config']);
	}//end testADueStepCarriesItsDeadline()

	/**
	 * Where OpenRegister offers a lapse outcome, the step's task carries the deadline and the declared outcome.
	 */
	public function testADeclaredSilenceRidesOnTheTaskWhereOpenRegisterOffersIt(): void {
		$compiler = new ApprovalRouteFlowCompiler(new ApprovalRouteFlowCapabilities(ApprovalRouteFlowCapabilities::ALL));
		$route    = self::raadscyclus();
		$route['steps'][0]['dueAt']     = '2026-10-14';
		$route['steps'][0]['onSilence'] = 'approve';

		$config = self::node($compiler->compile($route), 'step-1-1')['config'];
		$this->assertSame('2026-10-14', $config['expiresAt']);
		$this->assertSame('approve', $config['onTimeout']);
	}//end testADeclaredSilenceRidesOnTheTaskWhereOpenRegisterOffersIt()

	/**
	 * Scenario: a step names the manager rule on an OpenRegister without it.
	 */
	public function testAStepNamingARuleIsRefusedNamingTheStepAndTheRule(): void {
		$route = self::raadscyclus();
		unset($route['steps'][1]['actor']);
		$route['steps'][1]['actorRule'] = 'manager-of-owning-organisation';

		$refusals = $this->compiler->refusals($route);
		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('Step 2', $refusals[0]);
		$this->assertStringContainsString('performer by rule', $refusals[0]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Step 2 uses performer by rule');
		$this->compiler->compile($route);
	}//end testAStepNamingARuleIsRefusedNamingTheStepAndTheRule()

	/**
	 * Every missing feature is named, each with its step, before anything is stored.
	 */
	public function testEveryMissingFeatureIsNamedWithItsStep(): void {
		$route = self::parallel();
		$route['deadline']                       = '2026-12-01';
		$route['excluded']                       = ['piet'];
		$route['steps'][0]['onSilence']          = 'hold';
		$route['steps'][1]['onSilence']          = 'approve';
		$route['steps'][2]['askSubstituteAfter'] = 3;
		$route['steps'][3]['thresholdKind']      = 'count';

		$this->assertSame(
			[
				'The route uses route deadline split over its steps, which the installed OpenRegister does not offer, so the route cannot be saved.',
				'The route uses exclusion list, which the installed OpenRegister does not offer, so the route cannot be saved.',
				'Step 1 uses hold on silence, which the installed OpenRegister does not offer, so the route cannot be saved.',
				'Step 2 uses declared outcome on silence, which the installed OpenRegister does not offer, so the route cannot be saved.',
				'Step 2 uses reminder before the deadline, which the installed OpenRegister does not offer, so the route cannot be saved.',
				'Step 3 uses threshold of some of the parallel steps, which the installed OpenRegister does not offer, so the route cannot be saved.',
			],
			$this->compiler->refusals($route)
		);
	}//end testEveryMissingFeatureIsNamedWithItsStep()

	/**
	 * The same route passes once OpenRegister offers what it needs.
	 */
	public function testARouteIsAcceptedOnceOpenRegisterOffersTheFeature(): void {
		$route = self::raadscyclus();
		$route['steps'][0]['askSubstituteAfter'] = 3;

		$this->assertNotSame([], $this->compiler->refusals($route));
		$this->assertSame([], (new ApprovalRouteFlowCompiler(new ApprovalRouteFlowCapabilities([ApprovalRouteFlowCapabilities::REMINDER])))->refusals($route));
	}//end testARouteIsAcceptedOnceOpenRegisterOffersTheFeature()

	/**
	 * A step nobody acts on, and a route without steps, are refused.
	 */
	public function testAStepWithoutAPerformerAndAnEmptyRouteAreRefused(): void {
		$route = self::raadscyclus();
		$route['steps'][2]['actor'] = '';

		$this->assertSame(
			['Step 3 names nobody who acts on it; a step that runs as a task needs a person, a body or a role.'],
			$this->compiler->refusals($route)
		);
		$this->assertSame(
			['This route declares no steps, so there is nothing to send it along.'],
			$this->compiler->refusals(['id' => 'r', 'steps' => []])
		);
	}//end testAStepWithoutAPerformerAndAnEmptyRouteAreRefused()
}//end class
