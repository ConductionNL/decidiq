<?php

/**
 * Decidiq Onboarding Controller
 *
 * The step update of a member onboarding or offboarding checklist. Only the
 * body's secretary, chair or vice-chair (its signatory scope) or an
 * administrator may mark a step; a plain member gets 403 and nothing changes.
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
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\OnboardingStepRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\GovernanceScopeGuardInterface;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\OnboardingChecklistService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Marks checklist steps for the body's secretariat.
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
 */
class OnboardingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request      The request.
	 * @param IUserSession                  $userSession  The caller.
	 * @param IGroupManager                 $groupManager The administrator check.
	 * @param GovernanceScopeGuardInterface $scopeGuard   The body's signatory scope.
	 * @param OnboardingChecklistService    $checklist    Marks the step.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly GovernanceScopeGuardInterface $scopeGuard,
		private readonly OnboardingChecklistService $checklist,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * PUT /api/onboarding/{kind}/{id}/steps/{index}: mark a step completed or skipped.
	 *
	 * @param string $kind  `onboarding` or `offboarding`.
	 * @param string $id    The record's uuid.
	 * @param int    $index The step's position.
	 *
	 * @return JSONResponse The record as saved, or the refusal.
	 *
	 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
	 */
	#[NoAdminRequired]
	public function updateStep(string $kind, string $id, int $index): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$record = $this->checklist->record(kind: $kind, id: $id);
			if ($this->maySteer(userId: $user->getUID(), bodyId: OnboardingChecklistService::bodyOf(kind: $kind, record: $record)) === false) {
				return new JSONResponse(['message' => 'Only the secretary, the chair or an administrator may change this checklist.'], Http::STATUS_FORBIDDEN);
			}

			$outcome = $this->request->getParam('status', '');
			if (is_string($outcome) === false) {
				$outcome = '';
			}

			$saved = $this->checklist->completeStep(kind: $kind, id: $id, index: $index, outcome: $outcome);
		} catch (OnboardingStepRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		return new JSONResponse($saved);
	}//end updateStep()

	/**
	 * Whether the caller is an administrator or in the body's signatory scope.
	 *
	 * @param string $userId The caller.
	 * @param string $bodyId The record's body.
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
