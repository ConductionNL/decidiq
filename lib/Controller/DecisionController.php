<?php

/**
 * Decidesk Decision Controller
 *
 * Controller for Decision-specific operations such as server-side publication
 * enforcement (OWASP A01 — Broken Access Control).
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\DecisionLifecycleService;
use OCA\Decidesk\Service\DecisionPublicationService;
use OCA\Decidesk\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for Decision-specific operations.
 *
 * Provides a dedicated publish endpoint that enforces server-side admin checks
 * and validates outcome/publication state before persisting — preventing the
 * frontend-only guard bypass described in OWASP A01:2021 / ADR-005.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 */
class DecisionController extends Controller {

	/**
	 * Owns the server-side publication pipeline (load, validate, persist, activity).
	 *
	 * @var DecisionPublicationService
	 */
	private readonly DecisionPublicationService $publicationService;

	/**
	 * Constructor for DecisionController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param ContainerInterface $container DI container (lazy-loads OpenRegister services for publication)
	 * @param IUserSession $userSession The current user session
	 * @param IGroupManager $groupManager Group manager for admin checks
	 * @param LoggerInterface $logger The logger (handed to the publication service)
	 * @param DecisionLifecycleService $lifecycleService Guarded decision state machine
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 */
	public function __construct(
		IRequest $request,
		ContainerInterface $container,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		LoggerInterface $logger,
		private DecisionLifecycleService $lifecycleService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->publicationService = new DecisionPublicationService(container: $container, logger: $logger);

	}//end __construct()

	/**
	 * Apply a lifecycle transition to a decision.
	 *
	 * POST /api/decisions/{decisionId}/transition
	 *
	 * Expects JSON body: { "action": "<propose|deliberate|openVoting|decide|enact|archive>",
	 * "comment": "<optional audit comment>" }
	 *
	 * Access control (OWASP A01 / ADR-005): per-object authorization is
	 * OpenRegister ObjectService RBAC inside DecisionLifecycleService —
	 * find() returns null without read access on THIS decision (404),
	 * saveObject() throws without write access — plus the chair-only domain
	 * gate (fail closed). Chairs/clerks are not NC admins, so no
	 * IGroupManager::isAdmin() gate here (same approved pattern as
	 * MeetingController::lifecycle).
	 *
	 * Returns 200 with the updated decision, 401 unauthenticated, 422 when
	 * the transition is invalid/forbidden or the decision is not accessible.
	 *
	 * @param string $decisionId UUID of the Decision object
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function transition(string $decisionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$action = (string)$this->request->getParam('action', '');
		if ($action === '') {
			return new JSONResponse(
				['message' => "Missing required parameter 'action'."],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$comment = (string)$this->request->getParam('comment', '');

		// Per-object authorization happens inside the service via ObjectService
		// RBAC (find/saveObject) + the chair-only gate — see class docblock.
		$result = $this->lifecycleService->transition(
			decisionId: $decisionId,
			action: $action,
			currentUserId: $user->getUID(),
			comment: $comment
		);

		if ($result['success'] === false) {
			return new JSONResponse(
				['message' => $result['message']],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse($result);
	}//end transition()

	/**
	 * Return the current lifecycle state and allowed next transitions for a
	 * decision (consumed by the detail-view Lifecycle tab).
	 *
	 * GET /api/decisions/{decisionId}/transitions
	 *
	 * Access control (OWASP A01 / ADR-005): per-object read authorization is
	 * OpenRegister ObjectService RBAC inside DecisionLifecycleService —
	 * find() returns null for objects the caller may not read, which renders
	 * as 404 here (no UUID probing).
	 *
	 * @param string $decisionId UUID of the Decision object
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function transitions(string $decisionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Per-object read authorization happens inside the service via
		// ObjectService RBAC (find returns null without read access).
		$result = $this->lifecycleService->getAvailableTransitions(decisionId: $decisionId);

		if ($result['success'] === false) {
			return new JSONResponse(
				['message' => $result['message']],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($result);
	}//end transitions()

	/**
	 * Publish a Decision server-side.
	 *
	 * POST /api/decisions/{decisionId}/publish
	 *
	 * Validates server-side that outcome='adopted' and isPublished='internal' before
	 * persisting — preventing frontend-only guard bypass (OWASP A01 / ADR-005).
	 * Requires Nextcloud admin role to match the governance-level protection on
	 * the Minutes lifecycle.
	 *
	 * Sets isPublished to 'public' (p3-citizen-participation enum) so the decision
	 * becomes visible in the citizen transparency portal and via the ORI API.
	 *
	 * Returns 200 with the updated Decision object on success.
	 * Returns 401 when not authenticated.
	 * Returns 403 when the caller is not a Nextcloud administrator.
	 * Returns 404 when the Decision object is not found.
	 * Returns 422 when outcome ≠ 'adopted' or isPublished is not 'internal'.
	 * Returns 503 when OpenRegister is unavailable.
	 *
	 * @param string $decisionId UUID of the Decision object
	 *
	 * @return JSONResponse
	 *
	 * AUTH POSTURE. This carried `#[NoAdminRequired]` — "any authenticated
	 * user" — while the very next statements refuse anyone who is not an
	 * administrator, and the docblock above already said "Requires Nextcloud
	 * admin role". The attribute is what a reader, an auditor and the
	 * framework's middleware all see first, so a contradiction there is the
	 * kind that gets believed. `AuthorizedAdminSetting` is this repo's existing
	 * idiom for an admin-only REST endpoint (MemberImportController,
	 * SettingsController) and additionally lets an admin DELEGATE publication
	 * authority to a group rather than hardcoding "is a server admin". The
	 * in-body `isAdmin()` check is kept as defence in depth for any non-HTTP
	 * caller that bypasses the middleware.
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function publish(string $decisionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Unauthenticated.'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Only administrators may publish decisions (OWASP A01 — Broken Access Control).
		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['message' => 'Forbidden: only administrators may publish decisions.'],
				Http::STATUS_FORBIDDEN
			);
		}

		// Loading, the adopted/unpublished guard, the persist and the fail-soft
		// activity event all live in DecisionPublicationService; it returns the
		// status + payload this endpoint renders verbatim.
		$result = $this->publicationService->publish(
			decisionId: $decisionId,
			actorUid: $user->getUID()
		);

		return new JSONResponse($result['data'], $result['status']);
	}//end publish()
}//end class
