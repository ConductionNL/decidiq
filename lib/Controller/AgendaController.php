<?php

/**
 * Decidesk Agenda Controller
 *
 * Thin REST controller exposing agenda lifecycle operations.
 * Delegates all business logic to AgendaService.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\AgendaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST controller for agenda lifecycle operations.
 *
 * Routes:
 *   POST /api/agendas/{meetingId}/publish      → publishAgenda
 *   PUT  /api/agenda-items/{id}/bob-phase      → advanceBobPhase
 *   POST /api/agendas/{meetingId}/hamerstukken → processHamerstukken
 *   PUT  /api/agendas/{meetingId}/reorder      → reorderItems
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
 */
class AgendaController extends Controller
{
    /**
     * Constructor for AgendaController.
     *
     * @param IRequest      $request       The HTTP request
     * @param AgendaService $agendaService The agenda service
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private readonly AgendaService $agendaService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Publish the agenda for a meeting.
     *
     * Validates items exist, notifies participants, transitions Meeting to 'opened'.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function publish(string $meetingId): JSONResponse
    {
        try {
            $this->agendaService->publishAgenda($meetingId);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end publish()

    /**
     * Advance the BOB phase of a single agenda item.
     *
     * @param string $id UUID of the AgendaItem
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function advanceBobPhase(string $id): JSONResponse
    {
        try {
            $this->agendaService->advanceBobPhase($id);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end advanceBobPhase()

    /**
     * Process all hamerstukken (consent items) for a meeting.
     *
     * Sets status of all items tagged 'hamerstuk' to 'afgerond'.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function processHamerstukken(string $meetingId): JSONResponse
    {
        try {
            $this->agendaService->processHamerstukken($meetingId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end processHamerstukken()

    /**
     * Reorder agenda items for a meeting.
     *
     * Accepts body: { "ids": ["uuid1", "uuid2", ...] }
     * Assigns sequential orderNumber values 1..n.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reorder(string $meetingId): JSONResponse
    {
        $body = $this->request->getParams();
        $ids  = $body['ids'] ?? [];

        if (empty($ids) === true || is_array($ids) === false) {
            return new JSONResponse(['message' => 'ids array is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->agendaService->reorderItems($meetingId, $ids);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end reorder()
}//end class
