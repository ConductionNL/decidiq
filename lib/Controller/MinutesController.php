<?php

/**
 * Minutes Controller
 *
 * Thin controller for minutes-specific API actions.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin controller for minutes draft generation.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
 */
class MinutesController extends Controller
{

    /**
     * Constructor for the MinutesController.
     *
     * @param IRequest                  $request                  The request object
     * @param MinutesGenerationService  $minutesGenerationService The generation service
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function __construct(
        IRequest $request,
        private readonly MinutesGenerationService $minutesGenerationService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a draft minutes document from the linked meeting data.
     *
     * @NoAdminRequired
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse JSON with preview text or error
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function generateDraft(string $minutesId): JSONResponse
    {
        try {
            $preview = $this->minutesGenerationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }
    }//end generateDraft()
}//end class
