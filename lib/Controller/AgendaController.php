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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Exception\NotFoundException;
use OCA\Decidesk\Service\AgendaService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
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
     * @param IRequest        $request       The HTTP request
     * @param AgendaService   $agendaService The agenda service
     * @param ObjectService   $objectService OpenRegister object service (used for auth checks)
     * @param IUserSession    $userSession   The current user session
     * @param IGroupManager   $groupManager  Group manager for admin checks
     * @param LoggerInterface $logger        PSR-3 logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private readonly AgendaService $agendaService,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Verify the current user is an admin or holds a chair/secretary role for a meeting.
     *
     * @param string $meetingId UUID of the meeting to check
     *
     * @return JSONResponse|null Null if authorised, 403 JSONResponse if not.
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
     */
    private function requireChairOrAdmin(string $meetingId): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();

        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        $participants = $this->objectService->findAll(
            [
                'filters' => [
                    'register'                => 'decidesk',
                    'schema'                  => 'participant',
                    '@self.relations.meeting' => $meetingId,
                ],
            ]
        );

        foreach ($participants as $p) {
            if (is_array($p) === true) {
                $pData = $p;
            } else {
                $pData = (array) $p;
            }

            $owner = $pData['owner'] ?? null;
            $role  = $pData['role'] ?? null;
            if ($owner === $userId && in_array(needle: $role, haystack: ['chair', 'secretary'], strict: true) === true) {
                return null;
            }
        }

        return new JSONResponse(
            ['message' => 'Chair or secretary role required for this meeting'],
            Http::STATUS_FORBIDDEN
        );

    }//end requireChairOrAdmin()

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
        $denied = $this->requireChairOrAdmin(meetingId: $meetingId);
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
        try {
            // Resolve the meeting for authorization; 404 if item does not exist.
            $item = $this->objectService->find($id);
            if ($item === null) {
                return new JSONResponse(['message' => 'Agenda item not found.'], Http::STATUS_NOT_FOUND);
            }

            if (is_array($item) === true) {
                $itemData = $item;
            } else {
                $itemData = (array) $item;
            }

            $meetingId = $itemData['@self']['relations']['meeting'] ?? null;

            if ($meetingId === null) {
                return new JSONResponse(['message' => 'Could not resolve meeting for authorization.'], Http::STATUS_FORBIDDEN);
            }

            $denied = $this->requireChairOrAdmin(meetingId: (string) $meetingId);
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
        $denied = $this->requireChairOrAdmin(meetingId: $meetingId);
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
        $denied = $this->requireChairOrAdmin(meetingId: $meetingId);
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
        $denied = $this->requireChairOrAdmin(meetingId: $meetingId);
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
