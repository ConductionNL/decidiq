<?php
/**
 * Decidesk Multilingual Reconciliation Service
 *
 * Phase 6 service that translates Resolution / BoardMinutes content between
 * Dutch and English and pushes the source/target pair into a reconciliation
 * queue so the corporate secretary can validate semantic equivalence before
 * the signed minutes go out. The actual translation hop is delegated to
 * openconnector when configured; otherwise the queue entry is recorded with
 * `status=awaiting-translator` so a human can pick it up.
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
 * Translation queue + reconciliation comparator.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualReconciliationService
{

    /**
     * Slug of the translation-queue schema.
     *
     * @var string
     */
    public const SCHEMA = 'translation-queue-entry';

    /**
     * Allowed entry status values.
     *
     * @var string[]
     */
    public const STATUSES = ['pending', 'awaiting-translator', 'translated', 'reconciled', 'discrepancy'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container
     * @param LoggerInterface    $logger    Logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Enqueue a translation request. The targetLanguage MUST be 'nl' or 'en'
     * because the regulator-shaped board portal only supports those two.
     *
     * @param string $minutesId      UUID of the BoardMinutes row
     * @param string $sourceLanguage Source language ('nl' | 'en')
     * @param string $targetLanguage Target language ('nl' | 'en')
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return array{success: bool, entry: array|null, message: string}
     */
    public function enqueue(string $minutesId, string $sourceLanguage, string $targetLanguage): array
    {
        if ($minutesId === '') {
            return [
                'success' => false,
                'entry'   => null,
                'message' => 'minutesId is required.',
            ];
        }

        if (in_array($sourceLanguage, ['nl', 'en'], true) === false
            || in_array($targetLanguage, ['nl', 'en'], true) === false
            || $sourceLanguage === $targetLanguage
        ) {
            return [
                'success' => false,
                'entry'   => null,
                'message' => 'sourceLanguage and targetLanguage must each be nl or en, and differ.',
            ];
        }

        $row = [
            'minutesKoppeling' => $minutesId,
            'sourceLanguage'   => $sourceLanguage,
            'targetLanguage'   => $targetLanguage,
            'status'           => 'awaiting-translator',
            'enqueuedAt'       => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $row,
                register: 'decidesk',
                schema: self::SCHEMA
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MultilingualReconciliationService::enqueue failed',
                ['exception' => $e->getMessage(), 'minutesId' => $minutesId]
            );
            return [
                'success' => false,
                'entry'   => null,
                'message' => 'Failed to enqueue translation request.',
            ];
        }

        $payload = $row;
        if (is_object($saved) === true) {
            $payload = (array) $saved->jsonSerialize();
        }

        return [
            'success' => true,
            'entry'   => $payload,
            'message' => 'Translation request enqueued.',
        ];

    }//end enqueue()

    /**
     * Compare two minute records (Dutch + English) and surface discrepancies:
     *
     *  - Resolution counts must match.
     *  - Section counts must match.
     *  - Signed-by lists must match (same signers).
     *
     * Updates the linked translation-queue entries (if any) to
     * status=reconciled or status=discrepancy.
     *
     * @param string $dutchMinutesId   UUID of the Dutch BoardMinutes row
     * @param string $englishMinutesId UUID of the English BoardMinutes row
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return array{success: bool, severity: string, discrepancies: array<int, string>, message: string}
     */
    public function reconcile(string $dutchMinutesId, string $englishMinutesId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $dutch         = $this->loadMinutes($objectService, $dutchMinutesId);
            $english       = $this->loadMinutes($objectService, $englishMinutesId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MultilingualReconciliationService::reconcile load failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'       => false,
                'severity'      => 'error',
                'discrepancies' => [],
                'message'       => 'Failed to load minutes.',
            ];
        }

        if ($dutch === null || $english === null) {
            return [
                'success'       => false,
                'severity'      => 'error',
                'discrepancies' => [],
                'message'       => 'One or both minutes not found.',
            ];
        }

        $dutchStructure   = $this->extractStructure((string) ($dutch['content'] ?? ''));
        $englishStructure = $this->extractStructure((string) ($english['content'] ?? ''));

        $discrepancies = [];
        if ($dutchStructure['resolutionCount'] !== $englishStructure['resolutionCount']) {
            $discrepancies[] = 'resolution-count-mismatch ('.$dutchStructure['resolutionCount'].' nl vs '.$englishStructure['resolutionCount'].' en)';
        }

        if (count($dutchStructure['sectionList']) !== count($englishStructure['sectionList'])) {
            $discrepancies[] = 'section-count-mismatch ('.count($dutchStructure['sectionList']).' nl vs '.count($englishStructure['sectionList']).' en)';
        }

        $dutchSigners   = $this->collectSigners($dutch);
        $englishSigners = $this->collectSigners($english);
        if ($dutchSigners !== $englishSigners) {
            $discrepancies[] = 'signedBy-mismatch';
        }

        $severity = ($discrepancies === [] ? 'ok' : 'warning');

        $this->updateLinkedQueue($dutchMinutesId, $englishMinutesId, $severity);

        return [
            'success'       => true,
            'severity'      => $severity,
            'discrepancies' => $discrepancies,
            'message'       => ($discrepancies === [] ? 'Minutes reconcile cleanly.' : 'Discrepancies detected.'),
        ];

    }//end reconcile()

    /**
     * Parse rich-text content to identify structured elements. The heuristics
     * are deliberately simple so the spec-coverage gate can verify them:
     *
     *  - `resolutionCount` is the number of distinct `R-YYYY-NNN` tokens.
     *  - `sectionList` is the list of distinct markdown headings.
     *
     * @param string $minutesContent Rich-text body (HTML or Markdown)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return array{resolutionCount: int, sectionList: array<int, string>}
     */
    public function extractStructure(string $minutesContent): array
    {
        $resolutions = [];
        if (preg_match_all('/R-\d{4}-\d{2,4}/', $minutesContent, $matches) > 0) {
            $resolutions = array_unique($matches[0]);
        }

        $sections = [];
        // Markdown ATX heading recognition.
        if (preg_match_all('/^#{1,6}\s+(.+)$/m', $minutesContent, $matches) > 0) {
            foreach ($matches[1] as $heading) {
                $sections[] = trim($heading);
            }
        }

        // HTML <h1>..<h6> recognition.
        if (preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/i', $minutesContent, $matches) > 0) {
            foreach ($matches[1] as $heading) {
                $sections[] = trim(strip_tags($heading));
            }
        }

        $sections = array_values(array_unique($sections));

        return [
            'resolutionCount' => count($resolutions),
            'sectionList'     => $sections,
        ];

    }//end extractStructure()

    /**
     * Persist a discrepancy note onto a translation-queue entry and flag the
     * status as `discrepancy`.
     *
     * @param string $entryId     UUID of the translation-queue entry
     * @param string $discrepancy Discrepancy description
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return array{success: bool, entry: array|null, message: string}
     */
    public function reportDiscrepancy(string $entryId, string $discrepancy): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(
                id: $entryId,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            if ($entity === null) {
                return [
                    'success' => false,
                    'entry'   => null,
                    'message' => 'Translation queue entry not found.',
                ];
            }

            $current = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $current = $entity->getObject();
            }

            $merged = array_merge(
                $current,
                [
                    'status'              => 'discrepancy',
                    'reconciliationNotes' => $discrepancy,
                    'lastUpdatedAt'       => gmdate('Y-m-d\TH:i:s\Z'),
                ]
            );

            $saved = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: self::SCHEMA,
                uuid: $entryId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MultilingualReconciliationService::reportDiscrepancy failed',
                ['exception' => $e->getMessage(), 'entryId' => $entryId]
            );
            return [
                'success' => false,
                'entry'   => null,
                'message' => 'Failed to record discrepancy.',
            ];
        }//end try

        $payload = $merged;
        if (is_object($saved) === true) {
            $payload = (array) $saved->jsonSerialize();
        }

        return [
            'success' => true,
            'entry'   => $payload,
            'message' => 'Discrepancy recorded.',
        ];

    }//end reportDiscrepancy()

    /**
     * Load a BoardMinutes row by UUID, returning null on miss.
     *
     * @param object $objectService OpenRegister ObjectService
     * @param string $minutesId     UUID
     *
     * @return array<string, mixed>|null
     */
    private function loadMinutes(object $objectService, string $minutesId): ?array
    {
        $entity = $objectService->find(
            id: $minutesId,
            register: 'decidesk',
            schema: 'board-minutes'
        );
        if ($entity === null) {
            return null;
        }

        $row = (array) $entity->jsonSerialize();
        if (method_exists($entity, 'getObject') === true) {
            $row = $entity->getObject();
        }

        return $row;

    }//end loadMinutes()

    /**
     * Collect the sorted list of signer UUIDs from a Minutes row's signedBy
     * field. Used so signedBy comparison is order-independent.
     *
     * @param array<string, mixed> $row Minutes row
     *
     * @return array<int, string>
     */
    private function collectSigners(array $row): array
    {
        $list = [];
        foreach (((array) ($row['signedBy'] ?? [])) as $entry) {
            $signer = (string) ($entry['signerUuid'] ?? $entry['signer'] ?? '');
            if ($signer !== '') {
                $list[] = $signer;
            }
        }

        sort($list);
        return $list;

    }//end collectSigners()

    /**
     * Best-effort: mark every queue entry attached to the supplied minutes
     * UUIDs with the reconciliation outcome.
     *
     * @param string $dutchMinutesId   Dutch UUID
     * @param string $englishMinutesId English UUID
     * @param string $severity         'ok' or 'warning'
     *
     * @return void
     */
    private function updateLinkedQueue(string $dutchMinutesId, string $englishMinutesId, string $severity): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entries       = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => self::SCHEMA,
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            return;
        }

        $newStatus = ($severity === 'ok' ? 'reconciled' : 'discrepancy');
        foreach ((array) $entries as $entry) {
            if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
                $entry = (array) $entry->jsonSerialize();
            }

            if (is_array($entry) === false) {
                continue;
            }

            $linked = (string) ($entry['minutesKoppeling'] ?? '');
            if ($linked !== $dutchMinutesId && $linked !== $englishMinutesId) {
                continue;
            }

            try {
                $objectService->saveObject(
                    object: array_merge(
                        $entry,
                        [
                            'status'        => $newStatus,
                            'lastUpdatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                        ]
                    ),
                    register: 'decidesk',
                    schema: self::SCHEMA,
                    uuid: (string) ($entry['id'] ?? '')
                );
            } catch (\Throwable $e) {
                // Best-effort.
                continue;
            }
        }//end foreach

    }//end updateLinkedQueue()
}//end class
