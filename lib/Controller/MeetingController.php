<?php

/**
 * Decidesk Meeting Controller
 *
 * Thin controller for meeting lifecycle management API endpoints.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MeetingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Thin controller for meeting lifecycle management API endpoints.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-2
 */
class MeetingController extends Controller
{
    /**
     * Constructor for MeetingController.
     *
     * @param IRequest       $request        The request object
     * @param MeetingService $meetingService The meeting service
     * @param IL10N          $l10n           The localisation helper
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-2
     */
    public function __construct(
        IRequest $request,
        private readonly MeetingService $meetingService,
        private readonly IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Transition a meeting to a new lifecycle state.
     *
     * @param string $id The meeting ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-2
     */
    public function transitionLifecycle(string $id): JSONResponse
    {
        $transition = (string) $this->request->getParam('transition', '');

        if ($transition === '') {
            return new JSONResponse(
                ['success' => false, 'error' => $this->l10n->t('Missing required parameter: transition')],
                400
            );
        }

        try {
            $result = $this->meetingService->transitionLifecycle(meetingId: $id, transition: $transition);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if ($code === 403) {
                $status = 403;
            } else if ($code === 404) {
                $status = 404;
            } else if ($code === 503) {
                $status = 503;
            } else {
                $status = 400;
            }

            return new JSONResponse(
                ['success' => false, 'error' => $this->l10n->t('An error occurred')],
                $status
            );
        }

    }//end transitionLifecycle()

    /**
     * Get the current user's role for a meeting.
     *
     * @param string $id The meeting ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-2
     */
    public function userRole(string $id): JSONResponse
    {
        try {
            $result = $this->meetingService->getUserRole(meetingId: $id);
            return new JSONResponse($result);
        } catch (\Throwable) {
            return new JSONResponse(['role' => 'none']);
        }

    }//end userRole()
}//end class
