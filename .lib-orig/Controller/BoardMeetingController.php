<?php
/**
 * Decidesk Board Meeting Controller
 *
 * REST endpoints for BoardMeeting lifecycle.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-meeting-controller
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardMeetingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for BoardMeeting lifecycle.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-meeting-controller
 */
class BoardMeetingController extends Controller
{
    use BoardPortalControllerTrait;

    /**
     * Constructor for BoardMeetingController.
     *
     * @param IRequest            $request        The HTTP request
     * @param BoardMeetingService $meetingService The board-meeting service
     * @param IUserSession        $userSession    The user session
     */
    public function __construct(
        IRequest $request,
        private readonly BoardMeetingService $meetingService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Schedule a new board meeting.
     *
     * @param string $boardId UUID of the parent board
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-meeting-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function schedule(string $boardId): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $payload = $this->bodyParams(request: $this->request, stripKeys: ['boardId', '_route']);
        return $this->respondFromResult(
            result: $this->meetingService->schedule($boardId, $payload),
            payloadKey: 'meeting',
            successCode: Http::STATUS_CREATED
        );

    }//end schedule()

    /**
     * Send the formal meeting notice.
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-meeting-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function sendNotice(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $actor = $this->userSession->getUser()?->getUID() ?? 'system';

        return $this->respondFromResult(
            result: $this->meetingService->sendNotice($id, $actor),
            payloadKey: 'meeting'
        );

    }//end sendNotice()

    /**
     * Run a lifecycle transition.
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-meeting-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function transition(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $action = (string) $this->request->getParam('action', '');
        if ($action === '') {
            return new JSONResponse(['message' => "Missing required parameter 'action'."], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return $this->respondFromResult(
            result: $this->meetingService->runLifecycleTransition($id, $action),
            payloadKey: 'meeting'
        );

    }//end transition()
}//end class
