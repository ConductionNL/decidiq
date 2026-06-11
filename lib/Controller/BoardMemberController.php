<?php
/**
 * Decidesk Board Member Controller
 *
 * REST endpoints for the BoardMember entity.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardMemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the BoardMember entity.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
 */
class BoardMemberController extends Controller
{
    use BoardPortalControllerTrait;

    /**
     * Constructor for BoardMemberController.
     *
     * @param IRequest           $request       The HTTP request
     * @param BoardMemberService $memberService The board-member service
     * @param IUserSession       $userSession   The user session
     */
    public function __construct(
        IRequest $request,
        private readonly BoardMemberService $memberService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the members of a board.
     *
     * @param string $boardId UUID of the board
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(string $boardId): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $result = $this->memberService->listForBoard($boardId);
        if ($result['success'] === false) {
            return new JSONResponse(['message' => 'Failed to list members.'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse(
            [
                'results' => $result['members'],
                'total'   => $result['count'],
            ]
        );

    }//end index()

    /**
     * Invite a new member to a board.
     *
     * @param string $boardId UUID of the board
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function invite(string $boardId): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $payload = $this->bodyParams(request: $this->request, stripKeys: ['boardId', '_route']);
        $result  = $this->memberService->invite($boardId, $payload);

        return $this->respondFromResult(result: $result, payloadKey: 'member', successCode: Http::STATUS_CREATED);

    }//end invite()

    /**
     * Remove (end-of-term) a board member.
     *
     * @param string $id UUID of the board-member row
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function remove(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        return $this->respondFromResult(result: $this->memberService->remove($id), payloadKey: 'member');

    }//end remove()

    /**
     * Change a board member's role.
     *
     * @param string $id UUID of the board-member row
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-member-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function changeRole(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $role = (string) $this->request->getParam('role', '');
        if ($role === '') {
            return new JSONResponse(['message' => "Missing required parameter 'role'."], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return $this->respondFromResult(result: $this->memberService->changeRole($id, $role), payloadKey: 'member');

    }//end changeRole()
}//end class
