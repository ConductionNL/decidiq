<?php

/**
 * Decidiq TeamsMeetingController
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
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use Closure;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Exception\TeamsMeetingRefusedException;
use OCA\Decidiq\Kernel\Contracts\TeamsMeetingServiceInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionStaffGuardInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The Teams meeting of a meeting: whether one can be created, and creating it.
 *
 * Access control on every route: TranscriptionStaffGuard::forMeeting() on the
 * meeting id, so only the chair, the secretary or an admin reaches integriq.
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */
class TeamsMeetingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request The request
	 * @param TeamsMeetingServiceInterface     $teams   Creates the Teams meeting
	 * @param TranscriptionStaffGuardInterface $guard   Chair, secretary or admin of the meeting
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TeamsMeetingServiceInterface $teams,
		private readonly TranscriptionStaffGuardInterface $guard,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether Microsoft 365 is connected, and the meeting's Teams meeting.
	 *
	 * GET /api/meetings/{meetingId}/teams-meeting
	 *
	 * Access control: chair or secretary of the meeting (guard on meetingId).
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return JSONResponse 200 { connected, teamsMeeting }; 401, 403 or 404
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 */
	#[NoAdminRequired]
	public function status(string $meetingId): JSONResponse {
		return $this->guarded(meetingId: $meetingId, action: fn (): array => $this->teams->status(meetingId: $meetingId));
	}//end status()

	/**
	 * Create the Teams meeting through the linked Microsoft 365 source.
	 *
	 * POST /api/meetings/{meetingId}/teams-meeting
	 *
	 * Access control: chair or secretary of the meeting (guard on meetingId).
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return JSONResponse 201 the teamsMeeting; 401, 403, 404, 409 or 502 with a message
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 */
	#[NoAdminRequired]
	public function create(string $meetingId): JSONResponse {
		return $this->guarded(
			meetingId: $meetingId,
			action: fn (): array => $this->teams->create(meetingId: $meetingId),
			status: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Run an action after the staff guard, turning a refusal into its status.
	 *
	 * @param string  $meetingId The meeting the guard checks
	 * @param Closure $action    The action
	 * @param int     $status    The status on success
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 */
	private function guarded(string $meetingId, Closure $action, int $status=Http::STATUS_OK): JSONResponse {
		$denied = $this->guard->forMeeting(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse($action(), $status);
		} catch (TeamsMeetingRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}//end guarded()
}//end class
