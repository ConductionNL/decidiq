<?php

/**
 * Unit tests for DecidiqRequestDecisionNode: ask decidiq, wait, then ask again.
 *
 * Ported from dossiq's DossiqRequestDecisionNodeTest, whose properties this
 * node inherits: it FAILS CLOSED when the decision cannot be raised, it raises
 * ONCE however often the run wakes, and every re-entry READS THE DECISION BACK
 * and routes on its state rather than on whatever a wake claims.
 *
 * The decision service is doubled with onlyMethods(): raise() and readState()
 * are driven by the test, and runReference() stays the real one, so the
 * reference the node sends is the reference the listener parses.
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

use OCA\Decidiq\Flow\DecidiqRequestDecisionNode;
use OCA\Decidiq\Service\FlowDecisionService;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\Decidiq\Flow\DecidiqRequestDecisionNode
 * @uses   \OCA\Decidiq\Service\FlowDecisionService
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 */
class DecidiqRequestDecisionNodeTest extends TestCase {

	/**
	 * Every raise the service was asked for, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $raises = [];

	/**
	 * The identities a read was scoped to, in order.
	 *
	 * @var array<int, string>
	 */
	private array $readAs = [];

	/**
	 * What a read answers.
	 *
	 * @var array{state: string, status: string, envelope: array<string, mixed>}
	 */
	private array $answer = ['state' => 'open', 'status' => 'pending', 'envelope' => []];

	protected function setUp(): void {
		parent::setUp();

		$this->raises = [];
		$this->readAs = [];
		$this->answer = ['state' => FlowDecisionService::STATE_OPEN, 'status' => 'pending', 'envelope' => []];
	}//end setUp()

	/**
	 * The node over a decision service that behaves as told.
	 *
	 * @param string|null $ref The ref a raise returns, or null to fail it
	 *
	 * @return DecidiqRequestDecisionNode The node under test
	 */
	private function node(?string $ref = 'decision-1'): DecidiqRequestDecisionNode {
		$decisions = $this->getMockBuilder(FlowDecisionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['raise', 'readState'])
			->getMock();

		$decisions->method('raise')->willReturnCallback(
			function (string $decisionType, string $externalReference, array $subject, array $context, string $actorId) use ($ref): string {
				$this->raises[] = [
					'decisionType' => $decisionType,
					'externalReference' => $externalReference,
					'subject' => $subject,
					'context' => $context,
					'actorId' => $actorId,
				];

				if ($ref === null) {
					throw new RuntimeException('Decidiq did not raise the decision: OpenRegister is not available.');
				}

				return $ref;
			}
		);

		$decisions->method('readState')->willReturnCallback(
			function (string $decisionId, string $actorId): array {
				$this->readAs[] = $actorId;

				return $this->answer;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DecidiqRequestDecisionNode($decisions, $l10n, new NullLogger());
	}//end node()

	/**
	 * Say what decidiq reports on the next read.
	 *
	 * @param string $state One of the STATE_* constants
	 * @param string $status decidiq's status word
	 *
	 * @return void
	 */
	private function decidiqReports(string $state, string $status = ''): void {
		$this->answer = [
			'state' => $state,
			'status' => $status,
			'envelope' => ['status' => $status, 'decidedAt' => '2026-09-28T10:00:00+00:00', 'signed' => true],
		];
	}//end decidiqReports()

	/**
	 * One item carrying an OpenRegister object, serialised as the engine does.
	 *
	 * @return array<int, array<string, mixed>> The items
	 */
	private function items(): array {
		return [[
			'json' => [
				'id' => 'lead-1',
				'title' => 'Dakkapel Dorpsstraat 1',
				'@self' => ['id' => 'lead-1', 'register' => 'pipelinq', 'schema' => 'lead', 'name' => 'lead-1'],
			],
		]];
	}//end items()

	/**
	 * A valid configuration.
	 *
	 * @return array<string, mixed> The config
	 */
	private function config(): array {
		return ['question' => 'Toets aan register B'];
	}//end config()

	/**
	 * The context the engine hands a node.
	 *
	 * @param FlowNodeResumeState $resume The node's slot
	 * @param array<string, mixed> $extra Anything else the context carries
	 * @param string $runAs The run's acting identity
	 *
	 * @return array<string, mixed> The context
	 */
	private static function context(FlowNodeResumeState $resume, array $extra = [], string $runAs = 'alice'): array {
		return array_merge(
			[
				FlowNodeResumeState::CONTEXT_KEY => $resume,
				FlowRunService::RUN_AS_CONTEXT_KEY => $runAs,
				'runUuid' => 'run-1',
			],
			$extra
		);
	}//end context()

	/**
	 * One node's resume slot, built the way the engine builds it: a scoped
	 * view onto the run-level state.
	 *
	 * @param string $nodeId The node the slot belongs to
	 * @param array<string, mixed> $values What the slot already holds
	 *
	 * @return FlowNodeResumeState The scoped handle
	 */
	private static function resumeSlot(string $nodeId, array $values = []): FlowNodeResumeState {
		$slots = [];
		if ($values !== []) {
			$slots[$nodeId] = $values;
		}

		return (new FlowResumeState($slots))->forNode($nodeId);
	}//end resumeSlot()

	public function testItRaisesADecisionAboutTheItemsObjectAndSuspends(): void {
		$resume = self::resumeSlot('decide-register-b');

		try {
			$this->node()->execute($this->items(), $this->config() + ['advisor' => 'bob'], self::context($resume));
			self::fail('The node must suspend while the decision is outstanding.');
		} catch (FlowSuspension $suspension) {
			self::assertStringContainsString('Toets aan register B', $suspension->getMessage());
			self::assertNotNull($suspension->getResumeAt(), 'A decision step parks on a heartbeat, not forever.');
		}

		self::assertCount(1, $this->raises);
		self::assertSame(
			['subjectRegister' => 'pipelinq', 'subjectSchema' => 'lead', 'subjectId' => 'lead-1', 'subjectLabel' => 'Dakkapel Dorpsstraat 1'],
			$this->raises[0]['subject'],
			'The subject is the item\'s object, not a hardcoded dossiq case.'
		);
		self::assertSame('advice', $this->raises[0]['decisionType']);
		self::assertSame(['question' => 'Toets aan register B', 'advisor' => 'bob'], $this->raises[0]['context']);
		self::assertSame('flow-run:run-1:decide-register-b', $this->raises[0]['externalReference']);
		self::assertSame('alice', $this->raises[0]['actorId']);
		self::assertSame('decision-1', $resume->get('decisionRef'));
		self::assertSame('alice', $resume->get('raisedBy'));
		self::assertSame('Toets aan register B', $resume->get('question'));
	}//end testItRaisesADecisionAboutTheItemsObjectAndSuspends()

	public function testAnExpandedRegisterAndSchemaAreNamedBySlug(): void {
		$items = [['json' => ['uuid' => 'x-1', '@self' => ['register' => ['id' => 4, 'slug' => 'dossiq'], 'schema' => ['id' => 9, 'slug' => 'case']]]]];

		try {
			$this->node()->execute($items, ['question' => 'Q', 'decisionType' => 'report-adoption'], self::context(self::resumeSlot('n')));
		} catch (FlowSuspension $e) {
			// expected
		}

		self::assertSame('dossiq', $this->raises[0]['subject']['subjectRegister']);
		self::assertSame('case', $this->raises[0]['subject']['subjectSchema']);
		self::assertSame('x-1', $this->raises[0]['subject']['subjectId']);
		self::assertSame('report-adoption', $this->raises[0]['decisionType']);
	}//end testAnExpandedRegisterAndSchemaAreNamedBySlug()

	public function testWithoutARunUuidTheReferenceFallsBackToTheObject(): void {
		$context = self::context(self::resumeSlot('n'));
		unset($context['runUuid']);

		try {
			$this->node()->execute($this->items(), $this->config(), $context);
		} catch (FlowSuspension $e) {
			// expected
		}

		self::assertSame('lead-1', $this->raises[0]['externalReference']);
	}//end testWithoutARunUuidTheReferenceFallsBackToTheObject()

	public function testAnItemWithNoObjectFailsWithoutRaising(): void {
		try {
			$this->node()->execute([['json' => ['title' => 'no id']]], $this->config(), self::context(self::resumeSlot('n')));
			self::fail('An item with no object must fail the step.');
		} catch (FlowSuspension $e) {
			self::fail('A missing object must NOT read as waiting.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('no object to decide on', $e->getMessage());
		}

		self::assertSame([], $this->raises);
	}//end testAnItemWithNoObjectFailsWithoutRaising()

	public function testTheReadIsScopedToTheIdentityThatRaisedIt(): void {
		$resume = self::resumeSlot('decide-register-b');
		$node = $this->node();

		try {
			$node->execute($this->items(), $this->config(), self::context($resume, runAs: 'alice'));
		} catch (FlowSuspension $e) {
			// first pass
		}

		try {
			$node->execute($this->items(), $this->config(), self::context($resume, runAs: 'bob'));
		} catch (FlowSuspension $e) {
			// still open
		}

		self::assertSame(['alice'], $this->readAs);
	}//end testTheReadIsScopedToTheIdentityThatRaisedIt()

	public function testASlotWithNoRecordedIdentityFallsBackToTheRuns(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'askedAt' => 'then']);
		$this->decidiqReports(FlowDecisionService::STATE_DECIDED, 'approved');

		$out = $this->node()->execute($this->items(), $this->config(), self::context($resume, runAs: 'carol'));

		self::assertSame(['carol'], $this->readAs);
		self::assertSame('approved', $out[0]['json']['decisionOutcome']['decision']);
	}//end testASlotWithNoRecordedIdentityFallsBackToTheRuns()

	public function testARunNamingNoIdentitySuspendsWithoutReading(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1']);
		$this->decidiqReports(FlowDecisionService::STATE_DECIDED, 'approved');

		$this->expectException(FlowSuspension::class);

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume, runAs: ''));
		} finally {
			self::assertSame([], $this->readAs, 'A read naming nobody must not be attempted.');
		}
	}//end testARunNamingNoIdentitySuspendsWithoutReading()

	public function testAHeartbeatDoesNotRaiseTheDecisionAgain(): void {
		$resume = self::resumeSlot('decide-register-b');
		$node = $this->node();

		foreach ([1, 2, 3] as $ignored) {
			try {
				$node->execute($this->items(), $this->config(), self::context($resume));
			} catch (FlowSuspension $e) {
				// expected while unanswered
			}
		}

		self::assertCount(1, $this->raises, 'One question asked once, however often the run wakes.');
	}//end testAHeartbeatDoesNotRaiseTheDecisionAgain()

	public function testAnOpenDecisionSuspendsWithoutTouchingTheSlot(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'askedAt' => 'then', 'raisedBy' => 'alice']);
		$this->decidiqReports(FlowDecisionService::STATE_OPEN, 'pending');

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume));
			self::fail('An open decision must suspend.');
		} catch (FlowSuspension $e) {
			self::assertStringContainsString('Toets aan register B', $e->getMessage());
		}

		self::assertSame('decision-1', $resume->get('decisionRef'));
		self::assertSame('then', $resume->get('askedAt'), 'A heartbeat must not restamp askedAt.');
	}//end testAnOpenDecisionSuspendsWithoutTouchingTheSlot()

	public function testAnUnavailableDecisionServiceFailsTheStep(): void {
		$resume = self::resumeSlot('decide-register-b');

		try {
			$this->node(ref: null)->execute($this->items(), $this->config(), self::context($resume));
			self::fail('The step must fail when the decision cannot be raised.');
		} catch (FlowSuspension $e) {
			self::fail('A failure to raise must NOT read as waiting.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('decision_could_not_be_raised', $e->getMessage());
		}

		self::assertSame('', (string)$resume->get('decisionRef', ''), 'Nothing is recorded for a decision that was never made.');
	}//end testAnUnavailableDecisionServiceFailsTheStep()

	public function testTheOutcomeIsCarriedOntoEveryItem(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(FlowDecisionService::STATE_DECIDED, 'approved');

		$items = array_merge($this->items(), [['json' => ['id' => 'lead-2']]]);
		$out = $this->node()->execute(
			$items,
			array_merge($this->config(), ['signalKey' => 'toets']),
			self::context($resume, [FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approved', 'subjectId' => 'lead-1']])
		);

		self::assertSame(
			[
				'decision' => 'approved',
				'subjectId' => 'lead-1',
				'status' => 'approved',
				'decisionRef' => 'decision-1',
				'node' => 'decide-register-b',
				'decidedAt' => '2026-09-28T10:00:00+00:00',
				'signed' => true,
				'recovered' => false,
			],
			$out[0]['json']['toets'],
			'The output shape is dossiq.requestDecision\'s, plus what the wake added.'
		);
		self::assertSame($out[0]['json']['toets'], $out[1]['json']['toets']);
		self::assertSame([], $this->raises, 'An arriving outcome must not raise anything.');
	}//end testTheOutcomeIsCarriedOntoEveryItem()

	public function testTheReadDecidesAndTheWakeCannotOverrideIt(): void {
		$this->decidiqReports(FlowDecisionService::STATE_DECIDED, 'rejected');

		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$out = $this->node()->execute(
			$this->items(),
			$this->config(),
			self::context($resume, [FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approved', 'decisionRef' => 'other']])
		);

		self::assertSame('rejected', $out[0]['json']['decisionOutcome']['decision'], 'decidiq decides, not the wake.');
		self::assertSame('decision-1', $out[0]['json']['decisionOutcome']['decisionRef']);

		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$out = $this->node()->execute($this->items(), $this->config(), self::context($resume));

		self::assertSame('rejected', $out[0]['json']['decisionOutcome']['decision']);
		self::assertTrue($out[0]['json']['decisionOutcome']['recovered'], 'A recovered outcome says so.');
	}//end testTheReadDecidesAndTheWakeCannotOverrideIt()

	public function testASignalCannotAnswerForAnOpenDecision(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(FlowDecisionService::STATE_OPEN, 'pending');

		$this->expectException(FlowSuspension::class);

		$this->node()->execute(
			$this->items(),
			$this->config(),
			self::context($resume, [FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approved']])
		);
	}//end testASignalCannotAnswerForAnOpenDecision()

	/**
	 * @return array<string, array{0: string, 1: string}> state => expected message fragment
	 */
	public static function deadEnds(): array {
		return [
			'withdrawn' => [FlowDecisionService::STATE_WITHDRAWN, 'withdrawn'],
			'gone' => [FlowDecisionService::STATE_GONE, 'no longer exists'],
			'refused' => [FlowDecisionService::STATE_REFUSED, '"alice"'],
		];
	}//end deadEnds()

	#[DataProvider('deadEnds')]
	public function testAStateNoHeartbeatCanChangeFailsTheStep(string $state, string $fragment): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports($state);

		try {
			$this->node()->execute($this->items(), $this->config(), self::context($resume));
			self::fail('A ' . $state . ' decision must fail the step.');
		} catch (FlowSuspension $e) {
			self::fail('A ' . $state . ' decision must NOT read as waiting.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString($fragment, $e->getMessage());
			self::assertStringContainsString('decision-1', $e->getMessage());
		}
	}//end testAStateNoHeartbeatCanChangeFailsTheStep()

	public function testAnUnreadableDecisionSuspendsRatherThanFailing(): void {
		$resume = self::resumeSlot('decide-register-b', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);
		$this->decidiqReports(FlowDecisionService::STATE_UNREADABLE);

		$this->expectException(FlowSuspension::class);

		$this->node()->execute($this->items(), $this->config(), self::context($resume));
	}//end testAnUnreadableDecisionSuspendsRatherThanFailing()

	public function testTheHeartbeatHasAFloor(): void {
		$resume = self::resumeSlot('n', ['decisionRef' => 'decision-1', 'raisedBy' => 'alice']);

		try {
			$this->node()->execute($this->items(), $this->config() + ['heartbeatMinutes' => 1], self::context($resume));
			self::fail('An open decision must suspend.');
		} catch (FlowSuspension $e) {
			$minutes = (int)round(($e->getResumeAt()->getTimestamp() - time()) / 60);
			self::assertGreaterThanOrEqual(14, $minutes);
			self::assertLessThanOrEqual(15, $minutes);
		}
	}//end testTheHeartbeatHasAFloor()

	public function testAConfigWithNoQuestionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig(['question' => '  ']);
	}//end testAConfigWithNoQuestionIsRefused()

	public function testWithoutAResumeSlotItRefuses(): void {
		$this->expectException(RuntimeException::class);

		$this->node()->execute($this->items(), $this->config(), []);
	}//end testWithoutAResumeSlotItRefuses()

	public function testItAnnouncesTheContract(): void {
		$node = $this->node();

		self::assertSame('decidiq.request-decision', $node->getId());
		self::assertSame(
			['question', 'decisionType', 'advisor', 'signalKey', 'heartbeatMinutes'],
			$node->configKeys(),
			'The config keys are dossiq.requestDecision\'s, name for name.'
		);
		self::assertSame('Request a decision', $node->getDisplayName());
		self::assertNotSame('', $node->getDescription());
		self::assertSame('gavel', $node->getIcon());
		self::assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
		self::assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
	}//end testItAnnouncesTheContract()
}//end class
