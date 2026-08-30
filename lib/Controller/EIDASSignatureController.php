<?php

/**
 * Decidiq eIDAS Signature Controller
 *
 * Thin REST surface around the IEIDASSignatureService. Endpoints map
 * one-to-one onto the four interface methods so external integrations
 * (kassakoppeling, MFA wrappers, supervisor desks) can drive the signing
 * flow without learning the openconnector source name.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\IEIDASSignatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the eIDAS QES adapter.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 */
class EIDASSignatureController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param IEIDASSignatureService $signatureService eIDAS adapter
	 * @param IUserSession $userSession User session
	 * @param GovernanceScopeGuard $scopeGuard Consumes the OR-projected signatory scope (R-4)
	 */
	public function __construct(
		IRequest $request,
		private readonly IEIDASSignatureService $signatureService,
		private readonly IUserSession $userSession,
		private readonly GovernanceScopeGuard $scopeGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Initialise a QES signing request for a Minutes record.
	 *
	 * @param string $minutesId UUID of the minutes record
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function initiate(string $minutesId): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		// R-4 guard: only members of the linked GovernanceBody's OR-projected
		// signatory scope (chair/chairman/vice-chairman/secretary) may initiate
		// a QES signing request. Enforcement consumes the OpenRegister-owned
		// scope (consume-or-rbac-authorization); fail-closed.
		$userId = (string)$this->userSession->getUser()->getUID();
		if ($this->scopeGuard->canInitiateSigning(userId: $userId, minutesId: $minutesId) === false) {
			return new JSONResponse(
				['message' => 'You are not authorised to initiate a signing request for these minutes.'],
				Http::STATUS_FORBIDDEN
			);
		}

		$signatories = (array)$this->request->getParam('signatories', []);
		$signatories = array_values(array_map('strval', $signatories));

		$result = $this->signatureService->initializeSigningRequest($minutesId, $signatories);
		if ($result['success'] === false) {
			return new JSONResponse(
				['message' => $result['message']],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			[
				'requestId' => $result['requestId'],
				'signingUrl' => $result['signingUrl'],
				'message' => $result['message'],
			],
			Http::STATUS_ACCEPTED
		);

	}//end initiate()

	/**
	 * Verify a signature blob against the EU Trusted List.
	 *
	 * Authorization: the caller must be in the OR-projected signatory scope of
	 * the GovernanceBody that owns these minutes, or be a Nextcloud admin (the
	 * admin bypass lives inside the scope projection). This is the same rule
	 * `initiate()` enforces — the endpoint is routed per-Minutes and belongs to
	 * the same signing flow — and it is required because the response carries
	 * the signer's `certificateThumbprint`, which under eIDAS identifies a
	 * natural person. `requireUserOr401()` above answers "is anyone logged in",
	 * which is authentication, not authorization.
	 *
	 * @param string $minutesId UUID of the minutes record (forensic context)
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-102-only-a-body-signatory-may-verify-a-signature-on-a-minutes-record
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function verify(string $minutesId): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$userId = (string)$this->userSession->getUser()->getUID();
		if ($this->scopeGuard->isSignatoryForMinutes(userId: $userId, minutesId: $minutesId) === false) {
			return new JSONResponse(
				['message' => 'You are not authorised to verify signatures on these minutes.'],
				Http::STATUS_FORBIDDEN
			);
		}

		$requestId = (string)$this->request->getParam('requestId', '');
		$signature = (string)$this->request->getParam('signature', '');
		if ($requestId === '' || $signature === '') {
			return new JSONResponse(
				['message' => "Missing required parameter 'requestId' or 'signature'."],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$result = $this->signatureService->verifySignature($requestId, $signature);

		return new JSONResponse(
			[
				'valid' => $result['valid'],
				'certificateThumbprint' => $result['certificateThumbprint'],
				'timestamp' => $result['timestamp'],
				'message' => $result['message'],
				'minutesId' => $minutesId,
			]
		);

	}//end verify()

	/**
	 * Finalise a signed Minutes record (collect signatures, archive PDF).
	 *
	 * Authorization: the caller must be in the OR-projected signatory scope of
	 * the GovernanceBody that owns these minutes, or be a Nextcloud admin. This
	 * is the highest-stakes endpoint of the flow — `finalizeMinutes()` writes
	 * `pdfArchiveReference`, `hashSha256`, `signingCompletionDate`,
	 * `eidasSignatureLevel = QES`, `version = signed` and `signedBy` onto the
	 * Minutes row, resolves the `method=signature` DecisionStage to
	 * `outcome=adopted`, and appends a `signature` audit entry. Starting the
	 * flow already required this authority (`initiate()`); completing it must
	 * require no less. The guard runs BEFORE the service is reached, so a
	 * refusal writes nothing.
	 *
	 * @param string $minutesId UUID of the minutes record
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function finalize(string $minutesId): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$userId = (string)$this->userSession->getUser()->getUID();
		if ($this->scopeGuard->isSignatoryForMinutes(userId: $userId, minutesId: $minutesId) === false) {
			return new JSONResponse(
				['message' => 'You are not authorised to finalise the signing of these minutes.'],
				Http::STATUS_FORBIDDEN
			);
		}

		$signatures = (array)$this->request->getParam('signatures', []);

		$result = $this->signatureService->finalizeMinutes($minutesId, $signatures);
		if ($result['success'] === false) {
			return new JSONResponse(
				['message' => $result['message']],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			[
				'pdfArchiveReference' => $result['pdfArchiveReference'],
				'hashSha256' => $result['hashSha256'],
				'message' => $result['message'],
			]
		);

	}//end finalize()

	/**
	 * Report a certificate chain's trust status against the EU Trusted List
	 * (informational pre-flight for the signing UI — the authoritative chain
	 * validation happens server-side inside finalizeMinutes(), which REQ-SIG-101
	 * guards).
	 *
	 * Deliberately app-wide: unlike its three siblings this endpoint is routed
	 * without a Minutes id (`POST /api/eidas/validate-cert`) and accepts no
	 * caller-supplied object identifier at all. Its only input is a certificate
	 * SHA-256 thumbprint and its only output is `valid` / `issuer` /
	 * `trustListLevel` — facts published on the EU Trusted List. No Decidiq
	 * object is reachable through it and nothing it returns is derived from app
	 * data, so there is no per-object rule to enforce and narrowing it would
	 * invent an authorization rule no spec states. Both the action and the
	 * openconnector source slug are fixed constants, so it is not a
	 * request-forgery surface either. Residual and accepted: an authenticated
	 * caller can drive repeated outbound calls to the configured QSP — a
	 * rate/cost concern, not an access-control one.
	 *
	 * @NoAdminRequired
	 * @no-admin-idor-exempt Takes no caller-supplied object identifier — the only input is a
	 *   certificate SHA-256 thumbprint and the response is sourced entirely from the public EU
	 *   Trusted List, so no Decidiq object is reachable and nothing app-owned is disclosed.
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
	 * @spec openspec/changes/signature-and-outcome-authorization-guard/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-103-certificate-trust-status-lookup-is-a-deliberately-app-wide-authenticated-read
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function certStatus(): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$thumbprint = (string)$this->request->getParam('certificateThumbprint', '');
		if ($thumbprint === '') {
			return new JSONResponse(
				['message' => "Missing required parameter 'certificateThumbprint'."],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$result = $this->signatureService->validateCertificateChain($thumbprint);

		return new JSONResponse(
			[
				'valid' => $result['valid'],
				'issuer' => $result['issuer'],
				'trustListLevel' => $result['trustListLevel'],
				'message' => $result['message'],
			]
		);

	}//end certStatus()
}//end class
