<?php

/**
 * Who may publish from a source or change a publication record: an admin, or the meeting's chair or secretary.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\PublicationStaffGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\PublicationStaffGuard
 */
class PublicationStaffGuardTest extends TestCase {

	/**
	 * Every find() the store received: "register:schema/id".
	 *
	 * @var list<string>
	 */
	private array $finds = [];

	/**
	 * Every hasRole() asked: "meeting uid roles".
	 *
	 * @var list<string>
	 */
	private array $roleChecks = [];

	/**
	 * A stored object.
	 *
	 * @param array $data The object's data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function stored(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);

		return $entity;
	}//end stored()

	/**
	 * The guard for user 'piet'.
	 *
	 * @param bool                       $admin   Whether piet is an admin.
	 * @param array<string, ObjectEntity> $objects Stored objects by "register:schema/id".
	 * @param list<string>               $staffOf Meetings piet chairs or minutes.
	 *
	 * @return PublicationStaffGuard The guard.
	 */
	private function guard(bool $admin, array $objects=[], array $staffOf=[]): PublicationStaffGuard {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($objects): ?ObjectEntity {
				$key = $register . ':' . $schema . '/' . $id;
				$this->finds[] = $key;
				return ($objects[$key] ?? null);
			}
		);
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('hasRole')->willReturnCallback(
			function (string $meetingId, string $nextcloudUid, array $roles) use ($staffOf): bool {
				$this->roleChecks[] = $meetingId . ' ' . $nextcloudUid . ' ' . implode(',', $roles);
				return in_array($meetingId, $staffOf, true);
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('piet');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => ($uid === 'piet' && $admin));

		return new PublicationStaffGuard($store, $resolver, $session, $groups);
	}//end guard()

	/**
	 * An admin is allowed without any lookup.
	 *
	 * @return void
	 */
	public function testAnAdminIsAllowedWithoutALookup(): void {
		$guard = $this->guard(admin: true);

		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkSource('decision', 'd-1'));
		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkRecord('rec-1'));
		self::assertSame([], $this->finds);
		self::assertSame([], $this->roleChecks);
	}//end testAnAdminIsAllowedWithoutALookup()

	/**
	 * An agenda or activity source is its own meeting; the chair or secretary of it is allowed.
	 *
	 * @return void
	 */
	public function testAnAgendaSourceIsCheckedAgainstItsOwnMeeting(): void {
		$guard = $this->guard(admin: false, staffOf: ['m-1']);

		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkSource('agenda', 'm-1'));
		self::assertSame(PublicationStaffGuard::FORBIDDEN, $guard->checkSource('activity', 'm-2'));
		self::assertSame([], $this->finds);
		self::assertSame(['m-1 piet chair,secretary', 'm-2 piet chair,secretary'], $this->roleChecks);
	}//end testAnAgendaSourceIsCheckedAgainstItsOwnMeeting()

	/**
	 * Minutes and decisions are looked up in their own schema and checked against their meeting.
	 *
	 * @return void
	 */
	public function testMinutesAndDecisionsResolveTheirMeeting(): void {
		$guard = $this->guard(
			admin: false,
			objects: [
				'decidiq:minutes/min-1' => $this->stored(['relations' => ['meeting' => 'm-1']]),
				'decidiq:decision/d-1' => $this->stored(['relations' => ['Meeting' => ['id' => 'm-1']]]),
				'decidiq:decision/d-2' => $this->stored(['meeting' => ['m-1']]),
				'decidiq:decision/d-3' => $this->stored(['meeting' => '']),
			],
			staffOf: ['m-1']
		);

		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkSource('minutes', 'min-1'));
		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkSource('decision', 'd-1'));
		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkSource('other', 'd-2'));
		self::assertSame(PublicationStaffGuard::FORBIDDEN, $guard->checkSource('decision', 'd-3'));
		self::assertSame(PublicationStaffGuard::FORBIDDEN, $guard->checkSource('decision', 'gone'));
		self::assertSame(['decidiq:minutes/min-1', 'decidiq:decision/d-1', 'decidiq:decision/d-2', 'decidiq:decision/d-3', 'decidiq:decision/gone'], $this->finds);
		self::assertCount(3, $this->roleChecks, 'no meeting means no role is asked');
	}//end testMinutesAndDecisionsResolveTheirMeeting()

	/**
	 * A record is checked through its source; a missing record says so.
	 *
	 * @return void
	 */
	public function testARecordIsCheckedThroughItsSource(): void {
		$guard = $this->guard(
			admin: false,
			objects: [
				'decidiq:publication-record/rec-1' => $this->stored(['sourceType' => 'agenda', 'sourceObject' => 'm-1']),
				'decidiq:publication-record/rec-2' => $this->stored(['sourceType' => 'agenda', 'sourceObject' => 'm-9']),
			],
			staffOf: ['m-1']
		);

		self::assertSame(PublicationStaffGuard::ALLOWED, $guard->checkRecord('rec-1'));
		self::assertSame(PublicationStaffGuard::FORBIDDEN, $guard->checkRecord('rec-2'));
		self::assertSame(PublicationStaffGuard::RECORD_NOT_FOUND, $guard->checkRecord('rec-3'));
	}//end testARecordIsCheckedThroughItsSource()

	/**
	 * The current uid is the session user's, or null when nobody is signed in.
	 *
	 * @return void
	 */
	public function testCurrentUidIsTheSessionUser(): void {
		self::assertSame('piet', $this->guard(admin: false)->currentUid());

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$guard = new PublicationStaffGuard(
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(ParticipantResolver::class),
			$session,
			$this->createMock(IGroupManager::class)
		);
		self::assertNull($guard->currentUid());
	}//end testCurrentUidIsTheSessionUser()
}//end class
