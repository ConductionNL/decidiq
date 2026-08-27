<?php

/**
 * Unit tests for EngagementController authorisation, both directions (OWASP A01).
 *
 * One rule covers write and read alike: admins AND the meeting's chair/secretary
 * may record engagement for any participant and list the whole meeting; everyone
 * else is confined to their own participant record.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\EngagementController;
use OCA\Decidiq\Service\EngagementService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for EngagementController::capture() authorisation.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
class EngagementControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock EngagementService.
	 *
	 * @var EngagementService&MockObject
	 */
	private EngagementService&MockObject $engagementService;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->engagementService = $this->createMock(EngagementService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

	}//end setUp()

	/**
	 * Build a controller with the given user session.
	 *
	 * @param IUserSession $session The session to inject.
	 *
	 * @return EngagementController
	 */
	private function buildController(IUserSession $session): EngagementController {
		return new EngagementController(
			request: $this->request,
			engagementService: $this->engagementService,
			userSession: $session,
			groupManager: $this->groupManager,
			container: $this->container,
			participantResolver: $this->participantResolver,
		);

	}//end buildController()

	/**
	 * Build a session whose getUser() returns a user with the given UID.
	 *
	 * @param string|null $uid The Nextcloud UID, or null for unauthenticated.
	 *
	 * @return IUserSession&MockObject
	 */
	private function sessionFor(?string $uid): IUserSession&MockObject {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end sessionFor()

	/**
	 * Stub the capture request params.
	 *
	 * @param string $meeting Meeting UUID.
	 * @param string $participant Participant UUID.
	 *
	 * @return void
	 */
	private function stubCaptureParams(string $meeting, string $participant): void {
		$this->request->method('getParam')->willReturnCallback(
			function ($key, $default = null) use ($meeting, $participant) {
				return match ($key) {
					'meeting' => $meeting,
					'participant' => $participant,
					'eventType' => 'speech',
					'eventData' => ['duration' => 30],
					default => $default,
				};
			}
		);

	}//end stubCaptureParams()

	/**
	 * Unauthenticated capture returns 401 without touching the service.
	 *
	 * @return void
	 */
	public function testCaptureUnauthenticatedReturns401(): void {
		$this->engagementService->expects($this->never())->method('captureEngagement');

		$result = $this->buildController($this->sessionFor(null))->capture();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

	}//end testCaptureUnauthenticatedReturns401()

	/**
	 * A participant recording for THEIR OWN record is allowed.
	 *
	 * @return void
	 */
	public function testCaptureAllowedForOwnParticipant(): void {
		$this->stubCaptureParams('m-uuid', 'self-participant');

		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('hasRole')->willReturn(false);

		// resolveParticipantUuid resolves the caller to the SAME participant UUID.
		$this->objectService->method('findAll')->willReturn([
			new class {
				/**
				 * Serialise the participant record.
				 *
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					return ['uuid' => 'self-participant'];
				}
			},
		]);

		$this->engagementService->expects($this->once())
			->method('captureEngagement')
			->willReturn(['id' => 'rec-1']);

		$result = $this->buildController($this->sessionFor('uid-self'))->capture();

		self::assertSame(Http::STATUS_OK, $result->getStatus());

	}//end testCaptureAllowedForOwnParticipant()

	/**
	 * A non-privileged caller recording for ANOTHER participant is forbidden.
	 *
	 * @return void
	 */
	public function testCaptureForbiddenForOtherParticipant(): void {
		$this->stubCaptureParams('m-uuid', 'other-participant');

		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('hasRole')->willReturn(false);

		// Caller resolves to a DIFFERENT participant UUID.
		$this->objectService->method('findAll')->willReturn([
			new class {
				/**
				 * Serialise the participant record.
				 *
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					return ['uuid' => 'self-participant'];
				}
			},
		]);

		$this->engagementService->expects($this->never())->method('captureEngagement');

		$result = $this->buildController($this->sessionFor('uid-self'))->capture();

		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

	}//end testCaptureForbiddenForOtherParticipant()

	/**
	 * The meeting chair/secretary may record for ANY participant (widening).
	 *
	 * @return void
	 */
	public function testCaptureAllowedForMeetingChair(): void {
		$this->stubCaptureParams('m-uuid', 'other-participant');

		$this->groupManager->method('isAdmin')->willReturn(false);
		// Caller holds the chair role in this meeting.
		$this->participantResolver->method('hasRole')
			->with(meetingId: 'm-uuid', nextcloudUid: 'uid-chair', roles: ['chair', 'secretary'])
			->willReturn(true);

		$this->engagementService->expects($this->once())
			->method('captureEngagement')
			->willReturn(['id' => 'rec-2']);

		$result = $this->buildController($this->sessionFor('uid-chair'))->capture();

		self::assertSame(Http::STATUS_OK, $result->getStatus());

	}//end testCaptureAllowedForMeetingChair()

	/**
	 * An NC admin may record for ANY participant (original fallback preserved).
	 *
	 * @return void
	 */
	public function testCaptureAllowedForAdmin(): void {
		$this->stubCaptureParams('m-uuid', 'other-participant');

		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->engagementService->expects($this->once())
			->method('captureEngagement')
			->willReturn(['id' => 'rec-3']);

		$result = $this->buildController($this->sessionFor('uid-admin'))->capture();

		self::assertSame(Http::STATUS_OK, $result->getStatus());

	}//end testCaptureAllowedForAdmin()

	/**
	 * Stub the index() query parameter.
	 *
	 * @param string $meeting Meeting UUID.
	 *
	 * @return void
	 */
	private function stubIndexParams(string $meeting): void {
		$this->request->method('getParam')->willReturnCallback(
			static function ($key, $default = null) use ($meeting) {
				if ($key === 'meeting') {
					return $meeting;
				}

				return $default;
			}
		);

	}//end stubIndexParams()

	/**
	 * A participant record serialising to the given uuid.
	 *
	 * @param string $uuid The participant UUID.
	 *
	 * @return object
	 */
	private function participantEntity(string $uuid): object {
		return new class($uuid) {
			/**
			 * Construct with the participant UUID.
			 *
			 * @param string $uuid The participant UUID.
			 */
			public function __construct(
				private readonly string $uuid,
			) {
			}//end __construct()

			/**
			 * Serialise the participant record.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => $this->uuid];
			}//end jsonSerialize()
		};

	}//end participantEntity()

	/**
	 * The engagement records this meeting holds in every index() test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function meetingRecords(): array {
		return [
			['id' => 'rec-self', 'meeting' => 'm-uuid', 'participant' => 'self-participant', 'engagementScore' => 40],
			['id' => 'rec-other', 'meeting' => 'm-uuid', 'participant' => 'other-participant', 'engagementScore' => 90],
		];

	}//end meetingRecords()

	/**
	 * Unauthenticated list returns 401 without touching the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority
	 */
	public function testIndexUnauthenticatedReturns401(): void {
		$this->engagementService->expects($this->never())->method('findEngagementForMeeting');

		$result = $this->buildController($this->sessionFor(null))->index();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

	}//end testIndexUnauthenticatedReturns401()

	/**
	 * ALLOW — the meeting's chair reads the WHOLE meeting.
	 *
	 * This is the direction that protects REQ-PE-003: the engagement summary is
	 * a minutes-review surface for the chair and secretary, so a scoping rule
	 * that also narrowed them would be a functionality regression, not a fix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority
	 */
	public function testIndexReturnsTheWholeMeetingForTheChair(): void {
		$this->stubIndexParams('m-uuid');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('hasRole')
			->with(meetingId: 'm-uuid', nextcloudUid: 'uid-chair', roles: ['chair', 'secretary'])
			->willReturn(true);

		$this->engagementService->expects($this->once())
			->method('findEngagementForMeeting')
			->willReturn($this->meetingRecords());

		$result = $this->buildController($this->sessionFor('uid-chair'))->index();

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame($this->meetingRecords(), $result->getData()['records']);

	}//end testIndexReturnsTheWholeMeetingForTheChair()

	/**
	 * ALLOW — an NC admin reads the whole meeting (original p4 fallback).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority
	 */
	public function testIndexReturnsTheWholeMeetingForAnAdmin(): void {
		$this->stubIndexParams('m-uuid');
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->engagementService->expects($this->once())
			->method('findEngagementForMeeting')
			->willReturn($this->meetingRecords());

		$result = $this->buildController($this->sessionFor('uid-admin'))->index();

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertCount(2, $result->getData()['records']);

	}//end testIndexReturnsTheWholeMeetingForAnAdmin()

	/**
	 * DENY — a plain participant sees ONLY their own record.
	 *
	 * Before the guard this returned every participant's speech log, question
	 * count and derived engagementScore for any meeting UUID the caller cared to
	 * type (OWASP A01:2021).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority
	 */
	public function testIndexNarrowsAPlainParticipantToTheirOwnRecord(): void {
		$this->stubIndexParams('m-uuid');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('hasRole')->willReturn(false);
		$this->objectService->method('findAll')->willReturn([$this->participantEntity('self-participant')]);

		$this->engagementService->expects($this->once())
			->method('findEngagementForMeeting')
			->willReturn($this->meetingRecords());

		$result = $this->buildController($this->sessionFor('uid-self'))->index();

		self::assertSame(Http::STATUS_OK, $result->getStatus());

		$records = array_values($result->getData()['records']);
		self::assertCount(1, $records);
		self::assertSame('rec-self', $records[0]['id']);
		self::assertSame(
			['rec-self'],
			array_column($records, 'id'),
			"Another participant's engagement record must not be disclosed."
		);

	}//end testIndexNarrowsAPlainParticipantToTheirOwnRecord()

	/**
	 * DENY — an authenticated caller with no linked Participant sees nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority
	 */
	public function testIndexReturnsNothingForACallerWithNoParticipantRecord(): void {
		$this->stubIndexParams('m-uuid');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('hasRole')->willReturn(false);
		$this->objectService->method('findAll')->willReturn([]);

		$this->engagementService->method('findEngagementForMeeting')->willReturn($this->meetingRecords());

		$result = $this->buildController($this->sessionFor('uid-stranger'))->index();

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame([], $result->getData()['records']);

	}//end testIndexReturnsNothingForACallerWithNoParticipantRecord()

	/**
	 * A missing `meeting` query parameter is a 422, not an unscoped list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority
	 */
	public function testIndexWithoutAMeetingParameterIsRejected(): void {
		$this->stubIndexParams('');
		$this->engagementService->expects($this->never())->method('findEngagementForMeeting');

		$result = $this->buildController($this->sessionFor('uid-self'))->index();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

	}//end testIndexWithoutAMeetingParameterIsRejected()
}//end class
