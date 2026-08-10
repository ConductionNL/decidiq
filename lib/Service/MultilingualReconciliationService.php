<?php
/**
 * Decidesk Multilingual Reconciliation Service
 *
 * Queues minutes for translation, persists queue entries
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
// SPDX-License-Identifier: EUPL-1.2
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
     * Queue a minutes record for translation into one or more targets.
     *
     * The persisted queue entry references the source minutes record and
     * one target locale; an array of targets fan-out to multiple entries.
     *
     * @param string   $minutesId     UUID of the source minutes record
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

        $targets = $this->collectTargetLocales(targetLocales: $targetLocales, sourceLocale: $sourceLocale);

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
        foreach ($targets as $targetLocale) {
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
     * Reduce a raw target-locale list to the supported locales worth queueing.
     *
     * Lower-cases every entry, drops anything outside SUPPORTED_LOCALES, drops
     * the source locale itself and de-duplicates while preserving first-seen order.
     *
     * @param string[] $targetLocales Raw requested target locales
     * @param string   $sourceLocale  Lower-cased source locale
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return string[]
     */
    private function collectTargetLocales(array $targetLocales, string $sourceLocale): array
    {
        $normalised = array_map(
            static fn (mixed $locale): string => strtolower((string) $locale),
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

        $summary = array_fill_keys(self::STATUSES, 0);

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
     * Each successful translation creates a linked minutes record in
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
        $failed    = 0;

        foreach ($queued as $entry) {
            $processed++;
            $failed += (int) ($this->processEntry(
                objectService: $objectService,
                adapter: $adapter,
                entry: $entry
            ) === false);
        }

        $completed = ($processed - $failed);

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
     * Translate a single queue entry and persist its resulting state.
     *
     * @param object              $objectService Lazy ObjectService
     * @param ITranslationAdapter $adapter       Resolved translation adapter
     * @param array<string,mixed> $entry         Queue entry row
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return bool True when the entry completed, false when it failed
     */
    private function processEntry(object $objectService, ITranslationAdapter $adapter, array $entry): bool
    {
        $entryId  = (string) ($entry['id'] ?? '');
        $attempts = (int) ($entry['attempts'] ?? 0);
        $now      = gmdate('Y-m-d\TH:i:s\Z');
        $required = [
            (string) ($entry['minutesKoppeling'] ?? ''),
            (string) ($entry['sourceLocale'] ?? ''),
            (string) ($entry['targetLocale'] ?? ''),
        ];

        if ($entryId === '' || in_array('', $required, true) === true) {
            $this->failEntry(
                objectService: $objectService,
                entry: $entry,
                changes: ['lastError' => 'Malformed queue entry.']
            );
            return false;
        }

        [$minutesId, $sourceLocale, $targetLocale] = $required;

        $sourceMinutes = $this->loadMinutes(objectService: $objectService, minutesId: $minutesId);
        if ($sourceMinutes === null) {
            $this->failEntry(
                objectService: $objectService,
                entry: $entry,
                changes: [
                    'lastError' => 'Source minutes not found.',
                    'updatedAt' => $now,
                ]
            );
            return false;
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

        if ($translation['success'] !== true) {
            $this->failEntry(
                objectService: $objectService,
                entry: $entry,
                changes: [
                    'provider'  => $translation['provider'],
                    'lastError' => $translation['message'],
                    'updatedAt' => $now,
                ]
            );
            return false;
        }

        $newMinutesId = $this->writeTranslatedMinutes(
            objectService: $objectService,
            sourceMinutes: $sourceMinutes,
            translatedText: (string) $translation['text'],
            targetLocale: $targetLocale
        );

        $this->updateEntry(
            objectService: $objectService,
            entryId: $entryId,
            payload: array_merge(
                $entry,
                [
                    'status'                     => 'completed',
                    'attempts'                   => ($attempts + 1),
                    'provider'                   => $translation['provider'],
                    'lastError'                  => null,
                    'translatedMinutesKoppeling' => $newMinutesId,
                    'completedAt'                => $now,
                    'updatedAt'                  => $now,
                ]
            )
        );

        return true;

    }//end processEntry()

    /**
     * Flip a queue entry to `failed`, bumping its attempt counter.
     *
     * @param object              $objectService Lazy ObjectService
     * @param array<string,mixed> $entry         Queue entry row
     * @param array<string,mixed> $changes       Extra fields merged before the status flip
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return void
     */
    private function failEntry(object $objectService, array $entry, array $changes): void
    {
        $this->updateEntry(
            objectService: $objectService,
            entryId: (string) ($entry['id'] ?? ''),
            payload: array_merge(
                $entry,
                [
                    'status'   => 'failed',
                    'attempts' => (((int) ($entry['attempts'] ?? 0)) + 1),
                ],
                $changes
            )
        );

    }//end failEntry()

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
     * Load a minutes row, returning a plain array or null.
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
                schema: 'minutes'
            );
            if ($entity === null) {
                return null;
            }

            if (method_exists($entity, 'getObject') === true) {
                return (array) $entity->getObject();
            }

            return (array) $entity->jsonSerialize();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: MultilingualReconciliationService failed to load minutes',
                ['exception' => $e->getMessage(), 'minutesId' => $minutesId]
            );
            return null;
        }//end try

    }//end loadMinutes()

    /**
     * Write a translated minutes record (status=draft) and return its id.
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
                schema: 'minutes'
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
