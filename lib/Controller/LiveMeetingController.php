<?php

/**
 * Decidesk Live Meeting Controller
 *
 * Controller for live decision recording during meetings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\LiveDecisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for live decision recording during meetings.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 */
class LiveMeetingController extends Controller
{
    /**
     * Constructor for LiveMeetingController.
     *
     * @param IRequest             $request             The HTTP request
     * @param LiveDecisionService  $liveDecisionService The live decision service
     * @param IUserSession         $userSession         The current user session
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
     */
    public function __construct(
        IRequest $request,
        private LiveDecisionService $liveDecisionService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Record a decision during an active meeting.
     *
     * POST /api/meetings/{meetingId}/live-decisions
     *
     * Request body:
     * {
     *   "title": string (required),
     *   "text": string (required),
     *   "outcome": string (required, enum: adopted|rejected),
     *   "legalBasis": string (optional)
     * }
     *
     * @param string $meetingId The UUID of the meeting
     *
     * @return JSONResponse The created Decision object
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
     */
    public function recordDecision(string $meetingId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $decisionData = [
            'title' => $this->request->getParam('title'),
            'text' => $this->request->getParam('text'),
            'outcome' => $this->request->getParam('outcome', 'adopted'),
            'legalBasis' => $this->request->getParam('legalBasis'),
        ];

        try {
            $decisionSlug = $this->liveDecisionService->recordDecision(
                meetingId: $meetingId,
                decisionData: $decisionData,
                actorId: $user->getUID()
            );

            return new JSONResponse(
                ['id' => $decisionSlug, 'message' => 'Decision recorded'],
                Http::STATUS_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_CONFLICT
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to record decision: ' . $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
    }//end recordDecision()
}//end class
