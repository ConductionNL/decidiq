<?php

/**
 * Confidentiality Restriction Controller
 *
 * The impose, ratify and lift endpoints of a confidentiality restriction. Reading and
 * listing go through OpenRegister's object API (ADR-022).
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\ConfidentialityImposer;
use OCA\Decidiq\Service\ConfidentialityLifter;
use OCA\Decidiq\Service\ConfidentialityRatifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Imposes, ratifies and lifts a confidentiality restriction.
 *
 * Access control (OWASP A01 / ADR-005): chairs and secretaries of a body are
 * not Nextcloud administrators, hence NoAdminRequired; the authority check on
 * the imposing body runs in ConfidentialityImposer before any object is read,
 * and in ConfidentialityRatifier and ConfidentialityLifter right after the
 * restriction is read with the caller's own rights.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
class ConfidentialityRestrictionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request.
	 * @param IUserSession           $userSession The caller.
	 * @param ConfidentialityImposer $imposer     Checks the caller, then records the restriction.
	 * @param ConfidentialityLifter  $lifter      Checks the caller, then lifts the restriction.
	 * @param ConfidentialityRatifier $ratifier   Places it for ratification, and records the ratification.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ConfidentialityImposer $imposer,
		private readonly ConfidentialityLifter $lifter,
		private readonly ConfidentialityRatifier $ratifier,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/confidentiality/restrictions/impose: impose a restriction.
	 *
	 * Body: `scope`, `target`, `ground`, `imposedBy`, `imposedByBody`, and
	 * optionally `ratifyingBody` and `ratificationDeadline`. 201 with the
	 * restriction, the classifier outcome and the ratification placement,
	 * 400, 401, 403, 404 or 422.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	#[NoAdminRequired]
	public function impose(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$params = [];
		foreach (['scope', 'target', 'ground', 'imposedBy', 'imposedByBody'] as $key) {
			$params[$key] = $this->request->getParam($key);
		}

		try {
			$result = $this->imposer->impose(userId: $user->getUID(), params: $params);
		} catch (ConfidentialityImposeRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		$placement = $this->ratifier->place(
			restriction: $result['restriction'],
			params: [
				'ratifyingBody'        => $this->request->getParam('ratifyingBody'),
				'ratificationDeadline' => $this->request->getParam('ratificationDeadline'),
			]
		);

		return new JSONResponse(
			[
				'restriction'  => $placement['restriction'],
				'classifier'   => $result['classifier'],
				'ratification' => $placement['status'],
			],
			Http::STATUS_CREATED
		);
	}//end impose()

	/**
	 * POST /api/confidentiality/restrictions/{id}/ratify: record the ratifying decision.
	 *
	 * Body: `ratificationDecision`. 200 with the restriction, 400, 401, 403,
	 * 404, 409 (not imposed, or its ground needs no ratification) or 422.
	 *
	 * @param string $id The restriction.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	#[NoAdminRequired]
	public function ratify(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$restriction = $this->ratifier->ratify(
				userId: $user->getUID(),
				restrictionId: $id,
				params: ['ratificationDecision' => $this->request->getParam('ratificationDecision')]
			);
		} catch (ConfidentialityImposeRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse(['restriction' => $restriction], Http::STATUS_OK);
	}//end ratify()

	/**
	 * POST /api/confidentiality/restrictions/{id}/lift: lift a restriction.
	 *
	 * Body: `dissolutionDecision`, optional `liftingConditions`. 200 with the
	 * restriction and the classifier outcome, 400, 401, 403, 404, 409 or 422.
	 *
	 * @param string $id The restriction.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
	 */
	#[NoAdminRequired]
	public function lift(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$params = [
			'dissolutionDecision' => $this->request->getParam('dissolutionDecision'),
			'liftingConditions'   => $this->request->getParam('liftingConditions'),
		];

		try {
			$result = $this->lifter->lift(userId: $user->getUID(), restrictionId: $id, params: $params);
		} catch (ConfidentialityImposeRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end lift()
}//end class
