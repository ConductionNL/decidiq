<?php

/**
 * Decidesk Meeting Controller
 *
 * Controller for meeting operations: CRUD via CalDAV VEVENTs and lifecycle transitions.
 * Meetings are stored as CalDAV VEVENTs per ADR-002.
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
use OCP\IUserSession;

/**
 * Controller for meeting CRUD and lifecycle transitions.
 *
 * Access control: OpenRegister ObjectService RBAC for wrapper objects;
 * CalDAV calendar permissions for VEVENT operations.
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
     * @param IUserSession   $userSession    The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly MeetingService $meetingService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all meetings for the current user.
     *
     * GET /api/meetings
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $bodyUid  = $this->request->getParam('bodyUid');
        $meetings = $this->meetingService->list($bodyUid);

        return new JSONResponse(['results' => $meetings, 'total' => count($meetings)]);

    }//end index()

    /**
     * Get a single meeting by CalDAV UID.
     *
     * GET /api/meetings/{id}
     *
     * @param string $id CalDAV UID of the meeting
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $meeting = $this->meetingService->get($id);

        if ($meeting === null) {
            return new JSONResponse(['message' => "Meeting '$id' not found."], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($meeting);

    }//end show()

    /**
     * Create a new meeting as a CalDAV VEVENT.
     *
     * POST /api/meetings
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $meetingData = $this->request->getParams();
        $result      = $this->meetingService->create($meetingData);

        if ($result['success'] === false) {
            return new JSONResponse(
                ['message' => $result['message']],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse($result['meeting'], Http::STATUS_CREATED);

    }//end create()

    /**
     * Apply a lifecycle transition to a meeting.
     *
     * POST /api/meetings/{id}/lifecycle
     *
     * Expects JSON body: { "action": "<schedule|open|pause|resume|adjourn|close>" }
     *
     * @param string $id CalDAV UID of the meeting
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
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $action = $this->request->getParam('action', '');

        if (empty($action) === true) {
            return new JSONResponse(
                ['message' => "Missing required parameter 'action'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->meetingService->transition(meetingUid: $id, action: $action);

        if ($result['success'] === false) {
            return new JSONResponse(
                ['message' => $result['message']],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse($result);

    }//end lifecycle()
}//end class
