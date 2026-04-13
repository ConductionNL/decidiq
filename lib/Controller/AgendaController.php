<?php

/**
 * Decidesk Agenda Controller
 *
 * Thin controller for agenda management API endpoints.
 *
 * @category  Controller
 * @package   OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\AgendaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin controller for agenda management API endpoints.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
 */
class AgendaController extends Controller
{

    /**
     * Constructor for AgendaController.
     *
     * @param IRequest      $request       The request object
     * @param AgendaService $agendaService The agenda service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private AgendaService $agendaService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Publish the agenda for a meeting.
     *
     * @param string $meetingId The meeting ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function publish(string $meetingId): JSONResponse
    {
        try {
            $result = $this->agendaService->publishAgenda(meetingId: $meetingId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['success' => false, 'error' => $e->getMessage()],
                400
            );
        }

    }//end publish()

    /**
     * Advance the BOB phase of an agenda item.
     *
     * @param string $id The agenda item ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function advanceBobPhase(string $id): JSONResponse
    {
        try {
            $result = $this->agendaService->advanceBobPhase(agendaItemId: $id);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['success' => false, 'error' => $e->getMessage()],
                400
            );
        }

    }//end advanceBobPhase()

    /**
     * Process hamerstukken (consent items) for a meeting.
     *
     * @param string $meetingId The meeting ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function processHamerstukken(string $meetingId): JSONResponse
    {
        try {
            $result = $this->agendaService->processHamerstukken(meetingId: $meetingId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['success' => false, 'error' => $e->getMessage()],
                400
            );
        }

    }//end processHamerstukken()

    /**
     * Reorder agenda items for a meeting.
     *
     * @param string $meetingId The meeting ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function reorder(string $meetingId): JSONResponse
    {
        $ids = $this->request->getParam('ids', []);

        try {
            $result = $this->agendaService->reorderItems(
                meetingId: $meetingId,
                orderedIds: $ids
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['success' => false, 'error' => $e->getMessage()],
                400
            );
        }

    }//end reorder()
}//end class
