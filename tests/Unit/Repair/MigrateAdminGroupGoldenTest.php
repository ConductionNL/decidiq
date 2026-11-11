<?php

/**
 * Golden runs of the branches MigrateAdminGroupTest leaves open: an old group
 * that exists but cannot be fetched, and a group backend that throws, which
 * the step logs instead of failing the app enable. Pinned before run() was
 * split into named steps.
 *
 * The failure this step prevents: the register's authorization baseline named
 * `decidesk-administrators`, OpenRegister provisioned the group under that
 * old app id, and administrators granted real memberships in it. Renaming the
 * declaration alone would strand those memberships — a gid cannot be renamed,
 * so the step must copy members into `decidiq-administrators` while the old
 * group stays honored.
 *
 * Properties pinned here:
 *   - no old group means NO work: the step must not create an empty new group
 *     (the register import provisions it), preserving the admin-provisioned,
 *     fail-closed posture on fresh installs;
 *   - members are copied, never moved: the old group loses nobody;
 *   - a re-run copies nothing (members already present are skipped);
 *   - one failing membership write does not abort the rest, and nothing this
 *     step does may throw — it is registered under <install>, where an
 *     escaping exception stops the app enabling at all.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\MigrateAdminGroup;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateAdminGroup.
 *
 * @covers \OCA\Decidiq\Repair\MigrateAdminGroup
 */
final class MigrateAdminGroupGoldenTest extends TestCase {

	/**
	 * The administrators group id under the old app id.
	 *

	/**
	 * An old group that exists but cannot be fetched means no work.
	 *
	 * @return void
	 */
	public function testAnUnfetchableOldGroupMeansNoWork(): void {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);
		$groupManager->method('get')->willReturn(null);
		$groupManager->expects($this->never())->method('createGroup');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->never())->method('info');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		(new MigrateAdminGroup(groupManager: $groupManager, logger: $logger))->run(output: $output);
	}//end testAnUnfetchableOldGroupMeansNoWork()

	/**
	 * A group backend that throws is logged as an error and never escapes.
	 *
	 * @return void
	 */
	public function testAThrowingBackendIsLoggedAndSwallowed(): void {
		$error = new RuntimeException('backend down');
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willThrowException($error);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->never())->method('info');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Decidiq: administrators-group migration failed; members keep access through the old-id group',
			['exception' => $error]
		);

		(new MigrateAdminGroup(groupManager: $groupManager, logger: $logger))->run(output: $output);
	}//end testAThrowingBackendIsLoggedAndSwallowed()

	/**
	 * The new group is created only when it is missing, then members are
	 * copied and the count reported; an existing member is skipped.
	 *
	 * @return void
	 */
	public function testTheReportNamesBothGroupsAndTheCount(): void {
		// The step swallows every Throwable, PHPUnit's own expectation
		// failures included, so the calls are recorded and asserted after it.
		$added = [];
		$reported = [];
		$old = $this->createMock(originalClassName: IGroup::class);
		$new = $this->createMock(originalClassName: IGroup::class);
		$alice = $this->createMock(originalClassName: IUser::class);
		$bob = $this->createMock(originalClassName: IUser::class);
		$old->method('getUsers')->willReturn([$alice, $bob]);
		$new->method('inGroup')->willReturnCallback(static fn (IUser $user): bool => $user === $alice);
		$new->method('addUser')->willReturnCallback(
			static function (IUser $user) use (&$added): void {
				$added[] = $user;
			}
		);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturn(true);
		$groupManager->method('get')->willReturnCallback(static fn (string $gid): IGroup => $gid === 'decidesk-administrators' ? $old : $new);
		$groupManager->expects($this->never())->method('createGroup');

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$reported): void {
				$reported[] = $message;
			}
		);

		(new MigrateAdminGroup(groupManager: $groupManager, logger: $this->createMock(originalClassName: LoggerInterface::class)))->run(output: $output);

		self::assertSame([$bob], $added);
		self::assertSame(['Decidiq administrators group: copied 1 member(s) from decidesk-administrators to decidiq-administrators'], $reported);
	}//end testTheReportNamesBothGroupsAndTheCount()
}//end class
