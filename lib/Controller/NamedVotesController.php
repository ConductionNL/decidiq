<?php

/**
 * Decidiq Named Votes Controller
 *
 * Records the members' choices on an open roll call round, entered by the
 * chair or secretary of the round's meeting. Guard -> read input -> delegate
 * to RecordedVoteWriter; a refusal is answered with its status and the
 * members it names.
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
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\RecordedVotesRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\VotingRoundGuardInterface;
use OCA\Decidiq\Service\RecordedVoteWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for the roll call entered by name.
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
 */
class NamedVotesController extends Controller {
	/**
	 * Constructor for NamedVotesController.
	 *
	 * @param IRequest                  $request     The request object
	 * @param RecordedVoteWriter        $writer      Records the members' choices on a roll call round
	 * @param IUserSession              $userSession The user session
	 * @param VotingRoundGuardInterface $guard       Resolves the round's meeting and checks the presiding role
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
	 */
	public function __construct(
		IRequest $request,
		private readonly RecordedVoteWriter $writer,
		private readonly IUserSession $userSession,
		private readonly VotingRoundGuardInterface $guard,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Record each member's choice on an open roll call round (chair or secretary of its meeting).
	 *
	 * @param string $id The voting round.
	 *
	 * @return JSONResponse 200 `{votingRoundId, recorded}`, 403, or 400 / 404 / 409 naming the entries refused.
	 *
	 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-002-the-chair-or-secretary-records-each-members-choice
	 */
	#[NoAdminRequired]
	public function record(string $id): JSONResponse {
		$meetingId = $this->guard->resolveMeetingIdFromVotingRound(votingRoundId: $id);
		$guard     = $this->guard->requireChairOrSecretary(meetingId: $meetingId);
		if ($guard !== null) {
			return $guard;
		}

		$params  = $this->request->getParams();
		$entries = ($params['entries'] ?? []);
		if (is_array($entries) === false) {
			$entries = [];
		}

		$heldAt = ($params['heldAt'] ?? null);
		if (is_string($heldAt) === false) {
			$heldAt = null;
		}

		try {
			return new JSONResponse(
				data: $this->writer->record(
					votingRoundId: $id,
					entries: array_values($entries),
					heldAt: $heldAt,
					recordedBy: (string) $this->userSession->getUser()?->getUID()
				)
			);
		} catch (RecordedVotesRefusedException $refusal) {
			return new JSONResponse(
				data: ['message' => $refusal->getMessage(), 'participantIds' => $refusal->participantIds],
				statusCode: $refusal->status
			);
		}

	}//end record()
}//end class
