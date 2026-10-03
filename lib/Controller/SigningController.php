<?php

/**
 * Decidiq Signing Controller
 *
 * Send for signature on minutes, a meeting's decision list and motions, and
 * collect the signed copy. Only the signatories of the body the record
 * belongs to may do either (the same scope the minutes QES flow uses).
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
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\SigningRoundService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Send for signature and collect the signed copy.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class SigningController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param SigningRoundService $rounds Sends records for signature and stores the signed copy
	 * @param IUserSession $userSession User session
	 * @param GovernanceScopeGuard $scopeGuard Consumes the OR-projected signatory scope
	 */
	public function __construct(
		IRequest $request,
		private readonly SigningRoundService $rounds,
		private readonly IUserSession $userSession,
		private readonly GovernanceScopeGuard $scopeGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send a record for signature with its signers in order.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId UUID of the record
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function send(string $subjectType, string $subjectId): JSONResponse {
		$refusal = $this->refusal(subjectType: $subjectType, subjectId: $subjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->rounds->send(subjectType: $subjectType, subjectId: $subjectId);
		if ($result['success'] === false) {
			return new JSONResponse(['message' => $result['message']], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(
			[
				'message' => $result['message'],
				'requestId' => ($result['requestId'] ?? null),
				'signingUrl' => ($result['signingUrl'] ?? null),
			],
			Http::STATUS_ACCEPTED
		);
	}//end send()

	/**
	 * Ask the signing service where the round stands; once signed, the
	 * signed copy is stored on the record.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId UUID of the record
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function collect(string $subjectType, string $subjectId): JSONResponse {
		$refusal = $this->refusal(subjectType: $subjectType, subjectId: $subjectId);
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->rounds->collect(subjectType: $subjectType, subjectId: $subjectId);

		return new JSONResponse(
			[
				'status' => $result['status'],
				'message' => $result['message'],
				'signedCopy' => ($result['signedCopy'] ?? null),
			]
		);
	}//end collect()

	/**
	 * The response that stops the request, or null when it may go ahead:
	 * a login, a signable record type and the body's signatory scope.
	 *
	 * @param string $subjectType minutes, decision-list or motion
	 * @param string $subjectId UUID of the record
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(string $subjectType, string $subjectId): ?JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$schema = (SigningRoundService::SUBJECTS[$subjectType] ?? null);
		if ($schema === null) {
			return new JSONResponse(['message' => 'This record cannot be sent for signature.'], Http::STATUS_NOT_FOUND);
		}

		$userId = (string)$this->userSession->getUser()->getUID();
		if ($this->scopeGuard->isSignatoryForSubject(userId: $userId, schema: $schema, subjectId: $subjectId) === false) {
			return new JSONResponse(
				['message' => 'Only the signatories of this body can send it for signature.'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refusal()
}//end class
