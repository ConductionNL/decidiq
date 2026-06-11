<?php
/**
 * Decidesk Board Controller
 *
 * REST endpoints for the Board entity (CRUD).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the Board entity.
 *
 * Access control: every endpoint requires an authenticated NC session.
 * Per-object RBAC is enforced inside ObjectService (find()/saveObject()
 * return null / throw when the caller lacks access), the same pattern used
 * by MeetingController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
 */
class BoardController extends Controller
{


    /**
     * Constructor for BoardController.
     *
     * @param IRequest     $request      The HTTP request
     * @param BoardService $boardService The board service
     * @param IUserSession $userSession  The user session
     */
    public function __construct(
        IRequest $request,
        private readonly BoardService $boardService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()


    /**
     * List boards. Supports `type` query filter.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $auth = $this->requireUser();
        if ($auth !== null) {
            return $auth;
        }

        $filters = [];
        $type    = $this->request->getParam('type');
        if ($type !== null && $type !== '') {
            $filters['type'] = (string) $type;
        }

        $result = $this->boardService->list($filters);
        if ($result['success'] === false) {
            return new JSONResponse(['message' => $result['message']], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new JSONResponse(
            [
                'results' => $result['boards'],
                'total'   => $result['count'],
            ]
        );

    }//end index()


    /**
     * Show a single board.
     *
     * @param string $id UUID of the board
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $auth = $this->requireUser();
        if ($auth !== null) {
            return $auth;
        }

        $result = $this->boardService->get($id);
        if ($result['success'] === false) {
            return new JSONResponse(['message' => $result['message']], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($result['board']);

    }//end show()


    /**
     * Create a new board.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $auth = $this->requireUser();
        if ($auth !== null) {
            return $auth;
        }

        $payload = $this->jsonBody();
        $result  = $this->boardService->create($payload);

        if ($result['success'] === false) {
            return new JSONResponse(['message' => $result['message']], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return new JSONResponse($result['board'], Http::STATUS_CREATED);

    }//end create()


    /**
     * Update an existing board.
     *
     * @param string $id UUID of the board
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-board-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $auth = $this->requireUser();
        if ($auth !== null) {
            return $auth;
        }

        $payload = $this->jsonBody();
        $result  = $this->boardService->update($id, $payload);

        if ($result['success'] === false) {
            $status = ($result['message'] === 'Board not found.') ? Http::STATUS_NOT_FOUND : Http::STATUS_UNPROCESSABLE_ENTITY;
            return new JSONResponse(['message' => $result['message']], $status);
        }

        return new JSONResponse($result['board']);

    }//end update()


    /**
     * Return a 401 when the caller is anonymous; null when authenticated.
     *
     * @return JSONResponse|null
     */
    private function requireUser(): ?JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        return null;

    }//end requireUser()


    /**
     * Read the request payload, stripping URL routing params (id, etc.).
     *
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = $this->request->getParams();
        if (is_array($raw) === false) {
            return [];
        }

        unset($raw['id'], $raw['_route']);
        return $raw;

    }//end jsonBody()


}//end class
