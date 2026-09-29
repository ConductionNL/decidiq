<?php

/**
 * Decidiq Vote Breakdown Controller
 *
 * GET /api/voting-rounds/{id}/breakdown: a round's result per member and per
 * faction, for anyone who can read the round. A secret round gives totals
 * only.
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
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\VoteBreakdownService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A round's result per member and per faction.
 *
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
class VoteBreakdownController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param VoteBreakdownService $breakdowns Builds the breakdown
	 * @param IUserSession $userSession User session
	 */
	public function __construct(
		IRequest $request,
		private readonly VoteBreakdownService $breakdowns,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The breakdown of one round. Whether the round can be read is
	 * OpenRegister's call: the round is looked up with the user's own rights,
	 * so a round they cannot see is not found.
	 *
	 * @param string $id The voting round UUID
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$result = $this->breakdowns->forRound(roundId: $id);
		if ($result === null) {
			return new JSONResponse(['message' => 'Voting round not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end show()
}//end class
