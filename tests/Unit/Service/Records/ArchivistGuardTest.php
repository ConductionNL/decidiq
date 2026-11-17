<?php

/**
 * Unit tests for ArchivistGuard: only an archivist or an administrator passes.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Records;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Service\Records\ArchivistGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArchivistGuard.
 *
 * @covers \OCA\Decidiq\Service\Records\ArchivistGuard
 * @uses \OCA\Decidiq\Exception\AccessDeniedException
 */
class ArchivistGuardTest extends TestCase {

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * A guard over a session holding this uid, or nobody.
	 *
	 * @param string|null $uid The signed-in uid, or null.
	 *
	 * @return ArchivistGuard The guard under test.
	 */
	private function guard(?string $uid): ArchivistGuard {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->groups = $this->createMock(IGroupManager::class);

		return new ArchivistGuard(userSession: $session, groupManager: $this->groups);
	}//end guard()

	/**
	 * Nobody signed in is refused before any group is asked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsRefused(): void {
		$guard = $this->guard(uid: null);
		$this->groups->expects(self::never())->method('isAdmin');
		$this->groups->expects(self::never())->method('isInGroup');

		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('Not signed in.');
		$guard->requireArchivist();
	}//end testNobodySignedInIsRefused()

	/**
	 * An administrator passes without the group being asked.
	 *
	 * @return void
	 */
	public function testAnAdministratorPasses(): void {
		$guard = $this->guard(uid: 'admin');
		$this->groups->expects(self::once())->method('isAdmin')->with('admin')->willReturn(true);
		$this->groups->expects(self::never())->method('isInGroup');

		$guard->requireArchivist();
		self::assertTrue(true, 'An administrator passes without an exception.');
	}//end testAnAdministratorPasses()

	/**
	 * A member of the archivist group passes.
	 *
	 * @return void
	 */
	public function testAnArchivistPasses(): void {
		$guard = $this->guard(uid: 'anna');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->expects(self::once())->method('isInGroup')->with('anna', ArchivistGuard::GROUP)->willReturn(true);

		$guard->requireArchivist();
		self::assertSame('archivaris', ArchivistGuard::GROUP);
	}//end testAnArchivistPasses()

	/**
	 * Anyone else is refused, saying who may.
	 *
	 * @return void
	 */
	public function testAnyoneElseIsRefused(): void {
		$guard = $this->guard(uid: 'bob');
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);

		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('Only an archivist or an administrator sends a dossier to the archive or to destruction.');
		$guard->requireArchivist();
	}//end testAnyoneElseIsRefused()
}//end class
