<?php

/**
 * Tests: sending a proposal starts one flow run with the decision as its subject.
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

use OCA\Decidiq\Service\ApprovalFlowGateway;
use OCA\Decidiq\Service\ApprovalRouteFlowCapabilities;
use OCA\Decidiq\Service\ApprovalRouteFlowCompiler;
use OCA\Decidiq\Service\ApprovalActorResolver;
use OCA\Decidiq\Service\ApprovalRouteFlowStarter;
use OCA\Decidiq\Service\ApprovalStageActivator;
use OCA\Decidiq\Service\NotificationPreferenceService;
use DateTimeImmutable;
use OCA\Decidiq\Service\RegisterObjectStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\ApprovalRouteFlowStarter
 * @uses   \OCA\Decidiq\Service\ApprovalRouteFlowCompiler
 * @uses   \OCA\Decidiq\Service\ApprovalRouteFlowCapabilities
 * @uses   \OCA\Decidiq\Service\ApprovalFlowOrder
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
 */
class ApprovalRouteFlowStarterTest extends TestCase {

	private const DECISION = 'b1a7c0de-0000-4000-8000-000000000001';

	/**
	 * @var ApprovalFlowGateway&MockObject
	 */
	private $gateway;

	/**
	 * @var RegisterObjectStore&MockObject
	 */
	private $store;

	private ApprovalRouteFlowStarter $starter;

	/**
	 * @var NotificationPreferenceService&MockObject
	 */
	private $preferences;

	protected function setUp(): void {
		$this->gateway = $this->createMock(ApprovalFlowGateway::class);
		$this->store   = $this->createMock(RegisterObjectStore::class);
		$this->starter = new ApprovalRouteFlowStarter(
			new ApprovalRouteFlowCompiler(new ApprovalRouteFlowCapabilities([ApprovalRouteFlowCapabilities::RETURN_EDGE])),
			$this->gateway,
			$this->store
		);
		$this->preferences = $this->createMock(NotificationPreferenceService::class);
	}//end setUp()

	/**
	 * A starter that reads absences.
	 *
	 * @return ApprovalRouteFlowStarter
	 */
	private function starterWithAbsences(): ApprovalRouteFlowStarter {
		return new ApprovalRouteFlowStarter(
			new ApprovalRouteFlowCompiler(new ApprovalRouteFlowCapabilities([ApprovalRouteFlowCapabilities::RETURN_EDGE])),
			$this->gateway,
			$this->store,
			new ApprovalStageActivator(store: $this->store, resolver: new ApprovalActorResolver(), preferences: $this->preferences)
		);
	}//end starterWithAbsences()

	/**
	 * A route whose last step is Wouter Bakker himself.
	 *
	 * @return array<string, mixed>
	 */
	private static function routeWithPerson(): array {
		$route = self::route();
		$route['steps'][] = ['order' => 3, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'wouter', 'label' => 'Wouter Bakker'];

		return $route;
	}//end routeWithPerson()

	/**
	 * The task node of one step in a published document.
	 *
	 * @param array<string, mixed> $doc The flow document.
	 * @param string               $id  The node id.
	 *
	 * @return array<string, mixed> The node's config.
	 */
	private static function configOf(array $doc, string $id): array {
		foreach ($doc['nodes'] as $node) {
			if ($node['id'] === $id) {
				return $node['config'];
			}
		}

		self::fail('No node ' . $id);
	}//end configOf()

	/**
	 * Scenario: Lisa signs for Wouter. Wouter is away when the proposal is sent,
	 * so his step's task is offered to him and to Lisa, in a flow of its own.
	 */
	public function testAnAbsenceInForceMakesTheSubstituteACandidate(): void {
		$now = new DateTimeImmutable('2026-10-07T09:00:00+02:00');
		$this->store->method('find')->willReturn(['id' => self::DECISION]);
		$this->store->method('patch')->willReturn([]);
		$this->preferences->expects($this->once())->method('getActiveDelegate')->with('wouter', $now)->willReturn('lisa');

		$published = [];
		$this->gateway->method('publishedFlowFor')->willReturnCallback(static function (array $doc) use (&$published): string {
			$published[] = $doc;
			return 'flow-' . count($published);
		});
		$this->gateway->method('startRun')->willReturn('run-1');

		$this->starterWithAbsences()->start(self::routeWithPerson(), self::DECISION, $now);
		$this->starter->start(self::routeWithPerson(), self::DECISION);

		$this->assertSame(['wouter', 'lisa'], self::configOf($published[0], 'step-3-1')['candidateUsers']);
		$this->assertSame('wouter', self::configOf($published[1], 'step-3-1')['assignee']);
		$this->assertNotSame($published[0]['name'], $published[1]['name'], 'a send during an absence is its own flow version');
	}//end testAnAbsenceInForceMakesTheSubstituteACandidate()

	/**
	 * Nobody away, or an unreadable absence: the step stays with the person.
	 */
	public function testNoAbsenceOrAnUnreadableOneLeavesTheStepWithThePerson(): void {
		$this->store->method('find')->willReturn(['id' => self::DECISION]);
		$this->store->method('patch')->willReturn([]);
		$this->preferences->method('getActiveDelegate')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('store down')));

		$published = [];
		$this->gateway->method('publishedFlowFor')->willReturnCallback(static function (array $doc) use (&$published): string {
			$published[] = $doc;
			return 'flow-x';
		});
		$this->gateway->method('startRun')->willReturn('run-1');

		$starter = $this->starterWithAbsences();
		$starter->start(self::routeWithPerson(), self::DECISION);
		$starter->start(self::routeWithPerson(), self::DECISION);

		foreach ($published as $doc) {
			$this->assertSame('wouter', self::configOf($doc, 'step-3-1')['assignee']);
			$this->assertArrayNotHasKey('candidateUsers', self::configOf($doc, 'step-3-1'));
		}

		$this->assertCount(2, $published);
	}//end testNoAbsenceOrAnUnreadableOneLeavesTheStepWithThePerson()

	/**
	 * "Raadscyclus Zuiddrecht".
	 *
	 * @return array<string, mixed>
	 */
	private static function route(): array {
		return [
			'id' => 'route-1',
			'name' => 'Raadscyclus Zuiddrecht',
			'steps' => [
				['order' => 1, 'stageType' => 'advisory', 'actorType' => 'role', 'actor' => 'team-mobiliteit', 'label' => 'Team Mobiliteit'],
				['order' => 2, 'stageType' => 'decisive', 'actorType' => 'body', 'actor' => 'college', 'label' => 'College van B&W'],
			],
		];
	}//end route()

	/**
	 * Scenario: a proposal is sent. One run, the decision as subject, the run recorded on the decision.
	 */
	public function testSendingStartsExactlyOneRunWithTheDecisionAsSubject(): void {
		$this->store->method('find')->with('decision', self::DECISION)->willReturn(['id' => self::DECISION, 'title' => 'Verordening parkeren binnenstad 2027']);

		$this->gateway->expects($this->once())
			->method('publishedFlowFor')
			->with($this->callback(static fn (array $doc): bool => str_starts_with($doc['name'], 'decidiq-approval-route:route-1:') && count($doc['nodes']) > 2))
			->willReturn('flow-9');
		$this->gateway->expects($this->once())->method('startRun')->with('flow-9', self::DECISION)->willReturn('run-42');
		$this->store->expects($this->once())->method('patch')->with(
			'decision',
			['approvalRun' => 'run-42', 'approvalRoute' => 'route-1', 'approvalEngine' => 'flow', 'approvalRouteStatus' => 'running', 'approvalCurrentOrder' => 1],
			self::DECISION
		)->willReturn([]);

		$this->assertSame(['run' => 'run-42', 'flow' => 'flow-9'], $this->starter->start(self::route(), self::DECISION));
	}//end testSendingStartsExactlyOneRunWithTheDecisionAsSubject()

	/**
	 * Scenario: the same proposal is not sent twice; the refusal names the open step.
	 */
	public function testASecondSendWhileTheRouteIsOpenIsRefusedNamingTheOpenStep(): void {
		$this->store->method('find')->willReturn(['id' => self::DECISION, 'approvalRouteStatus' => 'running', 'approvalCurrentOrder' => 2]);
		$this->gateway->expects($this->never())->method('startRun');
		$this->gateway->expects($this->never())->method('publishedFlowFor');
		$this->store->expects($this->never())->method('patch');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('This proposal is already on its route: step 2 (College van B&W) is still open.');
		$this->starter->start(self::route(), self::DECISION);
	}//end testASecondSendWhileTheRouteIsOpenIsRefusedNamingTheOpenStep()

	/**
	 * A concluded route can be sent again: a returned proposal goes back on its route.
	 */
	public function testAReturnedProposalCanBeSentAgain(): void {
		$this->store->method('find')->willReturn(['id' => self::DECISION, 'approvalRouteStatus' => 'returned']);
		$this->gateway->method('publishedFlowFor')->willReturn('flow-9');
		$this->gateway->expects($this->once())->method('startRun')->willReturn('run-43');
		$this->store->method('patch')->willReturn([]);

		$this->assertSame('run-43', $this->starter->start(self::route(), self::DECISION)['run']);
	}//end testAReturnedProposalCanBeSentAgain()

	/**
	 * A route that cannot compile is refused before anything is stored or run.
	 */
	public function testARouteThatCannotCompileStoresAndRunsNothing(): void {
		$route = self::route();
		$route['steps'][1]['actor'] = '';
		$this->store->method('find')->willReturn(['id' => self::DECISION]);
		$this->gateway->expects($this->never())->method('publishedFlowFor');
		$this->gateway->expects($this->never())->method('startRun');
		$this->store->expects($this->never())->method('patch');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Step 2 names nobody who acts on it');
		$this->starter->start($route, self::DECISION);
	}//end testARouteThatCannotCompileStoresAndRunsNothing()

	/**
	 * A decision that does not exist is refused.
	 */
	public function testAMissingDecisionIsRefused(): void {
		$this->store->method('find')->willReturn(null);
		$this->gateway->expects($this->never())->method('startRun');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The decision to send for approval was not found.');
		$this->starter->start(self::route(), self::DECISION);
	}//end testAMissingDecisionIsRefused()
}//end class
