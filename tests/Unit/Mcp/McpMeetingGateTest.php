<?php

/**
 * Unit tests for McpMeetingGate.
 *
 * The gate decides whether an MCP caller may act on a meeting, so every
 * allowed and refused outcome, and each refusal message, is pinned.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Mcp
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

namespace OCA\Decidiq\Tests\Unit\Mcp;

use OCA\Decidiq\Mcp\McpArgumentValidator;
use OCA\Decidiq\Mcp\McpMeetingGate;
use OCA\Decidiq\Mcp\McpSourceFormatter;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for McpMeetingGate.
 *
 * @covers \OCA\Decidiq\Mcp\McpMeetingGate
 * @uses \OCA\Decidiq\Mcp\McpArgumentValidator
 * @uses \OCA\Decidiq\Mcp\McpMeetingScopeResolver
 * @uses \OCA\Decidiq\Mcp\McpSourceFormatter
 */
class McpMeetingGateTest extends TestCase {

	/**
	 * A syntactically valid meeting UUID.
	 */
	private const UUID = '0b3c1f9e-6d2a-4c11-9f0e-2a7b8c9d0e1f';

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The mocked group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The mocked participant resolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver $participantResolver;

	/**
	 * The mocked object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The gate under test.
	 *
	 * @var McpMeetingGate
	 */
	private McpMeetingGate $gate;

	/**
	 * Set up the gate with mocked boundaries and the real formatter and validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$formatter = new McpSourceFormatter();
		$this->gate = new McpMeetingGate(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
			participantResolver: $this->participantResolver,
			formatter: $formatter,
			validator: new McpArgumentValidator(formatter: $formatter),
			objectService: $this->objectService,
		);
	}

	/**
	 * Sign the given user in, or sign everyone out for null.
	 *
	 * @param string|null $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(?string $uid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Make the object service return a meeting entity carrying the data.
	 *
	 * @param array<string, mixed> $data The meeting data.
	 *
	 * @return void
	 */
	private function meetingIs(array $data): void {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['jsonSerialize'])->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		$this->objectService->method('find')->willReturn($entity);
	}

	/**
	 * The current user id is the session user's UID, or empty when signed out.
	 *
	 * @return void
	 */
	public function testCurrentUserIdAndSignedOutError(): void {
		$this->signIn(uid: null);

		$this->assertSame('', $this->gate->currentUserId());
		$this->assertSame(
			['isError' => true, 'error' => 'forbidden', 'message' => 'Sign in to use this tool.'],
			$this->gate->signedOutError()
		);
	}

	/**
	 * A signed-in caller gets no signed-out error.
	 *
	 * @return void
	 */
	public function testASignedInCallerHasNoSignedOutError(): void {
		$this->signIn(uid: 'carla');

		$this->assertSame('carla', $this->gate->currentUserId());
		$this->assertNull($this->gate->signedOutError());
	}

	/**
	 * The meeting is looked up in the decidiq meeting schema and serialised;
	 * an absent one is null.
	 *
	 * @return void
	 */
	public function testLoadMeetingReadsTheMeetingSchema(): void {
		$this->objectService->expects($this->exactly(2))->method('find')
			->with(self::UUID, $this->anything(), $this->anything(), 'decidiq', 'meeting')
			->willReturnOnConsecutiveCalls(null, $this->entityWith(data: ['title' => 'Raad']));

		$this->assertNull($this->gate->loadMeeting(meetingUuid: self::UUID));
		$this->assertSame(['title' => 'Raad'], $this->gate->loadMeeting(meetingUuid: self::UUID));
	}

	/**
	 * An entity double serialising to the given data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entityWith(array $data): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->onlyMethods(['jsonSerialize'])->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}

	/**
	 * An invalid meeting UUID is refused before the store is read.
	 *
	 * @return void
	 */
	public function testAnInvalidUuidIsRefusedWithoutALookup(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->assertSame(
			['error' => ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Required argument meetingUuid is missing.']],
			$this->gate->authorise(meetingUuid: null, requirement: 'participant')
		);
		$this->assertSame('invalid_arguments', $this->gate->authorise(meetingUuid: 'nope', requirement: 'chair')['error']['error']);
	}

	/**
	 * A lookup failure is logged and answered with an internal error.
	 *
	 * @return void
	 */
	public function testALookupFailureIsAnInternalError(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Decidiq MCP: meeting lookup failed',
			['meetingUuid' => self::UUID, 'exception' => 'register down']
		);

		$this->assertSame(
			['error' => ['isError' => true, 'error' => 'internal_error', 'message' => 'Failed to load the meeting. See server log for details.']],
			$this->gate->authorise(meetingUuid: self::UUID, requirement: 'participant')
		);
	}

	/**
	 * An unknown meeting is "not found" for an admin only; anyone else gets
	 * the same refusal as for a meeting they may not see.
	 *
	 * @return void
	 */
	public function testAnUnknownMeetingIsNotFoundOnlyForAnAdmin(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('Object not found'));
		$this->groupManager->method('isAdmin')->willReturnMap([['admin', true], ['bob', false]]);

		$this->signIn(uid: 'admin');
		$this->assertSame(
			['error' => ['isError' => true, 'error' => 'not_found', 'message' => 'Meeting not found.']],
			$this->gate->authorise(meetingUuid: self::UUID, requirement: 'participant')
		);
	}

	/**
	 * For a non-admin an unknown meeting reads as a refusal.
	 *
	 * @return void
	 */
	public function testAnUnknownMeetingIsARefusalForOthers(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->signIn(uid: 'bob');

		$this->assertSame(
			['error' => ['isError' => true, 'error' => 'forbidden', 'message' => 'Only the chair or an admin can start this meeting.']],
			$this->gate->authorise(meetingUuid: self::UUID, requirement: 'chair')
		);
	}

	/**
	 * A signed-out caller is refused an unknown meeting without an admin check.
	 *
	 * @return void
	 */
	public function testASignedOutCallerIsRefusedAnUnknownMeeting(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->signIn(uid: null);

		$this->assertSame(
			'You are not a participant of this meeting.',
			$this->gate->authorise(meetingUuid: self::UUID, requirement: 'participant')['error']['message']
		);
	}

	/**
	 * The chair is granted a chair action and gets the meeting back.
	 *
	 * @return void
	 */
	public function testTheChairIsGrantedAChairAction(): void {
		$this->meetingIs(data: ['title' => 'Raad', 'chair' => 'carla']);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->signIn(uid: 'carla');

		$this->assertSame(
			['meeting' => ['title' => 'Raad', 'chair' => 'carla'], 'userId' => 'carla', 'uuid' => self::UUID],
			$this->gate->authorise(meetingUuid: self::UUID, requirement: 'chair')
		);
	}

	/**
	 * A participant who is not the chair is refused a chair action, and the
	 * participant list is not consulted for it.
	 *
	 * @return void
	 */
	public function testAParticipantIsRefusedAChairAction(): void {
		$this->meetingIs(data: ['chair' => 'carla']);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->expects($this->never())->method('isParticipant');
		$this->signIn(uid: 'bob');

		$this->assertSame(
			['error' => ['isError' => true, 'error' => 'forbidden', 'message' => 'Only the chair or an admin can start this meeting.']],
			$this->gate->authorise(meetingUuid: self::UUID, requirement: 'chair')
		);
	}

	/**
	 * A participant is granted a participant action; a non-participant is refused.
	 *
	 * @return void
	 */
	public function testParticipantActionsFollowTheParticipantList(): void {
		$this->meetingIs(data: ['chair' => 'carla']);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('isParticipant')->willReturnMap(
			[[self::UUID, 'bob', true], [self::UUID, 'eve', false]]
		);

		$this->assertTrue($this->gate->isParticipantOrAdmin(meetingUuid: self::UUID, userId: 'bob'));
		$this->assertFalse($this->gate->isParticipantOrAdmin(meetingUuid: self::UUID, userId: 'eve'));

		$this->signIn(uid: 'eve');
		$this->assertSame(
			['error' => ['isError' => true, 'error' => 'forbidden', 'message' => 'You are not a participant of this meeting.']],
			$this->gate->authorise(meetingUuid: self::UUID, requirement: 'participant')
		);
	}

	/**
	 * An admin passes both checks without the participant list being read.
	 *
	 * @return void
	 */
	public function testAnAdminPassesBothChecks(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
		$this->participantResolver->expects($this->never())->method('isParticipant');

		$this->assertTrue($this->gate->isAdmin(userId: 'admin'));
		$this->assertTrue($this->gate->isChairOrAdmin(meeting: ['chair' => 'carla'], userId: 'admin'));
		$this->assertTrue($this->gate->isParticipantOrAdmin(meetingUuid: self::UUID, userId: 'admin'));
	}

	/**
	 * A blank user id passes neither check, and nothing is asked.
	 *
	 * @return void
	 */
	public function testABlankUserPassesNeitherCheck(): void {
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->participantResolver->expects($this->never())->method('isParticipant');

		$this->assertFalse($this->gate->isChairOrAdmin(meeting: ['chair' => ''], userId: ''));
		$this->assertFalse($this->gate->isParticipantOrAdmin(meetingUuid: self::UUID, userId: ''));
	}

	/**
	 * A meeting without a chair grants no non-admin the chair check; a numeric
	 * chair id matches its string form.
	 *
	 * @return void
	 */
	public function testTheChairCheckComparesAsStrings(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->gate->isChairOrAdmin(meeting: [], userId: 'carla'));
		$this->assertTrue($this->gate->isChairOrAdmin(meeting: ['chair' => 7], userId: '7'));
	}

	/**
	 * An admin's meeting scope is unrestricted.
	 *
	 * @return void
	 */
	public function testCallerMeetingUuidsDelegatesToTheScopeResolver(): void {
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertNull($this->gate->callerMeetingUuids(userId: 'admin'));
	}
}
