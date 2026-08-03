<?php
/**
 * Unit tests for GovernanceRoleScopeProjector — projects governance-body roles
 * into OpenRegister RBAC scopes (consume-or-rbac-authorization, REQ-RBAC-001).
 * Proves chair -> both scopes, secretary -> signatory only, idempotent reconcile
 * (a re-run adds nothing), and fail-closed behaviour.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
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

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\GovernanceRoleScopeProjector;
use OCA\Decidesk\Service\GovernanceScopeGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for GovernanceRoleScopeProjector.
 *
 * @spec openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 */
class GovernanceRoleScopeProjectorTest extends TestCase
{

    /**
     * Recorded membership per group id, shared across the group doubles.
     *
     * @var array<string, array<string, bool>>
     */
    private array $groups = [];

    /**
     * Reset recorded membership before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = [];
    }//end setUp()

    /**
     * A chair lands in BOTH the chair scope and the signatory scope; a secretary
     * lands in the signatory scope only.
     *
     * @return void
     */
    public function testChairInBothScopesSecretaryInSignatoryOnly(): void
    {
        $projector = $this->makeProjector(
            [
                ['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'body-1'],
                ['role' => 'secretary', 'nextcloudUserId' => 'bob', 'governanceBody' => 'body-1'],
                // A chair of a DIFFERENT body must not leak into body-1's scopes.
                ['role' => 'chair', 'nextcloudUserId' => 'carol', 'governanceBody' => 'body-2'],
            ]
        );

        $projector->reconcileBody('body-1');

        $chair     = $this->groups['decidesk:body:body-1:chair'] ?? [];
        $signatory = $this->groups['decidesk:body:body-1:signatory'] ?? [];

        $this->assertArrayHasKey('alice', $chair, 'chair is in the chair scope');
        $this->assertArrayNotHasKey('bob', $chair, 'secretary is NOT in the chair scope');
        $this->assertArrayNotHasKey('carol', $chair, 'chair of another body does not leak');

        $this->assertArrayHasKey('alice', $signatory, 'chair is in the signatory scope');
        $this->assertArrayHasKey('bob', $signatory, 'secretary is in the signatory scope');
        $this->assertArrayNotHasKey('carol', $signatory, 'other body does not leak into signatory');
    }//end testChairInBothScopesSecretaryInSignatoryOnly()

    /**
     * Removing a role reconciles the scopes: a former chair whose role is gone is
     * removed from both scopes on the next reconcile.
     *
     * @return void
     */
    public function testRemovingRoleReconcilesScopes(): void
    {
        // Pre-seed the scopes as if alice was previously a chair.
        $this->groups['decidesk:body:body-1:chair']     = ['alice' => true];
        $this->groups['decidesk:body:body-1:signatory'] = ['alice' => true];

        // Now the roster has NO chair/secretary for body-1.
        $projector = $this->makeProjector([]);
        $projector->reconcileBody('body-1');

        $this->assertArrayNotHasKey('alice', ($this->groups['decidesk:body:body-1:chair'] ?? []));
        $this->assertArrayNotHasKey('alice', ($this->groups['decidesk:body:body-1:signatory'] ?? []));
    }//end testRemovingRoleReconcilesScopes()

    /**
     * Reconcile is idempotent: a second run over the same roster makes no change.
     *
     * @return void
     */
    public function testReconcileIsIdempotent(): void
    {
        $projector = $this->makeProjector(
            [['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'body-1']]
        );

        $projector->reconcileBody('body-1');
        $first = $this->groups;

        $projector->reconcileBody('body-1');
        $this->assertSame($first, $this->groups, 'second reconcile is a no-op');
    }//end testReconcileIsIdempotent()

    /**
     * An empty body id is a no-op (fail-closed: never over-grants).
     *
     * @return void
     */
    public function testEmptyBodyIsNoOp(): void
    {
        $projector = $this->makeProjector(
            [['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'body-1']]
        );

        $projector->reconcileBody('');
        $this->assertSame([], $this->groups);
    }//end testEmptyBodyIsNoOp()

    /**
     * reconcileFromMemberRow resolves the body from the row and reconciles it.
     *
     * @return void
     */
    public function testReconcileFromMemberRow(): void
    {
        $projector = $this->makeProjector(
            [['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'body-1']]
        );

        $projector->reconcileFromMemberRow(['role' => 'chair', 'governanceBody' => 'body-1']);

        $this->assertArrayHasKey('alice', ($this->groups['decidesk:body:body-1:chair'] ?? []));
    }//end testReconcileFromMemberRow()

    /**
     * Build a projector whose ObjectService returns the given participant rows.
     *
     * @param array<int, array<string, mixed>> $participants Participant rows
     *
     * @return GovernanceRoleScopeProjector
     */
    private function makeProjector(array $participants): GovernanceRoleScopeProjector
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister');
        $objectService->method('setSchema');
        $objectService->method('findAll')->willReturnCallback(
            function () use ($participants): array {
                $entities = [];
                foreach ($participants as $row) {
                    $entity = $this->createMock(ObjectEntity::class);
                    $entity->method('jsonSerialize')->willReturn($row);
                    $entities[] = $entity;
                }

                return $entities;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        // A user manager that resolves any uid to a stub IUser with that UID.
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(
            function (string $uid): IUser {
                $user = $this->createMock(IUser::class);
                $user->method('getUID')->willReturn($uid);
                return $user;
            }
        );

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('groupExists')->willReturnCallback(
            fn (string $gid): bool => isset($this->groups[$gid])
        );
        $groupManager->method('createGroup')->willReturnCallback(
            function (string $gid): void {
                if (isset($this->groups[$gid]) === false) {
                    $this->groups[$gid] = [];
                }
            }
        );
        $groupManager->method('get')->willReturnCallback(
            fn (string $gid): IGroup => $this->makeGroup($gid)
        );

        return new GovernanceRoleScopeProjector(
            $container,
            $groupManager,
            $userManager,
            $this->createMock(LoggerInterface::class),
            new GovernanceScopeGuard(
                $container,
                $groupManager,
                $this->createMock(LoggerInterface::class)
            )
        );
    }//end makeProjector()

    /**
     * Build an IGroup double backed by the shared $this->groups membership map.
     *
     * @param string $gid Group id
     *
     * @return IGroup
     */
    private function makeGroup(string $gid): IGroup
    {
        if (isset($this->groups[$gid]) === false) {
            $this->groups[$gid] = [];
        }

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturnCallback(
            function () use ($gid): array {
                $users = [];
                foreach (array_keys($this->groups[$gid]) as $uid) {
                    $user = $this->createMock(IUser::class);
                    $user->method('getUID')->willReturn((string) $uid);
                    $users[] = $user;
                }

                return $users;
            }
        );
        $group->method('addUser')->willReturnCallback(
            function (IUser $user) use ($gid): void {
                $this->groups[$gid][$user->getUID()] = true;
            }
        );
        $group->method('removeUser')->willReturnCallback(
            function (IUser $user) use ($gid): void {
                unset($this->groups[$gid][$user->getUID()]);
            }
        );

        return $group;
    }//end makeGroup()
}//end class
