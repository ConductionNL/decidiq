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
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
	 */
	public function __construct(
		IRequest $request,
		private readonly RoomSystemMessageService $messages,
		private readonly TranscriptionStaffGuardInterface $guard,
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
		$denied = $this->guard->forMeeting(meetingId: $id);
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
		$denied = $this->guard->forMeeting(meetingId: ($this->messages->meetingOf(messageId: $id) ?? ''));
		if ($denied !== null) {
			return $denied;
		}

		$message = $this->messages->applyById(messageId: $id);
		if ($message === null) {
			return new JSONResponse(['message' => 'The room system message does not exist.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($message);
	}//end apply()
}//end class
