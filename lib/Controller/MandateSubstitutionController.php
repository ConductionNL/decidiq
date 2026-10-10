<?php

/**
 * Decidiq Mandate Substitution Controller
 *
 * The seats of a meeting and the mandate swap (bodies-substitute-mandate-swap,
 * design D3): GET the seats, POST a swap, POST the end of a swap. Every
 * signed-in member reads the seats; MandateSubstitutionService refuses a swap
 * by anyone but the meeting's chair or secretary, during an open vote, or
 * against the seat plan, each with its own status.
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
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\SubstitutionRefusedException;
use OCA\Decidiq\Service\MandateSubstitutionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Seats and mandate swaps of a meeting.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
 */
class MandateSubstitutionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request       The request
	 * @param MandateSubstitutionService $substitutions Starts, ends and reads
	 * @param IUserSession               $userSession   The caller
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly MandateSubstitutionService $substitutions,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The meeting's participants with their seats, its substitutions, and
	 * whether the caller may swap.
	 *
	 * GET /api/meetings/{meetingId}/seats
	 *
	 * Access control: any signed-in user; the participants and substitutions
	 * are member-readable on the object API too. 401 when anonymous.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return JSONResponse 200 { participants, substitutions, canSubstitute }; 401
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
	 */
	#[NoAdminRequired]
	public function seats(string $meetingId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->substitutions->seats(meetingId: $meetingId, userId: $user->getUID()));
	}//end seats()

	/**
	 * Swap a member for a substitute.
	 *
	 * POST /api/meetings/{meetingId}/substitutions
	 * Body: { outgoingParticipantId, incomingParticipantId, reason }
	 *
	 * Access control: per-object guard in MandateSubstitutionService, the
	 * meeting's chair or secretary or an admin (MeetingRoleGate); 403 for
	 * anyone else.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return JSONResponse 201 the substitution; 400, 401, 403 or 409 with a message
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
	 */
	#[NoAdminRequired]
	public function substitute(string $meetingId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$record = $this->substitutions->start(
				meetingId: $meetingId,
				outgoingId: (string)$this->request->getParam('outgoingParticipantId', ''),
				incomingId: (string)$this->request->getParam('incomingParticipantId', ''),
				reason: trim((string)$this->request->getParam('reason', '')),
				userId: $user->getUID()
			);
		} catch (SubstitutionRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($record, Http::STATUS_CREATED);
	}//end substitute()

	/**
	 * End a substitution; the seat goes back to the member and the record stays.
	 *
	 * POST /api/meetings/{meetingId}/substitutions/{id}/end
	 *
	 * Access control: per-object guard in MandateSubstitutionService, the
	 * meeting's chair or secretary or an admin; 403 for anyone else.
	 *
	 * @param string $meetingId The meeting
	 * @param string $id        The substitution
	 *
	 * @return JSONResponse 200 the substitution; 401, 403, 404 or 409 with a message
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member
	 */
	#[NoAdminRequired]
	public function endSubstitution(string $meetingId, string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$record = $this->substitutions->end(meetingId: $meetingId, substitutionId: $id, userId: $user->getUID());
		} catch (SubstitutionRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($record);
	}//end endSubstitution()
}//end class
