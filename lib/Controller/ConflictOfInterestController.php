<?php
/**
 * Decidesk Conflict of Interest Controller
 *
 * Thin REST controller for declaring conflicts of interest and recording the action taken.
 * Scoped to the board secretary/admin operator in this T1 backend.
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
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Conflict-of-interest declaration REST endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 */
class ConflictOfInterestController extends Controller
{
    /**
     * Constructor for ConflictOfInterestController.
     *
     * @param IRequest                  $request      The request object.
     * @param ConflictOfInterestService $coiService   The conflict-of-interest service.
     * @param IUserSession              $userSession  The user session.
     * @param IGroupManager             $groupManager The group manager.
     * @param IAppConfig                $appConfig    The app config.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
     */
    public function __construct(
        IRequest $request,
        private readonly ConflictOfInterestService $coiService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the caller to be a board secretary or system administrator.
     *
     * @return JSONResponse|null A 401/403 response on failure, null on success.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.5
     */
    private function requireBoardSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid            = $user->getUID();
        $secretaryGroup = $this->appConfig->getValueString('decidesk', 'board_secretary_group', '');
        $inGroup        = ($secretaryGroup !== '' && $this->groupManager->isInGroup($uid, $secretaryGroup) === true);

        if ($inGroup === false && $this->groupManager->isAdmin($uid) === false) {
            return new JSONResponse(['message' => 'Board secretary or administrator role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireBoardSecretary()

    /**
     * Declare a conflict of interest for a (board-member, agenda-item) pair.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
     */
    #[NoAdminRequired]
    public function declareConflict(): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $boardMemberId = (string) $this->request->getParam('boardMember', '');
        $agendaItemId  = (string) $this->request->getParam('agendaItem', '');
        $type          = (string) $this->request->getParam('declarationType', '');
        $description   = $this->request->getParam('description');
        $severity      = $this->request->getParam('severity');

        if ($boardMemberId === '' || $agendaItemId === '' || $type === '') {
            return new JSONResponse(['message' => 'boardMember, agendaItem and declarationType are required'], Http::STATUS_BAD_REQUEST);
        }

        $descriptionValue = null;
        if ($description !== null) {
            $descriptionValue = (string) $description;
        }

        $severityValue = null;
        if ($severity !== null) {
            $severityValue = (string) $severity;
        }

        try {
            $record = $this->coiService->declare(
                boardMemberId: $boardMemberId,
                agendaItemId: $agendaItemId,
                type: $type,
                description: $descriptionValue,
                severity: $severityValue
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($record, Http::STATUS_CREATED);

    }//end declareConflict()

    /**
     * Record the action taken for an existing declaration.
     *
     * @param string $id The declaration UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
     */
    #[NoAdminRequired]
    public function recordAction(string $id): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $action = (string) $this->request->getParam('actionTaken', '');
        if ($action === '') {
            return new JSONResponse(['message' => 'actionTaken is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $record = $this->coiService->recordAction(declarationId: $id, actionTaken: $action);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($record);

    }//end recordAction()
}//end class
