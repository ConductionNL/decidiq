<?php

/**
 * Decidiq RoomSystemControllerTest
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
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\RoomSystemController;
use OCA\Decidiq\Service\RoomSystemMessageService;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The chair or secretary guard runs on the message's own meeting before anything is read or applied.
 *
 * @covers \OCA\Decidiq\Controller\RoomSystemController
 */
class RoomSystemControllerTest extends TestCase {

	/**
	 * The meetings the guard was asked about.
	 *
	 * @var list<string>
	 */
	private array $guarded = [];

	/**
	 * The controller.
	 *
	 * @param RoomSystemMessageService&MockObject $messages The messages.
	 * @param JSONResponse|null                   $denied   What the staff guard answers.
	 *
	 * @return RoomSystemController
	 */
	private function controller(RoomSystemMessageService&MockObject $messages, ?JSONResponse $denied): RoomSystemController {
		$guard = $this->createMock(TranscriptionStaffGuard::class);
		$guard->method('forMeeting')->willReturnCallback(
			function (string $meetingId) use ($denied): ?JSONResponse {
				$this->guarded[] = $meetingId;
				return $denied;
			}
		);

		return new RoomSystemController(request: $this->createMock(IRequest::class), messages: $messages, guard: $guard);
	}//end controller()

	/**
	 * A council member who is neither chair nor secretary gets 403 and nothing is applied.
	 *
	 * @return void
	 */
	public function testOnlyStaffApplyAgain(): void {
		$messages = $this->createMock(RoomSystemMessageService::class);
		$messages->method('meetingOf')->with('msg-1')->willReturn('meet-1');
		$messages->expects($this->never())->method('applyById');
		$denied = new JSONResponse(['message' => 'Forbidden: chair or secretary role required.'], Http::STATUS_FORBIDDEN);

		$response = $this->controller(messages: $messages, denied: $denied)->apply(id: 'msg-1');

		$this->assertSame($denied, $response);
		$this->assertSame(['meet-1'], $this->guarded);
	}//end testOnlyStaffApplyAgain()

	/**
	 * The secretary applies a message again and gets it back as stored.
	 *
	 * @return void
	 */
	public function testTheSecretaryAppliesAgain(): void {
		$messages = $this->createMock(RoomSystemMessageService::class);
		$messages->method('meetingOf')->willReturn('meet-1');
		$messages->expects($this->once())->method('applyById')->with('msg-1')->willReturn(['id' => 'msg-1', 'status' => 'applied']);

		$response = $this->controller(messages: $messages, denied: null)->apply(id: 'msg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'msg-1', 'status' => 'applied'], $response->getData());
	}//end testTheSecretaryAppliesAgain()

	/**
	 * An unknown message resolves to no meeting, so the guard decides first; past it the answer is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownMessageIsGuardedThenNotFound(): void {
		$messages = $this->createMock(RoomSystemMessageService::class);
		$messages->method('meetingOf')->willReturn(null);
		$messages->method('applyById')->willReturn(null);

		$response = $this->controller(messages: $messages, denied: null)->apply(id: 'nope');

		$this->assertSame([''], $this->guarded);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownMessageIsGuardedThenNotFound()

	/**
	 * The meeting page reads whether a room system is connected and the messages, after the guard.
	 *
	 * @return void
	 */
	public function testTheMeetingReadsConnectionAndMessages(): void {
		$messages = $this->createMock(RoomSystemMessageService::class);
		$messages->method('isConnected')->willReturn(false);
		$messages->method('forMeeting')->with('meet-1')->willReturn([['id' => 'msg-2', 'status' => 'rejected']]);

		$response = $this->controller(messages: $messages, denied: null)->meeting(id: 'meet-1');

		$this->assertSame(['meet-1'], $this->guarded);
		$this->assertSame(['connected' => false, 'results' => [['id' => 'msg-2', 'status' => 'rejected']]], $response->getData());
	}//end testTheMeetingReadsConnectionAndMessages()

	/**
	 * A refused caller reads nothing about the meeting's messages.
	 *
	 * @return void
	 */
	public function testARefusedCallerReadsNothing(): void {
		$messages = $this->createMock(RoomSystemMessageService::class);
		$messages->expects($this->never())->method('forMeeting');
		$denied = new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);

		$this->assertSame($denied, $this->controller(messages: $messages, denied: $denied)->meeting(id: 'meet-1'));
	}//end testARefusedCallerReadsNothing()
}//end class
