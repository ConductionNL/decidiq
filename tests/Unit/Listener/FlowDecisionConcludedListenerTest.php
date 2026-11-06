<?php

/**
 * FlowDecisionConcludedListener: a concluded flow decision wakes the run that
 * asked, and only that run.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCA\Decidiq\Listener\FlowDecisionConcludedListener;
use OCA\Decidiq\Service\FlowDecisionService;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The event is decidiq's REAL DecisionConcludedEvent, built positionally as
 * DecisionLifecycleService builds it; the run is a real FlowRun shape with the
 * resume slots the node writes.
 *
 * @covers \OCA\Decidiq\Listener\FlowDecisionConcludedListener
 * @uses   \OCA\Decidiq\Service\FlowDecisionService
 * @uses   \OCA\Decidiq\Event\DecisionConcludedEvent
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
 */
class FlowDecisionConcludedListenerTest extends TestCase {

	/**
	 * @var FlowRunMapper&MockObject
	 */
	private FlowRunMapper&MockObject $runs;

	/**
	 * @var FlowRunSignalService&MockObject
	 */
	private FlowRunSignalService&MockObject $signals;

	/**
	 * @var FlowDecisionService
	 */
	private FlowDecisionService $decisions;

	protected function setUp(): void {
		parent::setUp();

		$this->runs = $this->createMock(FlowRunMapper::class);
		$this->signals = $this->createMock(FlowRunSignalService::class);
		$this->decisions = $this->getMockBuilder(FlowDecisionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['raise', 'readState'])
			->getMock();
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @return FlowDecisionConcludedListener The listener
	 */
	private function listener(): FlowDecisionConcludedListener {
		return new FlowDecisionConcludedListener($this->decisions, $this->runs, $this->signals, new NullLogger());
	}//end listener()

	/**
	 * A conclusion, constructed positionally as the lifecycle does.
	 *
	 * @param string $decisionId The decision
	 * @param string $sourceApp Who raised it
	 * @param string $externalReference Its external reference
	 *
	 * @return DecisionConcludedEvent The event
	 */
	private static function concluded(string $decisionId = 'd-1', string $sourceApp = 'decidiq-flow', string $externalReference = 'flow-run:run-1:decide'): DecisionConcludedEvent {
		return new DecisionConcludedEvent(
			$decisionId,
			'advice',
			'approved',
			'adopted',
			false,
			null,
			[],
			'2026-09-28T10:00:00+00:00',
			$sourceApp,
			'pipelinq',
			'lead',
			'lead-1',
			$externalReference,
			''
		);
	}//end concluded()

	/**
	 * A suspended run whose node slots record these refs.
	 *
	 * @param array<string, string> $refsByNode node id => decision ref
	 * @param string $status The run status
	 *
	 * @return FlowRun The run
	 */
	private static function suspendedRun(array $refsByNode, string $status = FlowRun::STATUS_SUSPENDED): FlowRun {
		$slots = [];
		foreach ($refsByNode as $nodeId => $ref) {
			$slots[$nodeId] = ['decisionRef' => $ref, 'askedAt' => 'then', 'raisedBy' => 'alice'];
		}

		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus($status);
		$run->setContext([FlowResumeState::CONTEXT_KEY => $slots]);

		return $run;
	}//end run()

	public function testTheConcludedDecisionWakesTheNodeThatAsked(): void {
		$run = self::suspendedRun(['decide-a' => 'd-0', 'decide-b' => 'd-1']);
		$this->runs->expects(self::once())->method('findByUuid')->with('run-1')->willReturn($run);
		$this->runs->expects(self::never())->method('findSuspendedBySubject');

		$this->signals->expects(self::once())->method('signalRunAs')->with(
			$run,
			['decision' => 'approved', 'decisionRef' => 'd-1', 'subjectId' => 'lead-1'],
			null,
			'decide-b'
		)->willReturn($run);

		$this->listener()->handle(self::concluded());
	}//end testTheConcludedDecisionWakesTheNodeThatAsked()

	public function testARunWaitingOnAnotherDecisionIsNotWoken(): void {
		$this->runs->method('findByUuid')->willReturn(self::suspendedRun(['decide' => 'd-9']));
		$this->signals->expects(self::never())->method('signalRunAs');

		$this->listener()->handle(self::concluded());
	}//end testARunWaitingOnAnotherDecisionIsNotWoken()

	public function testARunThatIsNoLongerSuspendedIsNotWoken(): void {
		$this->runs->method('findByUuid')->willReturn(self::suspendedRun(['decide' => 'd-1'], 'completed'));
		$this->signals->expects(self::never())->method('signalRunAs');

		$this->listener()->handle(self::concluded());
	}//end testARunThatIsNoLongerSuspendedIsNotWoken()

	public function testADecisionAnotherAppRaisedIsLeftAlone(): void {
		$this->runs->expects(self::never())->method('findByUuid');
		$this->runs->expects(self::never())->method('findSuspendedBySubject');
		$this->signals->expects(self::never())->method('signalRunAs');

		$this->listener()->handle(self::concluded(sourceApp: 'procest'));
	}//end testADecisionAnotherAppRaisedIsLeftAlone()

	public function testWithoutARunReferenceTheSubjectsRunsAreSearched(): void {
		$other = self::suspendedRun(['decide' => 'd-9']);
		$waiting = self::suspendedRun(['decide' => 'd-1']);
		$this->runs->expects(self::once())->method('findSuspendedBySubject')->with('lead-1')->willReturn([$other, $waiting]);

		$this->signals->expects(self::once())->method('signalRunAs')->with($waiting, self::anything(), null, 'decide')->willReturn($waiting);

		$this->listener()->handle(self::concluded(externalReference: 'lead-1'));
	}//end testWithoutARunReferenceTheSubjectsRunsAreSearched()

	public function testARefusedOrFailedWakeIsSwallowed(): void {
		$run = self::suspendedRun(['decide' => 'd-1']);
		$this->runs->method('findByUuid')->willReturn($run);
		$this->signals->method('signalRunAs')->willThrowException(
			new FlowSignalRefused(reason: FlowSignalRefused::NOT_SUSPENDED, message: 'not suspended', runUuid: 'run-1', actorUid: null)
		);

		$this->listener()->handle(self::concluded());

		$this->addToAssertionCount(1);
	}//end testARefusedOrFailedWakeIsSwallowed()

	public function testAnUnknownRunIsSwallowed(): void {
		$this->runs->method('findByUuid')->willThrowException(new \RuntimeException('no such run'));
		$this->signals->expects(self::never())->method('signalRunAs');

		$this->listener()->handle(self::concluded());
	}//end testAnUnknownRunIsSwallowed()

	public function testAnyOtherEventIsIgnored(): void {
		$this->runs->expects(self::never())->method('findByUuid');

		$this->listener()->handle(new Event());
	}//end testAnyOtherEventIsIgnored()
}//end class
