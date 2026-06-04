<?php
/**
 * Decidesk Board Proxy Service
 *
 * Registers, suspends and revokes proxy delegations for board meetings, with
 * per-agenda-item scope, automatic revocation at meeting close and audit logging.
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

/**
 * Proxy delegation lifecycle management.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class BoardProxyService
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Schema slug.
     *
     * @var string
     */
    private const SCHEMA = 'board-proxy';

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container The DI container.
     * @param BoardAuditLogService $auditLog  The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly BoardAuditLogService $auditLog,
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
     * Normalise a saved object (or array) into an associative array.
     *
     * @param mixed               $saved    ObjectEntity or array returned by the store.
     * @param array<string,mixed> $fallback The original payload used when serialization is unavailable.
     *
     * @return array<string,mixed>
     */
    private function serializeResult(mixed $saved, array $fallback): array
    {
        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            return $saved->jsonSerialize();
        }

        if (is_array($saved) === true) {
            return $saved;
        }

        return $fallback;

    }//end serializeResult()

    /**
     * Register a proxy delegation.
     *
     * @param string        $meetingId      BoardMeeting UUID.
     * @param string        $grantorId      Absent member BoardMember UUID.
     * @param string        $holderId       Proxy holder BoardMember UUID.
     * @param string        $scope          Scope enum value (full|per-agenda-item).
     * @param array<string> $resolutionUids Resolutions in scope when per-agenda-item.
     * @param string        $expiresAt      ISO-8601 expiry.
     * @param string        $actorUuid      Acting user UUID (for audit).
     *
     * @return array<string,mixed> The persisted proxy.
     *
     * @throws \RuntimeException On invalid input.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    public function register(
        string $meetingId,
        string $grantorId,
        string $holderId,
        string $scope,
        array $resolutionUids,
        string $expiresAt,
        string $actorUuid
    ): array {
        if (in_array($scope, ['full', 'per-agenda-item'], true) === false) {
            throw new \RuntimeException('Invalid proxy scope');
        }

        if ($grantorId === $holderId) {
            throw new \RuntimeException('A member cannot hold their own proxy');
        }

        $proxy = [
            'scope'                => $scope,
            'scopedResolutionUids' => array_values($resolutionUids),
            'status'               => 'active',
            'expiresAt'            => $expiresAt,
            'createdAt'            => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'relations'            => [
                ['schema' => 'board-meeting', 'id' => $meetingId],
                ['schema' => 'board-member', 'id' => $grantorId],
                ['schema' => 'board-member', 'id' => $holderId],
            ],
        ];

        $saved = $this->objectService()->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $proxy);
        $this->auditLog->append(actorUuid: $actorUuid, action: 'proxy-created', objectUids: [$meetingId, $grantorId, $holderId]);

        return $this->serializeResult(saved: $saved, fallback: $proxy);

    }//end register()

    /**
     * Set a proxy's status (suspend or revoke) and log the transition.
     *
     * @param string $proxyId   Proxy UUID.
     * @param string $status    New status (suspended|revoked).
     * @param string $actorUuid Acting user UUID (for audit).
     *
     * @return array<string,mixed> The updated proxy.
     *
     * @throws \RuntimeException When the proxy is missing or status invalid.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    public function setStatus(string $proxyId, string $status, string $actorUuid): array
    {
        if (in_array($status, ['suspended', 'revoked'], true) === false) {
            throw new \RuntimeException('Invalid proxy status transition');
        }

        $objectService = $this->objectService();
        $entity        = $objectService->find(id: $proxyId, register: self::REGISTER, schema: self::SCHEMA);
        if ($entity === null) {
            throw new \RuntimeException('Proxy not found');
        }

        $data           = $entity->jsonSerialize();
        $data['status'] = $status;
        if ($status === 'revoked') {
            $data['revokedAt'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
        }

        $saved = $objectService->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $data, uuid: $proxyId);
        $this->auditLog->append(actorUuid: $actorUuid, action: 'proxy-revoked', objectUids: [$proxyId]);

        return $this->serializeResult(saved: $saved, fallback: $data);

    }//end setStatus()

    /**
     * Revoke all active proxies for a meeting (called at meeting close).
     *
     * @param string $meetingId BoardMeeting UUID.
     * @param string $actorUuid Acting user UUID (for audit).
     *
     * @return int The number of proxies revoked.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    public function revokeAllForMeeting(string $meetingId, string $actorUuid): int
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema(self::SCHEMA);
        $result  = $objectService->findAll(['filters' => ['relations.boardMeeting' => $meetingId]]);
        $revoked = 0;
        foreach (($result['results'] ?? $result) as $item) {
            $data = $this->serializeResult(saved: $item, fallback: []);
            if (($data['status'] ?? '') !== 'active') {
                continue;
            }

            $uuid = ($data['id'] ?? ($data['uuid'] ?? null));
            if ($uuid !== null) {
                $this->setStatus(proxyId: (string) $uuid, status: 'revoked', actorUuid: $actorUuid);
                $revoked++;
            }
        }

        return $revoked;

    }//end revokeAllForMeeting()

    /**
     * Determine whether a proxy is active for a given resolution.
     *
     * @param array<string,mixed> $proxy        Proxy data.
     * @param string              $resolutionId Resolution UUID.
     *
     * @return bool
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     */
    public function isActiveForResolution(array $proxy, string $resolutionId): bool
    {
        if (($proxy['status'] ?? '') !== 'active') {
            return false;
        }

        if (($proxy['scope'] ?? '') === 'full') {
            return true;
        }

        return in_array($resolutionId, (array) ($proxy['scopedResolutionUids'] ?? []), true);

    }//end isActiveForResolution()
}//end class
