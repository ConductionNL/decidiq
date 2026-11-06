<?php

/**
 * Decidiq Paper Vote Controller
 *
 * Takes a named vote taken on paper (board DcStemmenInvoeren). Only the
 * chair or the secretary of the round's meeting may enter it.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\PaperVoteRecorder;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * POST /api/voting-rounds/{id}/recorded-votes.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class PaperVoteController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param PaperVoteRecorder $recorder Records the ballots
	 * @param IUserSession $userSession The user session
	 * @param VotingRoundGuard $guard Per-meeting authorisation guard
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PaperVoteRecorder $recorder,
		private readonly IUserSession $userSession,
		private readonly VotingRoundGuard $guard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Record a named vote taken on paper (board DcStemmenInvoeren).
	 *
	 * POST /api/voting-rounds/{id}/recorded-votes
	 * Body: { "votes": [ { "participant": "<uuid>", "value": "for"|"against"|"abstain" } ] }
	 *
	 * Only the chair or the secretary of the round's meeting may enter them;
	 * each ballot is then checked as a member's own vote is.
	 *
	 * @param string $id The voting round UUID
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function record(string $id): JSONResponse {
		$meetingId = $this->guard->resolveMeetingIdFromVotingRound(votingRoundId: $id);
		$guard = $this->guard->requireChairOrSecretary(meetingId: $meetingId);
		if ($guard !== null) {
			return $guard;
		}

		$votes = $this->request->getParams()['votes'] ?? null;
		if (is_array($votes) === false || $votes === []) {
			return new JSONResponse(['message' => 'votes must be a non-empty list'], Http::STATUS_BAD_REQUEST);
		}

		$callerUid = $this->userSession->getUser()?->getUID() ?? '';

		return new JSONResponse(
			$this->recorder->recordVotes(votingRoundId: $id, votes: array_values($votes), callerUid: $callerUid),
			Http::STATUS_CREATED
		);

	}//end record()
}//end class
