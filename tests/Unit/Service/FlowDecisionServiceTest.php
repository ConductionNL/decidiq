<?php

/**
 * FlowDecisionService over the REAL integration service and REAL guard.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\FlowDecisionService;
use OCA\Decidiq\Tests\Unit\Flow\FlowDecisionStoreFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Only OpenRegister's store is faked. The create path, the status derivation
 * and the read rule are decidiq's real ones, so a state this service reports
 * is the state the HTTP outcome endpoint would report.
 *
 * @covers \OCA\Decidiq\Service\FlowDecisionService
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 */
class FlowDecisionServiceTest extends TestCase {
	use FlowDecisionStoreFixture;

	/**
	 * The service under test.
	 *
	 * @var FlowDecisionService
	 */
	private FlowDecisionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->realFlowDecisionService();
	}//end setUp()

	/**
	 * Raise one decision about lead-1.
	 *
	 * @param string $reference The external reference
	 * @param string $label The subject label
	 *
	 * @return string The decision id
	 */
	private function raiseOne(string $reference = 'flow-run:run-1:decide', string $label = 'Dakkapel'): string {
		return $this->service->raise(
			decisionType: 'advice',
			externalReference: $reference,
			subject: ['subjectRegister' => 'pipelinq', 'subjectSchema' => 'lead', 'subjectId' => 'lead-1', 'subjectLabel' => $label],
			context: ['question' => 'Toets aan register B', 'advisor' => ''],
			actorId: 'alice'
		);
	}//end raiseOne()

	public function testARaisedDecisionCarriesTheFlowProvenanceAndTheAsk(): void {
		$id = $this->raiseOne();

		$row = $this->storedDecisions[$id];
		self::assertSame('decidiq-flow', $row['sourceApp']);
		self::assertSame('pipelinq', $row['subjectRegister']);
		self::assertSame('lead', $row['subjectSchema']);
		self::assertSame('lead-1', $row['subjectId']);
		self::assertSame('flow-run:run-1:decide', $row['externalReference']);
		self::assertSame('Dakkapel', $row['title']);
		self::assertSame('Toets aan register B', $row['text'], 'The question is the decision text, so it is schema-valid from birth.');
		self::assertSame('draft', $row['lifecycle']);
	}//end testARaisedDecisionCarriesTheFlowProvenanceAndTheAsk()

	public function testAnObjectWithNoLabelIsTitledByTheQuestion(): void {
		$id = $this->raiseOne(label: '');

		self::assertSame('Toets aan register B', $this->storedDecisions[$id]['title']);
	}//end testAnObjectWithNoLabelIsTitledByTheQuestion()

	public function testTheSameStepRaisesOnceAndAnotherStepRaisesItsOwn(): void {
		$first = $this->raiseOne();
		$retried = $this->raiseOne();
		$second = $this->raiseOne(reference: 'flow-run:run-1:decide-again');

		self::assertSame($first, $retried, 'A retried raise finds the decision it already made.');
		self::assertNotSame($first, $second, 'A second step on the same object gets its own decision.');
		self::assertCount(2, $this->storedDecisions);
	}//end testTheSameStepRaisesOnceAndAnotherStepRaisesItsOwn()

	public function testARaiseFailsClosedWhenOpenRegisterIsAbsent(): void {
		$this->storeReachable = false;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->raiseOne();
	}//end testARaiseFailsClosedWhenOpenRegisterIsAbsent()

	public function testARaiseFailsClosedWhenTheSaveFails(): void {
		$this->saveFails = true;

		$this->expectException(RuntimeException::class);

		$this->raiseOne();
	}//end testARaiseFailsClosedWhenTheSaveFails()

	public function testARaiseFailsClosedOnAnUnknownDecisionType(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unrecognised decisionType');

		$this->service->raise(
			decisionType: 'not-a-type',
			externalReference: 'r',
			subject: ['subjectId' => 'lead-1'],
			context: ['question' => 'Q'],
			actorId: 'alice'
		);
	}//end testARaiseFailsClosedOnAnUnknownDecisionType()

	public function testAFreshDecisionReadsAsOpen(): void {
		$id = $this->raiseOne();

		$read = $this->service->readState(decisionId: $id, actorId: 'alice');

		self::assertSame(FlowDecisionService::STATE_OPEN, $read['state']);
		self::assertSame('pending', $read['status']);
	}//end testAFreshDecisionReadsAsOpen()

	public function testAnAdoptedDecisionReadsAsDecidedApproved(): void {
		$id = $this->raiseOne();
		$this->concludeStored(uuid: $id, lifecycle: 'decided', outcome: 'adopted');

		$read = $this->service->readState(decisionId: $id, actorId: 'alice');

		self::assertSame(FlowDecisionService::STATE_DECIDED, $read['state']);
		self::assertSame('approved', $read['status']);
		self::assertSame('2026-09-28T10:00:00+00:00', $read['envelope']['decidedAt']);
		self::assertSame('flow-run:run-1:decide', $read['envelope']['externalReference']);
	}//end testAnAdoptedDecisionReadsAsDecidedApproved()

	public function testARejectedDecisionReadsAsDecidedRejected(): void {
		$id = $this->raiseOne();
		$this->concludeStored(uuid: $id, lifecycle: 'enacted', outcome: 'rejected');

		$read = $this->service->readState(decisionId: $id, actorId: 'alice');

		self::assertSame(FlowDecisionService::STATE_DECIDED, $read['state']);
		self::assertSame('rejected', $read['status']);
	}//end testARejectedDecisionReadsAsDecidedRejected()

	public function testAWithdrawnDecisionReadsAsWithdrawn(): void {
		$id = $this->raiseOne();
		$this->concludeStored(uuid: $id, lifecycle: 'withdrawn');

		self::assertSame(FlowDecisionService::STATE_WITHDRAWN, $this->service->readState(decisionId: $id, actorId: 'alice')['state']);
	}//end testAWithdrawnDecisionReadsAsWithdrawn()

	public function testAMissingDecisionReadsAsGone(): void {
		self::assertSame(FlowDecisionService::STATE_GONE, $this->service->readState(decisionId: 'nope', actorId: 'alice')['state']);
	}//end testAMissingDecisionReadsAsGone()

	public function testAnotherIdentityIsRefused(): void {
		$id = $this->raiseOne();

		self::assertSame(FlowDecisionService::STATE_REFUSED, $this->service->readState(decisionId: $id, actorId: 'mallory')['state']);
	}//end testAnotherIdentityIsRefused()

	public function testAnUnreachableStoreIsUnreadableNotGone(): void {
		$id = $this->raiseOne();
		$this->storeReachable = false;

		self::assertSame(FlowDecisionService::STATE_UNREADABLE, $this->service->readState(decisionId: $id, actorId: 'alice')['state']);
	}//end testAnUnreachableStoreIsUnreadableNotGone()

	public function testAReadNamingNobodyIsUnreadable(): void {
		$id = $this->raiseOne();

		self::assertSame(FlowDecisionService::STATE_UNREADABLE, $this->service->readState(decisionId: $id, actorId: ' ')['state']);
	}//end testAReadNamingNobodyIsUnreadable()

	public function testARunReferenceRoundTrips(): void {
		$reference = $this->service->runReference(runUuid: 'run-1', nodeId: 'decide:twice');

		self::assertSame('flow-run:run-1:decide:twice', $reference);
		self::assertSame(['runUuid' => 'run-1', 'nodeId' => 'decide:twice'], $this->service->parseRunReference(reference: $reference));
		self::assertNull($this->service->parseRunReference(reference: 'lead-1'));
		self::assertNull($this->service->parseRunReference(reference: 'flow-run:run-1'));
	}//end testARunReferenceRoundTrips()
}//end class
