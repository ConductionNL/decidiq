<?php

/**
 * Decidiq Translation Queue Entry Processor
 *
 * Translates one translation-queue entry for MultilingualReconciliationService:
 * marks it processing, translates the source minutes, writes the translated
 * minutes as a draft and records the outcome on the entry.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Psr\Log\LoggerInterface;

/**
 * Processes translation-queue entries against one ObjectService and adapter.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
final class TranslationQueueEntryProcessor {

	/**
	 * Constructor for TranslationQueueEntryProcessor.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param object $objectService Lazy ObjectService
	 * @param ITranslationAdapter $adapter Resolved translation adapter
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly object $objectService,
		private readonly ITranslationAdapter $adapter,
	) {
	}//end __construct()

	/**
	 * Translate a single queue entry and persist its resulting state.
	 *
	 * @param array<string,mixed> $entry Queue entry row
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return bool True when the entry completed, false when it failed
	 */
	public function process(array $entry): bool {
		$job = $this->startJob(entry: $entry);
		if (isset($job['failure']) === true) {
			$this->failEntry(entry: $entry, changes: $job['failure']);
			return false;
		}

		$translation = $this->adapter->translate((string)($job['source']['content'] ?? ''), $job['sourceLocale'], $job['targetLocale']);
		if ($translation['success'] !== true) {
			$changes = ['provider' => $translation['provider'], 'lastError' => $translation['message'], 'updatedAt' => $job['now']];
			$this->failEntry(entry: $entry, changes: $changes);
			return false;
		}

		$this->completeJob(job: $job, translation: $translation);
		return true;

	}//end process()

	/**
	 * Read an entry, load its source minutes and mark it processing; or say
	 * why it fails.
	 *
	 * @param array<string,mixed> $entry Queue entry row
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed> The job (entry, id, attempts, now, locales, source), or ['failure' => changes]
	 */
	private function startJob(array $entry): array {
		$job = $this->jobOf(entry: $entry);
		if ($job === null) {
			return ['failure' => ['lastError' => 'Malformed queue entry.']];
		}

		$job['source'] = $this->loadMinutes(minutesId: $job['minutesId']);
		if ($job['source'] === null) {
			return ['failure' => ['lastError' => 'Source minutes not found.', 'updatedAt' => $job['now']]];
		}

		$processing = ['status' => 'processing', 'attempts' => ($job['attempts'] + 1), 'updatedAt' => $job['now']];
		$this->updateEntry(entryId: $job['id'], payload: array_merge($entry, $processing));
		return $job;

	}//end startJob()

	/**
	 * The fields of a queue entry, or null when one of them is missing.
	 *
	 * @param array<string,mixed> $entry Queue entry row
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed>|null
	 */
	private function jobOf(array $entry): ?array {
		$job = [
			'entry' => $entry,
			'id' => $this->text(row: $entry, key: 'id'),
			'attempts' => (int)($entry['attempts'] ?? 0),
			'now' => gmdate('Y-m-d\TH:i:s\Z'),
			'minutesId' => $this->text(row: $entry, key: 'minutesKoppeling'),
			'sourceLocale' => $this->text(row: $entry, key: 'sourceLocale'),
			'targetLocale' => $this->text(row: $entry, key: 'targetLocale'),
		];
		if ($job['id'] === '' || in_array('', [$job['minutesId'], $job['sourceLocale'], $job['targetLocale']], true) === true) {
			return null;
		}

		return $job;

	}//end jobOf()

	/**
	 * A row's field as text, empty when it is missing.
	 *
	 * @param array<string,mixed> $row The row
	 * @param string $key The field
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return string
	 */
	private function text(array $row, string $key): string {
		return (string)($row[$key] ?? '');

	}//end text()

	/**
	 * Write the translated minutes and mark the entry completed.
	 *
	 * @param array<string, mixed> $job The started job
	 * @param array<string, mixed> $translation The adapter's answer
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return void
	 */
	private function completeJob(array $job, array $translation): void {
		$job['newMinutesId'] = $this->writeTranslatedMinutes(job: $job, translatedText: (string)$translation['text']);
		$this->updateEntry(entryId: $job['id'], payload: array_merge($job['entry'], $this->completedFields(job: $job, translation: $translation)));

	}//end completeJob()

	/**
	 * The fields that mark an entry completed.
	 *
	 * @param array<string, mixed> $job The started job, with the new minutes id under `newMinutesId`
	 * @param array<string, mixed> $translation The adapter's answer
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed>
	 */
	private function completedFields(array $job, array $translation): array {
		return [
			'status' => 'completed',
			'attempts' => ($job['attempts'] + 1),
			'provider' => $translation['provider'],
			'lastError' => null,
			'translatedMinutesKoppeling' => $job['newMinutesId'],
			'completedAt' => $job['now'],
			'updatedAt' => $job['now'],
		];

	}//end completedFields()

	/**
	 * Flip a queue entry to `failed`, bumping its attempt counter.
	 *
	 * @param array<string,mixed> $entry Queue entry row
	 * @param array<string,mixed> $changes Extra fields merged before the status flip
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return void
	 */
	private function failEntry(array $entry, array $changes): void {
		$failed = ['status' => 'failed', 'attempts' => (((int)($entry['attempts'] ?? 0)) + 1)];
		$this->updateEntry(entryId: $this->text(row: $entry, key: 'id'), payload: array_merge($entry, $failed, $changes));

	}//end failEntry()

	/**
	 * Load a minutes row, returning a plain array or null.
	 *
	 * @param string $minutesId UUID of the minutes record
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadMinutes(string $minutesId): ?array {
		try {
			$entity = $this->objectService->find(id: $minutesId, options: new ObjectFindOptions(register: 'decidiq', schema: 'minutes'));
			if ($entity === null) {
				return null;
			}

			return $this->minutesRow(entity: $entity);
		} catch (\Throwable $e) {
			$context = ['exception' => $e->getMessage(), 'minutesId' => $minutesId];
			$this->logger->warning('Decidiq: MultilingualReconciliationService failed to load minutes', $context);
			return null;
		}

	}//end loadMinutes()

	/**
	 * A loaded minutes entity as a plain array.
	 *
	 * @param object $entity The minutes entity
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed>
	 */
	private function minutesRow(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			return (array)$entity->getObject();
		}

		return (array)$entity->jsonSerialize();

	}//end minutesRow()

	/**
	 * Write a translated minutes record (status=draft) and return its id.
	 *
	 * @param array<string, mixed> $job The started job (source minutes, targetLocale)
	 * @param string $translatedText Translated body
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return string The new minutes id, or '' when it was not stored
	 */
	private function writeTranslatedMinutes(array $job, string $translatedText): string {
		try {
			$saved = $this->objectService->saveObject(
				object: $this->translatedMinutes(job: $job, translatedText: $translatedText),
				register: 'decidiq',
				schema: 'minutes'
			);
			if (is_object($saved) === true) {
				return $this->text(row: (array)$saved->jsonSerialize(), key: 'id');
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: MultilingualReconciliationService failed to persist translated minutes', ['exception' => $e->getMessage()]);
		}

		return '';

	}//end writeTranslatedMinutes()

	/**
	 * The translated minutes record to store.
	 *
	 * @param array<string, mixed> $job The started job (source minutes, targetLocale)
	 * @param string $translatedText Translated body
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed>
	 */
	private function translatedMinutes(array $job, string $translatedText): array {
		$source = $job['source'];
		return [
			'meetingIntegration' => $this->text(row: $source, key: 'meetingIntegration'),
			'language' => $job['targetLocale'],
			'version' => 'draft',
			'content' => $translatedText,
			'reconciliationNotes' => sprintf(
				'Translated from %s by reconciliation engine (source minutes %s).',
				(string)($source['language'] ?? 'unknown'),
				(string)($source['id'] ?? 'unknown')
			),
			'sourceMinutesKoppeling' => $this->text(row: $source, key: 'id'),
		];

	}//end translatedMinutes()

	/**
	 * Persist a queue entry update (best-effort; logs failures).
	 *
	 * @param string $entryId Queue entry UUID
	 * @param array<string, mixed> $payload Updated payload
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return void
	 */
	private function updateEntry(string $entryId, array $payload): void {
		if ($entryId === '') {
			return;
		}

		try {
			$this->objectService->saveObject(object: $payload, register: 'decidiq', schema: MultilingualReconciliationService::SCHEMA, uuid: $entryId);
		} catch (\Throwable $e) {
			$context = ['exception' => $e->getMessage(), 'entryId' => $entryId];
			$this->logger->warning('Decidiq: MultilingualReconciliationService failed to update queue entry', $context);
		}

	}//end updateEntry()
}//end class
