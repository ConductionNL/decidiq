<?php

/**
 * Unit tests for BoardBookDelivery.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\BoardBook;

use OCA\Decidiq\Service\BoardBook\BoardBookDelivery;
use OCP\Constants;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Read-only shares for every active attendee; one failure stops no one else.
 */
#[CoversClass(BoardBookDelivery::class)]
class BoardBookDeliveryTest extends TestCase {

	/**
	 * Five attendees: one without an account, one who left, one whose share fails.
	 *
	 * @return void
	 */
	public function testEveryReachableAttendeeGetsAReadOnlyShare(): void {
		$participants = [
			['displayName' => 'Jan Jansen', 'nextcloudUserId' => 'j.jansen'],
			['displayName' => 'Piet de Vries', 'nextcloudUserId' => ''],
			['displayName' => 'Oud lid', 'nextcloudUserId' => 'oud', 'leftAt' => '2024-12-01'],
			['displayName' => 'Anna Bos', 'nextcloudUserId' => 'a.bos'],
			['displayName' => 'Kees', 'nextcloudUserId' => 'kees'],
			['displayName' => 'Griffier', 'nextcloudUserId' => 'griffier'],
		];
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid !== '');

		$created = [];
		$shares = $this->createMock(IManager::class);
		$shares->method('createShare')->willReturnCallback(
			static function (IShare $share) use (&$created): IShare {
				$created[] = $share;
				return $share;
			}
		);

		// The pack lives in OpenRegister's account, so that account shares it.
		$holder = $this->createMock(IUser::class);
		$holder->method('getUID')->willReturn('openregister');
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($holder);
		$accepted = [];
		$shares->method('acceptShare')->willReturnCallback(
			static function (IShare $share, string $uid) use (&$accepted): IShare {
				$accepted[] = $uid;
				return $share;
			}
		);
		$recorded = [];
		$shares->method('newShare')->willReturnCallback(
			function () use (&$recorded, $file): IShare {
				$share = $this->createMock(IShare::class);
				$share->method('setSharedWith')->willReturnCallback(
					static function (string $uid) use (&$recorded, $share): IShare {
						if ($uid === 'kees') {
							throw new RuntimeException('boom');
						}

						$recorded[$uid] = [];
						return $share;
					}
				);
				$share->expects($this->once())->method('setNode')->with($file)->willReturnSelf();
				$share->expects($this->once())->method('setShareType')->with(IShare::TYPE_USER)->willReturnSelf();
				$share->method('setSharedBy')->with('openregister')->willReturnSelf();
				$share->method('setPermissions')->willReturnCallback(
					static function (int $permissions) use (&$recorded, $share): IShare {
						$recorded[array_key_last($recorded)] = $permissions;
						return $share;
					}
				);
				return $share;
			}
		);

		$failures = (new BoardBookDelivery(shares: $shares, users: $users, logger: $this->createMock(LoggerInterface::class), config: $this->config(askUsers: false)))
			->deliver(file: $file, participants: $participants, sharedBy: 'griffier');

		$this->assertSame(expected: ['j.jansen' => Constants::PERMISSION_READ, 'a.bos' => Constants::PERMISSION_READ], actual: $recorded);
		$this->assertCount(expectedCount: 2, haystack: $created);
		// Accepted on the attendee's behalf: the job runs where Nextcloud's own acceptance does not.
		$this->assertSame(expected: ['j.jansen', 'a.bos'], actual: $accepted);
		$this->assertSame(expected: ['Piet de Vries (no Nextcloud account)', 'Kees (share failed)'], actual: $failures);
	}//end testEveryReachableAttendeeGetsAReadOnlyShare()

	/**
	 * An instance that asks users to accept shares keeps the share pending;
	 * a file without an owner is shared by the one who compiled it.
	 *
	 * @return void
	 */
	public function testSharesStayPendingWhereUsersAcceptThemselves(): void {
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$share = $this->createMock(originalClassName: IShare::class);
		$share->method('setNode')->willReturnSelf();
		$share->method('setShareType')->willReturnSelf();
		$share->method('setSharedWith')->willReturnSelf();
		$share->method('setPermissions')->willReturnSelf();
		$share->expects($this->once())->method('setSharedBy')->with('griffier')->willReturnSelf();
		$shares = $this->createMock(originalClassName: IManager::class);
		$shares->method('newShare')->willReturn($share);
		$shares->method('createShare')->willReturn($share);
		$shares->expects($this->never())->method('acceptShare');
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getOwner')->willReturn(null);

		$failures = (new BoardBookDelivery(shares: $shares, users: $users, logger: $this->createMock(originalClassName: LoggerInterface::class), config: $this->config(askUsers: true)))
			->deliver(file: $file, participants: [['displayName' => 'Jan', 'nextcloudUserId' => 'j.jansen']], sharedBy: 'griffier');

		$this->assertSame(expected: [], actual: $failures);
	}//end testSharesStayPendingWhereUsersAcceptThemselves()

	/**
	 * The system config, with or without users accepting shares themselves.
	 *
	 * @param bool $askUsers The value of sharing.enable_share_accept.
	 *
	 * @return IConfig
	 */
	private function config(bool $askUsers): IConfig {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValueBool')->willReturnMap([['sharing.enable_share_accept', false, $askUsers]]);
		return $config;
	}//end config()
}//end class
