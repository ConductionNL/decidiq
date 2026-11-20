<?php

/**
 * Unit tests for PathwayStaffGuard: who may run the pathway steps of a body.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Pathway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Pathway;

use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Decidiq\Service\Pathway\PathwayStaffGuard;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PathwayStaffGuard.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
#[CoversClass(PathwayStaffGuard::class)]
#[UsesClass(PathwaySteps::class)]
class PathwayStaffGuardTest extends TestCase {

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The register's objects.
	 *
	 * @var RegisterObjectStore&MockObject
	 */
	private RegisterObjectStore&MockObject $store;

	/**
	 * The guard under test.
	 *
	 * @var PathwayStaffGuard
	 */
	private PathwayStaffGuard $guard;

	/**
	 * Build the guard; the secretariat role has one mapped extra group.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->store        = $this->createMock(RegisterObjectStore::class);
		$settings           = $this->createMock(SettingsServiceInterface::class);
		$settings->method('roleGroupMapping')->willReturn(['secretariat' => ['griffie']]);
		$this->guard = new PathwayStaffGuard(
			groupManager: $this->groupManager,
			settings: $settings,
			store: $this->store,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * An administrator may, without a roster read.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayManage(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
		$this->store->expects($this->never())->method('findAll');

		$this->assertTrue($this->guard->mayManage(userId: 'admin', bodyId: 'body-1'));
	}//end testAnAdministratorMayManage()

	/**
	 * A member of the secretariat's own group, or of a group mapped to it, may.
	 *
	 * @return void
	 */
	public function testTheSecretariatGroupsMayManage(): void {
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => ($uid === 'anna' && $gid === 'decidiq-secretariat')
				|| ($uid === 'bram' && $gid === 'griffie')
		);

		$this->assertTrue($this->guard->mayManage(userId: 'anna', bodyId: 'body-1'));
		$this->assertTrue($this->guard->mayManage(userId: 'bram', bodyId: 'body-1'));
	}//end testTheSecretariatGroupsMayManage()

	/**
	 * The secretary on the body's roster may; a secretary of another body
	 * or a plain member may not.
	 *
	 * @return void
	 */
	public function testOnlyTheSecretaryOfThatBodyMayManageFromTheRoster(): void {
		$this->store->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters): array {
				return [
					['nextcloudUserId' => 'cleo', 'role' => 'secretary', 'governanceBody' => ['id' => 'body-1']],
					['nextcloudUserId' => 'dirk', 'role' => 'secretary', 'governanceBody' => 'body-2'],
					['nextcloudUserId' => 'eva', 'role' => 'member', 'governanceBody' => 'body-1'],
				];
			}
		);

		$this->assertTrue($this->guard->mayManage(userId: 'cleo', bodyId: 'body-1'));
		$this->assertFalse($this->guard->mayManage(userId: 'dirk', bodyId: 'body-1'));
		$this->assertFalse($this->guard->mayManage(userId: 'eva', bodyId: 'body-1'));
	}//end testOnlyTheSecretaryOfThatBodyMayManageFromTheRoster()

	/**
	 * The roster read is narrowed to the body, the role and the user.
	 *
	 * @return void
	 */
	public function testTheRosterReadIsNarrowedToBodyRoleAndUser(): void {
		$this->store->expects($this->once())->method('findAll')
			->with('participant', ['governanceBody' => 'body-1', 'role' => 'secretary', 'nextcloudUserId' => 'eva'])
			->willReturn([]);

		$this->assertFalse($this->guard->mayManage(userId: 'eva', bodyId: 'body-1'));
	}//end testTheRosterReadIsNarrowedToBodyRoleAndUser()

	/**
	 * A roster that cannot be read refuses.
	 *
	 * @return void
	 */
	public function testAnUnreadableRosterRefuses(): void {
		$this->store->method('findAll')->willThrowException(new \RuntimeException('down'));

		$this->assertFalse($this->guard->mayManage(userId: 'cleo', bodyId: 'body-1'));
	}//end testAnUnreadableRosterRefuses()

	/**
	 * Nobody but an administrator may act on a record without a body, and
	 * an empty user never may.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyOrUserRefuses(): void {
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->guard->mayManage(userId: 'anna', bodyId: ''));
		$this->assertFalse($this->guard->mayManage(userId: '', bodyId: 'body-1'));
	}//end testAnEmptyBodyOrUserRefuses()

}//end class
