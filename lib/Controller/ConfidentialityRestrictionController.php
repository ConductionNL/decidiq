<?php

/**
 * Confidentiality Restriction Controller
 *
 * The impose endpoint of a confidentiality restriction. Reading, listing and
 * the later lifecycle moves go through OpenRegister's object API (ADR-022).
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Imposes a confidentiality restriction.
 *
 * Access control (OWASP A01 / ADR-005): chairs and secretaries of a body are
 * not Nextcloud administrators, hence NoAdminRequired; the authority check on
 * the imposing body runs in ConfidentialityImposer before any object is read.
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
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ConfidentialityImposer $imposer,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/confidentiality/restrictions/impose: impose a restriction.
	 *
	 * Body: `scope`, `target`, `ground`, `imposedBy`, `imposedByBody`. 201
	 * with the restriction and the classifier outcome, 400, 401, 403, 404 or
	 * 422.
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

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end impose()
}//end class
