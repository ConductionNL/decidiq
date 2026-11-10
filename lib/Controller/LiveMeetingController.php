<?php

/**
 * Decidiq Live Meeting Controller
 *
 * Controller for live meeting operations such as recording decisions during
 * an active meeting via the live decision panel.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\LiveDecisionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for live meeting operations.
 *
 * Provides the endpoint for recording decisions during an active meeting.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 */
class LiveMeetingController extends Controller {
	/**
	 * Constructor for LiveMeetingController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param LiveDecisionService $liveDecisionService The live decision service
	 * @param IUserSession $userSession The current user session
	 * @param IGroupManager $groupManager Group manager for admin checks
	 * @param ParticipantResolver $participantResolver Participant resolver for meeting-based access checks
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 */
	public function __construct(
		IRequest $request,
		private LiveDecisionService $liveDecisionService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ParticipantResolver $participantResolver,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Verify that the caller is an NC admin or holds a chair/secretary role for the meeting.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return JSONResponse|null Null if authorised, a 403/401 JSONResponse otherwise.
	 */
	private function requireChairOrAdmin(string $meetingId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		if ($this->participantResolver->hasRole(
			meetingId: $meetingId,
			nextcloudUid: $userId,
			roles: ['chair', 'secretary'],
		) === true
		) {
			return null;
		}

		return new JSONResponse(
			['error' => 'Forbidden: chair or secretary role required for this meeting.'],
			Http::STATUS_FORBIDDEN
		);

	}//end requireChairOrAdmin()

	/**
	 * Record a decision during an active meeting.
	 *
	 * POST /api/meetings/{meetingId}/live-decisions
	 *
	 * Body: { "title": string, "text": string, "outcome": string, "legalBasis"?: string,
	 *        "decisionType"?: string, "agendaItem"?: string }
	 *
	 * Returns 200 with the created Decision object on success.
	 * Returns 400 when required fields are missing.
	 * Returns 401 when not authenticated.
	 * Returns 403 when the caller does not hold a chair or secretary role for the meeting.
	 * Returns 404 when the Meeting is not found.
	 * Returns 409 when the Meeting is not in 'opened' state.
	 *
	 * @param string $meetingId The Meeting ID
	 *
	 * @return JSONResponse The created Decision object
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-003-a-decision-is-recorded-when-it-is-taken
	 */
	#[NoAdminRequired]
	public function recordLiveDecision(string $meetingId): JSONResponse {
		$denied = $this->requireChairOrAdmin(meetingId: $meetingId);
		if ($denied !== null) {
			return $denied;
		}

		try {
			$decisionData = $this->liveDecisionData();
			if ($decisionData === null) {
				return new JSONResponse(
					['error' => 'Missing required fields: title, text, outcome'],
					400
				);
			}

			$decisionSlug = $this->liveDecisionService->recordDecision(
				$meetingId,
				$decisionData
			);

			return new JSONResponse(
				[
					'slug' => $decisionSlug,
					'message' => 'Decision recorded successfully',
				]
			);
		} catch (MissingObjectException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 404);
		} catch (\Exception $e) {
			return self::failureResponse(exception: $e);
		}//end try
	}//end recordLiveDecision()

	/**
	 * The decision a live-decision request carries.
	 *
	 * @return array<string,mixed>|null The decision data, or null when a required field is empty
	 */
	private function liveDecisionData(): ?array {
		$decision = [
			'title' => $this->request->getParam('title'),
			'text' => $this->request->getParam('text'),
			'outcome' => $this->request->getParam('outcome'),
		];
		if (self::lacksRequiredField(decision: $decision) === true) {
			return null;
		}

		return $decision + [
			'legalBasis' => $this->request->getParam('legalBasis'),
			'decisionType' => $this->request->getParam('decisionType'),
			'agendaItem' => $this->request->getParam('agendaItem'),
		];
	}//end liveDecisionData()

	/**
	 * Whether a decision misses its title, text or outcome.
	 *
	 * @param array<string,mixed> $decision The title, text and outcome
	 *
	 * @return bool True when any of them is empty
	 */
	private static function lacksRequiredField(array $decision): bool {
		return empty($decision['title']) === true || empty($decision['text']) === true || empty($decision['outcome']) === true;
	}//end lacksRequiredField()

	/**
	 * The response for a failed recording: 409 passes its message, anything else is a generic 500.
	 *
	 * @param \Exception $exception The failure
	 *
	 * @return JSONResponse The error response
	 */
	private static function failureResponse(\Exception $exception): JSONResponse {
		if ((int)$exception->getCode() === 409) {
			return new JSONResponse(['error' => $exception->getMessage()], 409);
		}

		return new JSONResponse(['error' => 'Internal server error.'], 500);
	}//end failureResponse()
}//end class
