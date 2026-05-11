<?php
/**
 * Decidesk Task Controller
 *
 * REST controller for governance Task lifecycle endpoints. Generic CRUD
 * for the Task schema is delegated to OpenRegister's object API; this
 * controller adds the reclaim endpoint which enforces the delegator-only
 * authorisation rule.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Task-specific governance endpoints.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2.3
 */
class TaskController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest     $request     HTTP request
     * @param TaskService  $taskService Task service
     * @param IUserSession $userSession Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.3
     */
    public function __construct(
        IRequest $request,
        private readonly TaskService $taskService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Update the status of a task with state-machine validation.
     *
     * POST /api/tasks/{id}/status
     *
     * @param string $id Task UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.5
     */
    public function status(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $newStatus = (string) $this->request->getParam('taskStatus', '');
        if ($newStatus === '') {
            return new JSONResponse(
                ['message' => 'Missing required field: taskStatus.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $task = $this->taskService->updateTaskStatus($id, $newStatus);
            return new JSONResponse($task);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }//end try

    }//end status()

    /**
     * Reclaim a task: only the original delegator may invoke this endpoint.
     *
     * POST /api/tasks/{id}/reclaim
     *
     * @param string $id Task UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.3
     */
    public function reclaim(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $task = $this->taskService->reclaimTask($id, $user->getUID());
            return new JSONResponse($task);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }//end try

    }//end reclaim()
}//end class
