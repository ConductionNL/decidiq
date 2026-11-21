<?php

/**
 * Decidiq RoomSystemController
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
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

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\TranscriptionStaffGuardInterface;
use OCA\Decidiq\Service\RoomSystemFeed;
use OCA\Decidiq\Service\RoomSystemMessageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The room system on the meeting page: what it sent, and Apply again for a
 * message that was rejected. Both belong to the meeting's chair or secretary.
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
 */
class RoomSystemController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request  The request.
	 * @param RoomSystemMessageService         $messages The room system messages.
	 * @param TranscriptionStaffGuardInterface $guard    Chair or secretary of the meeting.
	 * @param RoomSystemFeed                   $feed     Sends the floor to the room system.
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
	 */
	public function __construct(
		IRequest $request,
		private readonly RoomSystemMessageService $messages,
		private readonly TranscriptionStaffGuardInterface $guard,
		private readonly RoomSystemFeed $feed,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether a room system is connected, and the meeting's messages, rejected ones first.
	 *
	 * @param string $id The meeting.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-001-the-room-system-is-an-integriq-connection
	 */
	#[NoAdminRequired]
	public function meeting(string $id): JSONResponse {
		$denied = $this->requireMeetingStaff(meetingId: $id);
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse(
			[
				'connected' => $this->messages->isConnected(),
				'results'   => $this->messages->forMeeting(meetingId: $id),
			]
		);
	}//end meeting()

	/**
	 * Apply a message again, after the clerk fixed what it was rejected for.
	 *
	 * The guard runs on the message's own meeting before anything is applied; a
	 * message that does not exist resolves to no meeting, which the guard refuses.
	 *
	 * @param string $id The message.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
	 */
	#[NoAdminRequired]
	public function apply(string $id): JSONResponse {
		$denied = $this->requireMeetingStaff(meetingId: ($this->messages->meetingOf(messageId: $id) ?? ''));
		if ($denied !== null) {
			return $denied;
		}

		$message = $this->messages->applyById(messageId: $id);
		if ($message === null) {
			return new JSONResponse(['message' => 'The room system message does not exist.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($message);
	}//end apply()

	/**
	 * Tell the room system that the chair gave a member the floor (REQ-PLB-006).
	 *
	 * Chair or secretary of the meeting only, checked before anything is read
	 * or sent. Without a linked room system nothing is sent and the answer is
	 * `sent: false`; the speaker queue goes on as before either way.
	 *
	 * @param string $id The meeting UUID.
	 *
	 * @return JSONResponse `{sent: bool}`, 422 without a participant, or the guard's refusal.
	 *
	 * @spec openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-006-the-rooms-cameras-and-microphones-follow-the-speaker
	 */
	#[NoAdminRequired]
	public function floor(string $id): JSONResponse {
		$denied = $this->requireMeetingStaff(meetingId: $id);
		if ($denied !== null) {
			return $denied;
		}

		$participant = trim((string)$this->request->getParam('participant', ''));
		if ($participant === '') {
			return new JSONResponse(['message' => 'Name the participant who has the floor.'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(['sent' => $this->feed->floorGiven(meetingId: $id, participantId: $participant)]);
	}//end floor()

	/**
	 * The refusal for a caller who is not the meeting's chair or secretary, or null.
	 *
	 * @param string $meetingId The meeting UUID.
	 *
	 * @return JSONResponse|null The 403, or null when the caller may act.
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
	 */
	private function requireMeetingStaff(string $meetingId): ?JSONResponse {
		return $this->guard->forMeeting(meetingId: $meetingId);
	}//end requireMeetingStaff()
}//end class
