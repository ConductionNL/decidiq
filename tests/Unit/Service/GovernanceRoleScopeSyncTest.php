<?php

/**
 * Characterisation tests for GovernanceRoleScopeProjector membership sync:
 * the exact group operations, in order, for a partial roster change, new
 * scopes, unknown users, unavailable groups and relation-based body ids.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\GovernanceRoleScopeProjector;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the exact group operations a reconcile performs, in order.
 */
class GovernanceRoleScopeSyncTest extends TestCase {

	/**
	 * Group members by group id.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $groups = [];

	/**
	 * Every group operation, in order.
	 *
	 * @var array<int, string>
	 */
	private array $ops = [];

	/**
	 * Build the projector.
	 *
	 * @param array<int, array<string, mixed>> $participants The participant rows.
	 * @param array<int, string>               $unknownUsers UIDs the user manager does not know.
	 * @param bool                             $groupsVanish Whether get() finds no group even after create.
	 *
	 * @return GovernanceRoleScopeProjector
	 */
	private function projector(array $participants, array $unknownUsers=[], bool $groupsVanish=false): GovernanceRoleScopeProjector {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function () use ($participants): array {
				return array_map(
					function (array $row): ObjectEntity {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						return $entity;
					},
					$participants
				);
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => in_array($uid, $unknownUsers, true) === true ? null : $this->user(uid: $uid)
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(fn (string $gid): bool => isset($this->groups[$gid]));
		$groupManager->method('createGroup')->willReturnCallback(
			function (string $gid): void {
				$this->ops[] = 'create ' . $gid;
				$this->groups[$gid] = ($this->groups[$gid] ?? []);
			}
		);
		$groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => ($groupsVanish === false && isset($this->groups[$gid]) === true) ? $this->group(gid: $gid) : null
		);

		return new GovernanceRoleScopeProjector(
			$groupManager,
			$users,
			$this->createMock(LoggerInterface::class),
			new GovernanceScopeGuard($groupManager, $this->createMock(LoggerInterface::class), objectService: $objectService),
			objectService: $objectService,
		);
	}//end projector()

	/**
	 * A user double.
	 *
	 * @param string $uid The UID.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A group double recording its operations.
	 *
	 * @param string $gid The group id.
	 *
	 * @return IGroup
	 */
	private function group(string $gid): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturnCallback(
			fn (): array => array_map(fn ($uid): IUser => $this->user(uid: (string)$uid), array_keys($this->groups[$gid]))
		);
		$group->method('addUser')->willReturnCallback(
			function (IUser $user) use ($gid): void {
				$this->ops[] = 'add ' . $gid . ' ' . $user->getUID();
				$this->groups[$gid][$user->getUID()] = true;
			}
		);
		$group->method('removeUser')->willReturnCallback(
			function (IUser $user) use ($gid): void {
				$this->ops[] = 'remove ' . $gid . ' ' . $user->getUID();
				unset($this->groups[$gid][$user->getUID()]);
			}
		);
		$group->method('delete')->willReturnCallback(
			function () use ($gid): bool {
				$this->ops[] = 'delete ' . $gid;
				unset($this->groups[$gid]);
				return true;
			}
		);

		return $group;
	}//end group()

	/**
	 * A partial roster change removes the leavers, then adds the joiners, in roster order.
	 *
	 * @return void
	 */
	public function testAPartialChangeRemovesThenAdds(): void {
		$this->groups['decidesk:body:b-1:chair'] = ['alice' => true, 'old-chair' => true];
		$this->groups['decidesk:body:b-1:signatory'] = ['alice' => true, 'old-chair' => true, 'bob' => true];
		$rows = [
			['role' => 'chairman', 'nextcloudUserId' => 'zed', 'relations' => ['GovernanceBody' => 'b-1']],
			['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'b-1'],
			['role' => 'vice-chairman', 'nextcloudUserId' => 'carol', 'governanceBody' => 'b-1'],
			['role' => 'secretary', 'nextcloudUserId' => 'bob', 'governanceBody' => 'b-1'],
			['role' => 'secretary', 'nextcloudUserId' => '', 'governanceBody' => 'b-1'],
			['role' => 'member', 'nextcloudUserId' => 'dave', 'governanceBody' => 'b-1'],
			['role' => 'chair', 'nextcloudUserId' => 'erin', 'governanceBody' => 'b-2'],
		];

		$this->projector(participants: $rows)->reconcileBody('b-1');

		$this->assertSame(
			[
				'remove decidesk:body:b-1:chair old-chair',
				'add decidesk:body:b-1:chair zed',
				'remove decidesk:body:b-1:signatory old-chair',
				'add decidesk:body:b-1:signatory zed',
				'add decidesk:body:b-1:signatory carol',
			],
			$this->ops
		);
	}//end testAPartialChangeRemovesThenAdds()

	/**
	 * New scopes are created; unknown users are skipped.
	 *
	 * @return void
	 */
	public function testNewScopesAreCreatedAndUnknownUsersSkipped(): void {
		$rows = [
			['role' => 'chair', 'nextcloudUserId' => 'ghost', 'governanceBody' => 'b-1'],
			['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'b-1'],
		];

		$this->projector(participants: $rows, unknownUsers: ['ghost'])->reconcileBody('b-1');

		$this->assertSame(
			[
				'create decidesk:body:b-1:chair',
				'add decidesk:body:b-1:chair alice',
				'create decidesk:body:b-1:signatory',
				'add decidesk:body:b-1:signatory alice',
			],
			$this->ops
		);
	}//end testNewScopesAreCreatedAndUnknownUsersSkipped()

	/**
	 * A group that cannot be fetched after creation is left alone.
	 *
	 * @return void
	 */
	public function testAnUnavailableGroupIsLeftAlone(): void {
		$this->projector(participants: [['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'b-1']], groupsVanish: true)->reconcileBody('b-1');

		$this->assertSame(['create decidesk:body:b-1:chair', 'create decidesk:body:b-1:signatory'], $this->ops);
	}//end testAnUnavailableGroupIsLeftAlone()

	/**
	 * A relation object or a non-array relations field still resolves the body.
	 *
	 * @return void
	 */
	public function testBodyIdFromRelationsAndRowFallback(): void {
		$rows = [
			['role' => 'chair', 'nextcloudUserId' => 'alice', 'relations' => 'junk', 'governanceBody' => ['id' => 'b-1']],
			['role' => 'secretary', 'nextcloudUserId' => 'bob', 'relations' => ['governanceBody' => 'b-9'], 'governanceBody' => 'b-1'],
		];

		$this->projector(participants: $rows)->reconcileBody('b-1');

		$this->assertSame(['alice' => true], $this->groups['decidesk:body:b-1:chair']);
		$this->assertSame(['alice' => true], $this->groups['decidesk:body:b-1:signatory']);
	}//end testBodyIdFromRelationsAndRowFallback()
}//end class
