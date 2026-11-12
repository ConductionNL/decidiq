<?php

/**
 * Unit tests for the branches of DecidiqRequestDecisionNode the main suite
 * does not reach: its catalogue metadata, how it reads the subject from an
 * item, the configured decision type, advisor, signal key and heartbeat, and
 * a run that names no acting identity.
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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Flow\DecidiqRequestDecisionNode
 * @uses   \OCA\Decidiq\Service\FlowDecisionService
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 */
class DecidiqRequestDecisionNodeBranchesTest extends TestCase {

	/**
	 * Every raise the service was asked for, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $raises = [];

	/**
	 * What a read answers.
	 *
	 * @var array{state: string, status: string, envelope: array<string, mixed>}
	 */
	private array $answer = [];

	/**
	 * Reset the recorded calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->raises = [];
		$this->answer = ['state' => FlowDecisionService::STATE_OPEN, 'status' => 'pending', 'envelope' => []];
	}//end setUp()

	/**
	 * The node over a decision service that records raises and answers reads.
	 *
	 * @return DecidiqRequestDecisionNode The node under test
	 */
	private function node(): DecidiqRequestDecisionNode {
		$decisions = $this->getMockBuilder(FlowDecisionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['raise', 'readState'])
			->getMock();

		$decisions->method('raise')->willReturnCallback(
			function (string $decisionType, string $externalReference, array $subject, array $context, string $actorId): string {
				$this->raises[] = [
					'decisionType' => $decisionType,
					'externalReference' => $externalReference,
					'subject' => $subject,
					'context' => $context,
					'actorId' => $actorId,
				];

				return 'decision-9';
			}
		);
		$decisions->method('readState')->willReturnCallback(fn (): array => $this->answer);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DecidiqRequestDecisionNode($decisions, $l10n, new NullLogger());
	}//end node()

	/**
	 * One node's resume slot over a fresh run-level state.
	 *
	 * @param array<string, mixed> $values What the slot already holds
	 *
	 * @return FlowNodeResumeState The slot
	 */
	private static function slot(array $values = []): FlowNodeResumeState {
		$slots = [];
		if ($values !== []) {
			$slots['decide'] = $values;
		}

		return (new FlowResumeState($slots))->forNode('decide');
	}//end slot()

	/**
	 * Run the first pass and answer the suspension it parks on.
	 *
	 * @param array<int, mixed>    $items   The items
	 * @param array<string, mixed> $config  The step configuration
	 * @param array<string, mixed> $context The run context
	 *
	 * @return FlowSuspension The suspension
	 */
	private function firstPass(array $items, array $config, array $context): FlowSuspension {
		try {
			$this->node()->execute($items, $config, $context);
		} catch (FlowSuspension $suspension) {
			return $suspension;
		}

		self::fail('The first pass must suspend.');
	}//end firstPass()

	/**
	 * The node describes itself and is offered to admins and users only.
	 *
	 * @return void
	 */
	public function testTheCatalogueMetadata(): void {
		$node = $this->node();

		self::assertSame('Ask Decidiq to decide about this item, and pause the flow until it has.', $node->getDescription());
		self::assertSame('gavel', $node->getIcon());
		self::assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
		self::assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
		self::assertFalse($node->isAvailableForScope(2));
		self::assertSame(['question', 'decisionType', 'advisor', 'signalKey', 'heartbeatMinutes'], $node->configKeys());
	}//end testTheCatalogueMetadata()

	/**
	 * A configured decision type and advisor are sent trimmed, with the question.
	 *
	 * @return void
	 */
	public function testTheConfiguredTypeAndAdvisorAreSent(): void {
		$resume = self::slot();
		$items = [['json' => ['@self' => ['id' => 'lead-1']]]];
		$config = ['question' => ' Akkoord? ', 'decisionType' => ' vote ', 'advisor' => ' juridisch '];

		$suspension = $this->firstPass($items, $config, [FlowNodeResumeState::CONTEXT_KEY => $resume, FlowRunService::RUN_AS_CONTEXT_KEY => 'alice', 'runUuid' => 'run-7']);

		self::assertSame('vote', $this->raises[0]['decisionType']);
		self::assertSame(['question' => 'Akkoord?', 'advisor' => 'juridisch'], $this->raises[0]['context']);
		self::assertSame('flow-run:run-7:decide', $this->raises[0]['externalReference']);
		self::assertSame('waiting for a decision: Akkoord?', $suspension->getMessage());
		self::assertSame('decision-9', $resume->get('decisionRef'));
		self::assertSame('Akkoord?', $resume->get('question'));
		self::assertSame('alice', $resume->get('raisedBy'));
	}//end testTheConfiguredTypeAndAdvisorAreSent()

	/**
	 * The subject falls back to the item id or uuid, and its label to the name or @self name.
	 *
	 * @return void
	 */
	public function testTheSubjectFallsBackAcrossTheItemsFields(): void {
		$context = static fn (): array => [FlowNodeResumeState::CONTEXT_KEY => self::slot(), FlowRunService::RUN_AS_CONTEXT_KEY => 'alice'];

		$this->firstPass([['json' => ['uuid' => ' uuid-3 ', 'name' => 'Aanvraag', '@self' => 'not-an-object']]], ['question' => 'Q'], $context());
		$this->firstPass(
			[['json' => ['id' => 'id-4', '@self' => ['register' => ['id' => 12], 'schema' => ['slug' => 'lead', 'id' => 5], 'name' => 'Zelfnaam']]]],
			['question' => 'Q'],
			$context()
		);

		self::assertSame(
			['subjectRegister' => '', 'subjectSchema' => '', 'subjectId' => 'uuid-3', 'subjectLabel' => 'Aanvraag'],
			$this->raises[0]['subject']
		);
		self::assertSame(
			['subjectRegister' => '12', 'subjectSchema' => 'lead', 'subjectId' => 'id-4', 'subjectLabel' => 'Zelfnaam'],
			$this->raises[1]['subject']
		);
		self::assertSame('advice', $this->raises[0]['decisionType']);
		self::assertSame('uuid-3', $this->raises[0]['externalReference']);
	}//end testTheSubjectFallsBackAcrossTheItemsFields()

	/**
	 * A run naming no identity raises as nobody and records no identity on the slot.
	 *
	 * @return void
	 */
	public function testARunWithoutAnIdentityRecordsNone(): void {
		$resume = self::slot();

		$this->firstPass([['json' => ['id' => 'lead-1']]], ['question' => 'Q'], [FlowNodeResumeState::CONTEXT_KEY => $resume]);

		self::assertSame('', $this->raises[0]['actorId']);
		self::assertSame('decision-9', $resume->get('decisionRef'));
		self::assertNull($resume->get('raisedBy'));
	}//end testARunWithoutAnIdentityRecordsNone()

	/**
	 * The heartbeat defaults to two hours and honours a longer configured one.
	 *
	 * @return void
	 */
	public function testTheHeartbeatDefaultAndConfiguredLength(): void {
		$context = static fn (): array => [FlowNodeResumeState::CONTEXT_KEY => self::slot(), FlowRunService::RUN_AS_CONTEXT_KEY => 'alice'];
		$items = [['json' => ['id' => 'lead-1']]];

		$default = $this->firstPass($items, ['question' => 'Q'], $context());
		$configured = $this->firstPass($items, ['question' => 'Q', 'heartbeatMinutes' => '45'], $context());

		$minutes = static fn (FlowSuspension $s): int => (int)round(($s->getResumeAt()->getTimestamp() - time()) / 60);
		self::assertSame(120, $minutes($default));
		self::assertSame(45, $minutes($configured));
	}//end testTheHeartbeatDefaultAndConfiguredLength()

	/**
	 * A decided outcome lands under the configured key, and non-object items pass through.
	 *
	 * @return void
	 */
	public function testTheOutcomeLandsUnderTheConfiguredKey(): void {
		$this->answer = [
			'state' => FlowDecisionService::STATE_DECIDED,
			'status' => 'rejected',
			'envelope' => ['status' => 'rejected'],
		];
		$resume = self::slot(['decisionRef' => 'decision-9', 'raisedBy' => 'alice']);

		$out = $this->node()->execute(
			[['json' => ['id' => 'lead-1']], 'raw', ['other' => 1]],
			['question' => 'Q', 'signalKey' => ' verdict '],
			[FlowNodeResumeState::CONTEXT_KEY => $resume, FlowRunService::SIGNAL_CONTEXT_KEY => 'not-a-signal']
		);

		self::assertSame(
			['decision' => 'rejected', 'status' => 'rejected', 'decisionRef' => 'decision-9', 'node' => 'decide', 'decidedAt' => '', 'signed' => false, 'recovered' => true],
			$out[0]['json']['verdict']
		);
		self::assertSame('raw', $out[1]);
		self::assertSame(['other' => 1, 'json' => ['verdict' => $out[0]['json']['verdict']]], $out[2]);
		self::assertArrayNotHasKey('decisionOutcome', $out[0]['json']);
	}//end testTheOutcomeLandsUnderTheConfiguredKey()
}//end class
