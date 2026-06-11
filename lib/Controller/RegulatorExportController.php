<?php
/**
 * Decidesk Regulator Export Controller
 *
 * Admin/secretary-gated REST surface for the Phase 6 regulator export
 * service.
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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the regulator export service.
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
     * Generate a regulator export and persist the export row.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function generate(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $boardId   = (string) $this->request->getParam('boardId', '');
        $template  = (string) $this->request->getParam('template', '');
        $startDate = (string) $this->request->getParam('startDate', '');
        $endDate   = (string) $this->request->getParam('endDate', '');
        $format    = (string) $this->request->getParam('format', 'csv');
        $regulator = (string) $this->request->getParam('regulator', '');

        if ($boardId === '' || $template === '' || $startDate === '' || $endDate === '') {
            return new JSONResponse(
                ['message' => "Missing required parameter 'boardId', 'template', 'startDate' or 'endDate'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->exportService->generate(
            $boardId,
            $template,
            $startDate,
            $endDate,
            $format,
            ['regulator' => $regulator]
        );

        if (($result['success'] ?? false) === false) {
            return new JSONResponse(
                ['message' => (string) ($result['message'] ?? 'Failed to generate regulator export.')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse(
            [
                'export'      => $result['export'],
                'contentType' => $result['contentType'],
                'message'     => $result['message'],
            ],
            Http::STATUS_CREATED
        );

    }//end generate()

    /**
     * Download a previously generated export.
     *
     * @param string $id UUID of the export row
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     *
     * @return Response
     */
    #[NoAdminRequired]
    public function download(string $id): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $result = $this->exportService->download($id);
        if (($result['success'] ?? false) === false) {
            $message = (string) ($result['message'] ?? 'Failed to download.');
            $status  = (stripos($message, 'not found') !== false ? Http::STATUS_NOT_FOUND : Http::STATUS_UNPROCESSABLE_ENTITY);
            return new JSONResponse(['message' => $message], $status);
        }

        $contentType = (string) $result['contentType'];
        $extension   = 'csv';
        if ($contentType === 'application/pdf') {
            $extension = 'pdf';
        } else if ($contentType === 'application/json') {
            $extension = 'json';
        }

        $response = new DataDisplayResponse(
            $result['body'],
            Http::STATUS_OK,
            ['Content-Type' => $contentType]
        );
        $response->addHeader(
            'Content-Disposition',
            'attachment; filename="regulator-export-'.$id.'.'.$extension.'"'
        );
        return $response;

    }//end download()

    /**
     * Return 401 / 403 when the caller is not an admin; null otherwise.
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
