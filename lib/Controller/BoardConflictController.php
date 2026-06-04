<?php
/**
 * Decidesk Board Conflict Controller
 *
 * API endpoints for filing and acting on conflict-of-interest declarations.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for conflict-of-interest endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 */
class BoardConflictController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                  $request     The request.
     * @param ConflictOfInterestService $conflictSvc The conflict service.
     * @param IUserSession              $userSession The user session.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
     */
    public function __construct(
        IRequest $request,
        private readonly ConflictOfInterestService $conflictSvc,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Resolve the current user UID.
     *
     * @return string|null
     */
    private function currentUid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();

    }//end currentUid()

    /**
     * File a conflict-of-interest declaration.
     *
     * POST /api/board/conflicts/declare
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
     */
    #[NoAdminRequired]
    public function declare(): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $params        = $this->request->getParams();
        $boardMemberId = (string) ($params['boardMemberId'] ?? '');
        $agendaItemId  = (string) ($params['agendaItemId'] ?? '');
        $type          = (string) ($params['declarationType'] ?? '');
        $severity      = (string) ($params['severity'] ?? 'non-material');
        $description   = (string) ($params['description'] ?? '');
        if ($boardMemberId === '' || $agendaItemId === '' || $type === '') {
            return new JSONResponse(['message' => 'boardMemberId, agendaItemId and declarationType are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $declaration = $this->conflictSvc->declare($boardMemberId, $agendaItemId, $type, $severity, $description, $uid);
            return new JSONResponse($declaration, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end declare()

    /**
     * Record the action taken for a declaration.
     *
     * PUT /api/board/conflicts/{id}/action
     *
     * @param string $id The declaration UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
     */
    #[NoAdminRequired]
    public function action(string $id): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $actionTaken = (string) ($this->request->getParam('actionTaken', ''));
        if ($actionTaken === '') {
            return new JSONResponse(['message' => 'actionTaken is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $updated = $this->conflictSvc->recordAction($id, $actionTaken, $uid);
            return new JSONResponse($updated, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end action()
}//end class
