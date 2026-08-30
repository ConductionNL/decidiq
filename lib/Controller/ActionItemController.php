<?php

/**
 * ActionItemController — VTODO-write endpoints for action items.
 *
 * The `action-item` schema is a read-only OpenRegister projection over CalDAV
 * VTODOs (action-items-vtodo-deck-reconcile), so the generic object API rejects
 * writes. The frontend therefore creates/updates/deletes action items through
 * these endpoints, which route to {@see ActionItemWriter} (OpenRegister
 * TaskService). Reads still use the object API (the projection).
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\Service\ActionItemWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Create / update / delete action items as CalDAV VTODOs.
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
 */
class ActionItemController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param ActionItemWriter $writer The VTODO write path.
	 * @param IUserSession $userSession The user session (CalDAV writes are user-scoped).
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ActionItemWriter $writer,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Create an action item (as a VTODO).
	 *
	 * Per-user safe: ActionItemWriter writes to the acting user's calendar; a
	 * logged-in user is required (NoAdminRequired) and the VTODO is owned by them.
	 *
	 * @return JSONResponse The created action item, or an error.
	 *
	 * @no-admin-idor-exempt Takes no caller-supplied object id at all. The VTODO
	 *   uid is server-generated (ActionItemWriter::uuidV4()) and the task is
	 *   written into the acting user's own calendar via
	 *   OCA\OpenRegister\Service\TaskService::createTask(). There is no object
	 *   reference a caller could point at someone else's data, so there is
	 *   nothing for a per-object guard to scope.
	 *
	 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$payload = $this->request->getParams();
		unset($payload['_route']);
		$created = $this->writer->create(item: $payload);
		if ($created === null) {
			return new JSONResponse(['error' => 'Could not create action item'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['success' => true, 'actionItem' => $created], Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update an action item (located by its VTODO uid).
	 *
	 * IDOR-safe: ActionItemWriter resolves the uid only among the acting user's
	 * own CalDAV tasks, so a user cannot mutate another user's VTODO.
	 *
	 * @param string $uid The action item's VTODO uid.
	 *
	 * @return JSONResponse The updated action item, or an error.
	 *
	 * @no-admin-idor-exempt The caller-supplied uid is resolved ONLY inside the
	 *   acting user's own calendars, so it can never name another user's task.
	 *   ActionItemWriter::update() -> locate() (lib/Service/ActionItemWriter.php:275)
	 *   calls OCA\OpenRegister\Service\TaskService::getAllUserTasks(), which at
	 *   TaskService.php:126-132 resolves the session user, THROWS when anonymous,
	 *   and reads only 'principals/users/' . $user->getUID(). An unmatched uid
	 *   returns null, which this method answers as 404 — the existence-oracle-safe
	 *   shape. gate-7 cannot see this because its Pattern 2b delegation closure is
	 *   gated on the collaborator naming OpenRegister's ObjectService, and the
	 *   enforcement here lives in TaskService instead.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	#[NoAdminRequired]
	public function update(string $uid): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$changes = $this->request->getParams();
		unset($changes['_route'], $changes['uid']);
		$updated = $this->writer->update(uid: $uid, changes: $changes);
		if ($updated === null) {
			return new JSONResponse(['error' => 'Action item not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['success' => true, 'actionItem' => $updated]);
	}//end update()

	/**
	 * Delete an action item (located by its VTODO uid).
	 *
	 * IDOR-safe for the same reason as update().
	 *
	 * @param string $uid The action item's VTODO uid.
	 *
	 * @return JSONResponse Success, or 404 when not found.
	 *
	 * @no-admin-idor-exempt Same enforcement as update(): ActionItemWriter::delete()
	 *   -> locate() (lib/Service/ActionItemWriter.php:275) reaches storage only via
	 *   OCA\OpenRegister\Service\TaskService::getAllUserTasks(), hard-scoped at
	 *   TaskService.php:126-132 to 'principals/users/' . $user->getUID(). A uid the
	 *   acting user does not own simply does not resolve, and the method answers 404.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.4
	 */
	#[NoAdminRequired]
	public function destroy(string $uid): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->writer->delete(uid: $uid) === false) {
			return new JSONResponse(['error' => 'Action item not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['success' => true]);
	}//end destroy()
}//end class
