<?php
/**
 * Decidesk Board Material Controller
 *
 * REST endpoints for the BoardMaterial entity with access-level enforcement.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardMaterialAuthorizationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the BoardMaterial entity.
 *
 * Every read attempt (granted or denied) is mirrored to the audit log via
 * BoardMaterialAuthorizationService::logMaterialAccess().
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
 */
class BoardMaterialController extends Controller
{
    use BoardPortalControllerTrait;

    /**
     * Constructor for BoardMaterialController.
     *
     * @param IRequest                          $request     The HTTP request
     * @param BoardMaterialAuthorizationService $authService The material auth service
     * @param IUserSession                      $userSession The user session
     */
    public function __construct(
        IRequest $request,
        private readonly BoardMaterialAuthorizationService $authService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List materials visible to the caller's role.
     *
     * @param string $boardId UUID of the board
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
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

        $role = (string) $this->request->getParam('role', '');
        if ($role === '') {
            return new JSONResponse(['message' => "Missing required parameter 'role'."], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $materials = $this->authService->filterMaterialsByRole($boardId, $role);
        return new JSONResponse(
            [
                'results' => $materials,
                'total'   => count($materials),
            ]
        );

    }//end index()

    /**
     * Read a material; logs the read attempt to the audit log.
     *
     * @param string $id UUID of the material
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $boardMemberId = (string) $this->request->getParam('boardMemberId', '');
        if ($boardMemberId === '') {
            return new JSONResponse(['message' => "Missing required parameter 'boardMemberId'."], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $granted = $this->authService->canViewMaterial($boardMemberId, $id);
        $this->authService->logMaterialAccess($boardMemberId, $id, $granted);

        if ($granted === false) {
            return new JSONResponse(['message' => 'Access denied.'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(['materialId' => $id, 'granted' => true]);

    }//end show()

    /**
     * Initiate the encrypted download stream. The actual byte stream is
     * handled out-of-band by docudesk; this endpoint authorizes + audits.
     *
     * @param string $id UUID of the material
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function download(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $boardMemberId = (string) $this->request->getParam('boardMemberId', '');
        if ($boardMemberId === '') {
            return new JSONResponse(['message' => "Missing required parameter 'boardMemberId'."], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        // Per-object authorization (OWASP A01 / ADR-005): the member must hold
        // view access on THIS material; every attempt is audited.
        $granted = $this->authService->canViewMaterial($boardMemberId, $id);
        $this->authService->logMaterialAccess($boardMemberId, $id, $granted);

        if ($granted === false) {
            return new JSONResponse(['message' => 'Access denied.'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(['materialId' => $id, 'granted' => true]);

    }//end download()
}//end class
