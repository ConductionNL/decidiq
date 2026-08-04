<?php
/**
 * Decidesk Agenda Controller
 *
 * Thin REST controller exposing agenda lifecycle operations.
 * Delegates all business logic to AgendaService and all authorization to
 * AgendaAuthorizationGuard.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Exception\NotFoundException;
use OCA\Decidesk\Service\AgendaAuthorizationGuard;
use OCA\Decidesk\Service\AgendaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

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
     * @param IRequest                 $request       The HTTP request
     * @param AgendaService            $agendaService The agenda service
     * @param AgendaAuthorizationGuard $guard         Authentication + chair/secretary authorization
     * @param LoggerInterface          $logger        PSR-3 logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private readonly AgendaService $agendaService,
        private readonly AgendaAuthorizationGuard $guard,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Authenticate the caller and authorise them as chair/secretary/admin on a meeting.
     *
     * @param string $meetingId UUID of the meeting to check
     *
     * @return JSONResponse|null Null if authorised, 401/403 JSONResponse if not.
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    private function denyUnlessChairOrAdmin(string $meetingId): ?JSONResponse
    {
        $denied = $this->guard->requireUser();
        if ($denied !== null) {
            return $denied;
        }

        return $this->guard->requireChairOrAdmin(meetingId: $meetingId);

    }//end denyUnlessChairOrAdmin()

    /**
     * Publish the agenda for a meeting.
     *
     * Validates items exist, notifies participants, transitions Meeting to 'opened'.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function publish(string $meetingId): JSONResponse
    {
        $denied = $this->denyUnlessChairOrAdmin(meetingId: $meetingId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->agendaService->publishAgenda($meetingId);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'publishAgenda failed for meeting {id}: {error}',
                ['id' => $meetingId, 'error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(['message' => 'An internal error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end publish()

    /**
     * Advance the BOB phase of a single agenda item.
     *
     * @param string $id UUID of the AgendaItem
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function advanceBobPhase(string $id): JSONResponse
    {
        $denied = $this->guard->requireUser();
        if ($denied !== null) {
            return $denied;
        }

        try {
            $denied = $this->guard->requireChairOrAdminForAgendaItem(agendaItemId: $id);
            if ($denied !== null) {
                return $denied;
            }

            $this->agendaService->advanceBobPhase($id);
            return new JSONResponse(['success' => true]);
        } catch (NotFoundException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'advanceBobPhase failed for item {id}: {error}',
                ['id' => $id, 'error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(['message' => 'An internal error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end advanceBobPhase()

    /**
     * Process all hamerstukken (consent items) for a meeting.
     *
     * Sets status of all items tagged 'hamerstuk' to 'afgerond'.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function processHamerstukken(string $meetingId): JSONResponse
    {
        $denied = $this->denyUnlessChairOrAdmin(meetingId: $meetingId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->agendaService->processHamerstukken($meetingId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'processHamerstukken failed for meeting {meetingId}: {error}',
                ['meetingId' => $meetingId, 'error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(['message' => 'An internal error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end processHamerstukken()

    /**
     * Revert a published agenda to draft (scheduled) state.
     *
     * Reverts the Meeting lifecycle back to 'scheduled', allowing further
     * edits before a subsequent publish. Requires chair or secretary role.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function revise(string $meetingId): JSONResponse
    {
        $denied = $this->denyUnlessChairOrAdmin(meetingId: $meetingId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->agendaService->reviseAgenda($meetingId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'reviseAgenda failed for meeting {meetingId}: {error}',
                ['meetingId' => $meetingId, 'error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(['message' => 'An internal error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end revise()

    /**
     * Reorder agenda items for a meeting.
     *
     * Accepts body: { "ids": ["uuid1", "uuid2", ...] }
     * Assigns sequential orderNumber values 1..n.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function reorder(string $meetingId): JSONResponse
    {
        $denied = $this->denyUnlessChairOrAdmin(meetingId: $meetingId);
        if ($denied !== null) {
            return $denied;
        }

        $body = $this->request->getParams();
        $ids  = $body['ids'] ?? [];

        if (empty($ids) === true || is_array($ids) === false) {
            return new JSONResponse(['message' => 'ids array is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->agendaService->reorderItems($meetingId, $ids);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'reorderItems failed for meeting {meetingId}: {error}',
                ['meetingId' => $meetingId, 'error' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(['message' => 'An internal error occurred.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end reorder()
}//end class
