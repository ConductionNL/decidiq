<?php
/**
 * Decidesk Workspace Controller
 *
 * REST controller for CollaborationWorkspace endpoints: workspace creation,
 * member management, and workspace-scoped visibility.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-4.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Workspace endpoints (member add/remove).
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-4.2
 */
class WorkspaceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          HTTP request
     * @param WorkspaceService $workspaceService Workspace service
     * @param IUserSession     $userSession      Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.2
     */
    public function __construct(
        IRequest $request,
        private readonly WorkspaceService $workspaceService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Add a member to a workspace.
     *
     * POST /api/workspaces/{id}/members
     *
     * Body: { personId }
     *
     * @param string $id Workspace UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.2
     */
    public function addMember(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $personId = (string) $this->request->getParam('personId', '');
        if ($personId === '') {
            return new JSONResponse(
                ['message' => 'Missing required field: personId.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $workspace = $this->workspaceService->addMember($id, $personId);
            return new JSONResponse($workspace);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end addMember()

    /**
     * Remove a member from a workspace.
     *
     * DELETE /api/workspaces/{id}/members/{personId}
     *
     * @param string $id       Workspace UUID
     * @param string $personId Person UUID to remove
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-4.2
     */
    public function removeMember(string $id, string $personId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $workspace = $this->workspaceService->removeMember($id, $personId);
            return new JSONResponse($workspace);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end removeMember()
}//end class
