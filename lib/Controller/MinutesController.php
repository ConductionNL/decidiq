<?php

/**
 * Decidesk Minutes Controller
 *
 * Controller for Minutes-specific operations such as draft generation and lifecycle transitions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Minutes-specific operations.
 *
 * Provides endpoints for generating a structured Dutch draft and for enforcing
 * server-side lifecycle transitions (draft → review → approved → signed → published).
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesController extends Controller
{
    /**
     * Constructor for MinutesController.
     *
     * @param IRequest                 $request                  The HTTP request
     * @param MinutesGenerationService $minutesGenerationService The generation service
     * @param IUserSession             $userSession              The user session (for auth checks)
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a Dutch draft text for the given Minutes object.
     *
     * POST /api/minutes/{minutesId}/generate-draft
     *
     * Returns { "preview": "<generated text>" } on success.
     * Returns 401 when the request is unauthenticated.
     * Returns 404 when the Minutes object is not found.
     * Returns 503 when OpenRegister is unavailable.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function generateDraft(string $minutesId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $preview = $this->minutesGenerationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

    }//end generateDraft()

    /**
     * Transition a Minutes object to the next lifecycle state (server-side enforced).
     *
     * POST /api/minutes/{minutesId}/transition
     * Body: { "lifecycle": "<target-state>" }
     *
     * Validates that the transition follows the allowed sequence and populates
     * server-side fields (approvedAt, signedBy) from the authenticated session.
     *
     * Returns 200 with the updated Minutes object on success.
     * Returns 401 when the request is unauthenticated.
     * Returns 400 when the transition is not valid (wrong sequence or unknown state).
     * Returns 404 when the Minutes object is not found.
     * Returns 503 when OpenRegister is unavailable.
     *
     * @param string $minutesId UUID of the Minutes object
     * @param string $lifecycle The target lifecycle state
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function transition(string $minutesId, string $lifecycle): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $updated = $this->minutesGenerationService->transition(
                minutesId: $minutesId,
                newLifecycle: $lifecycle,
                displayName: $user->getDisplayName(),
            );
            return new JSONResponse($updated);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => 'Service temporarily unavailable'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

    }//end transition()
}//end class
