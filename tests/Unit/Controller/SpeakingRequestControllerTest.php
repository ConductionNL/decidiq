<?php

/**
 * Registering is open to signed-in users; registering late and every
 * moderation are for the staff of the request's meeting (inspreekrecht-plenair
 * task 4).
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
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\SpeakingRequestController;
use OCA\Decidiq\Exception\SpeakingRequestRefusedException;
use OCA\Decidiq\Kernel\Contracts\SpeakingRequestServiceInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionStaffGuardInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Controller\SpeakingRequestController
 * @uses   \OCA\Decidiq\Exception\SpeakingRequestRefusedException
 */
final class SpeakingRequestControllerTest extends TestCase {

	private SpeakingRequestServiceInterface&MockObject $service;

	private TranscriptionStaffGuardInterface&MockObject $guard;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The meetings the guard was asked about.
	 *
	 * @var list<string>
	 */
	private array $guarded = [];

	/**
	 * The meetings the user is not staff of.
	 *
	 * @var list<string>
	 */
	private array $notStaff = [];

	/**
	 * Mocks for the service and the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(SpeakingRequestServiceInterface::class);
		$this->guard   = $this->createMock(TranscriptionStaffGuardInterface::class);
		$this->guard->method('forMeeting')->willReturnCallback(
			function (string $meetingId): ?JSONResponse {
				$this->guarded[] = $meetingId;
				return in_array($meetingId, $this->notStaff, true) ? new JSONResponse(['message' => 'staff only'], 403) : null;
			}
		);
	}//end setUp()

	/**
	 * The controller over the current parameters.
	 *
	 * @return SpeakingRequestController
	 */
	private function controller(): SpeakingRequestController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default));
		return new SpeakingRequestController($request, $this->service, $this->guard);
	}//end controller()

	/**
	 * A registration in time needs no staff and answers 201 with the request.
	 *
	 * @return void
	 */
	public function testARegistrationAnswersCreatedWithoutTheGuard(): void {
		$this->params = ['meeting' => 'm1', 'agendaItem' => 'i1', 'topic' => ['speakerName' => 'A', 'topicText' => 'T'], 'contactDetails' => ['name' => 'N']];
		$this->service->expects($this->once())->method('register')
			->with(['meeting' => 'm1', 'agendaItem' => 'i1', 'governanceBody' => '', 'topic' => ['speakerName' => 'A', 'topicText' => 'T'], 'contactDetails' => ['name' => 'N']], false)
			->willReturn(['id' => 'r1']);

		$response = $this->controller()->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame(['id' => 'r1'], $response->getData());
		self::assertSame([], $this->guarded);
	}//end testARegistrationAnswersCreatedWithoutTheGuard()

	/**
	 * Only the meeting's staff may register past the deadline.
	 *
	 * @return void
	 */
	public function testTheOverrideIsForTheMeetingsStaff(): void {
		$this->params   = ['meeting' => 'm1', 'override' => 'true'];
		$this->notStaff = ['m1'];
		$this->service->expects($this->never())->method('register');

		self::assertSame(403, $this->controller()->create()->getStatus());
		self::assertSame(['m1'], $this->guarded);

		$this->notStaff = [];
		$service = $this->createMock(SpeakingRequestServiceInterface::class);
		$service->expects($this->once())->method('register')->with($this->anything(), true)->willReturn(['id' => 'r2']);
		$this->service = $service;
		self::assertSame(201, $this->controller()->create()->getStatus());
	}//end testTheOverrideIsForTheMeetingsStaff()

	/**
	 * A refusal answers with its own status and message.
	 *
	 * @return void
	 */
	public function testARefusalAnswersWithItsStatus(): void {
		$this->service->method('register')->willThrowException(new SpeakingRequestRefusedException('Registration closed.', 409));

		$response = $this->controller()->create();

		self::assertSame(409, $response->getStatus());
		self::assertSame(['message' => 'Registration closed.'], $response->getData());
	}//end testARefusalAnswersWithItsStatus()

	/**
	 * Approving is guarded on the request's meeting and passes time and order on.
	 *
	 * @return void
	 */
	public function testApprovalIsGuardedOnTheRequestsMeeting(): void {
		$this->service->method('meetingOf')->with('r1')->willReturn('m1');
		$this->params = ['allottedMinutes' => '4', 'position' => ''];
		$this->service->expects($this->once())->method('approve')->with('r1', 4, null)->willReturn(['lifecycle' => 'approved']);

		self::assertSame(['lifecycle' => 'approved'], $this->controller()->approve('r1')->getData());
		self::assertSame(['m1'], $this->guarded);
	}//end testApprovalIsGuardedOnTheRequestsMeeting()

	/**
	 * A non-staff user cannot moderate, and an unknown request is a 404.
	 *
	 * @return void
	 */
	public function testModerationRefusesNonStaffAndUnknownRequests(): void {
		$this->service->method('meetingOf')->willReturnMap([['r1', 'm1'], ['missing', '']]);
		$this->notStaff = ['m1'];
		$this->service->expects($this->never())->method('reject');
		$this->service->expects($this->never())->method('approve');

		self::assertSame(403, $this->controller()->reject('r1')->getStatus());
		self::assertSame(404, $this->controller()->approve('missing')->getStatus());
	}//end testModerationRefusesNonStaffAndUnknownRequests()

	/**
	 * Rejection passes the reason on.
	 *
	 * @return void
	 */
	public function testRejectionPassesTheReason(): void {
		$this->service->method('meetingOf')->willReturn('m1');
		$this->params = ['rejectionReason' => 'Niet op de agenda'];
		$this->service->expects($this->once())->method('reject')->with('r1', 'Niet op de agenda')->willReturn(['lifecycle' => 'rejected']);

		self::assertSame(200, $this->controller()->reject('r1')->getStatus());
	}//end testRejectionPassesTheReason()

	/**
	 * A referral needs staff of the new meeting as well as of the old one.
	 *
	 * @return void
	 */
	public function testAReferralIsGuardedOnBothMeetings(): void {
		$this->service->method('meetingOf')->willReturn('m1');
		$this->params   = ['meeting' => 'm2', 'agendaItem' => ''];
		$this->notStaff = ['m2'];
		$this->service->expects($this->once())->method('refer')->with('r1', 'm2', null)->willReturn(['meeting' => 'm2']);

		self::assertSame(403, $this->controller()->refer('r1')->getStatus(), 'staff of the old meeting only is not enough');

		$this->notStaff = [];
		$this->guarded  = [];
		self::assertSame(['meeting' => 'm2'], $this->controller()->refer('r1')->getData());
		self::assertSame(['m2', 'm1'], $this->guarded);
	}//end testAReferralIsGuardedOnBothMeetings()

	/**
	 * The internal agenda and the queue are for the meeting's staff; others
	 * get the guard's refusal and the service is not asked.
	 *
	 * @return void
	 */
	public function testTheAgendaAndQueueAreForTheMeetingsStaff(): void {
		$this->notStaff = ['m-other'];
		$this->params   = ['agendaItem' => 'i1'];
		$this->service->expects($this->once())->method('agenda')->with('m1')->willReturn(['i1' => ['slots' => [], 'pending' => []]]);
		$this->service->expects($this->once())->method('queue')->with('m1', 'i1')->willReturn([['requestId' => 'r1']]);

		self::assertSame(['i1' => ['slots' => [], 'pending' => []]], $this->controller()->agenda('m1')->getData());
		self::assertSame([['requestId' => 'r1']], $this->controller()->queue('m1')->getData());
		self::assertSame(403, $this->controller()->agenda('m-other')->getStatus());
		self::assertSame(403, $this->controller()->queue('m-other')->getStatus());
		self::assertSame(['m1', 'm1', 'm-other', 'm-other'], $this->guarded);
	}//end testTheAgendaAndQueueAreForTheMeetingsStaff()

	/**
	 * The public view needs no staff guard: it is the public projection.
	 *
	 * @return void
	 */
	public function testThePublicViewIsTheProjection(): void {
		$this->service->expects($this->once())->method('publicAgenda')->with('m1')->willReturn(['display' => 'count', 'agenda' => []]);

		self::assertSame(['display' => 'count', 'agenda' => []], $this->controller()->publicAgenda('m1')->getData());
		self::assertSame([], $this->guarded);
	}//end testThePublicViewIsTheProjection()

	/**
	 * An outcome is guarded on the request's meeting and passes the outcome.
	 *
	 * @return void
	 */
	public function testAnOutcomeIsGuardedOnTheRequestsMeeting(): void {
		$this->service->method('meetingOf')->willReturnMap([['r1', 'm1'], ['r2', 'm-other']]);
		$this->notStaff = ['m-other'];
		$this->params   = ['outcome' => 'spoke'];
		$this->service->expects($this->once())->method('markOutcome')->with('r1', 'spoke')->willReturn(['lifecycle' => 'spoke']);

		self::assertSame(['lifecycle' => 'spoke'], $this->controller()->outcome('r1')->getData());
		self::assertSame(403, $this->controller()->outcome('r2')->getStatus());
	}//end testAnOutcomeIsGuardedOnTheRequestsMeeting()
}//end class
