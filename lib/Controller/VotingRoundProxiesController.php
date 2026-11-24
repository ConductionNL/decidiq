<?php

/**
 * Decidiq Voting Round Proxies Controller
 *
 * Read-only endpoint the voting panel reads while a round is open: the
 * proxies the caller holds on the round and the one they have given, so a
 * holder can cast the vote they were given on the grantor's behalf.
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
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\ProxyHoldings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The proxies a signed-in user holds and has given on a voting round.
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 */
class VotingRoundProxiesController extends Controller {
	/**
	 * Constructor for VotingRoundProxiesController.
	 *
	 * @param IRequest      $request     The request object
	 * @param IUserSession  $userSession The user session
	 * @param ProxyHoldings $holdings    Which proxies a participant holds and gave
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ProxyHoldings $holdings,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Say which proxies the caller holds and has given on a round.
	 *
	 * GET /api/voting-rounds/{id}/proxy
	 * Response: { "participantId": "uuid"|null, "held": [{ "participantId", "displayName" }], "granted": "uuid"|null }
	 *
	 * Each held participantId is the `delegatorId` the cast endpoint takes. The
	 * caller is resolved from the session, as VotingController::cast() and
	 * proxy() do, so the answer names the same participant they act as.
	 *
	 * @param string $id The voting round UUID
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$nextcloudUid = ($this->userSession->getUser()?->getUID() ?? '');
		if ($nextcloudUid === '') {
			return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->holdings->forUser(votingRoundId: $id, nextcloudUid: $nextcloudUid));
		} catch (RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end show()
}//end class
