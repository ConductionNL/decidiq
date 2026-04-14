<?php

/**
 * Decidesk Minutes Controller
 *
 * Controller for Minutes-specific actions including draft generation.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
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
 * Thin controller exposing the minutes draft-generation endpoint.
 *
 * A single POST action calls MinutesGenerationService::generateDraft() and
 * returns the generated text as a JSON preview payload.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.2
 */
class MinutesController extends Controller
{
    /**
     * Constructor for MinutesController.
     *
     * @param IRequest                 $request                  The request object
     * @param MinutesGenerationService $minutesGenerationService The minutes generation service
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a draft for the given minutes record.
     *
     * Fetches the linked Meeting and its AgendaItems, Motions, VotingRounds, and
     * Decisions, then renders them into a structured Dutch text template. Returns
     * only a preview — the caller is responsible for persisting the content.
     *
     * @param string $minutesId UUID of the Minutes object
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.2
     *
     * @return JSONResponse JSON body: { "preview": "<generated text>" }
     *                      or { "message": "<error>" } with an appropriate HTTP status
     */
    public function generateDraft(string $minutesId): JSONResponse
    {
        if ($minutesId === '') {
            return new JSONResponse(
                ['message' => 'minutesId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $preview = $this->minutesGenerationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\RuntimeException $e) {
            // Distinguish "not found" from other runtime errors.
            if (str_contains($e->getMessage(), 'not found') === true) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'An unexpected error occurred. See server log for details.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end generateDraft()
}//end class
