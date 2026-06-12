<?php
/**
 * Decidesk Regulator Export Controller
 *
 * Phase 6 — admin/secretary-gated REST surface for generating and re-emitting
 * regulator exports (PDF skeleton or CSV). Each generated export is mirrored
 * to the hash-chained audit log inside the service.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\RegulatorExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for regulator exports.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
 */
class RegulatorExportController extends Controller
{
    use BoardPortalControllerTrait;

    /**
     * Constructor.
     *
     * @param IRequest               $request       HTTP request
     * @param RegulatorExportService $exportService Export service
     * @param IUserSession           $userSession   User session
     * @param IGroupManager          $groupManager  Group manager
     */
    public function __construct(
        IRequest $request,
        private readonly RegulatorExportService $exportService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a regulator export and stream the body as an attachment.
     *
     * Body params:
     * - boardId (string, required)
     * - scope (string: resolutions|minutes|audit-log, required)
     * - format (string: pdf|csv, default pdf)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return Response
     */
    public function generate(): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $user = $this->userSession->getUser();
        $uid  = 'system';
        if ($user !== null) {
            $uid = $user->getUID();
        }

        $boardId = (string) $this->request->getParam('boardId', '');
        $scope   = (string) $this->request->getParam('scope', 'resolutions');
        $format  = (string) $this->request->getParam('format', 'pdf');

        if ($boardId === '') {
            return new JSONResponse(
                ['message' => "Missing required parameter 'boardId'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->exportService->generate($boardId, $scope, $format, $uid);
        if (($result['success'] ?? false) === false) {
            return new JSONResponse(
                ['message' => (string) ($result['message'] ?? 'Failed to generate export.')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $response = new DataDisplayResponse(
            $result['body'],
            Http::STATUS_OK,
            ['Content-Type' => $result['contentType']]
        );
        $response->addHeader('Content-Disposition', 'attachment; filename="'.$result['filename'].'"');
        $response->addHeader('X-Decidesk-Export-Sha256', (string) ($result['export']['sha256'] ?? ''));
        return $response;

    }//end generate()

    /**
     * Re-emit a persisted export by id (idempotent regeneration).
     *
     * @param string $id UUID of the persisted export record
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return Response
     */
    public function download(string $id): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $user = $this->userSession->getUser();
        $uid  = 'system';
        if ($user !== null) {
            $uid = $user->getUID();
        }

        $result = $this->exportService->download($id, $uid);
        if (($result['success'] ?? false) === false) {
            $message = (string) ($result['message'] ?? 'Failed to download export.');
            $status  = Http::STATUS_UNPROCESSABLE_ENTITY;
            if (stripos($message, 'not found') !== false) {
                $status = Http::STATUS_NOT_FOUND;
            }

            return new JSONResponse(['message' => $message], $status);
        }

        $response = new DataDisplayResponse(
            $result['body'],
            Http::STATUS_OK,
            ['Content-Type' => $result['contentType']]
        );
        $response->addHeader('Content-Disposition', 'attachment; filename="'.$result['filename'].'"');
        return $response;

    }//end download()

    /**
     * List previously generated exports for a board.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $boardId = (string) $this->request->getParam('boardId', '');
        if ($boardId === '') {
            return new JSONResponse(
                ['message' => "Missing required parameter 'boardId'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->exportService->listExports($boardId);
        return new JSONResponse(
            [
                'results' => $result['exports'],
                'total'   => $result['count'],
            ]
        );

    }//end index()

    /**
     * Reject the caller with 401/403 when they are not a Nextcloud admin.
     *
     * @return JSONResponse|null
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Administrator role required.'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireAdmin()
}//end class
