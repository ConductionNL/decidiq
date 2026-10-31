<?php

/**
 * The heartbeat delivers a conclusion whose wake never arrived, and the wake
 * delivers one when it does: decidiq.request-decision across passes.
 *
 * Ported from dossiq's RequestDecisionHeartbeatRecoveryTest. That suite drove
 * OpenRegister's real engine because dossiq reached decidiq over the bus and
 * the property lived between the two. Here both halves are decidiq's, so the
 * suite drives the REAL node over the REAL FlowDecisionService, integration
 * service and outcome-read guard, and fakes only OpenRegister's object store
 * and the engine's persistence of the resume slot, which it reproduces the way
 * FlowRunService does: the run-level FlowResumeState is JSON round-tripped
 * between passes and rehydrated with FlowResumeState::fromArray().
 *
 * The wake path is driven from its caller: the conclusion event is built by
 * decidiq's own DecisionConcludedEvent::fromEnvelope() from the envelope the
 * decision really carries, and handed to the real listener.
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
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Flow;

use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCA\Decidiq\Flow\DecidiqRequestDecisionNode;
use OCA\Decidiq\Listener\FlowDecisionConcludedListener;
use OCA\Decidiq\Service\FlowDecisionService;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Flow\DecidiqRequestDecisionNode
 * @covers \OCA\Decidiq\Listener\FlowDecisionConcludedListener
 * @uses   \OCA\Decidiq\Service\FlowDecisionService
 * @uses   \OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard
 * @uses   \OCA\Decidiq\Service\DecisionIntegrationService
 * @uses   \OCA\Decidiq\Service\DecisionTypeRegistry
 * @uses   \OCA\Decidiq\Service\DelegatedDecisionDefaults
 * @uses   \OCA\Decidiq\Event\DecisionConcludedEvent
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 */
class RequestDecisionHeartbeatRecoveryTest extends TestCase {
	use FlowDecisionStoreFixture;

	/**
	 * The resume state as the run row persists it: node id => slot.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $persisted = [];

	/**
	 * The real decision service.
	 *
	 * @var FlowDecisionService
	 */
	private FlowDecisionService $service;

	/**
	 * The node under test.
	 *
	 * @var DecidiqRequestDecisionNode
	 */
	private DecidiqRequestDecisionNode $node;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->realFlowDecisionService();
		$this->persisted = [];

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new DecidiqRequestDecisionNode($this->service, $l10n, new NullLogger());
	}//end setUp()

	/**
	 * One pass of the engine over one node: rehydrate the slot, run the node,
	 * persist the slot as the run row would.
	 *
	 * @param string $nodeId The node id in the graph
	 * @param array<string, mixed> $config The step config
	 * @param array<string, mixed>|null $signal A wake payload, or null for a heartbeat
	 * @param string $runAs The run's acting identity
	 *
	 * @return array<int, array<string, mixed>>|null The items when the step advanced, null when it suspended
	 */
	private function pass(string $nodeId, array $config = ['question' => 'Toets aan register B'], ?array $signal = null, string $runAs = 'alice'): ?array {
		$state = FlowResumeState::fromArray($this->persisted);
		$context = [
			FlowNodeResumeState::CONTEXT_KEY => $state->forNode($nodeId),
			FlowRunService::RUN_AS_CONTEXT_KEY => $runAs,
			'runUuid' => 'run-1',
		];
		if ($signal !== null) {
			$context[FlowRunService::SIGNAL_CONTEXT_KEY] = $signal;
		}

		$items = [['json' => ['id' => 'lead-1', 'title' => 'Dakkapel', '@self' => ['id' => 'lead-1', 'register' => 'pipelinq', 'schema' => 'lead']]]];

		try {
			return $this->node->execute($items, $config, $context);
		} catch (FlowSuspension $e) {
			return null;
		} finally {
			$this->persisted = (array)json_decode((string)json_encode($state), true);
		}
	}//end pass()

	/**
	 * The ref a node's persisted slot holds.
	 *
	 * @param string $nodeId The node id
	 *
	 * @return string The decision ref
	 */
	private function refOf(string $nodeId): string {
		return (string)($this->persisted[$nodeId]['decisionRef'] ?? '');
	}//end refOf()

	public function testAHeartbeatDeliversAConclusionWhoseWakeNeverArrived(): void {
		self::assertNull($this->pass('decide'), 'The first pass raises and suspends.');
		$ref = $this->refOf('decide');
		self::assertNotSame('', $ref);

		$this->concludeStored(uuid: $ref, lifecycle: 'decided', outcome: 'adopted');

		$out = $this->pass('decide');

		self::assertNotNull($out, 'The heartbeat must advance a run whose decision is taken.');
		self::assertSame('approved', $out[0]['json']['decisionOutcome']['decision']);
		self::assertSame($ref, $out[0]['json']['decisionOutcome']['decisionRef']);
		self::assertTrue($out[0]['json']['decisionOutcome']['recovered']);
		self::assertCount(1, $this->storedDecisions, 'No second decision was raised.');
	}//end testAHeartbeatDeliversAConclusionWhoseWakeNeverArrived()

	public function testAHeartbeatWithTheDecisionStillOpenParksAgainOnTheSameDecision(): void {
		$this->pass('decide');
		$slot = $this->persisted['decide'];

		self::assertNull($this->pass('decide'));
		self::assertNull($this->pass('decide'));

		self::assertSame($slot, $this->persisted['decide'], 'The ref, the asker and askedAt all survive every heartbeat.');
		self::assertCount(1, $this->storedDecisions);
	}//end testAHeartbeatWithTheDecisionStillOpenParksAgainOnTheSameDecision()

	public function testTwoDecisionStepsOnOneObjectGetTwoDecisions(): void {
		$this->pass('decide-register-b', ['question' => 'Toets aan register B', 'decisionType' => 'advice']);
		$this->pass('decide-tweede-toets', ['question' => 'Tweede inhoudelijke toets', 'decisionType' => 'advice']);

		self::assertNotSame($this->refOf('decide-register-b'), $this->refOf('decide-tweede-toets'));
		self::assertCount(2, $this->storedDecisions);

		// The first concluding does not answer the second.
		$this->concludeStored(uuid: $this->refOf('decide-register-b'), lifecycle: 'decided');
		self::assertNull($this->pass('decide-tweede-toets', ['question' => 'Tweede inhoudelijke toets']));
	}//end testTwoDecisionStepsOnOneObjectGetTwoDecisions()

	public function testARaiseRetriedAfterALostSlotFindsTheDecisionItAlreadyMade(): void {
		$this->pass('decide');
		$ref = $this->refOf('decide');

		// The slot write was lost (a crash between save and commit).
		$this->persisted = [];
		$this->pass('decide');

		self::assertSame($ref, $this->refOf('decide'));
		self::assertCount(1, $this->storedDecisions, 'People are not convened twice for one question.');
	}//end testARaiseRetriedAfterALostSlotFindsTheDecisionItAlreadyMade()

	public function testAWithdrawnDecisionFailsTheStep(): void {
		$this->pass('decide');
		$this->concludeStored(uuid: $this->refOf('decide'), lifecycle: 'withdrawn');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('withdrawn');

		$this->pass('decide');
	}//end testAWithdrawnDecisionFailsTheStep()

	public function testADecisionOwnedBySomebodyElseIsRefusedNotAwaited(): void {
		$this->savingAs = 'bob';
		$this->pass('decide');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('refused');

		$this->pass('decide');
	}//end testADecisionOwnedBySomebodyElseIsRefusedNotAwaited()

	public function testAnUnreachableStoreBuysAnotherHeartbeat(): void {
		$this->pass('decide');
		$this->concludeStored(uuid: $this->refOf('decide'), lifecycle: 'decided');

		$this->storeReachable = false;
		self::assertNull($this->pass('decide'), 'Unreadable is not gone.');

		$this->storeReachable = true;
		self::assertNotNull($this->pass('decide'), 'The next heartbeat reads it and advances.');
	}//end testAnUnreachableStoreBuysAnotherHeartbeat()

	public function testTheWakeAndTheHeartbeatAgree(): void {
		$this->pass('decide');
		$ref = $this->refOf('decide');
		$this->concludeStored(uuid: $ref, lifecycle: 'decided', outcome: 'rejected');

		// The conclusion, built the way DecisionLifecycleService builds it,
		// from the envelope the decision really carries.
		$envelope = $this->service->readState(decisionId: $ref, actorId: 'alice')['envelope'];
		$event = DecisionConcludedEvent::fromEnvelope(
			envelope: $envelope,
			outcome: 'rejected',
			sourceApp: (string)$this->storedDecisions[$ref]['sourceApp']
		);

		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setContext([FlowResumeState::CONTEXT_KEY => $this->persisted]);

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->expects(self::once())->method('findByUuid')->with('run-1')->willReturn($run);

		$delivered = null;
		$signals = $this->createMock(FlowRunSignalService::class);
		$signals->expects(self::once())->method('signalRunAs')->willReturnCallback(
			function (FlowRun $signalled, array $payload, ?string $actorUid, ?string $nodeId) use (&$delivered, $run): FlowRun {
				self::assertSame($run, $signalled);
				self::assertSame('decide', $nodeId, 'The wake is addressed to the node that asked.');
				$delivered = $payload;

				return $signalled;
			}
		);

		(new FlowDecisionConcludedListener($this->service, $runs, $signals, new NullLogger()))->handle($event);

		self::assertIsArray($delivered);
		$woken = $this->pass('decide', signal: $delivered);
		$recovered = $this->pass('decide');

		$announced = $woken[0]['json']['decisionOutcome'];
		$healed = $recovered[0]['json']['decisionOutcome'];

		self::assertFalse($announced['recovered']);
		self::assertTrue($healed['recovered']);
		foreach (['decision', 'status', 'decisionRef', 'node', 'decidedAt', 'signed'] as $field) {
			self::assertSame($healed[$field], $announced[$field], 'The wake and the heartbeat disagree on ' . $field . '.');
		}

		self::assertSame('rejected', $announced['decision']);
		self::assertSame('lead-1', $announced['subjectId'], 'The wake adds what the read does not carry.');
	}//end testTheWakeAndTheHeartbeatAgree()
}//end class
