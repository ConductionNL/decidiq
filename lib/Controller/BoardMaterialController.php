<?php
/**
 * Decidesk Board Material Controller
 *
 * API endpoints for listing and viewing board materials, enforcing the
 * access-level compartments at view time and logging access to the audit trail.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin controller for board material access.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
 */
class BoardMaterialController extends Controller
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Constructor.
     *
     * @param IRequest                          $request     The request.
     * @param BoardMaterialAuthorizationService $authService The material authorization service.
     * @param IUserSession                      $userSession The user session.
     * @param ContainerInterface                $container   The DI container.
     * @param LoggerInterface                   $logger      The logger.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     */
    public function __construct(
        IRequest $request,
        private readonly BoardMaterialAuthorizationService $authService,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Resolve the current user UID.
     *
     * @return string|null
     */
    private function currentUid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();

    }//end currentUid()

    /**
     * List materials the caller's role may view.
     *
     * GET /api/board/materials?role=member
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $role = (string) ($this->request->getParam('role', 'member'));

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister(self::REGISTER);
            $objectService->setSchema('board-material');
            $result    = $objectService->findAll([]);
            $materials = [];
            foreach (($result['results'] ?? $result) as $item) {
                $entry = (array) $item;
                if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
                    $entry = $item->jsonSerialize();
                }

                $materials[] = $entry;
            }

            $filtered = $this->authService->filterMaterialsByRole($materials, $role);
            return new JSONResponse(['results' => $filtered], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: board material list failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not list materials'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end index()

    /**
     * View a single material if the caller's role permits, logging the access.
     *
     * GET /api/board/materials/{id}?role=member
     *
     * @param string $id The material UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $role = (string) ($this->request->getParam('role', 'member'));

        try {
            $granted = $this->authService->canViewMaterial($uid, $role, $id, $uid);
            if ($granted === false) {
                return new JSONResponse(['message' => 'Access to this material is not permitted for your role'], Http::STATUS_FORBIDDEN);
            }

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $material      = $objectService->find(id: $id, register: self::REGISTER, schema: 'board-material');
            if ($material === null) {
                return new JSONResponse(['message' => 'Material not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($material->jsonSerialize(), Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: board material show failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not load material'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end show()
}//end class
