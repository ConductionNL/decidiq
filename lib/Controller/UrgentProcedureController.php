<?php

/**
 * Urgent Procedure Controller
 *
 * The two action endpoints of the urgent decision procedure: declare a
 * decision urgent, and open its expedited written round. The frontend keeps
 * reading and writing the objects themselves through OpenRegister (ADR-022).
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\ExpeditedRoundService;
use OCA\Decidiq\Service\UrgencyDeclarationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Declares urgency and opens the expedited round.
 *
 * Access control (OWASP A01 / ADR-005): both actions read the decision with
 * the caller's OpenRegister object rights (no read, 404) and then authorise
 * the caller in UrgencyTriggerGuard against the body's urgency policy: the
 * resolved meeting chair or an allowed role, failing closed when no policy,
 * meeting or role resolves. Chairs and secretaries are not Nextcloud
 * administrators, hence NoAdminRequired with the guard in the services.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
 */
class UrgentProcedureController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request      The request.
	 * @param IUserSession              $userSession  The caller.
	 * @param UrgencyDeclarationService $declarations Runs the guarded declaration.
	 * @param ExpeditedRoundService     $rounds       Opens the expedited round.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly UrgencyDeclarationService $declarations,
		private readonly ExpeditedRoundService $rounds,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Declare a decision urgent.
	 *
	 * POST /api/decisions/{decisionId}/urgency with `reason` and an optional
	 * `ratifyingBody`. 200 with the decision and its ratification, 401, 403
	 * (not the chair or an allowed role), 404, 409 (already urgent), 422 (no
	 * policy for the body, or no reason).
	 *
	 * @param string $decisionId The decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	#[NoAdminRequired]
	public function declareUrgency(string $decisionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->request->getParam('ratifyingBody');
		if (is_string($body) === false) {
			$body = null;
		}

		// Object rights (find) and the urgency guard run inside the service.
		$result = $this->declarations->declare(
			decisionId: $decisionId,
			userId: $user->getUID(),
			reason: (string)$this->request->getParam('reason', ''),
			bodyOverride: $body
		);

		return new JSONResponse($result['data'], $result['status']);
	}//end declareUrgency()

	/**
	 * Open the expedited written round of an urgent decision.
	 *
	 * POST /api/decisions/{decisionId}/expedited-round with
	 * `responseDeadlineHours`. 201 with the round, 401, 403, 404, 422 (outside
	 * the configured bounds, not urgent, no policy or no open decisive stage).
	 *
	 * @param string $decisionId The decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
	 */
	#[NoAdminRequired]
	public function expeditedRound(string $decisionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Object rights (find) and the urgency guard run inside the service.
		$result = $this->rounds->open(
			decisionId: $decisionId,
			userId: $user->getUID(),
			hours: $this->request->getParam('responseDeadlineHours')
		);

		return new JSONResponse($result['data'], $result['status']);
	}//end expeditedRound()
}//end class
