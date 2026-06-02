<?php
/**
 * Decidesk Board Material Controller
 *
 * Thin REST controller exposing board-material listing and detail with access-level
 * enforcement and audit logging. Endpoints are scoped to the board secretary/admin
 * operator (the T1 backend; per-member self-service portal endpoints are a T2 frontend
 * concern requiring a member-identity binding).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardMaterialAuthorizationService;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Board material REST endpoints with access-level enforcement.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
 */
class BoardMaterialController extends Controller
{
    /**
     * Constructor for BoardMaterialController.
     *
     * @param IRequest                          $request      The request object.
     * @param BoardMaterialAuthorizationService $authService  Material authorization service.
     * @param ConflictOfInterestService         $coiService   Conflict-of-interest declaration service.
     * @param IUserSession                      $userSession  The user session.
     * @param IGroupManager                     $groupManager The group manager.
     * @param IAppConfig                        $appConfig    The app config.
     * @param LoggerInterface                   $logger       The logger.
     * @param ContainerInterface                $container    DI container (lazy ObjectService).
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     */
    public function __construct(
        IRequest $request,
        private readonly BoardMaterialAuthorizationService $authService,
        private readonly ConflictOfInterestService $coiService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
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

        $inSecretaryGroup = ($secretaryGroup !== '' && $this->groupManager->isInGroup($uid, $secretaryGroup) === true);

        if ($inSecretaryGroup === false && $this->groupManager->isAdmin($uid) === false) {
            return new JSONResponse(['message' => 'Board secretary or administrator role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireBoardSecretary()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * List board materials for a meeting filtered to the materials accessible to a role.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $meetingId = (string) $this->request->getParam('meeting', '');
        $role      = (string) $this->request->getParam('role', 'board-only');
        if ($meetingId === '') {
            return new JSONResponse(['message' => 'meeting parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        $materials = $this->authService->filterMaterialsByRole(meetingId: $meetingId, role: $role);
        return new JSONResponse(['results' => $materials]);

    }//end index()

    /**
     * Return a single board material, enforcing the access-level for the supplied member.
     *
     * @param string $id The BoardMaterial UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $guard = $this->requireBoardSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $boardMemberId = (string) $this->request->getParam('boardMember', '');
        if ($boardMemberId === '') {
            return new JSONResponse(['message' => 'boardMember parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        $entity = $this->objectService()->find(id: $id, register: 'decidesk', schema: 'board-material');
        if ($entity === null) {
            return new JSONResponse(['message' => 'Material not found'], Http::STATUS_NOT_FOUND);
        }

        $material   = $entity->jsonSerialize();
        $agendaItem = (string) ($material['agenda-item-koppeling'] ?? '');

        // REQ-005: materials for an agenda item are blocked until the member has filed a
        // conflict-of-interest declaration for that (member, agenda-item) pair.
        if ($agendaItem !== ''
            && $this->coiService->requireDeclaration(boardMemberId: $boardMemberId, agendaItemId: $agendaItem) === false
        ) {
            return new JSONResponse(
                ['message' => 'A conflict-of-interest declaration is required before accessing this agenda item'],
                Http::STATUS_FORBIDDEN
            );
        }

        if ($this->authService->canViewMaterial(boardMemberId: $boardMemberId, materialId: $id) === false) {
            return new JSONResponse(['message' => 'Access denied for this material'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse($material);

    }//end show()
}//end class
