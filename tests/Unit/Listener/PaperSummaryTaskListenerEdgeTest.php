<?php

/**
 * Edge cases of the paper summary task listener.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
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

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\PaperSummaryTaskListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\TaskProcessing\Events\AbstractTaskProcessingEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Other events change nothing; a summary that no longer exists is logged and
 * left; a result whose provider cannot be read is saved without one; and a
 * task event that neither succeeded nor failed saves the summary unchanged.
 *
 * @covers \OCA\Decidiq\Listener\PaperSummaryTaskListener
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 */
class PaperSummaryTaskListenerEdgeTest extends TestCase {

	private const UUID = '7a1d2c3e-0000-4000-8000-000000000119';

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The task manager double.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $taskManager;

	/**
	 * The listener's logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The objects the listener saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The listener under test.
	 *
	 * @var PaperSummaryTaskListener
	 */
	private PaperSummaryTaskListener $listener;

	/**
	 * Set up the listener over the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return new ObjectEntity();
			}
		);
		$this->taskManager = $this->createMock(IManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new PaperSummaryTaskListener(
			objectService: $this->objectService,
			taskManager: $this->taskManager,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * A decidiq summary task with an output.
	 *
	 * @return Task
	 */
	private function task(): Task {
		$task = new Task(TextToTextSummary::ID, ['input' => 'De kadernota.'], 'decidiq', 'griffier', 'paper-summary:' . self::UUID);
		$task->setId(42);
		$task->setOutput(['output' => 'Samenvatting.']);
		return $task;
	}//end task()

	/**
	 * Let find() answer with a requested summary.
	 *
	 * @return void
	 */
	private function summaryIsRequested(): void {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setObject(['status' => 'requested', 'kind' => 'summary']);
		$this->objectService->method('find')->willReturn($entity);
	}//end summaryIsRequested()

	/**
	 * Events that are no task events change nothing.
	 *
	 * @return void
	 */
	public function testOtherEventsChangeNothing(): void {
		$this->objectService->expects(self::never())->method('find');

		$this->listener->handle(new Event());

		self::assertSame([], $this->saved);
	}//end testOtherEventsChangeNothing()

	/**
	 * A summary that no longer exists is logged and nothing is saved.
	 *
	 * @return void
	 */
	public function testAVanishedSummaryIsLogged(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->logger->expects(self::once())->method('info')->with(
			'Decidiq: the paper summary of a finished task no longer exists',
			['summary' => self::UUID]
		);

		$this->listener->handle(new TaskSuccessfulEvent($this->task()));

		self::assertSame([], $this->saved);
	}//end testAVanishedSummaryIsLogged()

	/**
	 * A result whose provider cannot be read is saved without a provider.
	 *
	 * @return void
	 */
	public function testAnUnreadableProviderIsSavedAsEmpty(): void {
		$this->summaryIsRequested();
		$this->taskManager->method('getPreferredProvider')->willThrowException(new RuntimeException('no provider'));
		$this->logger->expects(self::once())->method('debug')->with(
			'Decidiq: the provider of a paper summary task could not be read',
			['error' => 'no provider']
		);

		$this->listener->handle(new TaskSuccessfulEvent($this->task()));

		self::assertCount(1, $this->saved);
		self::assertSame('draft', $this->saved[0]['status']);
		self::assertSame('Samenvatting.', $this->saved[0]['text']);
		self::assertSame('', $this->saved[0]['provider']);
	}//end testAnUnreadableProviderIsSavedAsEmpty()

	/**
	 * A task event that neither succeeded nor failed saves the summary as it is.
	 *
	 * @return void
	 */
	public function testAnotherTaskEventSavesTheSummaryUnchanged(): void {
		$this->summaryIsRequested();
		$event = new class($this->task()) extends AbstractTaskProcessingEvent {
		};

		$this->listener->handle($event);

		self::assertCount(1, $this->saved);
		self::assertSame('requested', $this->saved[0]['status']);
		self::assertArrayNotHasKey('text', $this->saved[0]);
		self::assertArrayNotHasKey('taskId', $this->saved[0]);
	}//end testAnotherTaskEventSavesTheSummaryUnchanged()
}//end class
