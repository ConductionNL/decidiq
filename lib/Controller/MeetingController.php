<?php

/**
 * Decidesk Meeting Controller
 *
 * Controller for meeting-specific operations, particularly lifecycle transitions.
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
use Psr\Container\ContainerInterface;

/**
 * Controller for meeting lifecycle transitions.
 *
 * ## Access control design (OWASP A01 / ADR-005)
 *
 * This controller uses OpenRegister ObjectService RBAC rather than
 * `IGroupManager::isAdmin()` for the following domain reason:
 *
 * In Dutch local government, the meeting chair and clerk are NOT Nextcloud
 * system administrators. `requireChairOrSecretary()` (which gates on
 * `IGroupManager::isAdmin()`) would incorrectly block all legitimate users.
 *
 * Access control is enforced at the OpenRegister layer:
 * - `ObjectService::getObject()` returns null if the caller lacks read permission → 422
 * - `ObjectService::saveObject()` throws if the caller lacks write permission → 422
 *
 * The meeting objects carry per-object ACLs in OpenRegister; only users with
 * clerk or chair permission on the specific meeting object can transition it.
 * This is the approved pattern per ADR-005 for resources governed by
 * OpenRegister's own RBAC instead of Nextcloud group membership.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-2.1
 */
class MeetingController extends Controller
{
    /**
     * Constructor for MeetingController.
     *
     * @param IRequest           $request        The HTTP request
     * @param MeetingService     $meetingService The meeting service
     * @param IUserSession       $userSession    The user session
     * @param ContainerInterface $container      The DI container
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly MeetingService $meetingService,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get a list of meetings.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
     *
     * @return JSONResponse HTTP 200 with meetings list
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $limit  = (int) $this->request->getParam('_limit', 100);
            $offset = (int) $this->request->getParam('_offset', 0);

            $meetings = $objectService->findObjects(
                register: 'decidesk',
                schema: 'meeting',
                filters: [
                    '_limit'  => max(1, min($limit, 500)),
                    '_offset' => max(0, $offset),
                ]
            );

            return new JSONResponse($meetings);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to retrieve meetings'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Create a new meeting.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
     *
     * @return JSONResponse HTTP 201 with created meeting on success
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data = $this->request->getParams();

            if (empty($data['title'] ?? null) === true) {
                return new JSONResponse(
                    ['message' => 'Title is required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $meeting = $this->meetingService->create($data);

            return new JSONResponse($meeting, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to create meeting'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end create()

    /**
     * Get a specific meeting by ID.
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
     *
     * @return JSONResponse HTTP 200 with meeting data or 404 if not found
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $meeting = $this->meetingService->read($id);

            if ($meeting === null) {
                return new JSONResponse(
                    ['message' => 'Meeting not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            return new JSONResponse($meeting);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to retrieve meeting'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end show()

    /**
     * Update an existing meeting.
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
     *
     * @return JSONResponse HTTP 200 with updated meeting on success
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data = $this->request->getParams();

            $meeting = $this->meetingService->update($id, $data);

            if ($meeting === null) {
                return new JSONResponse(
                    ['message' => 'Meeting not found or update failed'],
                    Http::STATUS_NOT_FOUND
                );
            }

            return new JSONResponse($meeting);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to update meeting'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end update()

    /**
     * Delete a meeting.
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.4
     *
     * @return JSONResponse HTTP 204 on success or 404 if not found
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $deleted = $this->meetingService->delete($id);

            if ($deleted === false) {
                return new JSONResponse(
                    ['message' => 'Meeting not found or deletion failed'],
                    Http::STATUS_NOT_FOUND
                );
            }

            return new JSONResponse(statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to delete meeting'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end destroy()

    /**
     * Apply a lifecycle transition to a meeting.
     *
     * Access control: OpenRegister ObjectService RBAC (see class docblock).
     * The caller must be authenticated; write-level access to the meeting object
     * is enforced by ObjectService::saveObject() inside MeetingService::transition().
     *
     * Expects JSON body: { "action": "<schedule|open|pause|resume|adjourn|close>" }
     *
     * @param string $id UUID of the meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.3
     *
     * @return JSONResponse HTTP 200 with updated meeting on success; 422 if transition is invalid
     */
    #[NoAdminRequired]
    public function lifecycle(string $id): JSONResponse
    {
        // Require authentication — anonymous callers are rejected before service call.
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

        $userId = $this->userSession->getUser()->getUID();
        $result = $this->meetingService->transition(meetingId: $id, action: $action, currentUserId: $userId);

        if ($result['success'] === false) {
            return new JSONResponse(
                ['message' => $result['message']],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse($result);

    }//end lifecycle()
}//end class
