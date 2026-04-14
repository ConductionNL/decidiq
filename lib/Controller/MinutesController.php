<?php

/**
 * Decidesk Minutes Controller
 *
 * Controller for Minutes-specific operations such as draft generation
 * and server-side lifecycle transition enforcement.
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
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Minutes-specific operations.
 *
 * Provides endpoints for generating a structured Dutch draft from
 * a linked meeting's data and for enforcing server-side lifecycle
 * transitions with signatory attribution.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesController extends Controller
{

    /**
     * Lifecycle transitions that require a governance role (chair or secretary).
     *
     * Any authenticated user may move minutes to "review"; the remaining
     * privileged states require Nextcloud admin rights.
     */
    private const PRIVILEGED_TRANSITIONS = ['approved', 'signed', 'published'];

    /**
     * Constructor for MinutesController.
     *
     * @param IRequest                 $request                  The HTTP request
     * @param MinutesGenerationService $minutesGenerationService The generation service
     * @param IUserSession             $userSession              The current user session
     * @param IGroupManager            $groupManager             Group manager for role checks
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a Dutch draft text for the given Minutes object.
     *
     * POST /api/minutes/{minutesId}/generate-draft
     *
     * Returns { "preview": "<generated text>" } on success.
     * Returns 401 when the request is not authenticated.
     * Returns 404 when the Minutes object is not found.
     * Returns 422 when no Meeting is linked to the Minutes record.
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
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $preview = $this->minutesGenerationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (MissingRelationException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

    }//end generateDraft()

    /**
     * Transition the lifecycle state of a Minutes object server-side.
     *
     * POST /api/minutes/{minutesId}/transition
     *
     * Validates that the requested lifecycle value is the immediate next step in
     * the fixed sequence (draft → review → approved → signed → published).
     * Populates signedBy from the authenticated server-side user session for the
     * "approved" and "signed" transitions so that forged client-side attribution
     * is impossible.
     *
     * The "approved", "signed", and "published" transitions require the caller
     * to hold Nextcloud admin rights (governance-role enforcement).
     *
     * Returns 200 with the updated Minutes object on success.
     * Returns 401 when the request is not authenticated.
     * Returns 403 when the user lacks the required governance role.
     * Returns 404 when the Minutes object is not found.
     * Returns 422 when the requested transition is not the valid next step.
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
    public function transition(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $newLifecycle = $this->request->getParam('lifecycle');
        if ($newLifecycle === null || is_string($newLifecycle) === false || $newLifecycle === '') {
            return new JSONResponse(
                ['message' => 'Missing or invalid lifecycle parameter.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Privileged transitions (approved, signed, published) require admin rights.
        // This prevents any authenticated user from signing or publishing official records.
        if (in_array($newLifecycle, self::PRIVILEGED_TRANSITIONS, true) === true) {
            if ($this->groupManager->isAdmin($user->getUID()) === false) {
                return new JSONResponse(
                    ['message' => 'Insufficient permissions: governance role required for this transition.'],
                    Http::STATUS_FORBIDDEN
                );
            }
        }

        $displayName = $user->getDisplayName();

        try {
            $result = $this->minutesGenerationService->transition(
                minutesId: $minutesId,
                newLifecycle: $newLifecycle,
                displayName: $displayName
            );
            return new JSONResponse($result);
        } catch (MissingObjectException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }//end try

    }//end transition()
}//end class
