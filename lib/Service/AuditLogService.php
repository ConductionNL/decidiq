<?php
/**
 * Decidesk Audit Log Service
 *
 * Append-only governance audit log with SHA-256 hash chaining for tamper-evidence.
 * Every vote, conflict declaration, material access and signature is appended as an
 * AuditLogEntry whose hash is computed over the previous entry's hash, making any
 * retroactive modification detectable.
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
use Psr\Log\LoggerInterface;

/**
 * Append-only, hash-chained governance audit log.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
class AuditLogService
{
    /**
     * Register slug used for all decidesk objects.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Schema slug of the audit log entry.
     *
     * @var string
     */
    private const SCHEMA = 'audit-log-entry';

    /**
     * Constructor for AuditLogService.
     *
     * @param ContainerInterface $container The DI container used to resolve OpenRegister ObjectService.
     * @param LoggerInterface    $logger    The logger.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
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
     * Compute the SHA-256 hash for an entry over timestamp + actor + action + object-uids + previous-hash.
     *
     * @param string   $timestamp    ISO 8601 timestamp.
     * @param string   $actor        Actor UUID.
     * @param string   $action       Action name.
     * @param string[] $objectUids   Touched object UUIDs.
     * @param string   $previousHash Previous entry hash ('' for genesis).
     *
     * @return string Lowercase hexadecimal SHA-256 hash.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function computeHash(string $timestamp, string $actor, string $action, array $objectUids, string $previousHash): string
    {
        $payload = $timestamp.'|'.$actor.'|'.$action.'|'.implode(',', $objectUids).'|'.$previousHash;
        return hash('sha256', $payload);

    }//end computeHash()

    /**
     * Fetch the hash of the most recent audit log entry, or '' if the log is empty.
     *
     * @return string The latest current-hash or '' when no entries exist.
     */
    private function latestHash(): string
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema(self::SCHEMA);
        $entities = $objectService->findAll(['limit' => 0]);

        $latestTimestamp = '';
        $latestHash      = '';
        foreach ($entities as $entity) {
            $data      = $entity->jsonSerialize();
            $timestamp = (string) ($data['timestamp'] ?? '');
            if ($timestamp >= $latestTimestamp) {
                $latestTimestamp = $timestamp;
                $latestHash      = (string) ($data['current-hash'] ?? '');
            }
        }//end foreach

        return $latestHash;

    }//end latestHash()

    /**
     * Append a new entry to the immutable audit log.
     *
     * @param string   $actor      Actor UUID (board member or system).
     * @param string   $action     One of the AuditLogEntry action enum values.
     * @param string[] $objectUids Object UUIDs touched by the action.
     *
     * @return array The serialized created entry including current-hash.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function append(string $actor, string $action, array $objectUids): array
    {
        $timestamp    = gmdate('Y-m-d\TH:i:s\Z');
        $previousHash = $this->latestHash();
        $currentHash  = $this->computeHash(
            timestamp: $timestamp,
            actor: $actor,
            action: $action,
            objectUids: $objectUids,
            previousHash: $previousHash
        );

        $entry = [
            'actor-uuid'    => $actor,
            'action'        => $action,
            'object-uids'   => array_values($objectUids),
            'timestamp'     => $timestamp,
            'previous-hash' => $previousHash,
            'current-hash'  => $currentHash,
        ];
        $entry['immutable-blob'] = json_encode($entry, JSON_UNESCAPED_SLASHES);

        $saved = $this->objectService()->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $entry);

        return $saved->jsonSerialize();

    }//end append()

    /**
     * Verify the integrity of the hash chain up to and including a given entry.
     *
     * Recomputes each entry's hash from its content and the prior entry's hash;
     * any divergence indicates tampering.
     *
     * @param string|null $entryId Optional entry UUID to verify up to; null verifies the whole chain.
     *
     * @return array{valid: bool, tampered_entries: string[]}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function verify(?string $entryId=null): array
    {
        $entries  = $this->orderedEntries();
        $previous = '';
        $valid    = true;
        $tampered = [];

        foreach ($entries as $data) {
            $expected = $this->computeHash(
                timestamp: (string) ($data['timestamp'] ?? ''),
                actor: (string) ($data['actor-uuid'] ?? ''),
                action: (string) ($data['action'] ?? ''),
                objectUids: (array) ($data['object-uids'] ?? []),
                previousHash: $previous
            );

            if ($expected !== (string) ($data['current-hash'] ?? '')) {
                $valid      = false;
                $tampered[] = (string) ($data['id'] ?? ($data['@self']['id'] ?? ''));
            }

            $previous = (string) ($data['current-hash'] ?? '');

            $currentId = (string) ($data['id'] ?? ($data['@self']['id'] ?? ''));
            if ($entryId !== null && $currentId === $entryId) {
                break;
            }
        }//end foreach

        return [
            'valid'            => $valid,
            'tampered_entries' => $tampered,
        ];

    }//end verify()

    /**
     * Query the audit log with optional filters.
     *
     * @param array{actor?: string, action?: string, start?: string, end?: string, object?: string} $filters Filter map.
     *
     * @return array<int, array> Serialized matching entries in chronological order.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function query(array $filters): array
    {
        $entries = $this->orderedEntries();
        $result  = [];

        foreach ($entries as $data) {
            if ($this->entryMatches(data: $data, filters: $filters) === true) {
                $result[] = $data;
            }
        }//end foreach

        return $result;

    }//end query()

    /**
     * Determine whether a single entry satisfies all supplied filters.
     *
     * @param array $data    Serialized audit log entry.
     * @param array $filters Filter map.
     *
     * @return bool True when the entry matches every active filter.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    private function entryMatches(array $data, array $filters): bool
    {
        if (isset($filters['actor']) === true && (string) ($data['actor-uuid'] ?? '') !== $filters['actor']) {
            return false;
        }

        if (isset($filters['action']) === true && (string) ($data['action'] ?? '') !== $filters['action']) {
            return false;
        }

        $timestamp = (string) ($data['timestamp'] ?? '');
        if (isset($filters['start']) === true && $timestamp < $filters['start']) {
            return false;
        }

        if (isset($filters['end']) === true && $timestamp > $filters['end']) {
            return false;
        }

        $matchesObject = in_array($filters['object'] ?? null, (array) ($data['object-uids'] ?? []), true);
        if (isset($filters['object']) === true && $matchesObject === false) {
            return false;
        }

        return true;

    }//end entryMatches()

    /**
     * Export the audit log including the hash chain for an external auditor.
     *
     * @param string|null $startDate ISO 8601 lower bound (inclusive) or null.
     * @param string|null $endDate   ISO 8601 upper bound (inclusive) or null.
     *
     * @return array<int, array> Serialized entries within the range, chronological.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function export(?string $startDate=null, ?string $endDate=null): array
    {
        $filters = [];
        if ($startDate !== null) {
            $filters['start'] = $startDate;
        }

        if ($endDate !== null) {
            $filters['end'] = $endDate;
        }

        return $this->query(filters: $filters);

    }//end export()

    /**
     * Load all audit log entries ordered by timestamp ascending.
     *
     * @return array<int, array> Serialized entries.
     */
    private function orderedEntries(): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema(self::SCHEMA);
        $entities = $objectService->findAll(['limit' => 0]);

        $rows = [];
        foreach ($entities as $entity) {
            $rows[] = $entity->jsonSerialize();
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
            }
        );

        return $rows;

    }//end orderedEntries()
}//end class
