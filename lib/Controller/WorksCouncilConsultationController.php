<?php

/**
 * Decidiq Works Council Consultation Controller
 *
 * Writes the formal advice or consent document of a WOR traject. Reading and
 * editing the traject itself goes through OpenRegister's object API; this
 * controller only owns the document, which no schema dialect can render.
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
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\ConsultationResponseRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\ConsultationResponseDocumentService;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The response document of a works-council consultation.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
 */
class WorksCouncilConsultationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                            $request      The request.
	 * @param IUserSession                        $userSession  The caller.
	 * @param IGroupManager                       $groupManager The administrator check.
	 * @param GovernanceScopeGuard                $scopeGuard   The works council's signatory scope.
	 * @param ConsultationResponseDocumentService $documents    Writes the document.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly GovernanceScopeGuard $scopeGuard,
		private readonly ConsultationResponseDocumentService $documents,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/works-council-consultations/{id}/response-document: write the response document.
	 *
	 * Body: `format`, `markdown` (default) or `pdf`. Only the works
	 * council's chair or secretary (its signatory scope) or an administrator
	 * may write it.
	 *
	 * @param string $id The traject's uuid.
	 *
	 * @return JSONResponse The stored document, or the refusal.
	 *
	 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
	 */
	#[NoAdminRequired]
	public function generateResponseDocument(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$format = $this->request->getParam('format', 'markdown');
		if (is_string($format) === false) {
			$format = '';
		}

		try {
			$traject = $this->documents->traject(id: $id);
			if ($this->maySteer(userId: $user->getUID(), bodyId: (string)($traject['governanceBody'] ?? '')) === false) {
				return new JSONResponse(
					['message' => 'Only the chair or secretary of the works council, or an administrator, may write the response document.'],
					Http::STATUS_FORBIDDEN
				);
			}

			return new JSONResponse($this->documents->generate(id: $id, format: $format), Http::STATUS_CREATED);
		} catch (ConsultationResponseRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}//end generateResponseDocument()

	/**
	 * Whether the caller is an administrator or in the works council's signatory scope.
	 *
	 * @param string $userId The caller.
	 * @param string $bodyId The works council.
	 *
	 * @return bool
	 */
	private function maySteer(string $userId, string $bodyId): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->scopeGuard->isInBodyScope(userId: $userId, bodyId: $bodyId, scope: GovernanceScopeGuard::SCOPE_SIGNATORY);
	}//end maySteer()
}//end class
