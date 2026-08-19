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
	 */
	public function __construct(
		IRequest $request,
		private readonly ConflictOfInterestService $conflictService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Record a new conflict-of-interest declaration.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
	 * @spec openspec/changes/model-debt-cleanup-code/proposal.md#in-scope
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

		return $this->respondFromResult(
			result: $this->conflictService->declare($membershipId, $agendaItemId, $type, $description, $severity),
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
	 * @spec openspec/changes/model-debt-cleanup-code/proposal.md#in-scope
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

		return $this->respondFromResult(
			result: $this->conflictService->recordAction($id, $action),
			payloadKey: 'declaration'
		);

	}//end recordAction()
}//end class
