<?php

/**
 * Decidiq Onboarding Controller
 *
 * The secretariat's actions on member onboardings and offboardings that no
 * register dialect can express: granting and revoking groups.
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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\OnboardingProvisioningService;
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwayStaffGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Every action is open to signed-in users at the routing level and then
 * checked per record: the caller must be allowed to manage the body the
 * record belongs to ({@see PathwayStaffGuard}). An unknown record answers
 * 404 before the check; a record without a body is refused. The actions
 * change state, so they keep Nextcloud's CSRF check.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
class OnboardingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request      The request.
	 * @param IUserSession                  $userSession  The user session.
	 * @param PathwayStaffGuard             $guard        Who may manage a body's pathways.
	 * @param OnboardingProvisioningService $provisioning Granting and revoking groups.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PathwayStaffGuard $guard,
		private readonly OnboardingProvisioningService $provisioning,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Grant a joining member the groups of their role on the body.
	 *
	 * @param string $id The member onboarding uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	#[NoAdminRequired]
	public function assignGroups(string $id): JSONResponse {
		$denied = $this->deny(schema: OnboardingProvisioningService::ONBOARDING, id: $id);
		if ($denied !== null) {
			return $denied;
		}

		return $this->respond(result: $this->provisioning->provision(onboardingId: $id, actor: $this->uid()));
	}//end assignGroups()

	/**
	 * Take a leaving member's groups on the body away and end the membership.
	 *
	 * @param string $id The member offboarding uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	#[NoAdminRequired]
	public function revokeGroups(string $id): JSONResponse {
		$denied = $this->deny(schema: OnboardingProvisioningService::OFFBOARDING, id: $id);
		if ($denied !== null) {
			return $denied;
		}

		return $this->respond(result: $this->provisioning->revoke(offboardingId: $id, actor: $this->uid()));
	}//end revokeGroups()

	/**
	 * The refusal for a caller who may not manage the record's body, or null.
	 *
	 * @param string $schema The record's schema.
	 * @param string $id     The record uuid.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function deny(string $schema, string $id): ?JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(['message' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		$bodyId = $this->provisioning->bodyOf(schema: $schema, id: $id);
		if ($bodyId === null) {
			return new JSONResponse(['message' => 'Not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->guard->mayManage(userId: $uid, bodyId: $bodyId) === false) {
			return new JSONResponse(
				['message' => 'Only the secretariat of this body may do this.'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end deny()

	/**
	 * The response for a pathway result.
	 *
	 * @param PathwayResult $result The result.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function respond(PathwayResult $result): JSONResponse {
		return new JSONResponse($result->toArray(), $result->httpStatus());
	}//end respond()

	/**
	 * The signed-in uid, or empty.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end uid()

}//end class
