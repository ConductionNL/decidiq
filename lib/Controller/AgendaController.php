<?php

/**
 * Decidesk Agenda Controller
 *
 * Thin controller for agenda management API endpoints.
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
use OCP\IL10N;
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
     * @param IL10N         $l10n          The localisation helper
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly AgendaService $agendaService,
        private readonly IL10N $l10n,
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
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if ($code === 403) {
                $status = 403;
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
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if ($code === 403) {
                $status = 403;
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
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if ($code === 403) {
                $status = 403;
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
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if ($code === 403) {
                $status = 403;
            } else if ($code === 503) {
                $status = 503;
            } else {
                $status = 400;
            }

            return new JSONResponse(
                ['success' => false, 'error' => $this->l10n->t('An error occurred')],
                $status
            );
        }//end try

    }//end reorder()

    /**
     * Get the current user's role for a meeting.
     *
     * @param string $meetingId The meeting ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function userRole(string $meetingId): JSONResponse
    {
        try {
            $result = $this->agendaService->getUserRole(meetingId: $meetingId);
            return new JSONResponse($result);
        } catch (\Throwable) {
            return new JSONResponse(['role' => 'none']);
        }

    }//end userRole()
}//end class
