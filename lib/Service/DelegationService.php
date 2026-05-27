<?php
/**
 * Decidesk Delegation Service
 *
 * Stateless service handling task delegation with optional substitute
 * (during absence) and revocation/expiry lifecycle.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for creating, revoking, and expiring task delegations.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2.2
 */
class DelegationService
{
    /**
     * Construct the DelegationService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.2
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Create a delegation, optionally with a substitute during absence.
     *
     * @param array<string, mixed> $delegation Delegation properties
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When persisting fails
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.2
     */
    public function createDelegation(array $delegation): array
    {
        if (isset($delegation['delegatedAt']) === false) {
            $delegation['delegatedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        }

        if (isset($delegation['status']) === false) {
            $delegation['status'] = 'active';
        }

        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $delegation,
            register: 'decidesk',
            schema: 'delegation',
        );

        $this->logger->info(
            'Decidesk: Delegation created',
            ['taskUid' => ($delegation['taskUid'] ?? null)]
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end createDelegation()

    /**
     * Find a delegation by UUID.
     *
     * @param string $delegationId UUID of the Delegation object
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.2
     */
    public function findDelegation(string $delegationId): ?array
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('delegation');

        $entity = $objectService->find($delegationId);
        if ($entity === null) {
            return null;
        }

        return $entity->getObject();

    }//end findDelegation()

    /**
     * Revoke a delegation (set status='revoked').
     *
     * Only the principal (the user who created the delegation, stored in
     * `delegatedBy`) or a system admin (NC admin) may revoke it.
     * Pass `$callerUid = null` only from background jobs or admin-only paths
     * that have already enforced their own access check.
     *
     * @param string      $delegationId UUID of the Delegation object
     * @param string|null $callerUid    Nextcloud UID of the caller (null = skip owner check)
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException         When the delegation cannot be found
     * @throws \InvalidArgumentException When the caller is not the principal
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.2
     */
    public function revokeDelegation(string $delegationId, ?string $callerUid = null): array
    {
        $delegation = $this->findDelegation(delegationId: $delegationId);
        if ($delegation === null) {
            throw new RuntimeException("Delegation $delegationId not found");
        }

        // OWASP A01 — only the principal may revoke their own delegation.
        if ($callerUid !== null) {
            $principal = (string) ($delegation['delegatedBy'] ?? '');
            if ($principal === '' || $principal !== $callerUid) {
                throw new \InvalidArgumentException('Only the delegation principal may revoke this delegation');
            }
        }

        $delegation['status'] = 'revoked';

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $delegation,
            register: 'decidesk',
            schema: 'delegation',
            uuid: $delegationId,
        );

        $this->logger->info('Decidesk: Delegation revoked', ['delegationId' => $delegationId, 'by' => $callerUid]);

        return $delegation;

    }//end revokeDelegation()

    /**
     * Expire a delegation (set status='expired').
     *
     * Intended to be called by a background job (no caller check) or by the
     * principal themselves. To prevent IDOR, pass `$callerUid` when invoked
     * from a user-facing API; omit only for background-job or admin-only paths.
     *
     * @param string      $delegationId UUID of the Delegation object
     * @param string|null $callerUid    Nextcloud UID of the caller (null = skip owner check)
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException         When the delegation cannot be found
     * @throws \InvalidArgumentException When the caller is not the principal
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.2
     */
    public function expireDelegation(string $delegationId, ?string $callerUid = null): array
    {
        $delegation = $this->findDelegation(delegationId: $delegationId);
        if ($delegation === null) {
            throw new RuntimeException("Delegation $delegationId not found");
        }

        // OWASP A01 — only the principal may manually expire their own delegation.
        if ($callerUid !== null) {
            $principal = (string) ($delegation['delegatedBy'] ?? '');
            if ($principal === '' || $principal !== $callerUid) {
                throw new \InvalidArgumentException('Only the delegation principal may expire this delegation');
            }
        }

        $delegation['status'] = 'expired';

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $delegation,
            register: 'decidesk',
            schema: 'delegation',
            uuid: $delegationId,
        );

        $this->logger->info('Decidesk: Delegation expired', ['delegationId' => $delegationId, 'by' => $callerUid]);

        return $delegation;

    }//end expireDelegation()
}//end class
