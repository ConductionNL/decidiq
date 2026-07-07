<?php
/**
 * Decidesk Governance Role Scope Projector
 *
 * Projects each GovernanceBody's signatory roster into OpenRegister RBAC scopes
 * — the OR/Nextcloud-native scope primitive is a group, so the projector
 * maintains two per-body Nextcloud groups from the body's Participant roster:
 *
 *   - `decidesk:body:{bodyId}:chair`      — members whose role is chair/chairman
 *   - `decidesk:body:{bodyId}:signatory`  — chair/chairman/vice-chairman/secretary
 *
 * This makes "who may sign / advance this body's objects" an OR-owned,
 * OR-queryable, OR-enforceable fact rather than a runtime graph walk. The
 * reconcile is idempotent and fails CLOSED: a missing scope membership denies,
 * never over-grants — so projection drift can only ever deny. Driven by a
 * Participant/Membership write listener and an idempotent repair-step backfill
 * (consume-or-rbac-authorization).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles per-body chair/signatory OR RBAC scopes from the Participant
 * roster. Idempotent, fails closed.
 *
 * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 */
class GovernanceRoleScopeProjector
{

    /**
     * Roles that grant chair scope membership.
     *
     * @var string[]
     */
    private const CHAIR_ROLES = ['chair', 'chairman'];

    /**
     * Roles that grant signatory scope membership (superset of chair).
     *
     * @var string[]
     */
    private const SIGNATORY_ROLES = ['chair', 'chairman', 'vice-chairman', 'secretary'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container    DI container (lazy ObjectService)
     * @param IGroupManager      $groupManager NC group manager (scope groups)
     * @param IUserManager       $userManager  NC user manager (uid -> IUser)
     * @param LoggerInterface    $logger       Diagnostic logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Reconcile both scope groups for a single body to its current roster.
     *
     * Idempotent: computes the desired chair/signatory member sets from the
     * body's Participants and makes the group membership match exactly (adds
     * missing, removes stale). A second run is a no-op. On any failure it logs
     * and leaves the scopes unchanged (deny-biased) — never over-grants.
     *
     * @param string $bodyId GovernanceBody UUID
     *
     * @return void
     *
     * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
     */
    public function reconcileBody(string $bodyId): void
    {
        if ($bodyId === '') {
            return;
        }

        try {
            [$chairUids, $signatoryUids] = $this->desiredMembers(bodyId: $bodyId);

            $this->syncScope(
                groupId: GovernanceScopeGuard::scopeGroupId(bodyId: $bodyId, scope: GovernanceScopeGuard::SCOPE_CHAIR),
                desiredUids: $chairUids
            );
            $this->syncScope(
                groupId: GovernanceScopeGuard::scopeGroupId(bodyId: $bodyId, scope: GovernanceScopeGuard::SCOPE_SIGNATORY),
                desiredUids: $signatoryUids
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'GovernanceRoleScopeProjector::reconcileBody failed; scopes left unchanged (deny-biased)',
                ['exception' => $e->getMessage(), 'bodyId' => $bodyId]
            );
        }//end try
    }//end reconcileBody()

    /**
     * Reconcile the body referenced by a Participant/Membership row.
     *
     * @param array<string, mixed> $row Serialised Participant/Membership object
     *
     * @return void
     *
     * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
     */
    public function reconcileFromMemberRow(array $row): void
    {
        $bodyId = $this->extractBodyId(row: $row);
        if ($bodyId !== null) {
            $this->reconcileBody(bodyId: $bodyId);
        }
    }//end reconcileFromMemberRow()

    /**
     * Reconcile every GovernanceBody (backfill). Idempotent.
     *
     * @return int Number of bodies reconciled
     *
     * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
     */
    public function reconcileAll(): int
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $objectService->setRegister('decidesk');
        $objectService->setSchema('governancebody');
        $bodies = $objectService->findAll(['filters' => ['_limit' => 9999]]);

        $count = 0;
        foreach ($bodies as $bodyEntity) {
            $body   = $bodyEntity->jsonSerialize();
            $bodyId = (string) ($body['id'] ?? ($body['@self']['id'] ?? ''));
            if ($bodyId !== '') {
                $this->reconcileBody(bodyId: $bodyId);
                $count++;
            }
        }

        return $count;
    }//end reconcileAll()

    /**
     * Compute the desired chair + signatory Nextcloud UID sets for a body.
     *
     * @param string $bodyId GovernanceBody UUID
     *
     * @return array{0: string[], 1: string[]} [chairUids, signatoryUids]
     */
    private function desiredMembers(string $bodyId): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $participants = $objectService->findAll(
            [
                'filters' => [
                    'role'   => self::SIGNATORY_ROLES,
                    '_limit' => 9999,
                ],
            ]
        );

        $chairUids     = [];
        $signatoryUids = [];
        foreach ($participants as $participantEntity) {
            $participant = $participantEntity->jsonSerialize();

            if ($this->extractBodyId(row: $participant) !== $bodyId) {
                continue;
            }

            $uid = (string) ($participant['nextcloudUserId'] ?? '');
            if ($uid === '') {
                continue;
            }

            $role = (string) ($participant['role'] ?? '');
            if (in_array($role, self::SIGNATORY_ROLES, true) === true) {
                $signatoryUids[$uid] = $uid;
            }

            if (in_array($role, self::CHAIR_ROLES, true) === true) {
                $chairUids[$uid] = $uid;
            }
        }//end foreach

        return [array_values($chairUids), array_values($signatoryUids)];
    }//end desiredMembers()

    /**
     * Make a scope group's membership match the desired UID set exactly.
     *
     * @param string   $groupId     Scope group id
     * @param string[] $desiredUids Desired member UIDs
     *
     * @return void
     */
    private function syncScope(string $groupId, array $desiredUids): void
    {
        if ($this->groupManager->groupExists($groupId) === false) {
            $this->groupManager->createGroup($groupId);
        }

        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return;
        }

        $desired = array_fill_keys($desiredUids, true);

        $currentUids = [];
        foreach ($group->getUsers() as $user) {
            $uid = $user->getUID();
            $currentUids[$uid] = true;
            if (isset($desired[$uid]) === false) {
                $group->removeUser($user);
            }
        }

        foreach ($desiredUids as $uid) {
            if (isset($currentUids[$uid]) === true) {
                continue;
            }

            $user = $this->userManager->get($uid);
            if ($user !== null) {
                $group->addUser($user);
            }
        }
    }//end syncScope()

    /**
     * Extract the GovernanceBody UUID from a serialised member row (relations
     * map or a direct governanceBody property).
     *
     * @param array<string, mixed> $row Serialised object
     *
     * @return string|null
     */
    private function extractBodyId(array $row): ?string
    {
        $candidates = [];

        $relations = ($row['relations'] ?? []);
        if (is_array($relations) === true) {
            foreach (['governanceBody', 'GovernanceBody'] as $key) {
                if (isset($relations[$key]) === true) {
                    $candidates[] = $relations[$key];
                }
            }
        }

        foreach (['governanceBody', 'GovernanceBody'] as $key) {
            if (isset($row[$key]) === true) {
                $candidates[] = $row[$key];
            }
        }

        foreach ($candidates as $value) {
            if (is_array($value) === true) {
                $value = ($value['id'] ?? ($value[0] ?? null));
            }

            if (is_string($value) === true && $value !== '') {
                return $value;
            }
        }

        return null;
    }//end extractBodyId()
}//end class
