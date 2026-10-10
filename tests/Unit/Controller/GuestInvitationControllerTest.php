<?php

/**
 * Decidiq GuestInvitationControllerTest
 *
 * The guest route answers 201 with the created guest, and a refusal from the
 * service travels to the organiser with its own status and message
 * (meeting-ad-hoc-with-guests, pla-20).
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
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\GuestInvitationController;
use OCA\Decidiq\Exception\GuestInvitationRefusedException;
use OCA\Decidiq\Service\GuestInvitationService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GuestInvitationController::invite().
 */
class GuestInvitationControllerTest extends TestCase {

	private const MEETING = '0f5b8a8e-6a3c-4c1b-9d3e-1a2b3c4d5e61';

	/** @var GuestInvitationService&MockObject */
	private GuestInvitationService $invitations;

	/**
	 * The controller for a signed-in user, with these request parameters.
	 *
	 * @param string|null          $uid    The signed-in user, or null for none
	 * @param array<string, mixed> $params The request parameters
	 *
	 * @return GuestInvitationController
	 */
	private function controller(?string $uid, array $params): GuestInvitationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new GuestInvitationController(
			request: $request,
			invitations: $this->invitations,
			userSession: $session
		);
	}//end controller()

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->invitations = $this->createMock(GuestInvitationService::class);
	}//end setUp()

	/**
	 * The organiser's invitation answers 201 with the created guest.
	 *
	 * @return void
	 */
	public function testTheOrganiserGetsTheCreatedGuest(): void {
		$this->invitations->expects($this->once())
			->method('requireOrganiserOf')
			->with(self::MEETING, 'anna')
			->willReturn([]);
		$this->invitations->expects($this->once())
			->method('invite')
			->with(self::MEETING, 'Advisor', 'advisor@example.org', 'anna')
			->willReturn(['participant' => 'p1', 'attendance' => 'a1', 'mailed' => true]);

		$response = $this->controller(uid: 'anna', params: ['name' => 'Advisor', 'email' => 'advisor@example.org'])
			->invite(id: self::MEETING);

		self::assertSame(201, $response->getStatus());
		self::assertSame(['participant' => 'p1', 'attendance' => 'a1', 'mailed' => true], $response->getData());
	}//end testTheOrganiserGetsTheCreatedGuest()

	/**
	 * Someone who did not organise the meeting gets the 403 and its message,
	 * and no guest is invited.
	 *
	 * @return void
	 */
	public function testARefusalAnswersWithItsStatusAndMessage(): void {
		$this->invitations->method('requireOrganiserOf')->willThrowException(
			new GuestInvitationRefusedException(message: 'Only the organiser of this meeting can invite guests.', status: 403)
		);
		$this->invitations->expects($this->never())->method('invite');

		$response = $this->controller(uid: 'pieter', params: ['email' => 'a@example.org'])
			->invite(id: self::MEETING);

		self::assertSame(403, $response->getStatus());
		self::assertSame(['message' => 'Only the organiser of this meeting can invite guests.'], $response->getData());
	}//end testARefusalAnswersWithItsStatusAndMessage()

	/**
	 * Without a session the empty user id goes to the service, which refuses;
	 * missing parameters arrive as empty strings, never as null.
	 *
	 * @return void
	 */
	public function testNoSessionPassesAnEmptyUserAndEmptyParameters(): void {
		$this->invitations->expects($this->once())
			->method('requireOrganiserOf')
			->with(self::MEETING, '')
			->willReturn([]);
		$this->invitations->expects($this->once())
			->method('invite')
			->with(self::MEETING, '', '', '')
			->willThrowException(new GuestInvitationRefusedException(message: 'Enter a valid email address.'));

		$response = $this->controller(uid: null, params: [])->invite(id: self::MEETING);

		self::assertSame(422, $response->getStatus());
	}//end testNoSessionPassesAnEmptyUserAndEmptyParameters()
}//end class
