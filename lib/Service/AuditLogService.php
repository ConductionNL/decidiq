<?php
/**
 * Decidesk Audit Log Service
 *
 * Append-only hash-chained audit log for governance actions. Every action
 * (vote, conflict declaration, material access, signature, notice send, proxy
 * grant or revocation) creates an `audit-trail` entry whose `currentHash`
 * is SHA-256 over the canonical payload plus the previous entry's hash. Any
 * later tampering of a row breaks the chain and is caught by `verify()`.
 * (Retargeted to the unified audit-trail store per ADR-006; the OR built-in
 * auditTrail integration is finalised in Cycle 2. // TODO Cycle 2)
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
 * Tamper-evident append-only audit log service.
 *
 * Each entry stores a SHA-256 hash over (timestamp + actor + action +
 * objectUids + previousHash). The genesis entry uses the literal string
 * "GENESIS" as its previousHash so the chain has a well-defined root.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
 */
class AuditLogService
{

    /**
     * Sentinel value used as previousHash for the very first audit log entry.
     *
     * @var string
     */
    public const GENESIS_HASH = 'GENESIS';

    /**
     * Allowed action enum values (must match the BoardAuditLogEntry schema).
     *
     * @var string[]
     */
    public const ACTIONS = [
        'vote',
        'conflict-declaration',
        'material-access',
        'signature',
        'notice-sent',
        'proxy-created',
        'proxy-revoked',
        'decision-transition',
        'series-generated',
    ];

    /**
     * Exact-match query filters, mapped from their filter key to the audit
     * row property they compare against.
     *
     * @var array<string, string>
     */
    private const QUERY_EQUALITY_FIELDS = [
        'actor'  => 'actorUuid',
        'action' => 'action',
    ];

    /**
     * Constructor for AuditLogService.
     *
     * @param ContainerInterface $container The DI container (used to retrieve ObjectService lazily)
     * @param LoggerInterface    $logger    The logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Append a new entry to the audit log.
     *
     * Computes `currentHash = sha256(timestamp + actor + action + objectUids + previousHash)`
     * over the canonical JSON encoding of those fields and persists the resulting entry
     * via OpenRegister's ObjectService.
     *
     * @param string   $actor      Nextcloud UID of the acting user (or 'system')
     * @param string   $action     One of self::ACTIONS
     * @param string[] $objectUids List of object UUIDs the action touched
     * @param array    $payload    Optional extra structured detail; stored in immutableBlob
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     *
     * @return array{success: bool, entry: array|null, message: string}
     */
    public function append(string $actor, string $action, array $objectUids, array $payload=[]): array
    {
        if (in_array($action, self::ACTIONS, true) === false) {
            return [
                'success' => false,
                'entry'   => null,
                'message' => 'Unknown action: '.$action,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $previousHash = $this->resolvePreviousHash(objectService: $objectService);
            $timestamp    = gmdate('Y-m-d\TH:i:s\Z');

            $canonicalObjectUids = array_values(array_map('strval', $objectUids));
            sort($canonicalObjectUids);

            $canonical = json_encode(
                [
                    'timestamp'    => $timestamp,
                    'actor'        => $actor,
                    'action'       => $action,
                    'objectUids'   => $canonicalObjectUids,
                    'previousHash' => $previousHash,
                ],
                (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );

            if ($canonical === false) {
                return [
                    'success' => false,
                    'entry'   => null,
                    'message' => 'Failed to canonicalize audit entry payload.',
                ];
            }

            $currentHash = hash('sha256', $canonical);

            $immutableBlob = json_encode(
                [
                    'canonical' => $canonical,
                    'payload'   => $payload,
                ],
                (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );

            $blob = $canonical;
            if ($immutableBlob !== false) {
                $blob = $immutableBlob;
            }

            $entry = [
                'actorUuid'     => $actor,
                'action'        => $action,
                'objectUids'    => $canonicalObjectUids,
                'timestamp'     => $timestamp,
                'previousHash'  => $previousHash,
                'currentHash'   => $currentHash,
                'immutableBlob' => $blob,
            ];

            $saved = $objectService->saveObject(
                object: $entry,
                register: 'decidesk',
                schema: 'audit-trail'
            );

            $this->logger->info(
                'Decidesk: audit log entry appended',
                ['actor' => $actor, 'action' => $action, 'currentHash' => $currentHash]
            );

            $entryPayload = $entry;
            if (is_object($saved) === true) {
                $entryPayload = $saved->jsonSerialize();
            }

            return [
                'success' => true,
                'entry'   => $entryPayload,
                'message' => 'Audit log entry appended.',
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to append audit log entry',
                ['actor' => $actor, 'action' => $action, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'entry'   => null,
                'message' => 'Failed to append audit log entry.',
            ];
        }//end try

    }//end append()

    /**
     * Verify the hash chain from the genesis up to (and including) the
     * referenced entry. Returns the verification status and a list of any
     * entries whose recomputed hash does not match the stored value.
     *
     * @param string|null $entryUuid Optional UUID to stop at; null verifies the
     *                               entire chain.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     *
     * @return array{valid: bool, checked: int, tampered: array<int, string>}
     */
    public function verify(?string $entryUuid=null): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $chain         = $this->loadChain(objectService: $objectService);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to load audit log for verification',
                ['exception' => $e->getMessage()]
            );
            return [
                'valid'    => false,
                'checked'  => 0,
                'tampered' => [],
            ];
        }

        $previousHash = self::GENESIS_HASH;
        $tampered     = [];
        $checked      = 0;
        $stopHit      = ($entryUuid === null);
        foreach ($chain as $row) {
            $checked++;

            $canonical = json_encode(
                [
                    'timestamp'    => ($row['timestamp'] ?? ''),
                    'actor'        => ($row['actorUuid'] ?? ''),
                    'action'       => ($row['action'] ?? ''),
                    'objectUids'   => array_values((array) ($row['objectUids'] ?? [])),
                    'previousHash' => $previousHash,
                ],
                (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );

            // A json_encode() failure returns false, which casts to the same
            // empty string the previous explicit fallback used.
            $expected = hash('sha256', (string) $canonical);

            if (($row['currentHash'] ?? '') !== $expected) {
                $tampered[] = (string) ($row['id'] ?? $row['uuid'] ?? '?');
            }

            $previousHash = ($row['currentHash'] ?? $expected);

            if ($entryUuid !== null && ($row['id'] ?? $row['uuid'] ?? null) === $entryUuid) {
                $stopHit = true;
                break;
            }
        }//end foreach

        return [
            'valid'    => ($tampered === [] && $stopHit === true),
            'checked'  => $checked,
            'tampered' => $tampered,
        ];

    }//end verify()

    /**
     * Export audit log entries between two ISO-8601 UTC timestamps in the
     * requested format.
     *
     * @param string $startDate Inclusive start (ISO-8601, e.g. "2026-01-01T00:00:00Z")
     * @param string $endDate   Inclusive end (ISO-8601)
     * @param string $format    'json' (default) or 'csv'
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     *
     * @return array{success: bool, format: string, body: string, count: int}
     */
    public function export(string $startDate, string $endDate, string $format='json'): array
    {
        $format = strtolower($format);
        if (in_array($format, ['json', 'csv'], true) === false) {
            return [
                'success' => false,
                'format'  => $format,
                'body'    => '',
                'count'   => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $chain         = $this->loadChain(objectService: $objectService);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to load audit log for export',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'format'  => $format,
                'body'    => '',
                'count'   => 0,
            ];
        }

        $filtered = array_values(
            array_filter(
                $chain,
                static function (array $row) use ($startDate, $endDate): bool {
                    $timestamp = ($row['timestamp'] ?? '');
                    return ($timestamp >= $startDate && $timestamp <= $endDate);
                }
            )
        );

        if ($format === 'csv') {
            $lines = ['id,timestamp,actor,action,objectUids,previousHash,currentHash'];
            foreach ($filtered as $row) {
                $lines[] = implode(
                    ',',
                    [
                        (string) ($row['id'] ?? $row['uuid'] ?? ''),
                        (string) ($row['timestamp'] ?? ''),
                        (string) ($row['actorUuid'] ?? ''),
                        (string) ($row['action'] ?? ''),
                        '"'.implode('|', (array) ($row['objectUids'] ?? [])).'"',
                        (string) ($row['previousHash'] ?? ''),
                        (string) ($row['currentHash'] ?? ''),
                    ]
                );
            }

            return [
                'success' => true,
                'format'  => 'csv',
                'body'    => implode("\n", $lines),
                'count'   => count($filtered),
            ];
        }//end if

        $body = json_encode(
            ['entries' => $filtered],
            (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $jsonBody = '{}';
        if ($body !== false) {
            $jsonBody = $body;
        }

        return [
            'success' => true,
            'format'  => 'json',
            'body'    => $jsonBody,
            'count'   => count($filtered),
        ];

    }//end export()

    /**
     * Filter the audit log by actor / action / date-range / object-uuid. All
     * filters are optional and combined with AND semantics. Pagination defaults
     * to 100 rows.
     *
     * @param array<string, mixed> $filters Filter criteria (actor / action / startDate / endDate / objectUuid / limit / offset)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     *
     * @return array{success: bool, entries: array, count: int}
     */
    public function query(array $filters=[]): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $chain         = $this->loadChain(objectService: $objectService);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to load audit log for query',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'entries' => [],
                'count'   => 0,
            ];
        }

        $filtered = array_values(
            array_filter(
                $chain,
                fn (array $row): bool => $this->matchesEqualityFilters(row: $row, filters: $filters) === true
                    && $this->matchesDateRange(row: $row, filters: $filters) === true
                    && $this->matchesObjectUuid(row: $row, filters: $filters) === true
            )
        );

        $limit  = (int) ($filters['limit'] ?? 100);
        $offset = (int) ($filters['offset'] ?? 0);
        if ($limit <= 0) {
            $limit = 100;
        }

        $page = array_slice($filtered, $offset, $limit);

        return [
            'success' => true,
            'entries' => $page,
            'count'   => count($filtered),
        ];

    }//end query()

    /**
     * Whether an audit row satisfies the exact-match filters (actor, action).
     *
     * @param array<string, mixed> $row     A single audit-chain row
     * @param array<string, mixed> $filters Filter criteria
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     *
     * @return bool
     */
    private function matchesEqualityFilters(array $row, array $filters): bool
    {
        foreach (self::QUERY_EQUALITY_FIELDS as $filterKey => $rowKey) {
            $expected = ($filters[$filterKey] ?? null);
            if ($expected !== null && ($row[$rowKey] ?? null) !== $expected) {
                return false;
            }
        }

        return true;

    }//end matchesEqualityFilters()

    /**
     * Whether an audit row's timestamp falls inside the requested date range.
     *
     * Both bounds are optional and compared lexicographically on the ISO-8601
     * timestamp, matching the original inline filter.
     *
     * @param array<string, mixed> $row     A single audit-chain row
     * @param array<string, mixed> $filters Filter criteria
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     *
     * @return bool
     */
    private function matchesDateRange(array $row, array $filters): bool
    {
        $timestamp = ($row['timestamp'] ?? '');

        $startDate = ($filters['startDate'] ?? null);
        if ($startDate !== null && $timestamp < $startDate) {
            return false;
        }

        $endDate = ($filters['endDate'] ?? null);
        if ($endDate !== null && $timestamp > $endDate) {
            return false;
        }

        return true;

    }//end matchesDateRange()

    /**
     * Whether an audit row references the requested object uuid.
     *
     * @param array<string, mixed> $row     A single audit-chain row
     * @param array<string, mixed> $filters Filter criteria
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     *
     * @return bool
     */
    private function matchesObjectUuid(array $row, array $filters): bool
    {
        $objectUuid = ($filters['objectUuid'] ?? null);
        if ($objectUuid === null) {
            return true;
        }

        $uids = array_map('strval', (array) ($row['objectUids'] ?? []));

        return in_array($objectUuid, $uids, true);

    }//end matchesObjectUuid()

    /**
     * Resolve the previousHash for a new entry by inspecting the most recent
     * audit log row. Returns GENESIS_HASH when the log is empty.
     *
     * Uses `loadLastEntry()` — a query bounded to a single row — instead of
     * loading the whole chain (up to 10,000 rows) just to read its tail. This
     * is the hot write path invoked on every governance action; `verify()`
     * and `export()` still use `loadChain()` because they legitimately need
     * the full ordered chain.
     *
     * @param object $objectService OpenRegister ObjectService instance
     *
     * @spec openspec/changes/audit-log-chain-tail-hash/tasks.md#task-2
     *
     * @return string
     */
    private function resolvePreviousHash(object $objectService): string
    {
        $last = $this->loadLastEntry(objectService: $objectService);
        if ($last === null) {
            return self::GENESIS_HASH;
        }

        return (string) ($last['currentHash'] ?? self::GENESIS_HASH);

    }//end resolvePreviousHash()

    /**
     * Load only the single most-recent `audit-trail` row (by `timestamp`),
     * without loading the rest of the chain. Returns null when the log is
     * empty.
     *
     * @param object $objectService OpenRegister ObjectService instance
     *
     * @spec openspec/changes/audit-log-chain-tail-hash/tasks.md#task-1
     *
     * @return array<string, mixed>|null
     */
    private function loadLastEntry(object $objectService): ?array
    {
        $rows = $objectService->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'audit-trail',
                'order'    => ['timestamp' => 'DESC'],
                'limit'    => 1,
            ]
        );

        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                return (array) $row->jsonSerialize();
            }

            if (is_array($row) === true) {
                return $row;
            }
        }

        return null;

    }//end loadLastEntry()

    /**
     * Load the full audit log chain ordered by timestamp ASC. Returns a list of
     * plain arrays so callers can hash / serialize uniformly regardless of the
     * underlying ObjectService row representation.
     *
     * @param object $objectService OpenRegister ObjectService instance
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadChain(object $objectService): array
    {
        $rows = $objectService->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'audit-trail',
                'order'    => ['timestamp' => 'ASC'],
                'limit'    => 10000,
            ]
        );

        $out = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $out[] = (array) $row->jsonSerialize();
            } else if (is_array($row) === true) {
                $out[] = $row;
            }
        }

        return $out;

    }//end loadChain()
}//end class
