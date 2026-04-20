<?php

/**
 * Decidesk Minutes Version Controller
 *
 * Handles API endpoints for Minutes version history and diff.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MinutesVersionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin controller for Minutes version endpoints.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
 */
class MinutesVersionController extends Controller
{
    /**
     * Constructor for MinutesVersionController.
     *
     * @param IRequest              $request        The HTTP request
     * @param MinutesVersionService $versionService The version service
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function __construct(
        IRequest $request,
        private MinutesVersionService $versionService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get version history for a Minutes object.
     *
     * GET /api/minutes/{id}/versions
     *
     * @param string $id UUID of the Minutes object
     *
     * @return JSONResponse with `{ versions: [...] }`
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function getVersionHistory(string $id): JSONResponse
    {
        $versions = $this->versionService->getVersionHistory($id);
        return new JSONResponse(['versions' => $versions]);
    }//end getVersionHistory()

    /**
     * Get content of a specific version.
     *
     * GET /api/minutes/{id}/versions/{version}
     *
     * @param string $id      UUID of the Minutes object
     * @param string $version Version number as string
     *
     * @return JSONResponse with `{ version, content, savedAt, savedBy }` or 404
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function getVersionContent(string $id, string $version): JSONResponse
    {
        $versionNum = (int) $version;
        $content    = $this->versionService->getVersionContent($id, $versionNum);

        if ($content === null) {
            return new JSONResponse(['message' => 'Version not found'], 404);
        }

        return new JSONResponse($content);
    }//end getVersionContent()

    /**
     * Get diff between two versions.
     *
     * GET /api/minutes/{id}/versions/{versionA}/diff/{versionB}
     *
     * @param string $id       UUID of the Minutes object
     * @param string $versionA First version number as string
     * @param string $versionB Second version number as string
     *
     * @return JSONResponse with `{ diff: [...] }` or 404
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function diffVersions(string $id, string $versionA, string $versionB): JSONResponse
    {
        $vNumA = (int) $versionA;
        $vNumB = (int) $versionB;

        $contentA = $this->versionService->getVersionContent($id, $vNumA);
        $contentB = $this->versionService->getVersionContent($id, $vNumB);

        if ($contentA === null || $contentB === null) {
            return new JSONResponse(['message' => 'One or both versions not found'], 404);
        }

        $contentAStr = $contentA['content'] ?? '';
        $contentBStr = $contentB['content'] ?? '';

        $diff = $this->versionService->diffVersions($contentAStr, $contentBStr);
        return new JSONResponse(['diff' => $diff]);
    }//end diffVersions()
}//end class
