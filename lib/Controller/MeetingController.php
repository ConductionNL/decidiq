<?php

/**
 * Decidesk Meeting Controller
 *
 * Controller for meeting-specific operations, particularly lifecycle transitions.
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
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MeetingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for meeting lifecycle transitions.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-2.1
 */
class MeetingController extends Controller
{
    /**
     * Constructor for MeetingController.
     *
     * @param IRequest       $request        The HTTP request
     * @param MeetingService $meetingService The meeting service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly MeetingService $meetingService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Apply a lifecycle transition to a meeting.
     *
     * Any authenticated Nextcloud user may call this endpoint. Meeting-level
     * permission (e.g. only the clerk/chair may advance the state) is enforced
     * at the service layer via OpenRegister, not via the Nextcloud admin role.
     * In Dutch local government the meeting clerk is typically not a Nextcloud
     * system administrator, so this route must be available to all logged-in users.
     *
     * Expects JSON body: { "action": "<schedule|open|pause|resume|adjourn|close>" }
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-2.1
     *
     * @return JSONResponse HTTP 200 with updated meeting on success; 422 if transition is invalid
     */
    #[NoAdminRequired]
    public function lifecycle(string $id): JSONResponse
    {
        $action = $this->request->getParam('action', '');

        if (empty($action) === true) {
            return new JSONResponse(
                ['message' => "Missing required parameter 'action'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->meetingService->transition(meetingId: $id, action: $action);

        if ($result['success'] === false) {
            return new JSONResponse(
                ['message' => $result['message']],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse($result);

    }//end lifecycle()
}//end class
