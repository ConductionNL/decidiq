<?php

/**
 * Decidiq Broadcast Controller
 *
 * The meeting's chair or secretary runs the public livestream of a meeting:
 * a test on the staff preview, going live, pausing for a closed session,
 * resuming and stopping. The streaming itself is done by the linked streaming
 * service; this controller guards the caller and hands the work to
 * MeetingBroadcastService.
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
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use Closure;
use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\BroadcastRefusedException;
use OCA\Decidiq\Service\BroadcastCaptionService;
use OCA\Decidiq\Service\MeetingBroadcastService;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The broadcast routes of a meeting.
 *
 * Access control on every route: TranscriptionStaffGuard::forMeeting() on the
 * meeting the broadcast belongs to. 401 without a session, 403 for anyone who
 * is not the meeting's chair or secretary (Nextcloud admins pass), and 403 for
 * a broadcast id that resolves to no meeting. A refused caller reaches neither
 * the streaming service nor the register.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
 */
class BroadcastController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request    The request
	 * @param MeetingBroadcastService $broadcasts Runs the broadcast
	 * @param TranscriptionStaffGuard $guard      Chair, secretary or admin of the meeting
	 * @param BroadcastCaptionService $captions   Makes and releases the subtitles
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly MeetingBroadcastService $broadcasts,
		private readonly TranscriptionStaffGuard $guard,
		private readonly BroadcastCaptionService $captions,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether a streaming service is connected, and the meeting's broadcast.
	 *
	 * GET /api/meetings/{meetingId}/broadcast
	 *
	 * Access control: chair or secretary of the meeting (guard on meetingId).
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return JSONResponse 200 { connected, broadcast }; 401 or 403
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 */
	#[NoAdminRequired]
	public function status(string $meetingId): JSONResponse {
		return $this->guarded(meetingId: $meetingId, action: fn (): array => $this->broadcasts->status(meetingId: $meetingId));
	}//end status()

	/**
	 * Start a test broadcast on the service's staff preview.
	 *
	 * POST /api/meetings/{meetingId}/broadcast/test
	 *
	 * Access control: chair or secretary of the meeting (guard on meetingId).
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return JSONResponse 201 the broadcast; 401, 403, 404, 409 or 502 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 */
	#[NoAdminRequired]
	public function test(string $meetingId): JSONResponse {
		return $this->guarded(
			meetingId: $meetingId,
			action: fn (): array => $this->broadcasts->startTest(meetingId: $meetingId),
			status: Http::STATUS_CREATED
		);
	}//end test()

	/**
	 * Record what the test showed.
	 *
	 * POST /api/meeting-broadcasts/{id}/test-result
	 * Body: { result: ok|problems, note }
	 *
	 * Access control: chair or secretary of the broadcast's meeting.
	 *
	 * @param string $id The broadcast
	 *
	 * @return JSONResponse 200 the broadcast; 401, 403, 409 or 422 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 */
	#[NoAdminRequired]
	public function testResult(string $id): JSONResponse {
		return $this->guarded(
			meetingId: $this->broadcasts->meetingOf(broadcastId: $id),
			action: fn (): array => $this->broadcasts->recordTestResult(
				broadcastId: $id,
				result: (string)$this->request->getParam('result', ''),
				note: (string)$this->request->getParam('note', '')
			)
		);
	}//end testResult()

	/**
	 * Go live.
	 *
	 * POST /api/meeting-broadcasts/{id}/start
	 *
	 * Access control: chair or secretary of the broadcast's meeting.
	 *
	 * @param string $id The broadcast
	 *
	 * @return JSONResponse 200 the broadcast; 401, 403, 409, 422 or 502 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 */
	#[NoAdminRequired]
	public function start(string $id): JSONResponse {
		return $this->guarded(
			meetingId: $this->broadcasts->meetingOf(broadcastId: $id),
			action: fn (): array => $this->broadcasts->start(broadcastId: $id)
		);
	}//end start()

	/**
	 * Pause for a closed session.
	 *
	 * POST /api/meeting-broadcasts/{id}/pause
	 *
	 * Access control: chair or secretary of the broadcast's meeting.
	 *
	 * @param string $id The broadcast
	 *
	 * @return JSONResponse 200 the broadcast; 401, 403, 409 or 502 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 */
	#[NoAdminRequired]
	public function pause(string $id): JSONResponse {
		return $this->guarded(
			meetingId: $this->broadcasts->meetingOf(broadcastId: $id),
			action: fn (): array => $this->broadcasts->pause(broadcastId: $id)
		);
	}//end pause()

	/**
	 * Resume after a closed session.
	 *
	 * POST /api/meeting-broadcasts/{id}/resume
	 *
	 * Access control: chair or secretary of the broadcast's meeting.
	 *
	 * @param string $id The broadcast
	 *
	 * @return JSONResponse 200 the broadcast; 401, 403, 409 or 502 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 */
	#[NoAdminRequired]
	public function resume(string $id): JSONResponse {
		return $this->guarded(
			meetingId: $this->broadcasts->meetingOf(broadcastId: $id),
			action: fn (): array => $this->broadcasts->resume(broadcastId: $id)
		);
	}//end resume()

	/**
	 * Stop the broadcast.
	 *
	 * POST /api/meeting-broadcasts/{id}/stop
	 *
	 * Access control: chair or secretary of the broadcast's meeting.
	 *
	 * @param string $id The broadcast
	 *
	 * @return JSONResponse 200 the broadcast; 401, 403, 409 or 502 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 */
	#[NoAdminRequired]
	public function stop(string $id): JSONResponse {
		return $this->guarded(
			meetingId: $this->broadcasts->meetingOf(broadcastId: $id),
			action: fn (): array => $this->broadcasts->stop(broadcastId: $id)
		);
	}//end stop()

	/**
	 * Make the subtitles of the broadcast from the meeting's aligned transcript.
	 *
	 * POST /api/meeting-broadcasts/{id}/captions, body `language` (default nl)
	 *
	 * Access control: chair or secretary of the broadcast's meeting.
	 *
	 * @param string $id The broadcast
	 *
	 * @return JSONResponse 201 the caption file; 401, 403, 404, 409, 422 or 500 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-006-subtitles-for-the-recording-come-from-the-aligned-transcript-and-cover-only-the-public-windows
	 */
	#[NoAdminRequired]
	public function captions(string $id): JSONResponse {
		$language = (string)$this->request->getParam('language', 'nl');
		return $this->guarded(
			meetingId: $this->broadcasts->meetingOf(broadcastId: $id),
			action: fn (): array => $this->captions->derive(broadcastId: $id, language: $language),
			status: Http::STATUS_CREATED
		);
	}//end captions()

	/**
	 * Release the reviewed subtitles of an ended public broadcast.
	 *
	 * POST /api/meeting-broadcasts/{id}/captions/{language}/release
	 *
	 * Access control: chair or secretary of the broadcast's meeting.
	 *
	 * @param string $id       The broadcast
	 * @param string $language The track's language code
	 *
	 * @return JSONResponse 200 the broadcast; 401, 403, 404, 409 or 422 with a message
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-007-a-caption-track-is-public-only-after-the-clerk-releases-it
	 */
	#[NoAdminRequired]
	public function releaseCaptions(string $id, string $language): JSONResponse {
		return $this->guarded(
			meetingId: $this->broadcasts->meetingOf(broadcastId: $id),
			action: fn (): array => $this->captions->release(broadcastId: $id, language: $language)
		);
	}//end releaseCaptions()

	/**
	 * Run an action for the meeting's staff only; a refusal comes back with its status.
	 *
	 * @param string  $meetingId The meeting the guard checks
	 * @param Closure $action    The work, returning the response body
	 * @param int     $status    The status on success
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 */
	private function guarded(string $meetingId, Closure $action, int $status=Http::STATUS_OK): JSONResponse {
		$denied = $this->guard->forMeeting(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse($action(), $status);
		} catch (BroadcastRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}//end guarded()
}//end class
