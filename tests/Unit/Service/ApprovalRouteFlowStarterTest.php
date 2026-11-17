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
use OCA\Decidiq\Service\ApprovalRouteFlowStarter;
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

	protected function setUp(): void {
		$this->gateway = $this->createMock(ApprovalFlowGateway::class);
		$this->store   = $this->createMock(RegisterObjectStore::class);
		$this->starter = new ApprovalRouteFlowStarter(
			new ApprovalRouteFlowCompiler(new ApprovalRouteFlowCapabilities([ApprovalRouteFlowCapabilities::RETURN_EDGE])),
			$this->gateway,
			$this->store
		);
	}//end setUp()

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
			['approvalRun' => 'run-42', 'approvalEngine' => 'flow', 'approvalRouteStatus' => 'running', 'approvalCurrentOrder' => 1],
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
