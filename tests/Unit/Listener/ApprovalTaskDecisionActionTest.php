<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\ApprovalTaskDecisionListener;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the exact engine action the listener builds from an answered task.
 */
class ApprovalTaskDecisionActionTest extends TestCase {

	/**
	 * The actions the engine was asked to record.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * The subjects announced.
	 *
	 * @var array<int, string>
	 */
	private array $announced = [];

	/**
	 * An event over a task answering every getter from $fields.
	 *
	 * @param array<string, mixed> $fields Getter values by getter name.
	 *
	 * @return Event The event.
	 */
	private function eventFor(array $fields): Event {
		$task = new class ($fields) {
			/**
			 * @param array<string, mixed> $fields The canned values.
			 */
			public function __construct(private array $fields) {
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getTemplateId(): mixed {
				return ($this->fields['getTemplateId'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getState(): mixed {
				return ($this->fields['getState'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getObjectUuid(): mixed {
				return ($this->fields['getObjectUuid'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getCompletedBy(): mixed {
				return ($this->fields['getCompletedBy'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getOutcome(): mixed {
				return ($this->fields['getOutcome'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getComment(): mixed {
				return ($this->fields['getComment'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getUuid(): mixed {
				return ($this->fields['getUuid'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getOnBehalfOf(): mixed {
				return ($this->fields['getOnBehalfOf'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getMandate(): mixed {
				return ($this->fields['getMandate'] ?? null);
			}

			/**
			 * @return mixed The canned value.
			 */
			public function getResultText(): mixed {
				return ($this->fields['getResultText'] ?? null);
			}
		};

		return new class ($task) extends Event {
			/**
			 * @param object $task The task.
			 */
			public function __construct(private object $task) {
				parent::__construct();
			}

			/**
			 * The task.
			 *
			 * @return object The task.
			 */
			public function getTask(): object {
				return $this->task;
			}
		};
	}//end eventFor()

	/**
	 * Run the listener over one event with the given active stages.
	 *
	 * @param array<int, array<string, mixed>> $stages The engine's stages.
	 * @param Event                            $event  The event.
	 *
	 * @return void
	 */
	private function handle(array $stages, Event $event): void {
		$this->recorded = [];
		$this->announced = [];
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('stagesFor')->willReturn($stages);
		$engine->method('record')->willReturnCallback(
			function (array $action): array {
				$this->recorded[] = $action;

				return $action;
			}
		);
		$announcer = $this->createMock(ApprovalRouteConclusionAnnouncer::class);
		$announcer->method('announceIfConcluded')->willReturnCallback(
			function (string $subject): void {
				$this->announced[] = $subject;
			}
		);

		$listener = new ApprovalTaskDecisionListener($engine, $announcer, $this->createMock(LoggerInterface::class));
		$listener->handle($event);
	}//end handle()

	/**
	 * The fields of a completed projected task, overridable.
	 *
	 * @param array<string, mixed> $overrides Getter values to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(array $overrides = []): array {
		return array_merge(
			[
				'getTemplateId' => 'decidiq:approval-stage',
				'getState' => 'completed',
				'getObjectUuid' => 'v-1',
				'getCompletedBy' => 'carol',
				'getOutcome' => 'approved',
				'getComment' => 'Akkoord.',
				'getUuid' => 'task-9',
			],
			$overrides
		);
	}//end fields()

	/**
	 * One active stage of the given type carrying task-9.
	 *
	 * @param string $type The stage type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stage(string $type): array {
		return [['status' => 'active', 'stageType' => $type, 'taskUuid' => 'task-9']];
	}//end stage()

	/**
	 * Each stage type maps to its verb; unknown types approve.
	 *
	 * @return void
	 */
	public function testEveryStageTypeMapsToItsVerb(): void {
		$expected = ['advisory' => 'advised', 'endorsement' => 'endorsed', 'decisive' => 'approved', 'other' => 'approved', '' => 'approved'];
		foreach ($expected as $type => $verb) {
			$this->handle($this->stage(type: (string) $type), $this->eventFor(fields: $this->fields()));
			$this->assertSame($verb, $this->recorded[0]['action'], 'type ' . $type);
		}
	}//end testEveryStageTypeMapsToItsVerb()

	/**
	 * A plain completion records the exact action and announces its subject.
	 *
	 * @return void
	 */
	public function testAPlainCompletionRecordsTheExactAction(): void {
		$this->handle($this->stage(type: 'decisive'), $this->eventFor(fields: $this->fields()));

		$this->assertSame([['subject' => 'v-1', 'actor' => 'carol', 'comment' => 'Akkoord.', 'action' => 'approved']], $this->recorded);
		$this->assertSame(['v-1'], $this->announced);
	}//end testAPlainCompletionRecordsTheExactAction()

	/**
	 * A delegate's completion carries the delegate fields.
	 *
	 * @return void
	 */
	public function testADelegateCompletionCarriesTheMandate(): void {
		$this->handle(
			$this->stage(type: 'endorsement'),
			$this->eventFor(fields: $this->fields(overrides: ['getOnBehalfOf' => 'dave', 'getMandate' => 'm-1']))
		);

		$this->assertSame(
			[['subject' => 'v-1', 'actor' => 'carol', 'comment' => 'Akkoord.', 'actorType' => 'delegate', 'onBehalfOf' => 'dave', 'mandate' => 'm-1', 'action' => 'endorsed']],
			$this->recorded
		);
	}//end testADelegateCompletionCarriesTheMandate()

	/**
	 * A rejecting outcome without a reason gets the standard reason; outcomes are trimmed and lowercased.
	 *
	 * @return void
	 */
	public function testARejectionWithoutReasonGetsTheStandardReason(): void {
		$this->handle([], $this->eventFor(fields: $this->fields(overrides: ['getOutcome' => '  Denied ', 'getComment' => '  '])));

		$this->assertSame(
			[['subject' => 'v-1', 'actor' => 'carol', 'comment' => 'Returned from the task inbox without a stated reason.', 'action' => 'returned']],
			$this->recorded
		);
		$this->assertSame(['v-1'], $this->announced);
	}//end testARejectionWithoutReasonGetsTheStandardReason()

	/**
	 * Advice comes from the result text, else from the comment.
	 *
	 * @return void
	 */
	public function testAdviceComesFromTheResultTextElseTheComment(): void {
		$this->handle($this->stage(type: 'advisory'), $this->eventFor(fields: $this->fields(overrides: ['getResultText' => 'Positief advies'])));
		$this->assertSame('Positief advies', $this->recorded[0]['advice']);

		$this->handle($this->stage(type: 'advisory'), $this->eventFor(fields: $this->fields(overrides: ['getResultText' => '   '])));
		$this->assertSame('Akkoord.', $this->recorded[0]['advice']);

		$this->handle($this->stage(type: 'endorsement'), $this->eventFor(fields: $this->fields(overrides: ['getResultText' => 'Ignored'])));
		$this->assertArrayNotHasKey('advice', $this->recorded[0]);
	}//end testAdviceComesFromTheResultTextElseTheComment()

	/**
	 * Only an ACTIVE stage carrying THIS task decides the verb; a blank task uuid records nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheActiveStageOfThisTaskCounts(): void {
		$stages = [
			['status' => 'active', 'stageType' => 'advisory', 'taskUuid' => 'task-other'],
			['status' => 'pending', 'stageType' => 'advisory', 'taskUuid' => 'task-9'],
			['status' => 'active', 'stageType' => 'endorsement', 'taskUuid' => 'task-9'],
		];
		$this->handle($stages, $this->eventFor(fields: $this->fields()));
		$this->assertSame('endorsed', $this->recorded[0]['action']);

		$this->handle(array_slice($stages, 0, 2), $this->eventFor(fields: $this->fields()));
		$this->assertSame([], $this->recorded);

		$this->handle($this->stage(type: 'decisive'), $this->eventFor(fields: $this->fields(overrides: ['getUuid' => ''])));
		$this->assertSame([], $this->recorded);
		$this->assertSame([], $this->announced);
	}//end testOnlyTheActiveStageOfThisTaskCounts()

	/**
	 * A task without subject or completer, or an event without a task, records nothing.
	 *
	 * @return void
	 */
	public function testIncompleteTasksAndEventsRecordNothing(): void {
		$this->handle($this->stage(type: 'decisive'), $this->eventFor(fields: $this->fields(overrides: ['getObjectUuid' => ''])));
		$this->assertSame([], $this->recorded);

		$this->handle($this->stage(type: 'decisive'), $this->eventFor(fields: $this->fields(overrides: ['getCompletedBy' => null])));
		$this->assertSame([], $this->recorded);

		$this->handle($this->stage(type: 'decisive'), new Event());
		$this->assertSame([], $this->recorded);

		$nonObjectTask = new class extends Event {
			/**
			 * Not a task.
			 *
			 * @return string
			 */
			public function getTask(): string {
				return 'task-9';
			}
		};
		$this->handle($this->stage(type: 'decisive'), $nonObjectTask);
		$this->assertSame([], $this->recorded);
	}//end testIncompleteTasksAndEventsRecordNothing()

	/**
	 * An engine refusal logs one warning with subject, actor and reason.
	 *
	 * @return void
	 */
	public function testAnEngineRefusalLogsTheReason(): void {
		$engine = $this->createMock(ApprovalRouteService::class);
		$engine->method('stagesFor')->willReturn($this->stage(type: 'decisive'));
		$engine->method('record')->willThrowException(new RuntimeException('Not yours.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: a completed approval task did not advance the route — the engine refused it',
			['subject' => 'v-1', 'actor' => 'carol', 'reason' => 'Not yours.']
		);

		$listener = new ApprovalTaskDecisionListener($engine, $this->createMock(ApprovalRouteConclusionAnnouncer::class), $logger);
		$listener->handle($this->eventFor(fields: $this->fields()));
	}//end testAnEngineRefusalLogsTheReason()
}//end class
