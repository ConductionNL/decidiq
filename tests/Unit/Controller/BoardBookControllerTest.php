<?php

/**
 * Unit tests for BoardBookController.
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
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\BackgroundJob\BoardBookCompileJob;
use OCA\Decidiq\Controller\BoardBookController;
use OCA\Decidiq\Kernel\Contracts\BoardBookServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingRoleGateInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Service\BoardBook\BoardBookJobState;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Compile is for the chair or secretary; status for whoever takes part.
 */
#[CoversClass(BoardBookController::class)]
class BoardBookControllerTest extends TestCase {

	private BoardBookServiceInterface&MockObject $boardBooks;

	private BoardBookJobState&MockObject $state;

	private IJobList&MockObject $jobs;

	/**
	 * The controller for a caller with the given roles.
	 *
	 * @param string|null $uid         The caller, or null for nobody signed in.
	 * @param bool        $presiding   Chair or secretary.
	 * @param bool        $participant Takes part in the meeting.
	 * @param string      $changeNote  The request's change note.
	 *
	 * @return BoardBookController
	 */
	private function controller(?string $uid, bool $presiding, bool $participant=false, string $changeNote=''): BoardBookController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['changeNote', '', $changeNote]]);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$gate = $this->createMock(MeetingRoleGateInterface::class);
		$gate->method('isChairOrSecretary')->willReturn($presiding);
		$participants = $this->createMock(ParticipantResolverInterface::class);
		$participants->method('isParticipant')->willReturn($participant);
		$this->boardBooks = $this->createMock(BoardBookServiceInterface::class);
		$this->state = $this->createMock(BoardBookJobState::class);
		$this->jobs = $this->createMock(IJobList::class);

		return new BoardBookController(
			request: $request,
			boardBooks: $this->boardBooks,
			state: $this->state,
			jobs: $this->jobs,
			roleGate: $gate,
			participants: $participants,
			userSession: $session,
		);
	}//end controller()

	/**
	 * Nobody signed in, or a member who does not preside, queues nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheChairOrSecretaryMayCompile(): void {
		$anonymous = $this->controller(uid: null, presiding: false);
		$this->jobs->expects($this->never())->method('add');
		$this->assertSame(expected: 401, actual: $anonymous->compile(id: 'm1')->getStatus());

		$member = $this->controller(uid: 'lid', presiding: false, participant: true);
		$this->jobs->expects($this->never())->method('add');
		$this->boardBooks->expects($this->never())->method('precheck');
		$this->assertSame(expected: 403, actual: $member->compile(id: 'm1')->getStatus());
	}//end testOnlyTheChairOrSecretaryMayCompile()

	/**
	 * A compilation in flight, or a precheck refusal, queues nothing.
	 *
	 * @return void
	 */
	public function testInFlightAndRefusalsQueueNothing(): void {
		$busy = $this->controller(uid: 'griffier', presiding: true);
		$this->state->method('inFlight')->willReturn(true);
		$this->jobs->expects($this->never())->method('add');
		$this->assertSame(expected: 409, actual: $busy->compile(id: 'm1')->getStatus());

		$noNote = $this->controller(uid: 'griffier', presiding: true);
		$this->state->method('inFlight')->willReturn(false);
		$this->boardBooks->method('precheck')->willReturn(['ok' => false, 'code' => 'change_note_required', 'message' => 'note', 'nextVersion' => 2]);
		$this->jobs->expects($this->never())->method('add');
		$response = $noNote->compile(id: 'm1');
		$this->assertSame(expected: [422, 'change_note_required'], actual: [$response->getStatus(), $response->getData()['code']]);
	}//end testInFlightAndRefusalsQueueNothing()

	/**
	 * An accepted request queues one job with the caller and the note, and answers 202.
	 *
	 * @return void
	 */
	public function testAnAcceptedRequestIsQueued(): void {
		$controller = $this->controller(uid: 'griffier', presiding: true, changeNote: 'Punt 3 aangevuld');
		$this->state->method('inFlight')->willReturn(false);
		$this->boardBooks->method('precheck')->with('m1', 'Punt 3 aangevuld')->willReturn(['ok' => true, 'code' => 'ok', 'message' => '', 'nextVersion' => 2]);
		$this->state->expects($this->once())->method('set')->with('m1', 'queued');
		$this->jobs->expects($this->once())->method('add')->with(BoardBookCompileJob::class, ['meetingId' => 'm1', 'uid' => 'griffier', 'changeNote' => 'Punt 3 aangevuld']);

		$response = $controller->compile(id: 'm1');

		$this->assertSame(expected: 202, actual: $response->getStatus());
		$this->assertSame(expected: ['status' => 'queued', 'meetingId' => 'm1', 'nextVersion' => 2], actual: $response->getData());
	}//end testAnAcceptedRequestIsQueued()

	/**
	 * Status: refused to an outsider, answered to a participant with state and freshness.
	 *
	 * @return void
	 */
	public function testStatusIsForTheMeetingsParticipants(): void {
		$outsider = $this->controller(uid: 'x', presiding: false, participant: false);
		$this->boardBooks->expects($this->never())->method('freshness');
		$this->assertSame(expected: 403, actual: $outsider->status(id: 'm1')->getStatus());

		$member = $this->controller(uid: 'lid', presiding: false, participant: true);
		$this->boardBooks->method('freshness')->willReturn(['latestVersion' => 1, 'outdated' => true]);
		$this->state->method('get')->willReturn(['state' => 'done', 'outcome' => ['version' => 1]]);
		$response = $member->status(id: 'm1');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['state' => 'done', 'lastRun' => ['version' => 1], 'latestVersion' => 1, 'outdated' => true], actual: $response->getData());
	}//end testStatusIsForTheMeetingsParticipants()
}//end class
