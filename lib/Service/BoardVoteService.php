<?php
/**
 * Decidesk Board Vote Service
 *
 * Phase 2 service for the BoardVote entity: cast votes (guarded by the
 * resolution lifecycle guard for conflict-of-interest), audit every cast,
 * tally a resolution's votes.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-vote-service
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side vote cast / tally service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-vote-service
 */
class BoardVoteService
{

    /**
     * Allowed vote enum values.
     *
     * @var string[]
     */
    public const VOTES = ['in-favor', 'against', 'abstain', 'absent', 'recused-due-to-conflict'];

    /**
     * Allowed vote-method enum values.
     *
     * @var string[]
     */
    public const METHODS = ['raised-hand', 'electronic', 'written-ballot', 'proxy'];

    /**
     * Constructor for BoardVoteService.
     *
     * @param ContainerInterface       $container       The DI container
     * @param LoggerInterface          $logger          The logger
     * @param ResolutionLifecycleGuard $guard           Resolution guard (conflict gate)
     * @param AuditLogService          $auditLogService Audit log dependency
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ResolutionLifecycleGuard $guard,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Cast a vote on a resolution. The conflict-of-interest gate is consulted
     * via ResolutionLifecycleGuard::canCastVote() before the vote is saved;
     * recused members are blocked. Every successful cast is mirrored to the
     * audit log via AuditLogService::append(action='vote').
     *
     * @param string               $resolutionId  UUID of the resolution
     * @param string               $boardMemberId UUID of the casting board member
     * @param string               $vote          One of self::VOTES
     * @param array<string, mixed> $extra         Optional fields: voteMethod, proxyHolder, anonymized, agendaItemKoppeling
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-vote-service
     *
     * @return array{success: bool, vote: array|null, message: string}
     */
    public function cast(string $resolutionId, string $boardMemberId, string $vote, array $extra=[]): array
    {
        if (in_array($vote, self::VOTES, true) === false) {
            return [
                'success' => false,
                'vote'    => null,
                'message' => 'Unknown vote enum: '.$vote,
            ];
        }

        $method = (string) ($extra['voteMethod'] ?? 'electronic');
        if (in_array($method, self::METHODS, true) === false) {
            return [
                'success' => false,
                'vote'    => null,
                'message' => 'Unknown vote method: '.$method,
            ];
        }

        $agendaItemId = (string) ($extra['agendaItemKoppeling'] ?? '');
        if ($agendaItemId !== '') {
            $gate = $this->guard->canCastVote($boardMemberId, $agendaItemId);
            if ($gate['allowed'] === false) {
                return [
                    'success' => false,
                    'vote'    => null,
                    'message' => $gate['reason'],
                ];
            }
        }

        // Proxy votes must reference an ACTIVE proxy record granted by this
        // member to the named holder (board-meeting-resolutions task-2.4;
        // OWASP A01 — without this gate any caller could record a proxy vote
        // with a fabricated holder).
        if ($method === 'proxy') {
            $proxyHolder = trim((string) ($extra['proxyHolder'] ?? ''));
            if ($proxyHolder === '') {
                return [
                    'success' => false,
                    'vote'    => null,
                    'message' => "Proxy votes require the 'proxyHolder' parameter.",
                ];
            }

            $proxyGate = $this->hasActiveProxy(grantorId: $boardMemberId, holderId: $proxyHolder);
            if ($proxyGate === false) {
                return [
                    'success' => false,
                    'vote'    => null,
                    'message' => 'No active proxy from this board member to the named holder.',
                ];
            }
        }

        $row = [
            'resolutionKoppeling'  => $resolutionId,
            'boardMemberKoppeling' => $boardMemberId,
            'vote'                 => $vote,
            'voteTimestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'voteMethod'           => $method,
            'anonymized'           => (bool) ($extra['anonymized'] ?? false),
        ];

        if (isset($extra['proxyHolder']) === true && trim((string) $extra['proxyHolder']) !== '') {
            $row['proxyHolder'] = (string) $extra['proxyHolder'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $row,
                register: 'decidesk',
                schema: 'board-vote'
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardVoteService::cast failed',
                ['resolutionId' => $resolutionId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'vote'    => null,
                'message' => 'Failed to record vote.',
            ];
        }

        $serialized = $row;
        if (is_object($saved) === true) {
            $serialized = (array) $saved->jsonSerialize();
        }

        $this->auditLogService->append(
            actor: $boardMemberId,
            action: 'vote',
            objectUids: [
                (string) ($serialized['id'] ?? $serialized['uuid'] ?? ''),
                $resolutionId,
            ],
            payload: ['vote' => $vote, 'method' => $method]
        );

        return [
            'success' => true,
            'vote'    => $serialized,
            'message' => 'Vote recorded.',
        ];

    }//end cast()

    /**
     * Check whether an ACTIVE board-proxy record exists from the grantor to
     * the holder (any meeting — the resolution's meeting link is on the
     * proxy record itself and proxies are registered per meeting, so a
     * status filter plus the grantor/holder pair is the authoritative gate).
     *
     * @param string $grantorId UUID of the board member the vote is cast for
     * @param string $holderId  UUID of the board member holding the proxy
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     *
     * @return bool True when an active proxy from grantor to holder exists
     */
    private function hasActiveProxy(string $grantorId, string $holderId): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board-proxy',
                    'filters'  => ['grantorKoppeling' => $grantorId],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to resolve proxy records for vote gate',
                ['grantorId' => $grantorId, 'exception' => $e->getMessage()]
            );
            // Fail closed: an unverifiable proxy is rejected, never skipped.
            return false;
        }

        foreach ($rows as $entity) {
            $row = $entity;
            if (is_object($entity) === true) {
                $row = (array) $entity->jsonSerialize();
            }

            if (is_array($row) === true
                && ($row['grantorKoppeling'] ?? null) === $grantorId
                && ($row['holderKoppeling'] ?? null) === $holderId
                && ($row['status'] ?? '') === 'active'
            ) {
                return true;
            }
        }

        return false;

    }//end hasActiveProxy()

    /**
     * Tally the votes recorded against a resolution. Returns a count per vote
     * enum value plus the total cast (non-absent, non-recused).
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-vote-service
     *
     * @return array{success: bool, tally: array<string, int>, cast: int, total: int}
     */
    public function tally(string $resolutionId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board-vote',
                    'filters'  => ['resolutionKoppeling' => $resolutionId],
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardVoteService::tally failed',
                ['resolutionId' => $resolutionId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'tally'   => [],
                'cast'    => 0,
                'total'   => 0,
            ];
        }//end try

        $tally = array_fill_keys(self::VOTES, 0);
        $total = 0;

        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false || ($row['resolutionKoppeling'] ?? null) !== $resolutionId) {
                continue;
            }

            $vote = (string) ($row['vote'] ?? '');
            if (isset($tally[$vote]) === true) {
                $tally[$vote]++;
            }

            $total++;
        }

        $cast = ($tally['in-favor'] + $tally['against'] + $tally['abstain']);

        return [
            'success' => true,
            'tally'   => $tally,
            'cast'    => $cast,
            'total'   => $total,
        ];

    }//end tally()

    /**
     * Return the full audit log slice for one resolution (every vote cast,
     * not the tally). This is the chairman's running-tally view.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-vote-service
     *
     * @return array{success: bool, votes: array<int, array<string, mixed>>}
     */
    public function audit(string $resolutionId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board-vote',
                    'filters'  => ['resolutionKoppeling' => $resolutionId],
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardVoteService::audit failed',
                ['resolutionId' => $resolutionId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'votes'   => [],
            ];
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false || ($row['resolutionKoppeling'] ?? null) !== $resolutionId) {
                continue;
            }

            $out[] = $row;
        }

        return [
            'success' => true,
            'votes'   => $out,
        ];

    }//end audit()
}//end class
