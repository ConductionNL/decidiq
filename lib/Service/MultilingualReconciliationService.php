<?php
/**
 * Decidesk Multilingual Reconciliation Service
 *
 * Phase 6 — queues board-minutes for translation, persists queue entries
 * via OpenRegister, and processes them through a pluggable
 * ITranslationAdapter (default LogTranslationAdapter). Designed to be
 * driven by the TranslationQueueJob on a recurring schedule.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Multilingual minutes reconciliation service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualReconciliationService
{

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
     * @param LoggerInterface    $logger    Logger
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
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return void
     */
    public function setAdapter(ITranslationAdapter $adapter): void
    {
        $this->adapter = $adapter;

    }//end setAdapter()

    /**
     * Queue a board-minutes record for translation into one or more targets.
     *
     * The persisted queue entry references the source minutes record and
     * one target locale; an array of targets fan-out to multiple entries.
     *
     * @param string   $minutesId     UUID of the source board-minutes record
     * @param string   $sourceLocale  ISO 639-1 of the source content
     * @param string[] $targetLocales ISO 639-1 list of locales to translate into
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return array{success: bool, entries: array<int, array<string, mixed>>, message: string}
     */
    public function queue(string $minutesId, string $sourceLocale, array $targetLocales): array
    {
        if ($minutesId === '') {
            return [
                'success' => false,
                'entries' => [],
                'message' => 'minutesId is required.',
            ];
        }

        $sourceLocale = strtolower($sourceLocale);
        if (in_array($sourceLocale, self::SUPPORTED_LOCALES, true) === false) {
            return [
                'success' => false,
                'entries' => [],
                'message' => 'Unsupported source locale: '.$sourceLocale,
            ];
        }

        $targets = [];
        foreach ($targetLocales as $locale) {
            $locale = strtolower((string) $locale);
            if ($locale === '' || $locale === $sourceLocale) {
                continue;
            }

            if (in_array($locale, self::SUPPORTED_LOCALES, true) === false) {
                continue;
            }

            $targets[$locale] = true;
        }

        if ($targets === []) {
            return [
                'success' => false,
                'entries' => [],
                'message' => 'No valid target locales supplied.',
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MultilingualReconciliationService unable to resolve ObjectService',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'entries' => [],
                'message' => 'OpenRegister is unavailable.',
            ];
        }

        $now     = gmdate('Y-m-d\TH:i:s\Z');
        $entries = [];
        foreach (array_keys($targets) as $targetLocale) {
            $entry = [
                'minutesKoppeling' => $minutesId,
                'sourceLocale'     => $sourceLocale,
                'targetLocale'     => $targetLocale,
                'status'           => 'queued',
                'attempts'         => 0,
                'queuedAt'         => $now,
                'provider'         => null,
                'lastError'        => null,
            ];

            try {
                $saved = $objectService->saveObject(
                    object: $entry,
                    register: 'decidesk',
                    schema: self::SCHEMA
                );
                if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
                    $entry = (array) $saved->jsonSerialize();
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Decidesk: MultilingualReconciliationService failed to persist queue entry',
                    ['exception' => $e->getMessage(), 'targetLocale' => $targetLocale]
                );
            }

            $entries[] = $entry;
        }//end foreach

        return [
            'success' => true,
            'entries' => $entries,
            'message' => 'Translation queue entries created.',
        ];

    }//end queue()

    /**
     * Return current queue status grouped by status value plus a queue listing.
     *
     * @param int $limit Optional max number of entries returned (default 50)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return array{success: bool, summary: array<string, int>, entries: array<int, array<string, mixed>>, message: string}
     */
    public function status(int $limit=50): array
    {
        if ($limit <= 0) {
            $limit = 50;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $this->normalize(
                rows: $objectService->findAll(
                    [
                        'register' => 'decidesk',
                        'schema'   => self::SCHEMA,
                        'limit'    => $limit,
                    ]
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MultilingualReconciliationService::status failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'summary' => [],
                'entries' => [],
                'message' => 'Failed to query queue.',
            ];
        }//end try

        $summary = [];
        foreach (self::STATUSES as $s) {
            $summary[$s] = 0;
        }

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($summary[$status]) === true) {
                $summary[$status]++;
            }
        }

        return [
            'success' => true,
            'summary' => $summary,
            'entries' => $rows,
            'message' => 'Queue status loaded.',
        ];

    }//end status()

    /**
     * Process up to $maxEntries queued items through the translation adapter.
     *
     * Each successful translation creates a linked board-minutes record in
     * the target language and flips the queue entry to `completed`.
     *
     * @param int $maxEntries Maximum entries processed per call
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return array{success: bool, processed: int, completed: int, failed: int, message: string}
     */
    public function processQueue(int $maxEntries=10): array
    {
        if ($maxEntries <= 0) {
            $maxEntries = 10;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MultilingualReconciliationService::processQueue unable to resolve ObjectService',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'   => false,
                'processed' => 0,
                'completed' => 0,
                'failed'    => 0,
                'message'   => 'OpenRegister is unavailable.',
            ];
        }

        try {
            $queued = $this->normalize(
                rows: $objectService->findAll(
                    [
                        'register' => 'decidesk',
                        'schema'   => self::SCHEMA,
                        'filters'  => ['status' => 'queued'],
                        'limit'    => $maxEntries,
                    ]
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MultilingualReconciliationService::processQueue failed to load queue',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'   => false,
                'processed' => 0,
                'completed' => 0,
                'failed'    => 0,
                'message'   => 'Failed to load queue.',
            ];
        }//end try

        $adapter   = $this->resolveAdapter();
        $processed = 0;
        $completed = 0;
        $failed    = 0;

        foreach ($queued as $entry) {
            $processed++;
            $entryId      = (string) ($entry['id'] ?? '');
            $minutesId    = (string) ($entry['minutesKoppeling'] ?? '');
            $sourceLocale = (string) ($entry['sourceLocale'] ?? '');
            $targetLocale = (string) ($entry['targetLocale'] ?? '');
            $attempts     = (int) ($entry['attempts'] ?? 0);
            $now          = gmdate('Y-m-d\TH:i:s\Z');

            if ($entryId === '' || $minutesId === '' || $sourceLocale === '' || $targetLocale === '') {
                $failed++;
                $this->updateEntry(
                    objectService: $objectService,
                    entryId: $entryId,
                    payload: array_merge(
                        $entry,
                        [
                            'status'    => 'failed',
                            'attempts'  => ($attempts + 1),
                            'lastError' => 'Malformed queue entry.',
                        ]
                    )
                );
                continue;
            }

            $sourceMinutes = $this->loadMinutes(objectService: $objectService, minutesId: $minutesId);
            if ($sourceMinutes === null) {
                $failed++;
                $this->updateEntry(
                    objectService: $objectService,
                    entryId: $entryId,
                    payload: array_merge(
                        $entry,
                        [
                            'status'    => 'failed',
                            'attempts'  => ($attempts + 1),
                            'lastError' => 'Source minutes not found.',
                            'updatedAt' => $now,
                        ]
                    )
                );
                continue;
            }

            $this->updateEntry(
                objectService: $objectService,
                entryId: $entryId,
                payload: array_merge(
                    $entry,
                    [
                        'status'    => 'processing',
                        'attempts'  => ($attempts + 1),
                        'updatedAt' => $now,
                    ]
                )
            );

            $source      = (string) ($sourceMinutes['content'] ?? '');
            $translation = $adapter->translate($source, $sourceLocale, $targetLocale);

            if (($translation['success'] ?? false) !== true) {
                $failed++;
                $this->updateEntry(
                    objectService: $objectService,
                    entryId: $entryId,
                    payload: array_merge(
                        $entry,
                        [
                            'status'    => 'failed',
                            'attempts'  => ($attempts + 1),
                            'provider'  => (string) ($translation['provider'] ?? 'unknown'),
                            'lastError' => (string) ($translation['message'] ?? 'Translation failed.'),
                            'updatedAt' => $now,
                        ]
                    )
                );
                continue;
            }

            $newMinutesId = $this->writeTranslatedMinutes(
                objectService: $objectService,
                sourceMinutes: $sourceMinutes,
                translatedText: (string) $translation['text'],
                targetLocale: $targetLocale
            );

            $completed++;
            $this->updateEntry(
                objectService: $objectService,
                entryId: $entryId,
                payload: array_merge(
                    $entry,
                    [
                        'status'                     => 'completed',
                        'attempts'                   => ($attempts + 1),
                        'provider'                   => (string) ($translation['provider'] ?? 'log'),
                        'lastError'                  => null,
                        'translatedMinutesKoppeling' => $newMinutesId,
                        'completedAt'                => $now,
                        'updatedAt'                  => $now,
                    ]
                )
            );
        }//end foreach

        return [
            'success'   => true,
            'processed' => $processed,
            'completed' => $completed,
            'failed'    => $failed,
            'message'   => sprintf(
                'Processed %d entries (%d completed, %d failed).',
                $processed,
                $completed,
                $failed
            ),
        ];

    }//end processQueue()

    /**
     * Lazy-resolve the translation adapter (test seam falls back to DI).
     *
     * @return ITranslationAdapter
     */
    private function resolveAdapter(): ITranslationAdapter
    {
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
                'Decidesk: ITranslationAdapter not bound; using LogTranslationAdapter',
                ['exception' => $e->getMessage()]
            );
        }

        return new LogTranslationAdapter(container: $this->container, logger: $this->logger);

    }//end resolveAdapter()

    /**
     * Load a board-minutes row, returning a plain array or null.
     *
     * @param object $objectService Lazy ObjectService
     * @param string $minutesId     UUID of the minutes record
     *
     * @return array<string, mixed>|null
     */
    private function loadMinutes(object $objectService, string $minutesId): ?array
    {
        try {
            $entity = $objectService->find(
                id: $minutesId,
                register: 'decidesk',
                schema: 'board-minutes'
            );
            if ($entity === null) {
                return null;
            }

            if (method_exists($entity, 'getObject') === true) {
                $row = (array) $entity->getObject();
            } else {
                $row = (array) $entity->jsonSerialize();
            }

            return $row;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: MultilingualReconciliationService failed to load minutes',
                ['exception' => $e->getMessage(), 'minutesId' => $minutesId]
            );
            return null;
        }//end try

    }//end loadMinutes()

    /**
     * Write a translated board-minutes record (status=draft) and return its id.
     *
     * @param object               $objectService  Lazy ObjectService
     * @param array<string, mixed> $sourceMinutes  Source minutes row
     * @param string               $translatedText Translated body
     * @param string               $targetLocale   ISO 639-1 of the translation
     *
     * @return string
     */
    private function writeTranslatedMinutes(
        object $objectService,
        array $sourceMinutes,
        string $translatedText,
        string $targetLocale,
    ): string {
        $candidate = [
            'meetingKoppeling'       => (string) ($sourceMinutes['meetingKoppeling'] ?? ''),
            'language'               => $targetLocale,
            'version'                => 'draft',
            'content'                => $translatedText,
            'reconciliationNotes'    => sprintf(
                'Translated from %s by reconciliation engine (source minutes %s).',
                (string) ($sourceMinutes['language'] ?? 'unknown'),
                (string) ($sourceMinutes['id'] ?? 'unknown')
            ),
            'sourceMinutesKoppeling' => (string) ($sourceMinutes['id'] ?? ''),
        ];

        try {
            $saved = $objectService->saveObject(
                object: $candidate,
                register: 'decidesk',
                schema: 'board-minutes'
            );

            if (is_object($saved) === true) {
                $row = (array) $saved->jsonSerialize();
                return (string) ($row['id'] ?? '');
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: MultilingualReconciliationService failed to persist translated minutes',
                ['exception' => $e->getMessage()]
            );
        }

        return '';

    }//end writeTranslatedMinutes()

    /**
     * Persist a queue entry update (best-effort; logs failures).
     *
     * @param object               $objectService Lazy ObjectService
     * @param string               $entryId       Queue entry UUID
     * @param array<string, mixed> $payload       Updated payload
     *
     * @return void
     */
    private function updateEntry(object $objectService, string $entryId, array $payload): void
    {
        if ($entryId === '') {
            return;
        }

        try {
            $objectService->saveObject(
                object: $payload,
                register: 'decidesk',
                schema: self::SCHEMA,
                uuid: $entryId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: MultilingualReconciliationService failed to update queue entry',
                ['exception' => $e->getMessage(), 'entryId' => $entryId]
            );
        }

    }//end updateEntry()

    /**
     * Normalise heterogeneous findAll() output.
     *
     * @param mixed $rows Raw findAll() result
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === true) {
                $out[] = $row;
            }
        }

        return $out;

    }//end normalize()
}//end class
