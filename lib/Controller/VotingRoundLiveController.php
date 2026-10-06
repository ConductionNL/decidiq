<?php

/**
 * Decidiq Voting Round Live Controller
 *
 * Read-only endpoints the voting panel polls while a round is open: the
 * running tally and the proxies the caller holds or has given. They sit
 * apart from VotingController, which changes rounds (open, cast, close,
 * publish, tally, proxy grant / revoke).
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
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingService;
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
	 * @param VotingService $votingService Resolves the caller's participant UUID
	 * @param VotingRoundResults $results Running counts of an open round
	 * @param IUserSession $userSession The user session
	 * @param VotingRoundGuard $guard Per-meeting authorisation guard
	 * @param ProxyDelegationService $proxyService Proxy (volmacht) lookups
	 * @param VotingErrorResponder $errors Exception-to-status mapping
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly VotingService $votingService,
		private readonly VotingRoundResults $results,
		private readonly IUserSession $userSession,
		private readonly VotingRoundGuard $guard,
		private readonly ProxyDelegationService $proxyService,
		private readonly VotingErrorResponder $errors,
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

	/**
	 * Say which proxies the caller holds and has given on a round.
	 *
	 * GET /api/voting-rounds/{id}/proxy
	 * Response: { "participantId": "uuid", "held": [{ "participantId", "displayName" }], "granted": "uuid"|null }
	 *
	 * The voting panel reads it to offer the holder "vote on behalf of" for
	 * each grant they hold; each held participantId is the `delegatorId` the
	 * cast endpoint takes. The caller is resolved from the session, as
	 * VotingController::cast() and proxy() do, so the answer names the same
	 * participant they act as.
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
	public function proxies(string $id): JSONResponse {
		$nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
		if ($nextcloudUid === '') {
			return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$participantId = $this->votingService->resolveParticipantUuid($nextcloudUid);
		if ($participantId === null) {
			// Not a participant: nothing held, nothing given.
			return new JSONResponse(['participantId' => null, 'held' => [], 'granted' => null]);
		}

		return $this->errors->badRequestOrNotFound(
			fn (): JSONResponse => new JSONResponse(
				$this->proxyService->proxiesFor(votingRoundId: $id, participantId: $participantId)
			)
		);

	}//end proxies()
}//end class
