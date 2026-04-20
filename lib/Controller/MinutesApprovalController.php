<?php

/**
 * Decidesk Minutes Approval Controller
 *
 * Handles API endpoints for Minutes approval and sign-off workflow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
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
use OCA\Decidesk\Service\MinutesApprovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for Minutes approval endpoints.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
 */
class MinutesApprovalController extends Controller
{
    /**
     * Constructor for MinutesApprovalController.
     *
     * @param IRequest               $request         The HTTP request
     * @param MinutesApprovalService $approvalService The approval service
     * @param IUserSession           $userSession     The current user session
     * @param IGroupManager          $groupManager    Group manager for role checks
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function __construct(
        IRequest $request,
        private MinutesApprovalService $approvalService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Add approval for Minutes.
     *
     * POST /api/minutes/{id}/approve
     *
     * Request body: `{ role: 'chair'|'secretary' }`
     *
     * @param string $id UUID of the Minutes object
     *
     * @return JSONResponse with approval status or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function approve(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated'], 401);
        }

        $role = $this->request->getParam('role', '');

        if (in_array($role, ['chair', 'secretary'], true) === false) {
            return new JSONResponse(['message' => 'Invalid role'], 400);
        }

        try {
            $this->approvalService->addApproval($id, $user->getUID(), $role);
            $status = $this->approvalService->getApprovalStatus($id);
            return new JSONResponse($status);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Failed to add approval'], 500);
        }
    }//end approve()

    /**
     * Sign Minutes (advance from approved to signed).
     *
     * POST /api/minutes/{id}/sign
     *
     * Only secretary role can sign.
     *
     * @param string $id UUID of the Minutes object
     *
     * @return JSONResponse with updated approval status or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function sign(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated'], 401);
        }

        // Verify user has secretary role or is admin.
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Only secretary can sign'], 403);
        }

        try {
            $this->approvalService->advance($id, $user->getUID(), 'signed');
            $status = $this->approvalService->getApprovalStatus($id);
            return new JSONResponse($status);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Failed to sign minutes'], 500);
        }
    }//end sign()

    /**
     * Publish Minutes (advance from signed to published).
     *
     * POST /api/minutes/{id}/publish
     *
     * Only secretary role can publish.
     *
     * @param string $id UUID of the Minutes object
     *
     * @return JSONResponse with updated approval status or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function publish(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated'], 401);
        }

        // Verify user has secretary role or is admin.
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Only secretary can publish'], 403);
        }

        try {
            $this->approvalService->advance($id, $user->getUID(), 'published');
            $status = $this->approvalService->getApprovalStatus($id);
            return new JSONResponse($status);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Failed to publish minutes'], 500);
        }
    }//end publish()

    /**
     * Get approval status for Minutes.
     *
     * GET /api/minutes/{id}/approval-status
     *
     * @param string $id UUID of the Minutes object
     *
     * @return JSONResponse with approval status
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function getApprovalStatus(string $id): JSONResponse
    {
        try {
            $status = $this->approvalService->getApprovalStatus($id);
            return new JSONResponse($status);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Failed to get approval status'], 500);
        }
    }//end getApprovalStatus()
}//end class
