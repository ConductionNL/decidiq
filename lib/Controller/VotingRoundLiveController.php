<?php

/**
 * Decidiq Voting Round Live Controller
 *
 * Read-only endpoint the voting panel polls while a round is open: the
 * running tally. It sits apart from VotingController, which changes rounds
 * (open, cast, close, publish, tally, proxy grant / revoke); the proxies the
 * caller holds are read through VotingRoundProxiesController.
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
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundResults;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only views of an open voting round for the voting panel.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundLiveController extends Controller {
	/**
	 * Constructor for VotingRoundLiveController.
	 *
	 * @param IRequest $request The request object
	 * @param VotingRoundResults $results Running counts of an open round
	 * @param IUserSession $userSession The user session
	 * @param VotingRoundGuard $guard Per-meeting authorisation guard
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly VotingRoundResults $results,
		private readonly IUserSession $userSession,
		private readonly VotingRoundGuard $guard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The running tally of an open VotingRound (vot-14, #1375).
	 *
	 * GET /api/voting-rounds/{id}/live-tally
	 *
	 * Every signed-in user who can read the round sees how many votes have been
	 * cast; only the meeting's chair or secretary sees the for / against /
	 * abstain split, as the voting panel already restricts it. No ballot, voter
	 * or individual value is ever returned.
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
	public function liveTally(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$counts = $this->results->liveCounts(votingRoundId: $id);
		if ($counts === null) {
			return new JSONResponse(['message' => 'VotingRound not found'], Http::STATUS_NOT_FOUND);
		}

		$meetingId = $this->guard->resolveMeetingIdFromVotingRound(votingRoundId: $id);
		if ($this->guard->requireChairOrSecretary(meetingId: $meetingId) !== null) {
			return new JSONResponse(['cast' => $counts['cast']]);
		}

		return new JSONResponse($counts);

	}//end liveTally()
}//end class
