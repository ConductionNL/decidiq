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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
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
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-2.1
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
