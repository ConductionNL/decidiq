<?php

/**
 * Unit tests for TranscriptionStaffGuard.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who may manage transcripts and play recordings.
 *
 * @covers \OCA\Decidiq\Service\TranscriptionStaffGuard
 */
class TranscriptionStaffGuardTest extends TestCase {

	/**
	 * Mock object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock participant resolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participants;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->participants = $this->createMock(ParticipantResolver::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

	}//end setUp()

	/**
	 * Build the guard under test.
	 *
	 * @return TranscriptionStaffGuard
	 */
	private function guard(): TranscriptionStaffGuard {
		return new TranscriptionStaffGuard(
			objectService: $this->objectService,
			participantResolver: $this->participants,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
		);

	}//end guard()

	/**
	 * Log a user in.
	 *
	 * @param string $uid The user id
	 * @param bool $admin Whether the user is an admin
	 *
	 * @return void
	 */
	private function login(string $uid, bool $admin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);

	}//end login()

	/**
	 * Wrap a payload in an ObjectEntity double.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * Assert a refusal with the given status and message.
	 *
	 * @param int $status Expected HTTP status
	 * @param string $message Expected message
	 * @param JSONResponse|null $response The guard's answer
	 *
	 * @return void
	 */
	private function assertRefused(int $status, string $message, ?JSONResponse $response): void {
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['message' => $message], $response->getData());

	}//end assertRefused()

	/**
	 * The current user id is the session user's uid, or empty when anonymous.
	 *
	 * @return void
	 */
	public function testCurrentUserId(): void {
		$this->assertSame('', $this->guard()->currentUserId());

		$this->login('alice');
		$this->assertSame('alice', $this->guard()->currentUserId());

	}//end testCurrentUserId()

	/**
	 * Anonymous callers are refused with 401 before anything is resolved.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthenticated(): void {
		$this->participants->expects($this->never())->method('hasRole');

		$this->assertRefused(401, 'Unauthenticated.', $this->guard()->forMeeting('m-1'));

	}//end testAnonymousIsUnauthenticated()

	/**
	 * Admins pass without any meeting lookup.
	 *
	 * @return void
	 */
	public function testAdminPasses(): void {
		$this->login('root', true);
		$this->objectService->expects($this->never())->method('find');
		$this->participants->expects($this->never())->method('hasRole');

		$this->assertNull($this->guard()->forTranscript('t-1'));

	}//end testAdminPasses()

	/**
	 * A chair or secretary of the meeting passes; anyone else gets the role message.
	 *
	 * @return void
	 */
	public function testForMeetingRequiresStaffRole(): void {
		$this->login('alice');
		$this->participants->method('hasRole')
			->willReturnCallback(
				static fn (string $meetingId, string $nextcloudUid, array $roles): bool => $roles === ['chair', 'secretary'] && $meetingId === 'm-1'
			);

		$guard = $this->guard();
		$this->assertNull($guard->forMeeting('m-1'));
		$this->assertRefused(403, 'Forbidden: chair or secretary role required.', $guard->forMeeting('m-2'));

	}//end testForMeetingRequiresStaffRole()

	/**
	 * An empty meeting id cannot be resolved and is refused generically.
	 *
	 * @return void
	 */
	public function testForMeetingEmptyIdIsUnresolved(): void {
		$this->login('alice');
		$this->participants->expects($this->never())->method('hasRole');

		$this->assertRefused(403, 'Forbidden.', $this->guard()->forMeeting(''));

	}//end testForMeetingEmptyIdIsUnresolved()

	/**
	 * A transcript authorises against its meeting, from relations or an array reference.
	 *
	 * @return void
	 */
	public function testForTranscriptResolvesMeeting(): void {
		$this->login('alice');
		$this->objectService->method('find')->willReturnCallback(
			fn (string|int $id, ?array $extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?ObjectEntity => match ([$id, $register, $schema]) {
				['t-1', 'decidiq', 'transcript'] => $this->entity(['relations' => ['meeting' => 'm-1']]),
				['t-2', 'decidiq', 'transcript'] => $this->entity(['meeting' => ['id' => 'm-2']]),
				['t-3', 'decidiq', 'transcript'] => $this->entity(['meeting' => ['m-3']]),
				default => null,
			}
		);
		$seen = [];
		$this->participants->method('hasRole')->willReturnCallback(
			static function (string $meetingId) use (&$seen): bool {
				$seen[] = $meetingId;
				return $meetingId !== 'm-3';
			}
		);

		$guard = $this->guard();
		$this->assertNull($guard->forTranscript('t-1'));
		$this->assertNull($guard->forTranscript('t-2'));
		$this->assertRefused(403, 'Forbidden: chair or secretary role required.', $guard->forTranscript('t-3'));
		$this->assertSame(['m-1', 'm-2', 'm-3'], $seen);

	}//end testForTranscriptResolvesMeeting()

	/**
	 * An unknown transcript, or one without a meeting, is refused generically.
	 *
	 * @return void
	 */
	public function testForTranscriptUnresolved(): void {
		$this->login('alice');
		$this->objectService->method('find')->willReturnCallback(
			fn (string|int $id): ?ObjectEntity => ($id === 't-empty') ? $this->entity(['meeting' => '']) : null
		);
		$this->participants->expects($this->never())->method('hasRole');

		$guard = $this->guard();
		$this->assertRefused(403, 'Forbidden.', $guard->forTranscript('t-missing'));
		$this->assertRefused(403, 'Forbidden.', $guard->forTranscript('t-empty'));

	}//end testForTranscriptUnresolved()

	/**
	 * Any participant of the meeting may listen; staff pass without the participant check.
	 *
	 * @return void
	 */
	public function testForTranscriptListenerAdmitsParticipants(): void {
		$this->login('alice');
		$this->objectService->method('find')->willReturn($this->entity(['meeting' => 'm-1']));
		$this->participants->method('hasRole')->willReturn(false);
		$this->participants->expects($this->once())
			->method('isParticipant')
			->with('m-1', 'alice')
			->willReturn(true);

		$this->assertNull($this->guard()->forTranscriptListener('t-1'));

	}//end testForTranscriptListenerAdmitsParticipants()

	/**
	 * Staff are admitted as listeners without the participant lookup.
	 *
	 * @return void
	 */
	public function testForTranscriptListenerAdmitsStaff(): void {
		$this->login('alice');
		$this->objectService->method('find')->willReturn($this->entity(['meeting' => 'm-1']));
		$this->participants->method('hasRole')->willReturn(true);
		$this->participants->expects($this->never())->method('isParticipant');

		$this->assertNull($this->guard()->forTranscriptListener('t-1'));

	}//end testForTranscriptListenerAdmitsStaff()

	/**
	 * Outsiders are refused playback with the listener message.
	 *
	 * @return void
	 */
	public function testForTranscriptListenerRefusesOutsiders(): void {
		$this->login('mallory');
		$this->objectService->method('find')->willReturn($this->entity(['meeting' => 'm-1']));
		$this->participants->method('hasRole')->willReturn(false);
		$this->participants->method('isParticipant')->willReturn(false);

		$this->assertRefused(
			403,
			'Forbidden: only participants of this meeting can play its recording.',
			$this->guard()->forTranscriptListener('t-1')
		);

	}//end testForTranscriptListenerRefusesOutsiders()

	/**
	 * Body access passes when the user is staff in any of the body's meetings.
	 *
	 * @return void
	 */
	public function testForBodyChecksEveryMeeting(): void {
		$this->login('alice');
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				[
					'register' => 'decidiq',
					'schema' => 'meeting',
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'meeting',
						'_relations.GovernanceBody' => 'body-1',
					],
				]
			)
			->willReturn(
				[
					['id' => 'm-1'],
					$this->entity(['@self' => ['id' => 'm-2']]),
					['title' => 'no id'],
					'not-an-object',
				]
			);
		$seen = [];
		$this->participants->method('hasRole')->willReturnCallback(
			static function (string $meetingId) use (&$seen): bool {
				$seen[] = $meetingId;
				return $meetingId === 'm-2';
			}
		);

		$this->assertNull($this->guard()->forBody('body-1'));
		$this->assertSame(['m-1', 'm-2'], $seen);

	}//end testForBodyChecksEveryMeeting()

	/**
	 * A body with no reachable meetings is refused with the body message; an empty id generically.
	 *
	 * @return void
	 */
	public function testForBodyRefusals(): void {
		$this->login('alice');
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('store down'));
		$this->participants->expects($this->never())->method('hasRole');

		$guard = $this->guard();
		$this->assertRefused(403, 'Forbidden: chair or secretary role required for this body.', $guard->forBody('body-1'));
		$this->assertRefused(403, 'Forbidden.', $guard->forBody(''));

	}//end testForBodyRefusals()
}//end class
