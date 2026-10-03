<?php

/**
 * Decidiq Paper Summary Service
 *
 * A clerk asks Nextcloud's AI for a summary of a paper attached to an agenda
 * item, or for the main differences between two of its papers. The request is
 * checked (PaperSummaryAccess: secretariat, confidentiality circle; PaperText:
 * the paper is attached and has text), a TaskProcessing task is scheduled
 * under the custom id PaperSummaryTaskListener answers to, and only then is
 * the PaperSummary saved as `requested`, so a refused or failed schedule
 * leaves no object behind. A paper longer than one task's input is summarised
 * part by part first, synchronously, and the scheduled task summarises the
 * parts; the summary records that it was (`chunked`).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use OCA\Decidiq\Listener\PaperSummaryTaskListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use Psr\Log\LoggerInterface;

/**
 * Requests AI summaries and comparisons of meeting papers.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 */
class PaperSummaryService {

	/**
	 * The app id the tasks are scheduled under.
	 */
	private const APP_ID = 'decidiq';

	/**
	 * The task type each kind needs.
	 */
	private const TASK_TYPES = [
		'summary' => TextToTextSummary::ID,
		'comparison' => TextToText::ID,
	];

	/**
	 * The instruction of a comparison task.
	 */
	private const COMPARISON_PROMPT = 'Compare the two meeting papers below for a council member. '
		. 'List the main differences in content, figures and proposals, in short paragraphs, '
		. 'and say where they agree. Answer in the language of the papers.';

	/**
	 * Constructor.
	 *
	 * @param PaperSummaryAccess $access Who may ask
	 * @param PaperText $paperText The paper's attachment and text
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service
	 * @param IManager $taskManager The TaskProcessing manager
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PaperSummaryAccess $access,
		private readonly PaperText $paperText,
		private readonly ObjectServiceInterface $objectService,
		private readonly IManager $taskManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Which kinds an installed TaskProcessing provider can answer.
	 *
	 * @return array{available: bool, summary: bool, comparison: bool} Per kind, and whether any.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	public function availability(): array {
		try {
			$types = $this->taskManager->getAvailableTaskTypeIds();
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: the TaskProcessing task types could not be read', ['error' => $e->getMessage()]);
			$types = [];
		}

		$summary = in_array(self::TASK_TYPES['summary'], $types, true);
		$comparison = in_array(self::TASK_TYPES['comparison'], $types, true);

		return ['available' => ($summary === true || $comparison === true), 'summary' => $summary, 'comparison' => $comparison];
	}//end availability()

	/**
	 * Whether the signed-in user may ask for summaries and review them.
	 *
	 * @return bool True for a clerk.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
	 */
	public function canRequest(): bool {
		return $this->access->currentUserIsClerk();
	}//end canRequest()

	/**
	 * Ask for a summary of a paper, or a comparison of it with a second paper.
	 *
	 * @param string $agendaItemId The agenda item the papers are attached to
	 * @param int $fileId The paper's Nextcloud file id
	 * @param string $kind `summary` or `comparison`
	 * @param int|null $comparedFileId The second paper of a comparison
	 *
	 * @return array<string, mixed> The saved summary, status `requested`, with its id.
	 *
	 * @throws PaperSummaryRefusedException 401, 403, 422 or 503 with the reason
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-003-a-confidential-paper-is-not-summarised-outside-its-circle
	 */
	public function request(string $agendaItemId, int $fileId, string $kind, ?int $comparedFileId=null): array {
		$files = $this->papersOf(fileId: $fileId, kind: $kind, comparedFileId: $comparedFileId);
		$uid = '';
		foreach ($files as $file) {
			$uid = $this->access->assertMayRequest(agendaItemId: $agendaItemId, fileId: $file);
		}

		if ($this->availability()[$kind] === false) {
			throw new PaperSummaryRefusedException(message: 'No AI provider is installed that can do this.', status: 503);
		}

		$titles = [];
		foreach ($files as $file) {
			$titles[] = $this->paperText->titleOf(agendaItemId: $agendaItemId, fileId: $file);
		}

		$record = ['agendaItem' => $agendaItemId, 'paperFileId' => $fileId, 'paperTitle' => $titles[0], 'kind' => $kind];
		if (count($files) === 2) {
			$record += ['comparedFileId' => $files[1], 'comparedTitle' => $titles[1]];
		}

		[$input, $chunked] = $this->taskInput(files: $files, titles: $titles, uid: $uid);
		$uuid = $this->uuid();
		$task = new Task(self::TASK_TYPES[$kind], ['input' => $input], self::APP_ID, $uid, PaperSummaryTaskListener::CUSTOM_ID_PREFIX . $uuid);
		try {
			$this->taskManager->scheduleTask($task);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: a paper summary task could not be scheduled', ['error' => $e->getMessage()]);
			throw new PaperSummaryRefusedException(message: 'The AI provider could not take the request. Try again later.', status: 503);
		}

		$record += ['status' => 'requested', 'taskId' => $task->getId(), 'chunked' => $chunked];
		$saved = $this->objectService->saveObject(
			object: $record,
			register: 'decidiq',
			schema: 'paper-summary',
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => ($saved->getUuid() ?? $uuid)] + $record;
	}//end request()

	/**
	 * The papers a request is about: the paper, and for a comparison the
	 * second paper.
	 *
	 * @param int $fileId The paper
	 * @param string $kind `summary` or `comparison`
	 * @param int|null $comparedFileId The second paper of a comparison
	 *
	 * @return array<int, int> One file id, or two for a comparison.
	 *
	 * @throws PaperSummaryRefusedException 422 for another kind or a comparison without a second paper
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	private function papersOf(int $fileId, string $kind, ?int $comparedFileId): array {
		if (isset(self::TASK_TYPES[$kind]) === false) {
			throw new PaperSummaryRefusedException(message: 'Ask for a summary or a comparison.', status: 422);
		}

		if ($kind === 'summary') {
			return [$fileId];
		}

		if ($comparedFileId === null || $comparedFileId === $fileId) {
			throw new PaperSummaryRefusedException(message: 'Pick a second paper to compare with.', status: 422);
		}

		return [$fileId, $comparedFileId];
	}//end papersOf()

	/**
	 * The scheduled task's input: the paper's text for a summary, the
	 * comparison instruction over both papers for a comparison.
	 *
	 * @param array<int, int> $files The papers
	 * @param array<int, string> $titles Their file names
	 * @param string $uid The clerk asking
	 *
	 * @return array{0: string, 1: bool} The input, and whether a paper was summarised in parts.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	private function taskInput(array $files, array $titles, string $uid): array {
		if (count($files) === 1) {
			return $this->condensed(fileId: $files[0], uid: $uid);
		}

		$texts = [];
		$chunked = false;
		foreach ($files as $index => $file) {
			[$text, $inParts] = $this->condensed(fileId: $file, uid: $uid);
			$texts[] = '# ' . $titles[$index] . "\n\n" . $text;
			$chunked = ($chunked === true || $inParts === true);
		}

		return [self::COMPARISON_PROMPT . "\n\n" . implode("\n\n", $texts), $chunked];
	}//end taskInput()

	/**
	 * The paper's text, or for a paper longer than one task's input the
	 * summaries of its parts, run synchronously.
	 *
	 * @param int $fileId The paper
	 * @param string $uid The clerk asking
	 *
	 * @return array{0: string, 1: bool} The text, and whether it was summarised in parts.
	 *
	 * @throws PaperSummaryRefusedException 422 without text, 503 when a part cannot be summarised
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	private function condensed(int $fileId, string $uid): array {
		$parts = $this->paperText->parts(fileId: $fileId);
		if (count($parts) === 1) {
			return [$parts[0], false];
		}

		if ($this->availability()['summary'] === false) {
			throw new PaperSummaryRefusedException(message: 'This paper is too long for the installed AI provider.', status: 503);
		}

		$summaries = [];
		foreach ($parts as $part) {
			try {
				$done = $this->taskManager->runTask(new Task(TextToTextSummary::ID, ['input' => $part], self::APP_ID, $uid));
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq: a part of a long paper could not be summarised', ['fileId' => $fileId, 'error' => $e->getMessage()]);
				throw new PaperSummaryRefusedException(message: 'The AI provider could not summarise this paper. Try again later.', status: 503);
			}

			$summaries[] = trim((string)(($done->getOutput() ?? [])['output'] ?? ''));
		}

		return [implode("\n\n", $summaries), true];
	}//end condensed()

	/**
	 * A random version 4 uuid for the new summary.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuid()
}//end class
