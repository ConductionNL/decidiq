<?php

/**
 * Decidesk Conflict-of-Interest Controller
 *
 * REST endpoints for conflict-of-interest declarations.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the ConflictOfInterest entity.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 */
class ConflictOfInterestController extends Controller {
	use GovernanceControllerTrait;

	/**
	 * Constructor for ConflictOfInterestController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param ConflictOfInterestService $conflictService The conflict service
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager Group manager (for admin bypass on the authorization guard)
	 */
	public function __construct(
		IRequest $request,
		private readonly ConflictOfInterestService $conflictService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the caller's UID for the authorization guard: null when the
	 * caller is a Nextcloud admin (admin bypass, mirroring
	 * `ProxyVoteController::resolveCallerUid()`'s convention), the UID
	 * otherwise.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 */
	private function resolveCallerUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid) === true) {
			return null;
		}

		return $uid;
	}//end resolveCallerUid()

	/**
	 * Map a service result to a JSONResponse, promoting authorization
	 * failures (service message prefixed `Forbidden:`) to `403 Forbidden`
	 * with a static message instead of the generic `422` that
	 * `respondFromResult()` would otherwise return (mirrors
	 * `ProxyVoteController::respondFromAuthorizedResult()`).
	 *
	 * @param array<string, mixed> $result The service result
	 * @param string $payloadKey Key of the success-side payload
	 * @param int $successCode HTTP code to return on success
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 */
	private function respondFromAuthorizedResult(array $result, string $payloadKey, int $successCode = Http::STATUS_OK): JSONResponse {
		if (($result['success'] ?? false) === false
			&& stripos((string)($result['message'] ?? ''), 'Forbidden:') === 0
		) {
			return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
		}

		return $this->respondFromResult(result: $result, payloadKey: $payloadKey, successCode: $successCode);
	}//end respondFromAuthorizedResult()

	/**
	 * Record a new conflict-of-interest declaration.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/proposal.md#in-scope
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function declare(): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$membershipId = (string)$this->request->getParam('membershipId', '');
		$agendaItemId = (string)$this->request->getParam('agendaItemId', '');
		$type = (string)$this->request->getParam('declarationType', 'none');
		$description = (string)$this->request->getParam('description', '');
		$severity = (string)$this->request->getParam('severity', 'material');

		if ($membershipId === '' || $agendaItemId === '') {
			return new JSONResponse(
				['message' => "Missing required parameter 'membershipId' or 'agendaItemId'."],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$callerUid = $this->resolveCallerUid();

		return $this->respondFromAuthorizedResult(
			result: $this->conflictService->declare($membershipId, $agendaItemId, $type, $description, $severity, $callerUid),
			payloadKey: 'declaration',
			successCode: Http::STATUS_CREATED
		);

	}//end declare()

	/**
	 * List active conflicts for a member's Membership (optionally narrowed
	 * to one agenda item).
	 *
	 * @param string $id UUID of the Membership (was Participant)
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/proposal.md#in-scope
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-102-only-the-member-or-an-authorized-official-may-read-a-members-conflict-declarations
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function forMember(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$agendaItemId = (string)$this->request->getParam('agendaItemId', '');
		if ($agendaItemId === '') {
			return new JSONResponse(['message' => "Missing required parameter 'agendaItemId'."], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$callerUid = $this->resolveCallerUid();
		if ($callerUid !== null
			&& $this->conflictService->isAuthorizedForMember(membershipId: $id, agendaItemId: $agendaItemId, callerUid: $callerUid) === false
		) {
			return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
		}

		$conflict = $this->conflictService->getActiveConflicts($id, $agendaItemId);
		return new JSONResponse(['conflict' => $conflict]);
	}//end forMember()

	/**
	 * Update the action-taken on an existing declaration.
	 *
	 * @param string $id UUID of the declaration
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
	 * @spec openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function recordAction(string $id): JSONResponse {
		$auth = $this->requireUserOr401(session: $this->userSession);
		if ($auth !== null) {
			return $auth;
		}

		$action = (string)$this->request->getParam('actionTaken', '');
		if ($action === '') {
			return new JSONResponse(['message' => "Missing required parameter 'actionTaken'."], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$callerUid = $this->resolveCallerUid();

		return $this->respondFromAuthorizedResult(
			result: $this->conflictService->recordAction($id, $action, $callerUid),
			payloadKey: 'declaration'
		);

	}//end recordAction()
}//end class
