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

		$file = $this->createMock(File::class);
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
				$share->method('setSharedBy')->with('griffier')->willReturnSelf();
				$share->method('setPermissions')->willReturnCallback(
					static function (int $permissions) use (&$recorded, $share): IShare {
						$recorded[array_key_last($recorded)] = $permissions;
						return $share;
					}
				);
				return $share;
			}
		);

		$failures = (new BoardBookDelivery(shares: $shares, users: $users, logger: $this->createMock(LoggerInterface::class)))
			->deliver(file: $file, participants: $participants, sharedBy: 'griffier');

		$this->assertSame(expected: ['j.jansen' => Constants::PERMISSION_READ, 'a.bos' => Constants::PERMISSION_READ], actual: $recorded);
		$this->assertCount(expectedCount: 2, haystack: $created);
		$this->assertSame(expected: ['Piet de Vries (no Nextcloud account)', 'Kees (share failed)'], actual: $failures);
	}//end testEveryReachableAttendeeGetsAReadOnlyShare()
}//end class
