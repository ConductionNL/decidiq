<?php

/**
 * Fail-soft tests for MigrateGovernanceGroupNames.
 *
 * A repair step that throws fails the whole `occ upgrade`, so every failure
 * inside the group rename must be caught, reported and survived: a group
 * manager that throws, a group that cannot be resolved, and a single member
 * that cannot be copied.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude One-off group-id rename plumbing; mirrors memberships and adds
 *       no behaviour of its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\MigrateGovernanceGroupNames;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Repair\MigrateGovernanceGroupNames
 */
class MigrateGovernanceGroupNamesFailureTest extends TestCase {

	/**
	 * A user mock that knows its own uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The mock.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A group manager that throws for one group is reported, and the other
	 * group is still migrated.
	 *
	 * @return void
	 */
	public function testAThrowingGroupIsReportedAndTheNextStillRuns(): void {
		$member = $this->user('alice');
		$oldIntegrity = $this->createMock(originalClassName: IGroup::class);
		$oldIntegrity->method('getUsers')->willReturn([$member]);
		$newIntegrity = $this->createMock(originalClassName: IGroup::class);
		$newIntegrity->method('inGroup')->willReturn(false);
		$newIntegrity->expects($this->once())->method('addUser')->with($member);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			static function (string $gid): bool {
				if ($gid === 'griffie') {
					throw new RuntimeException('backend down');
				}

				return $gid === 'decidesk-integriteit' || $gid === 'decidiq-integrity';
			}
		);
		$groupManager->method('get')->willReturnMap([
			['decidesk-integriteit', $oldIntegrity],
			['decidiq-integrity', $newIntegrity],
		]);
		$groupManager->expects($this->never())->method('createGroup');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with('Could not migrate the group griffie: backend down');
		$output->expects($this->once())
			->method('info')
			->with('Decidiq governance group rename complete: 1 membership(s) copied.');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Decidiq: governance group rename failed',
				['old' => 'griffie', 'new' => 'decidiq-secretariat', 'exception' => 'backend down']
			);

		(new MigrateGovernanceGroupNames(groupManager: $groupManager, logger: $logger))->run(output: $output);

	}//end testAThrowingGroupIsReportedAndTheNextStillRuns()

	/**
	 * An old group that exists but cannot be fetched is skipped without
	 * creating the new one.
	 *
	 * @return void
	 */
	public function testUnresolvableOldGroupIsSkipped(): void {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => $gid === 'griffie' || $gid === 'decidesk-integriteit'
		);
		$groupManager->method('get')->willReturn(null);
		$groupManager->expects($this->never())->method('createGroup');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->never())->method('warning');
		$output->expects($this->once())
			->method('info')
			->with('Decidiq governance group rename complete: 0 membership(s) copied.');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new MigrateGovernanceGroupNames(groupManager: $groupManager, logger: $logger))->run(output: $output);

	}//end testUnresolvableOldGroupIsSkipped()

	/**
	 * A missing new group is created; when it still cannot be resolved the
	 * old group keeps its members and the failure is logged.
	 *
	 * @return void
	 */
	public function testNewGroupThatCannotBeResolvedLeavesMembersInPlace(): void {
		$oldGriffie = $this->createMock(originalClassName: IGroup::class);
		$oldGriffie->expects($this->never())->method('getUsers');

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => $gid === 'griffie'
		);
		$groupManager->method('get')->willReturnMap([
			['griffie', $oldGriffie],
			['decidiq-secretariat', null],
		]);
		$groupManager->expects($this->once())->method('createGroup')->with('decidiq-secretariat');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with('Decidiq governance group rename complete: 0 membership(s) copied.');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Decidiq: could not resolve the renamed group; the old group keeps its members',
				['group' => 'decidiq-secretariat']
			);

		(new MigrateGovernanceGroupNames(groupManager: $groupManager, logger: $logger))->run(output: $output);

	}//end testNewGroupThatCannotBeResolvedLeavesMembersInPlace()

	/**
	 * One member that cannot be copied is logged; the rest are still copied
	 * and only successful copies are counted.
	 *
	 * @return void
	 */
	public function testAFailingMemberIsLoggedAndTheRestAreCopied(): void {
		$bob = $this->user('bob');
		$carol = $this->user('carol');
		$oldGriffie = $this->createMock(originalClassName: IGroup::class);
		$oldGriffie->method('getUsers')->willReturn([$bob, $carol]);

		$newSecretariat = $this->createMock(originalClassName: IGroup::class);
		$newSecretariat->method('inGroup')->willReturn(false);
		$newSecretariat->expects($this->exactly(2))
			->method('addUser')
			->willReturnCallback(static function (IUser $user) use ($bob): void {
				if ($user === $bob) {
					throw new RuntimeException('quota');
				}
			});

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => $gid === 'griffie' || $gid === 'decidiq-secretariat'
		);
		$groupManager->method('get')->willReturnMap([
			['griffie', $oldGriffie],
			['decidiq-secretariat', $newSecretariat],
		]);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->never())->method('warning');
		$output->expects($this->once())
			->method('info')
			->with('Decidiq governance group rename complete: 1 membership(s) copied.');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Decidiq: could not copy a user into the renamed group',
				['user' => 'bob', 'group' => 'decidiq-secretariat', 'exception' => 'quota']
			);

		(new MigrateGovernanceGroupNames(groupManager: $groupManager, logger: $logger))->run(output: $output);

	}//end testAFailingMemberIsLoggedAndTheRestAreCopied()
}//end class
