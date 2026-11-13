<?php

/**
 * Decidiq Motion Coauthor Controller
 *
 * REST controller for motion co-authoring (add/remove coauthor, update text
 * with version capture, get history).
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
 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\MotionCoauthorServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for motion co-authoring endpoints.
 *
 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
 */
class MotionCoauthorController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param MotionCoauthorServiceInterface $coauthorService Co-author service
	 * @param IUserSession $userSession Current user session
	 * @param IGroupManager $groupManager Group manager (for admin checks)
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
	 */
	public function __construct(
		IRequest $request,
		private readonly MotionCoauthorServiceInterface $coauthorService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Add a coauthor to a motion.
	 *
	 * POST /api/motions/{id}/coauthors
	 *
	 * Body: { personId }
	 *
	 * @param string $id Motion UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
	 */
	public function addCoauthor(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$personId = (string)$this->request->getParam('personId', '');
		if ($personId === '') {
			return new JSONResponse(
				['message' => 'Missing required field: personId.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$callerUid = $user->getUID();
		$accessUid = $this->accessUid(callerUid: $callerUid);

		return $this->serviceAnswer(
			call: fn (): array => $this->coauthorService->addCoauthor(
				motionId: $id,
				personId: $personId,
				callerUid: $accessUid,
			),
			runtimeStatus: Http::STATUS_NOT_FOUND,
		);

	}//end addCoauthor()

	/**
	 * Remove a coauthor from a motion.
	 *
	 * DELETE /api/motions/{id}/coauthors/{personId}
	 *
	 * @param string $id Motion UUID
	 * @param string $personId Person UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
	 */
	public function removeCoauthor(string $id, string $personId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$callerUid = $user->getUID();
		$accessUid = $this->accessUid(callerUid: $callerUid);

		return $this->serviceAnswer(
			call: fn (): array => $this->coauthorService->removeCoauthor(
				motionId: $id,
				personId: $personId,
				callerUid: $accessUid,
			),
			runtimeStatus: Http::STATUS_NOT_FOUND,
		);

	}//end removeCoauthor()

	/**
	 * Update motion text with version capture and conflict detection.
	 *
	 * POST /api/motions/{id}/text
	 *
	 * Body: { text, summary }
	 *
	 * @param string $id Motion UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
	 */
	public function updateText(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$text = (string)$this->request->getParam('text', '');
		$summary = (string)$this->request->getParam('summary', '');

		if ($text === '') {
			return new JSONResponse(
				['message' => 'Missing required field: text.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$callerUid = $user->getUID();
		$accessUid = $this->accessUid(callerUid: $callerUid);

		return $this->serviceAnswer(
			call: fn (): array => $this->coauthorService->updateMotionText(
				motionId: $id,
				newText: $text,
				author: $callerUid,
				changeSummary: $summary,
				callerUid: $accessUid,
			),
			runtimeStatus: Http::STATUS_CONFLICT,
		);

	}//end updateText()

	/**
	 * Get the version history of a motion.
	 *
	 * GET /api/motions/{id}/history
	 *
	 * Access control (OWASP A01:2021 / ADR-005): per-object, in
	 * MotionCoauthorService::getHistory() via checkMotionAccess() — the same
	 * guard addCoauthor/removeCoauthor/updateText already use. A caller who is
	 * neither the proposer, a co-author, nor an admin gets 403.
	 *
	 * @param string $id Motion UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
	 */
	public function history(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		$callerUid = $user->getUID();
		$accessUid = $this->accessUid(callerUid: $callerUid);

		return $this->serviceAnswer(
			call: fn (): array => ['history' => $this->coauthorService->getHistory(
				motionId: $id,
				callerUid: $accessUid,
			)],
			runtimeStatus: Http::STATUS_NOT_FOUND,
		);

	}//end history()
	/**
	 * The uid the service checks motion access for: null for an admin, who bypasses the ownership check.
	 *
	 * @param string $callerUid The session user's uid
	 *
	 * @return string|null The uid to check, or null to skip the check
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
	 */
	private function accessUid(string $callerUid): ?string {
		if ($this->groupManager->isAdmin($callerUid) === true) {
			return null;
		}

		return $callerUid;
	}//end accessUid()

	/**
	 * Run a service call and map its refusals: not authorised is 403, a runtime failure the given status.
	 *
	 * @param callable(): array<string, mixed> $call The service call
	 * @param int $runtimeStatus The status for a RuntimeException
	 *
	 * @return JSONResponse The answer
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-9.3
	 */
	private function serviceAnswer(callable $call, int $runtimeStatus): JSONResponse {
		try {
			return new JSONResponse($call());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $runtimeStatus);
		}
	}//end serviceAnswer()
}//end class
