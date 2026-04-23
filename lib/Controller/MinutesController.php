<?php

/**
 * Decidesk Minutes Controller
 *
 * Controller for Minutes-specific operations such as draft generation
 * and server-side lifecycle transition enforcement.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
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
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use OCA\Decidesk\Service\ActionAuthService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
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
     * Constructor for MinutesController.
     *
     * Note: userId is intentionally NOT injected via DI. The Nextcloud container
     * caches service instances as singletons; resolving the UID at construction
     * time would freeze it as null when the container is first built in a cron or
     * pre-flight context. The UID is resolved per-request via $this->userSession.
     *
     * @param IRequest                 $request                  The HTTP request
     * @param MinutesGenerationService $minutesGenerationService The generation service
     * @param IUserSession             $userSession              The current user session
     * @param ActionAuthService        $actionAuth               ADR-023 action authorization service
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
        private IUserSession $userSession,
        private ActionAuthService $actionAuth,
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
     * Returns 403 when the caller's groups don't include any group mapped to
     *   the `minutes.generate-draft` action in the admin matrix.
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
     * @spec openspec/architecture/adr-023-action-authorization.md
     */
    #[NoAdminRequired]
    public function generateDraft(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // ADR-023 — action-level authorization via admin matrix.
        try {
            $this->actionAuth->requireAction($user, 'minutes.generate-draft');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_FORBIDDEN
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
        }//end try

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
     * All lifecycle transitions require the caller to hold Nextcloud admin rights
     * (governance-role enforcement / OWASP A01 — Broken Access Control).
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
     * @spec openspec/architecture/adr-023-action-authorization.md
     */
    #[NoAdminRequired]
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

        // ADR-023 — action-level authorization via admin matrix.
        try {
            $this->actionAuth->requireAction($user, 'minutes.transition');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_FORBIDDEN
            );
        }

        $displayName = $user->getDisplayName();

        try {
            $updated = $this->minutesGenerationService->transition(
                minutesId: $minutesId,
                newLifecycle: $newLifecycle,
                displayName: $displayName,
            );
            return new JSONResponse($updated);
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
