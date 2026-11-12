<?php

/**
 * Decidiq Multilingual Reconciliation Service
 *
 * Queues minutes for translation, persists queue entries
 * via OpenRegister, and processes them through a pluggable
 * ITranslationAdapter (default LogTranslationAdapter). Designed to be
 * driven by the TranslationQueueJob on a recurring schedule.
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
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Multilingual minutes reconciliation service.
 *
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualReconciliationService {

	/**
	 * Schema storing queued translation requests.
	 *
	 * @var string
	 */
	public const SCHEMA = 'translation-queue';

	/**
	 * Allowed status values for a queue entry.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['queued', 'processing', 'completed', 'failed'];

	/**
	 * Default ISO 639-1 locales supported by the engine.
	 *
	 * @var string[]
	 */
	public const SUPPORTED_LOCALES = ['nl', 'en', 'de', 'fr'];

	/**
	 * Adapter resolved lazily so tests can swap it without DI.
	 *
	 * @var ITranslationAdapter|null
	 */
	private ?ITranslationAdapter $adapter = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService + adapter)
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Override the translation adapter (test seam).
	 *
	 * @param ITranslationAdapter $adapter Adapter instance
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return void
	 */
	public function setAdapter(ITranslationAdapter $adapter): void {
		$this->adapter = $adapter;

	}//end setAdapter()

	/**
	 * Queue a minutes record for translation into one or more targets.
	 *
	 * The persisted queue entry references the source minutes record and
	 * one target locale; an array of targets fan-out to multiple entries.
	 *
	 * @param string $minutesId UUID of the source minutes record
	 * @param string $sourceLocale ISO 639-1 of the source content
	 * @param string[] $targetLocales ISO 639-1 list of locales to translate into
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, entries: array<int, array<string, mixed>>, message: string}
	 */
	public function queue(string $minutesId, string $sourceLocale, array $targetLocales): array {
		$request = ['minutesId' => $minutesId, 'sourceLocale' => strtolower($sourceLocale), 'targetLocales' => $targetLocales];
		$plan = $this->queueTargets(request: $request);
		if (isset($plan['message']) === true) {
			return ['success' => false, 'entries' => [], 'message' => $plan['message']];
		}

		$entries = $this->saveQueueEntries(request: $request + ['targets' => $plan['targets']]);
		if ($entries === null) {
			return ['success' => false, 'entries' => [], 'message' => 'OpenRegister is unavailable.'];
		}

		return ['success' => true, 'entries' => $entries, 'message' => 'Translation queue entries created.'];

	}//end queue()

	/**
	 * The target locales to queue for a request, or the reason it is refused.
	 *
	 * @param array<string, mixed> $request The minutesId, the lower-cased sourceLocale and the raw targetLocales
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{targets?: string[], message?: string}
	 */
	private function queueTargets(array $request): array {
		if ($request['minutesId'] === '') {
			return ['message' => 'minutesId is required.'];
		}

		if (in_array($request['sourceLocale'], self::SUPPORTED_LOCALES, true) === false) {
			return ['message' => 'Unsupported source locale: ' . $request['sourceLocale']];
		}

		$targets = $this->collectTargetLocales(targetLocales: $request['targetLocales'], sourceLocale: $request['sourceLocale']);
		if ($targets === []) {
			return ['message' => 'No valid target locales supplied.'];
		}

		return ['targets' => $targets];

	}//end queueTargets()

	/**
	 * Create one queue entry per target locale.
	 *
	 * @param array<string, mixed> $request The minutesId, the lower-cased sourceLocale and the targets
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<int, array<string, mixed>>|null The entries, or null when OpenRegister is unavailable
	 */
	private function saveQueueEntries(array $request): ?array {
		$objectService = $this->resolveObjectService(message: 'Decidiq: MultilingualReconciliationService unable to resolve ObjectService');
		if ($objectService === null) {
			return null;
		}

		$request['now'] = gmdate('Y-m-d\TH:i:s\Z');
		$entries = [];
		foreach ($request['targets'] as $targetLocale) {
			$entries[] = $this->saveQueueEntry(objectService: $objectService, entry: $this->queueEntry(request: $request, targetLocale: $targetLocale));
		}

		return $entries;

	}//end saveQueueEntries()

	/**
	 * The ObjectService, or null (logged as an error) when it cannot be resolved.
	 *
	 * @param string $message The log message for a failure
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return object|null
	 */
	private function resolveObjectService(string $message): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error($message, ['exception' => $e->getMessage()]);
			return null;
		}

	}//end resolveObjectService()

	/**
	 * A new queue entry for one target locale.
	 *
	 * @param array<string, mixed> $request The minutesId, sourceLocale and the queue time under `now`
	 * @param string $targetLocale The target locale
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed>
	 */
	private function queueEntry(array $request, string $targetLocale): array {
		return [
			'minutesKoppeling' => $request['minutesId'],
			'sourceLocale' => $request['sourceLocale'],
			'targetLocale' => $targetLocale,
			'status' => 'queued',
			'attempts' => 0,
			'queuedAt' => $request['now'],
			'provider' => null,
			'lastError' => null,
		];

	}//end queueEntry()

	/**
	 * Persist a queue entry; a failure is logged and the unsaved entry returned.
	 *
	 * @param object $objectService Lazy ObjectService
	 * @param array<string, mixed> $entry The new entry
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, mixed> The stored entry, or the entry as built
	 */
	private function saveQueueEntry(object $objectService, array $entry): array {
		try {
			$saved = $objectService->saveObject(object: $entry, register: 'decidiq', schema: self::SCHEMA);
			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				$entry = (array)$saved->jsonSerialize();
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: MultilingualReconciliationService failed to persist queue entry',
				['exception' => $e->getMessage(), 'targetLocale' => $entry['targetLocale']]
			);
		}

		return $entry;

	}//end saveQueueEntry()

	/**
	 * Reduce a raw target-locale list to the supported locales worth queueing.
	 *
	 * Lower-cases every entry, drops anything outside SUPPORTED_LOCALES, drops
	 * the source locale itself and de-duplicates while preserving first-seen order.
	 *
	 * @param string[] $targetLocales Raw requested target locales
	 * @param string $sourceLocale Lower-cased source locale
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return string[]
	 */
	private function collectTargetLocales(array $targetLocales, string $sourceLocale): array {
		$normalised = array_map(
			static fn (mixed $locale): string => strtolower((string)$locale),
			$targetLocales
		);

		return array_values(
			array_unique(
				array_diff(
					array_intersect($normalised, self::SUPPORTED_LOCALES),
					[$sourceLocale]
				)
			)
		);

	}//end collectTargetLocales()

	/**
	 * Return current queue status grouped by status value plus a queue listing.
	 *
	 * @param int $limit Optional max number of entries returned (default 50)
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, summary: array<string, int>, entries: array<int, array<string, mixed>>, message: string}
	 */
	public function status(int $limit = 50): array {
		if ($limit <= 0) {
			$limit = 50;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $this->normalize(rows: $objectService->findAll(['register' => 'decidiq', 'schema' => self::SCHEMA, 'limit' => $limit]));
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: MultilingualReconciliationService::status failed', ['exception' => $e->getMessage()]);
			return ['success' => false, 'summary' => [], 'entries' => [], 'message' => 'Failed to query queue.'];
		}

		return ['success' => true, 'summary' => $this->statusSummary(rows: $rows), 'entries' => $rows, 'message' => 'Queue status loaded.'];

	}//end status()

	/**
	 * The number of queue rows per known status.
	 *
	 * @param array<int, array<string, mixed>> $rows The queue rows
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<string, int>
	 */
	private function statusSummary(array $rows): array {
		$summary = array_fill_keys(self::STATUSES, 0);
		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if (isset($summary[$status]) === true) {
				$summary[$status]++;
			}
		}

		return $summary;

	}//end statusSummary()

	/**
	 * Process up to $maxEntries queued items through the translation adapter.
	 *
	 * Each successful translation creates a linked minutes record in
	 * the target language and flips the queue entry to `completed`.
	 *
	 * @param int $maxEntries Maximum entries processed per call
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, processed: int, completed: int, failed: int, message: string}
	 */
	public function processQueue(int $maxEntries = 10): array {
		if ($maxEntries <= 0) {
			$maxEntries = 10;
		}

		$objectService = $this->resolveObjectService(message: 'Decidiq: MultilingualReconciliationService::processQueue unable to resolve ObjectService');
		if ($objectService === null) {
			return $this->processResult(counts: [0, 0], message: 'OpenRegister is unavailable.');
		}

		$queued = $this->loadQueued(objectService: $objectService, maxEntries: $maxEntries);
		if ($queued === null) {
			return $this->processResult(counts: [0, 0], message: 'Failed to load queue.');
		}

		$counts = [count($queued), $this->processEntries(objectService: $objectService, queued: $queued)];
		return $this->processResult(counts: $counts, message: null);

	}//end processQueue()

	/**
	 * Load the queued entries, or null (logged as an error) when the query fails.
	 *
	 * @param object $objectService Lazy ObjectService
	 * @param int $maxEntries Maximum entries loaded
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function loadQueued(object $objectService, int $maxEntries): ?array {
		try {
			$config = ['register' => 'decidiq', 'schema' => self::SCHEMA, 'filters' => ['status' => 'queued'], 'limit' => $maxEntries];
			return $this->normalize(rows: $objectService->findAll($config));
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: MultilingualReconciliationService::processQueue failed to load queue', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end loadQueued()

	/**
	 * Translate every queued entry and count the ones that failed.
	 *
	 * @param object $objectService Lazy ObjectService
	 * @param array<int, array<string, mixed>> $queued The queued entries
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return int The number of failed entries
	 */
	private function processEntries(object $objectService, array $queued): int {
		$translator = new TranslationQueueEntryProcessor(logger: $this->logger, objectService: $objectService, adapter: $this->resolveAdapter());
		$failed = 0;
		foreach ($queued as $entry) {
			$failed += (int)($translator->process(entry: $entry) === false);
		}

		return $failed;

	}//end processEntries()

	/**
	 * The processQueue() envelope. A message marks a run that could not start.
	 *
	 * @param array{0: int, 1: int} $counts The processed and failed counts
	 * @param string|null $message The failure message, or null for a run that took place
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, processed: int, completed: int, failed: int, message: string}
	 */
	private function processResult(array $counts, ?string $message): array {
		[$processed, $failed] = $counts;
		$completed = ($processed - $failed);
		return [
			'success' => $message === null,
			'processed' => $processed,
			'completed' => $completed,
			'failed' => $failed,
			'message' => $message ?? sprintf('Processed %d entries (%d completed, %d failed).', $processed, $completed, $failed),
		];

	}//end processResult()

	/**
	 * Lazy-resolve the translation adapter (test seam falls back to DI).
	 *
	 * @return ITranslationAdapter
	 */
	private function resolveAdapter(): ITranslationAdapter {
		if ($this->adapter instanceof ITranslationAdapter) {
			return $this->adapter;
		}

		try {
			$adapter = $this->container->get(ITranslationAdapter::class);
			if ($adapter instanceof ITranslationAdapter) {
				return $adapter;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: ITranslationAdapter not bound; using LogTranslationAdapter',
				['exception' => $e->getMessage()]
			);
		}

		return new LogTranslationAdapter(container: $this->container, logger: $this->logger);
	}//end resolveAdapter()

	/**
	 * Normalise heterogeneous findAll() output.
	 *
	 * @param mixed $rows Raw findAll() result
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize(mixed $rows): array {
		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end normalize()
}//end class
