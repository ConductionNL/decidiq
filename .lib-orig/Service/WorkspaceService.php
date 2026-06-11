<?php
/**
 * Decidesk Workspace Service
 *
 * Stateless service handling CollaborationWorkspace lifecycle: bounded
 * collaboration spaces scoped to factions, committees, or task groups.
 * Member-list management is handled here; full RBAC enforcement is
 * delegated to OpenRegister's AuthorizationService.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for creating, updating, and managing membership of CollaborationWorkspace objects.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
 */
class WorkspaceService
{
    /**
     * Construct the WorkspaceService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
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
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Create a new CollaborationWorkspace.
     *
     * @param array<string, mixed> $workspace Workspace properties
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When required fields are missing
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    public function createWorkspace(array $workspace): array
    {
        if (empty($workspace['name']) === true) {
            throw new InvalidArgumentException('Workspace name is required');
        }

        if (empty($workspace['type']) === true) {
            throw new InvalidArgumentException('Workspace type is required');
        }

        if (isset($workspace['accessLevel']) === false) {
            $workspace['accessLevel'] = 'private';
        }

        if (isset($workspace['createdAt']) === false) {
            $workspace['createdAt'] = (new DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }

        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $workspace,
            register: 'decidesk',
            schema: 'collaboration-workspace',
        );

        $this->logger->info(
            'Decidesk: Workspace created',
            ['name' => $workspace['name'], 'type' => $workspace['type']]
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end createWorkspace()

    /**
     * Find a workspace by UUID.
     *
     * @param string $workspaceId UUID of the workspace
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    public function findWorkspace(string $workspaceId): ?array
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('collaboration-workspace');

        $entity = $objectService->find($workspaceId);
        if ($entity === null) {
            return null;
        }

        return $entity->getObject();

    }//end findWorkspace()

    /**
     * Update a workspace.
     *
     * @param string               $workspaceId UUID of the workspace
     * @param array<string, mixed> $changes     Fields to update
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the workspace cannot be found
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    public function updateWorkspace(string $workspaceId, array $changes): array
    {
        $workspace = $this->findWorkspace(workspaceId: $workspaceId);
        if ($workspace === null) {
            throw new RuntimeException("Workspace $workspaceId not found");
        }

        $allowed = ['name', 'purpose', 'accessLevel', 'owner', 'type'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $changes) === true) {
                $workspace[$key] = $changes[$key];
            }
        }

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $workspace,
            register: 'decidesk',
            schema: 'collaboration-workspace',
            uuid: $workspaceId,
        );

        return $workspace;

    }//end updateWorkspace()

    /**
     * Resolve the participant UUID for a given Nextcloud UID.
     *
     * Uses the canonical OR-API path: setRegister/setSchema/findAll with a
     * `nextcloudUserId` filter. Returns null when no participant is linked.
     *
     * @param string $nextcloudUid Nextcloud user ID to resolve
     *
     * @return string|null Participant UUID, or null when not found
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    private function resolveParticipantUuid(string $nextcloudUid): ?string
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('participant');
            $entities = $objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

            foreach ($entities as $entity) {
                $participant = $entity->jsonSerialize();
                return ($participant['uuid'] ?? $participant['id'] ?? null);
            }
        } catch (\Throwable $e) {
            // Non-fatal — service may be unavailable.
        }

        return null;

    }//end resolveParticipantUuid()

    /**
     * Check whether a caller owns the workspace or is a known member.
     *
     * A caller is authorised when the workspace `owner` field matches the
     * caller's Nextcloud UID, or when the caller's resolved participant UUID
     * is already in the `members` list. The `members` field stores participant
     * UUIDs (OpenRegister object references), so we resolve the NC UID to a
     * participant UUID before comparison. This prevents IDOR on
     * membership-mutation endpoints (OWASP A01:2021 — Broken Access Control).
     *
     * @param array<string, mixed> $workspace Workspace data array
     * @param string               $callerUid Nextcloud UID of the requester
     *
     * @return bool True when the caller may modify membership
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    public function isMembershipManager(array $workspace, string $callerUid): bool
    {
        if ($callerUid === '') {
            return false;
        }

        // Workspace owner may always manage membership.
        if ((string) ($workspace['owner'] ?? '') === $callerUid) {
            return true;
        }

        // Existing workspace members may add/remove other members.
        // members[] stores participant UUIDs — resolve caller's UID first.
        $callerPtUuid = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
        if ($callerPtUuid === null) {
            return false;
        }

        $members = ($workspace['members'] ?? []);
        if (in_array(needle: $callerPtUuid, haystack: $members, strict: true) === true) {
            return true;
        }

        return false;

    }//end isMembershipManager()

    /**
     * Add a member reference to the workspace's member list.
     *
     * The caller must be the workspace owner or an existing member (OWASP A01).
     * Pass `$callerUid = null` only from admin-only or system-internal paths.
     *
     * @param string      $workspaceId UUID of the workspace
     * @param string      $memberRef   Membership reference (UUID or Nextcloud UID)
     * @param string|null $callerUid   Nextcloud UID of the requester (null = skip check)
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException         When the workspace cannot be found
     * @throws \InvalidArgumentException When the caller is not authorised to manage membership
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    public function addMember(string $workspaceId, string $memberRef, ?string $callerUid=null): array
    {
        $workspace = $this->findWorkspace(workspaceId: $workspaceId);
        if ($workspace === null) {
            throw new RuntimeException("Workspace $workspaceId not found");
        }

        if ($callerUid !== null && $this->isMembershipManager(workspace: $workspace, callerUid: $callerUid) === false) {
            throw new InvalidArgumentException('Only workspace owners and members may add members to this workspace');
        }

        $members = ($workspace['members'] ?? []);
        if (in_array($memberRef, $members, true) === false) {
            $members[]            = $memberRef;
            $workspace['members'] = $members;

            $objectService = $this->getObjectService();
            $objectService->saveObject(
                object: $workspace,
                register: 'decidesk',
                schema: 'collaboration-workspace',
                uuid: $workspaceId,
            );

            $this->logger->info(
                'Decidesk: Workspace member added',
                ['workspaceId' => $workspaceId, 'member' => $memberRef, 'by' => $callerUid]
            );
        }

        return $workspace;

    }//end addMember()

    /**
     * Remove a member reference from the workspace's member list.
     *
     * The caller must be the workspace owner or an existing member (OWASP A01).
     * Pass `$callerUid = null` only from admin-only or system-internal paths.
     *
     * @param string      $workspaceId UUID of the workspace
     * @param string      $memberRef   Membership reference (UUID or Nextcloud UID)
     * @param string|null $callerUid   Nextcloud UID of the requester (null = skip check)
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException         When the workspace cannot be found
     * @throws \InvalidArgumentException When the caller is not authorised to manage membership
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.1
     */
    public function removeMember(string $workspaceId, string $memberRef, ?string $callerUid=null): array
    {
        $workspace = $this->findWorkspace(workspaceId: $workspaceId);
        if ($workspace === null) {
            throw new RuntimeException("Workspace $workspaceId not found");
        }

        if ($callerUid !== null && $this->isMembershipManager(workspace: $workspace, callerUid: $callerUid) === false) {
            throw new InvalidArgumentException('Only workspace owners and members may remove members from this workspace');
        }

        $members = ($workspace['members'] ?? []);
        $workspace['members'] = array_values(
            array_filter(
                $members,
                static fn($id) => $id !== $memberRef
            )
        );

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $workspace,
            register: 'decidesk',
            schema: 'collaboration-workspace',
            uuid: $workspaceId,
        );

        $this->logger->info(
            'Decidesk: Workspace member removed',
            ['workspaceId' => $workspaceId, 'member' => $memberRef, 'by' => $callerUid]
        );

        return $workspace;

    }//end removeMember()
}//end class
