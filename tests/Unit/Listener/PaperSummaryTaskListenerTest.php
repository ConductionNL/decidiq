<?php

/**
 * The TaskProcessing result of a paper summary lands as a draft, a failure
 * as failed, and another app's task changes nothing; the listener is wired
 * by the registrar the app boots (agenda-ai-paper-summaries task 4).
 *
 * The events are the real OCP classes around a real OCP Task, so a wrong
 * accessor fails here and not on the first live task.
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
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\CrossAppEventRegistrar;
use OCA\Decidiq\Listener\PaperSummaryTaskListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Decidiq\Listener\PaperSummaryTaskListener
 * @covers \OCA\Decidiq\AppInfo\Registrar\TaskProcessingEventRegistrar
 * @uses   \OCA\Decidiq\AppInfo\Registrar\CrossAppEventRegistrar
 * @uses   \OCA\Decidiq\AppInfo\Registrar\FlowNodeRegistrar
 * @uses   \OCA\Decidiq\AppInfo\Registrar\FilesEventRegistrar
 * @uses   \OCA\Decidiq\AppInfo\OpenRegisterAutoloader
 */
class PaperSummaryTaskListenerTest extends TestCase {

	private const UUID = '7a1d2c3e-0000-4000-8000-000000000119';

	/**
	 * What saveObject() was asked to store, one entry per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * A finished summary task of decidiq for the summary under test.
	 *
	 * @param string $appId    The app that scheduled the task
	 * @param string $customId The task's custom id
	 *
	 * @return Task The task.
	 */
	private function task(string $appId='decidiq', string $customId='paper-summary:' . self::UUID): Task {
		$task = new Task(TextToTextSummary::ID, ['input' => 'De kadernota gaat uit van een sluitende begroting.'], $appId, 'griffier', $customId);
		$task->setId(42);
		$task->setOutput(['output' => 'Het college vraagt de raad de kaders vast te stellen.']);

		return $task;
	}//end task()

	/**
	 * The listener over an object service holding one summary in the given status.
	 *
	 * @param string $status The stored summary's status
	 *
	 * @return array{0: PaperSummaryTaskListener, 1: ObjectServiceInterface&MockObject}
	 */
	private function listener(string $status='requested'): array {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setObject(
			[
				'id' => self::UUID,
				'agendaItem' => 'a1b2c3d4-0000-4000-8000-000000000001',
				'paperFileId' => 900412,
				'kind' => 'summary',
				'status' => $status,
			]
		);

		$objects = $this->getMockBuilder(ObjectServiceInterface::class)->getMock();
		$objects->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntity => ((string)$id === self::UUID ? $entity : null)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use ($entity): ObjectEntity {
				$this->saved[] = $object;
				return $entity;
			}
		);

		$provider = $this->createMock(IProvider::class);
		$provider->method('getId')->willReturn('llm2');
		$manager = $this->createMock(IManager::class);
		$manager->method('getPreferredProvider')->willReturn($provider);

		return [new PaperSummaryTaskListener(objectService: $objects, taskManager: $manager, logger: $this->createMock(LoggerInterface::class)), $objects];
	}//end listener()

	/**
	 * A successful task moves the summary to draft with the text, the provider and the task.
	 *
	 * @return void
	 */
	public function testTheResultArrivesAsADraft(): void {
		[$listener] = $this->listener();
		$listener->handle(new TaskSuccessfulEvent($this->task()));

		self::assertCount(1, $this->saved);
		self::assertSame('draft', $this->saved[0]['status']);
		self::assertSame('Het college vraagt de raad de kaders vast te stellen.', $this->saved[0]['text']);
		self::assertSame('llm2', $this->saved[0]['provider']);
		self::assertSame(42, $this->saved[0]['taskId']);
		self::assertSame(900412, $this->saved[0]['paperFileId'], 'the rest of the summary is kept');
	}//end testTheResultArrivesAsADraft()

	/**
	 * A failed task moves the summary to failed with the provider's message.
	 *
	 * @return void
	 */
	public function testTheProviderFails(): void {
		[$listener] = $this->listener();
		$listener->handle(new TaskFailedEvent($this->task(), 'The provider timed out'));

		self::assertCount(1, $this->saved);
		self::assertSame('failed', $this->saved[0]['status']);
		self::assertSame('The provider timed out', $this->saved[0]['failure']);
		self::assertArrayNotHasKey('text', $this->saved[0]);
	}//end testTheProviderFails()

	/**
	 * Another app's task, or another decidiq task, changes nothing.
	 *
	 * @return void
	 */
	public function testAnotherAppsTaskChangesNothing(): void {
		[$listener, $objects] = $this->listener();
		$objects->expects(self::never())->method('find');
		$listener->handle(new TaskSuccessfulEvent($this->task(appId: 'assistant')));
		$listener->handle(new TaskSuccessfulEvent($this->task(customId: 'minutes-draft:' . self::UUID)));
		$listener->handle(new TaskFailedEvent($this->task(appId: 'assistant'), 'not ours'));

		self::assertSame([], $this->saved);
	}//end testAnotherAppsTaskChangesNothing()

	/**
	 * A late answer never overwrites a summary a clerk already reviewed.
	 *
	 * @return void
	 */
	public function testALateResultLeavesAReviewedSummaryAlone(): void {
		[$listener] = $this->listener(status: 'shown');
		$listener->handle(new TaskSuccessfulEvent($this->task()));

		self::assertSame([], $this->saved);
	}//end testALateResultLeavesAReviewedSummaryAlone()

	/**
	 * The registrar the app boots (Application calls CrossAppEventRegistrar) wires both task events to the listener.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresBothTaskEvents(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new CrossAppEventRegistrar())->register(context: $context);

		self::assertContains([TaskSuccessfulEvent::class, PaperSummaryTaskListener::class], $registered);
		self::assertContains([TaskFailedEvent::class, PaperSummaryTaskListener::class], $registered);
	}//end testTheRegistrarWiresBothTaskEvents()
}//end class
