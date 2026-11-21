<?php

/**
 * Decidiq ChangeRecommendationController
 *
 * Accept and reject for a recommended text change to a motion. Only the chair
 * or secretary of the motion's meeting may decide one; without a meeting the
 * global chair group (or an administrator) decides, the same rule the motion
 * routes use.
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
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Exception\ChangeRecommendationRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\ChangeRecommendationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MotionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\VotingRoundGuardInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Accept or reject a change recommendation.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
 */
class ChangeRecommendationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                             $request         The request
	 * @param ChangeRecommendationServiceInterface $recommendations Accepts and rejects
	 * @param MotionServiceInterface               $motions         Resolves a motion's meeting
	 * @param VotingRoundGuardInterface            $guard           The chair-or-secretary check
	 * @param IUserSession                         $userSession     The signed-in user
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ChangeRecommendationServiceInterface $recommendations,
		private readonly MotionServiceInterface $motions,
		private readonly VotingRoundGuardInterface $guard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Accept a recommendation into the motion text.
	 *
	 * @param string $id The recommendation uuid
	 *
	 * @return JSONResponse 200 with the recommendation and motion, 403, 404, 409 or 422.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		$refusal = $this->requireDecider(recommendationId: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse($this->recommendations->accept(recommendationId: $id, deciderUid: $this->uid()));
		} catch (ChangeRecommendationRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}//end accept()

	/**
	 * Reject a recommendation; the motion text is not changed.
	 *
	 * @param string $id The recommendation uuid
	 *
	 * @return JSONResponse 200 with the recommendation, 403, 404 or 409.
	 *
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-004-a-rejected-recommendation-changes-nothing
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$refusal = $this->requireDecider(recommendationId: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse(['recommendation' => $this->recommendations->reject(recommendationId: $id, deciderUid: $this->uid())]);
		} catch (ChangeRecommendationRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}//end reject()

	/**
	 * Null when the caller is chair or secretary of the recommendation's
	 * meeting, else the refusal. An unknown recommendation is 404.
	 *
	 * @param string $recommendationId The recommendation uuid
	 *
	 * @return JSONResponse|null
	 */
	private function requireDecider(string $recommendationId): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$motionId = $this->recommendations->motionIdOf(recommendationId: $recommendationId);
		if ($motionId === null) {
			return new JSONResponse(['message' => 'Recommendation not found'], Http::STATUS_NOT_FOUND);
		}

		return $this->guard->requireChairOrSecretary(meetingId: $this->motions->resolveMeetingId(motionId: $motionId));
	}//end requireDecider()

	/**
	 * The signed-in user's id.
	 *
	 * @return string
	 */
	private function uid(): string {
		return (string) ($this->userSession->getUser()?->getUID() ?? '');
	}//end uid()
}//end class
