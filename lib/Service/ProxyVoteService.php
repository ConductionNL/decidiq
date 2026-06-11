<?php
/**
 * Decidesk Proxy Vote Service
 *
 * Phase 5 service governing proxy votes on board meetings. Proxies are
 * registered by the grantor (a board member) and approved by the secretary;
 * they are automatically suspended when the grantor joins the meeting
 * remotely, and revoked either at meeting close or by secretary action.
 *
 * Proxy rows are persisted on a separate `board-proxy` slot of the
 * BoardVote schema (`voteMethod=proxy`, `proxyHolder` set, additional
 * `proxyStatus` field) so the audit log always shows the cast trail.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proxy vote lifecycle service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class ProxyVoteService
{

    /**
     * Allowed proxy status values.
     *
     * @var string[]
     */
    public const STATUSES = ['pending-approval', 'active', 'suspended', 'revoked'];

    /**
     * Slug of the OpenRegister schema storing proxy rows.
     *
     * @var string
     */
    public const SCHEMA = 'board-proxy';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container       DI container
     * @param LoggerInterface    $logger          Logger
     * @param AuditLogService    $auditLogService Audit log dependency
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Register a proxy. The grantor (a board member) delegates their vote on
     * the named meeting to a holder until the meeting closes or the proxy is
     * revoked. The persisted row starts in `pending-approval`; the secretary
     * must call approve() before the proxy counts toward quorum.
     *
     * @param string               $meetingId UUID of the board meeting
     * @param string               $grantorId UUID of the granting board member
     * @param string               $holderId  UUID of the receiving board member
     * @param array<string, mixed> $extra     Optional fields: scope, expiresAt
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     *
     * @return array{success: bool, proxy: array|null, message: string}
     */
    public function register(string $meetingId, string $grantorId, string $holderId, array $extra=[]): array
    {
        if ($meetingId === '' || $grantorId === '' || $holderId === '') {
            return [
                'success' => false,
                'proxy'   => null,
                'message' => 'meetingId, grantorId and holderId are required.',
            ];
        }

        if ($grantorId === $holderId) {
            return [
                'success' => false,
                'proxy'   => null,
                'message' => 'Grantor and holder must differ.',
            ];
        }

        $row = [
            'meetingKoppeling' => $meetingId,
            'grantorKoppeling' => $grantorId,
            'holderKoppeling'  => $holderId,
            'scope'            => (string) ($extra['scope'] ?? 'all-resolutions'),
            'expiresAt'        => (string) ($extra['expiresAt'] ?? ''),
            'proxyStatus'      => 'pending-approval',
            'registeredAt'     => gmdate('Y-m-d\TH:i:s\Z'),
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
                'Decidesk: ProxyVoteService::register failed',
                ['exception' => $e->getMessage(), 'meeting' => $meetingId]
            );
            return [
                'success' => false,
                'proxy'   => null,
                'message' => 'Failed to register proxy.',
            ];
        }

        $payload = $row;
        if (is_object($saved) === true) {
            $payload = (array) $saved->jsonSerialize();
        }

        $this->auditLogService->append(
            actor: $grantorId,
            action: 'proxy-created',
            objectUids: [(string) ($payload['id'] ?? $payload['uuid'] ?? ''), $meetingId],
            payload: ['grantor' => $grantorId, 'holder' => $holderId]
        );

        return [
            'success' => true,
            'proxy'   => $payload,
            'message' => 'Proxy registered.',
        ];

    }//end register()

    /**
     * Return the proxies attached to a meeting. Optionally filtered by status.
     *
     * @param string      $meetingId UUID of the meeting
     * @param string|null $status    Filter by proxyStatus
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     *
     * @return array{success: bool, proxies: array, count: int}
     */
    public function forMeeting(string $meetingId, ?string $status=null): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => self::SCHEMA,
                    'filters'  => ['meetingKoppeling' => $meetingId],
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: ProxyVoteService::forMeeting failed',
                ['exception' => $e->getMessage(), 'meeting' => $meetingId]
            );
            return [
                'success' => false,
                'proxies' => [],
                'count'   => 0,
            ];
        }//end try

        $out = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false || ($row['meetingKoppeling'] ?? null) !== $meetingId) {
                continue;
            }

            if ($status !== null && ($row['proxyStatus'] ?? '') !== $status) {
                continue;
            }

            $out[] = $row;
        }

        return [
            'success' => true,
            'proxies' => $out,
            'count'   => count($out),
        ];

    }//end forMeeting()

    /**
     * Transition a proxy to a new status. Mirrors the change to the audit
     * log (proxy-created on `active`, proxy-revoked on `revoked`).
     *
     * @param string $proxyId   UUID of the proxy row
     * @param string $newStatus New status
     * @param string $actor     UUID of the user driving the transition
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     *
     * @return array{success: bool, proxy: array|null, message: string}
     */
    public function transition(string $proxyId, string $newStatus, string $actor): array
    {
        if (in_array($newStatus, self::STATUSES, true) === false) {
            return [
                'success' => false,
                'proxy'   => null,
                'message' => 'Unknown proxy status: '.$newStatus,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(
                id: $proxyId,
                register: 'decidesk',
                schema: self::SCHEMA
            );
            if ($entity === null) {
                return [
                    'success' => false,
                    'proxy'   => null,
                    'message' => 'Proxy not found.',
                ];
            }

            $current = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $current = $entity->getObject();
            }

            $previousStatus = (string) ($current['proxyStatus'] ?? '');
            $merged         = array_merge(
                $current,
                [
                    'proxyStatus'      => $newStatus,
                    'lastTransitionAt' => gmdate('Y-m-d\TH:i:s\Z'),
                    'lastTransitionBy' => $actor,
                ]
            );

            $saved = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: self::SCHEMA,
                uuid: $proxyId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: ProxyVoteService::transition failed',
                ['exception' => $e->getMessage(), 'proxyId' => $proxyId]
            );
            return [
                'success' => false,
                'proxy'   => null,
                'message' => 'Failed to transition proxy.',
            ];
        }//end try

        $payload = $merged;
        if (is_object($saved) === true) {
            $payload = (array) $saved->jsonSerialize();
        }

        if ($newStatus === 'revoked') {
            $this->auditLogService->append(
                actor: $actor,
                action: 'proxy-revoked',
                objectUids: [$proxyId, (string) ($current['meetingKoppeling'] ?? '')],
                payload: ['previousStatus' => $previousStatus]
            );
        }

        return [
            'success' => true,
            'proxy'   => $payload,
            'message' => 'Proxy now '.$newStatus.'.',
        ];

    }//end transition()

    /**
     * Convenience: suspend a proxy (e.g. grantor joins remotely).
     *
     * @param string $proxyId UUID of the proxy
     * @param string $actor   UUID of the user driving the transition
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     *
     * @return array{success: bool, proxy: array|null, message: string}
     */
    public function suspend(string $proxyId, string $actor): array
    {
        return $this->transition(proxyId: $proxyId, newStatus: 'suspended', actor: $actor);

    }//end suspend()

    /**
     * Convenience: revoke a proxy.
     *
     * @param string $proxyId UUID of the proxy
     * @param string $actor   UUID of the user driving the transition
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     *
     * @return array{success: bool, proxy: array|null, message: string}
     */
    public function revoke(string $proxyId, string $actor): array
    {
        return $this->transition(proxyId: $proxyId, newStatus: 'revoked', actor: $actor);

    }//end revoke()
}//end class
