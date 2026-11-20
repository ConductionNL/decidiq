<?php

/**
 * Decidiq Board Book Controller
 *
 * Starts the compilation of a meeting pack and reports where it stands.
 * Reading packs is not done here: MeetingPack objects are read through the
 * OpenRegister objects API, under the meeting's own access.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\BackgroundJob\BoardBookCompileJob;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\BoardBookServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingRoleGateInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Service\BoardBook\BoardBookJobState;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Compile (secretary or chair) and status (anyone on the meeting) of a meeting pack.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */
class BoardBookController extends Controller {

	/**
	 * The HTTP status per refusal of the precheck.
	 *
	 * @var array<string, int>
	 */
	private const REFUSALS = [
		'not_found' => Http::STATUS_NOT_FOUND,
		'empty_agenda' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'change_note_required' => Http::STATUS_UNPROCESSABLE_ENTITY,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request      The request.
	 * @param BoardBookServiceInterface    $boardBooks   Checks and reports the pack.
	 * @param BoardBookJobState            $state        Where a compilation stands.
	 * @param IJobList                     $jobs         Queues the compilation.
	 * @param MeetingRoleGateInterface     $roleGate     Chair or secretary of the meeting (fails closed).
	 * @param ParticipantResolverInterface $participants Whether the caller takes part in the meeting.
	 * @param IUserSession                 $userSession  The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly BoardBookServiceInterface $boardBooks,
		private readonly BoardBookJobState $state,
		private readonly IJobList $jobs,
		private readonly MeetingRoleGateInterface $roleGate,
		private readonly ParticipantResolverInterface $participants,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Queue the compilation of the meeting's next pack version.
	 *
	 * Only the meeting's chair or secretary (or an administrator) may; one
	 * compilation per meeting at a time; from version 2 a `changeNote` is required.
	 *
	 * @param string $id UUID of the meeting
	 *
	 * @return JSONResponse 202 `{status, meetingId, nextVersion}`; 401, 403, 404, 409 or 422.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
	 */
	#[NoAdminRequired]
	public function compile(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->roleGate->isChairOrSecretary(meetingId: $id, userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden: chair or secretary role required to compile the meeting pack.'], Http::STATUS_FORBIDDEN);
		}

		if ($this->state->inFlight(meetingId: $id) === true) {
			return new JSONResponse(['message' => 'A meeting pack is already being compiled for this meeting.'], Http::STATUS_CONFLICT);
		}

		$changeNote = $this->request->getParam('changeNote', '');
		if (is_string($changeNote) === false) {
			$changeNote = '';
		}

		$check = $this->boardBooks->precheck(meetingId: $id, changeNote: $changeNote);
		if ($check['ok'] === false) {
			$status = (self::REFUSALS[$check['code']] ?? Http::STATUS_UNPROCESSABLE_ENTITY);
			return new JSONResponse(['code' => $check['code'], 'message' => $check['message']], $status);
		}

		$this->state->set(meetingId: $id, state: 'queued');
		$this->jobs->add(BoardBookCompileJob::class, ['meetingId' => $id, 'uid' => $user->getUID(), 'changeNote' => $changeNote]);

		return new JSONResponse(['status' => 'queued', 'meetingId' => $id, 'nextVersion' => $check['nextVersion']], Http::STATUS_ACCEPTED);
	}//end compile()

	/**
	 * Where the meeting's pack stands: the compile state, the latest
	 * version, both fingerprints, whether it is outdated, and its reports.
	 *
	 * @param string $id UUID of the meeting
	 *
	 * @return JSONResponse 200; 401, 403 or 404.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	#[NoAdminRequired]
	public function status(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		if ($this->roleGate->isChairOrSecretary(meetingId: $id, userId: $uid) === false
			&& $this->participants->isParticipant(meetingId: $id, nextcloudUid: $uid) === false
		) {
			return new JSONResponse(['message' => 'Forbidden: only the meeting\'s participants may see its pack status.'], Http::STATUS_FORBIDDEN);
		}

		$freshness = $this->boardBooks->freshness(meetingId: $id);
		if ($freshness === null) {
			return new JSONResponse(['message' => 'Meeting not found.'], Http::STATUS_NOT_FOUND);
		}

		$state = $this->state->get(meetingId: $id);

		return new JSONResponse(['state' => $state['state'], 'lastRun' => $state['outcome']] + $freshness);
	}//end status()
}//end class
