<?php
/**
 * Decidesk Board Audit Log Service
 *
 * Append-only, SHA-256 hash-chained audit trail for board governance events
 * (votes, conflict declarations, material access, signatures, proxy lifecycle).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Immutable cryptographic audit trail with SHA-256 chaining.
 *
 * Each entry binds its own canonical payload to the hash of the previous entry,
 * so any tampering with a historical entry breaks every subsequent hash and is
 * detectable by {@see BoardAuditLogService::verify()}. Entries are never updated
 * or deleted (the schema is hardDelete:false and the service only appends).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
class BoardAuditLogService
{

    /**
     * Register slug for all decidesk objects.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Schema slug for audit log entries.
     *
     * @var string
     */
    private const SCHEMA = 'board-audit-log-entry';

    /**
     * Genesis hash used as the previous-hash for the very first entry.
     *
     * @var string
     */
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Compute the canonical SHA-256 hash for an entry payload.
     *
     * The hash is computed over a deterministic, sorted JSON serialization of the
     * actor, action, affected object UIDs, timestamp and previous hash, so the
     * same logical event always yields the same hash regardless of input ordering.
     *
     * @param string        $actorUuid    Actor UUID.
     * @param string        $action       Action enum value.
     * @param array<string> $objectUids   Affected object UUIDs.
     * @param string        $timestamp    ISO-8601 timestamp.
     * @param string        $previousHash Previous entry hash.
     *
     * @return string 64-character lowercase hex SHA-256.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function computeHash(string $actorUuid, string $action, array $objectUids, string $timestamp, string $previousHash): string
    {
        $uids = $objectUids;
        sort($uids);
        $canonical = json_encode(
            [
                'actor'     => $actorUuid,
                'action'    => $action,
                'objects'   => array_values($uids),
                'timestamp' => $timestamp,
                'previous'  => $previousHash,
            ],
            (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return hash('sha256', (string) $canonical);

    }//end computeHash()

    /**
     * Append a new immutable audit entry to the chain.
     *
     * @param string        $actorUuid  Actor UUID (Participant/user).
     * @param string        $action     Action enum value.
     * @param array<string> $objectUids Affected object UUIDs.
     *
     * @return array<string,mixed> The created entry as an associative array.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function append(string $actorUuid, string $action, array $objectUids): array
    {
        $timestamp    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
        $previousHash = $this->latestHash();
        $currentHash  = $this->computeHash(
            actorUuid: $actorUuid,
            action: $action,
            objectUids: $objectUids,
            timestamp: $timestamp,
            previousHash: $previousHash
        );

        $entry = [
            'actorUuid'     => $actorUuid,
            'action'        => $action,
            'objectUids'    => array_values($objectUids),
            'timestamp'     => $timestamp,
            'previousHash'  => $previousHash,
            'currentHash'   => $currentHash,
            'immutableBlob' => json_encode(
                [
                    'actorUuid'    => $actorUuid,
                    'action'       => $action,
                    'objectUids'   => array_values($objectUids),
                    'timestamp'    => $timestamp,
                    'previousHash' => $previousHash,
                ],
                (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ),
        ];

        $saved = $this->objectService()->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $entry);
        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            return $saved->jsonSerialize();
        }

        return $entry;

    }//end append()

    /**
     * Return the hash of the most recent entry, or the genesis hash if empty.
     *
     * @return string
     */
    private function latestHash(): string
    {
        $entries = $this->allEntriesOrdered();
        if ($entries === []) {
            return self::GENESIS;
        }

        $last = end($entries);
        return (string) ($last['currentHash'] ?? self::GENESIS);

    }//end latestHash()

    /**
     * Load all entries ordered by timestamp ascending.
     *
     * @return array<int,array<string,mixed>>
     */
    private function allEntriesOrdered(): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema(self::SCHEMA);
        $result = $objectService->findAll([]);

        $entries = [];
        foreach (($result['results'] ?? $result) as $item) {
            if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
                $entries[] = $item->jsonSerialize();
            } else if (is_array($item) === true) {
                $entries[] = $item;
            }
        }

        usort(
            $entries,
            static function (array $left, array $right): int {
                return strcmp((string) ($left['timestamp'] ?? ''), (string) ($right['timestamp'] ?? ''));
            }
        );

        return $entries;

    }//end allEntriesOrdered()

    /**
     * Verify the integrity of the audit chain.
     *
     * Recomputes every entry's hash from its payload and the recorded previous
     * hash; any mismatch indicates tampering. Returns the first broken link.
     *
     * @return array{valid:bool,brokenAt:?string,reason:?string}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function verify(): array
    {
        $entries  = $this->allEntriesOrdered();
        $expected = self::GENESIS;

        foreach ($entries as $entry) {
            $previous = (string) ($entry['previousHash'] ?? '');
            if ($previous !== $expected) {
                return ['valid' => false, 'brokenAt' => ($entry['currentHash'] ?? null), 'reason' => 'previous-hash-mismatch'];
            }

            $recomputed = $this->computeHash(
                actorUuid: (string) ($entry['actorUuid'] ?? ''),
                action: (string) ($entry['action'] ?? ''),
                objectUids: (array) ($entry['objectUids'] ?? []),
                timestamp: (string) ($entry['timestamp'] ?? ''),
                previousHash: $previous
            );
            if ($recomputed !== (string) ($entry['currentHash'] ?? '')) {
                return ['valid' => false, 'brokenAt' => ($entry['currentHash'] ?? null), 'reason' => 'content-hash-mismatch'];
            }

            $expected = (string) ($entry['currentHash'] ?? '');
        }//end foreach

        return ['valid' => true, 'brokenAt' => null, 'reason' => null];

    }//end verify()

    /**
     * Query audit entries by optional filters.
     *
     * @param array<string,mixed> $filters Supported keys: actor, action, from, to, objectUuid.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function query(array $filters=[]): array
    {
        $entries = $this->allEntriesOrdered();

        return array_values(
            array_filter(
                $entries,
                static function (array $entry) use ($filters): bool {
                    if (isset($filters['actor']) === true && ($entry['actorUuid'] ?? null) !== $filters['actor']) {
                        return false;
                    }

                    if (isset($filters['action']) === true && ($entry['action'] ?? null) !== $filters['action']) {
                        return false;
                    }

                    if (isset($filters['from']) === true && strcmp((string) ($entry['timestamp'] ?? ''), (string) $filters['from']) < 0) {
                        return false;
                    }

                    if (isset($filters['to']) === true && strcmp((string) ($entry['timestamp'] ?? ''), (string) $filters['to']) > 0) {
                        return false;
                    }

                    if (isset($filters['objectUuid']) === true
                        && in_array($filters['objectUuid'], (array) ($entry['objectUids'] ?? []), true) === false
                    ) {
                        return false;
                    }

                    return true;
                }
            )
        );

    }//end query()

    /**
     * Export the audit log for an external auditor.
     *
     * @param string|null $startDate Optional ISO-8601 lower bound.
     * @param string|null $endDate   Optional ISO-8601 upper bound.
     * @param string      $format    Either 'json' or 'csv'.
     *
     * @return string The serialized export.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function export(?string $startDate=null, ?string $endDate=null, string $format='json'): string
    {
        $filters = [];
        if ($startDate !== null) {
            $filters['from'] = $startDate;
        }

        if ($endDate !== null) {
            $filters['to'] = $endDate;
        }

        $entries = $this->query(filters: $filters);

        if ($format === 'csv') {
            $lines = ['timestamp,actorUuid,action,objectUids,previousHash,currentHash'];
            foreach ($entries as $entry) {
                $lines[] = implode(
                    ',',
                    [
                        '"'.($entry['timestamp'] ?? '').'"',
                        '"'.($entry['actorUuid'] ?? '').'"',
                        '"'.($entry['action'] ?? '').'"',
                        '"'.implode('|', (array) ($entry['objectUids'] ?? [])).'"',
                        '"'.($entry['previousHash'] ?? '').'"',
                        '"'.($entry['currentHash'] ?? '').'"',
                    ]
                );
            }

            return implode("\n", $lines);
        }//end if

        return (string) json_encode(
            ['integrity' => $this->verify(), 'entries' => $entries],
            (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

    }//end export()
}//end class
