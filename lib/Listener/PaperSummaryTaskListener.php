<?php

/**
 * Decidiq PaperSummaryTaskListener
 *
 * Writes the answer of a paper summary's TaskProcessing task back onto the
 * summary: the text as a draft for the clerk to check, or failed with the
 * provider's message. Tasks of other apps, and other decidiq tasks, are left
 * alone, and a summary a clerk already reviewed is never overwritten.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
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

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\AbstractTaskProcessingEvent;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Lands a paper summary task's answer on its PaperSummary object.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 */
class PaperSummaryTaskListener implements IEventListener {

	/**
	 * The custom id prefix PaperSummaryService gives its tasks.
	 */
	public const CUSTOM_ID_PREFIX = 'paper-summary:';

	/**
	 * The app id the tasks are scheduled under.
	 */
	private const APP_ID = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service
	 * @param IManager $taskManager The TaskProcessing manager, for the provider's id
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IManager $taskManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a TaskProcessing success or failure.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	public function handle(Event $event): void {
		if (($event instanceof AbstractTaskProcessingEvent) === false) {
			return;
		}

		$task = $event->getTask();
		$customId = ($task->getCustomId() ?? '');
		if ($task->getAppId() !== self::APP_ID || str_starts_with($customId, self::CUSTOM_ID_PREFIX) === false) {
			return;
		}

		$summaryId = substr($customId, strlen(self::CUSTOM_ID_PREFIX));
		$summary = $this->objectService->find(
			id: $summaryId,
			register: 'decidiq',
			schema: 'paper-summary',
			_rbac: false,
			_multitenancy: false
		);
		if ($summary === null) {
			$this->logger->info('Decidiq: the paper summary of a finished task no longer exists', ['summary' => $summaryId]);
			return;
		}

		$data = $summary->jsonSerialize();
		if (($data['status'] ?? null) !== 'requested') {
			return;
		}

		$data = array_merge($data, $this->outcome(event: $event, task: $task));
		$this->objectService->saveObject(
			object: $data,
			register: 'decidiq',
			schema: 'paper-summary',
			uuid: $summaryId,
			_rbac: false,
			_multitenancy: false
		);
	}//end handle()

	/**
	 * The fields the task's outcome sets on the summary.
	 *
	 * @param AbstractTaskProcessingEvent $event The success or failure event
	 * @param Task $task The finished task
	 *
	 * @return array<string, mixed> The fields to merge.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	private function outcome(AbstractTaskProcessingEvent $event, Task $task): array {
		if ($event instanceof TaskFailedEvent) {
			return ['status' => 'failed', 'failure' => $event->getErrorMessage(), 'taskId' => $task->getId()];
		}

		if (($event instanceof TaskSuccessfulEvent) === false) {
			return [];
		}

		return [
			'status' => 'draft',
			'text' => (string)(($task->getOutput() ?? [])['output'] ?? ''),
			'provider' => $this->providerId(taskTypeId: $task->getTaskTypeId()),
			'taskId' => $task->getId(),
		];
	}//end outcome()

	/**
	 * The id of the provider that runs this task type, for provenance.
	 *
	 * @param string $taskTypeId The task type
	 *
	 * @return string The provider id, or '' when it cannot be read.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	private function providerId(string $taskTypeId): string {
		try {
			return $this->taskManager->getPreferredProvider($taskTypeId)->getId();
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: the provider of a paper summary task could not be read', ['error' => $e->getMessage()]);
			return '';
		}
	}//end providerId()
}//end class
