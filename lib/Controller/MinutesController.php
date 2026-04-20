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
use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\Decidesk\Service\ALVMinutesService;
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
     * Constructor for MinutesController.
     *
     * Note: userId is intentionally NOT injected via DI. The Nextcloud container
     * caches service instances as singletons; resolving the UID at construction
     * time would freeze it as null when the container is first built in a cron or
     * pre-flight context. The UID is resolved per-request via $this->userSession.
     *
     * @param IRequest                    $request                  The HTTP request
     * @param MinutesGenerationService    $minutesGenerationService The generation service
     * @param ALVMinutesService           $alvMinutesService        The ALV minutes service
     * @param ActionItemExtractionService $extractionService        The extraction service
     * @param IUserSession                $userSession              The current user session
     * @param IGroupManager               $groupManager             Group manager for role checks
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
        private ALVMinutesService $alvMinutesService,
        private ActionItemExtractionService $extractionService,
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
     * Returns 403 when the caller is not a Nextcloud admin.
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
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent information disclosure across governance bodies.
        // (OWASP A01 — Broken Access Control / ADR-005 tenant isolation).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may generate minutes drafts.'],
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

        // Gate ALL lifecycle transitions behind admin — prevents cross-tenant manipulation.
        // by arbitrary authenticated users regardless of the lifecycle value passed.
        // (OWASP A01 — Broken Access Control / ADR-005 tenant isolation).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may perform lifecycle transitions on minutes.'],
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

    /**
     * Generate an ALV draft for the given Minutes object.
     *
     * POST /api/minutes/{minutesId}/generate-alv
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     */
    public function generateAlv(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent unauthorized access to governance data.
        // (OWASP A01 — Broken Access Control / ADR-005 tenant isolation).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may generate ALV minutes.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $result = $this->alvMinutesService->generateALVDraft($minutesId);
            return new JSONResponse(['preview' => $result['content']]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to generate ALV minutes.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
    }//end generateAlv()

    /**
     * Distribute ALV minutes to participants.
     *
     * POST /api/minutes/{minutesId}/distribute
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
     */
    public function distributeAlv(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent unauthorized notification dispatch.
        // (OWASP A01 — Broken Access Control / ADR-005 tenant isolation).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may distribute minutes.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $count = $this->alvMinutesService->distribute($minutesId);
            return new JSONResponse(['notified' => $count]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_CONFLICT
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to distribute minutes.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
    }//end distributeAlv()

    /**
     * Extract action item candidates from minutes content.
     *
     * POST /api/minutes/{minutesId}/extract-action-items
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
     */
    public function extractActionItems(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent unauthorized access to governance data.
        // (OWASP A01 — Broken Access Control / ADR-005 tenant isolation).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may extract action items.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */

            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            $minutes       = $objectService->find(id: $minutesId);

            if ($minutes === null) {
                return new JSONResponse(
                    ['message' => 'Minutes not found.'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $minutesObj = $minutes->getObject();
            $content    = $minutesObj['content'] ?? '';

            $candidates = $this->extractionService->extractFromContent($content);
            return new JSONResponse(['candidates' => $candidates]);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to extract action items.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }//end try
    }//end extractActionItems()

    /**
     * Save extracted action items.
     *
     * POST /api/minutes/{minutesId}/save-extracted-action-items
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
     */
    public function saveExtractedActionItems(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent unauthorized object creation.
        // (OWASP A01 — Broken Access Control / ADR-005 tenant isolation).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may save action items.'],
                Http::STATUS_FORBIDDEN
            );
        }

        $confirmed = $this->request->getParam('confirmed');
        if (is_array($confirmed) === false) {
            return new JSONResponse(
                ['message' => 'Invalid confirmed array.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $count = $this->extractionService->saveExtracted($minutesId, $confirmed);
            return new JSONResponse(['saved' => $count]);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to save action items.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
    }//end saveExtractedActionItems()

    /**
     * Submit minutes for approval.
     *
     * POST /api/minutes/{minutesId}/submit-for-approval
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
     */
    public function submitForApproval(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent unauthorized state transitions.
        // (OWASP A01 — Broken Access Control / ADR-005 tenant isolation).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may submit minutes for approval.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */

            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');

            $minutes = $objectService->find(id: $minutesId);
            if ($minutes === null) {
                return new JSONResponse(
                    ['message' => 'Minutes not found.'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $minutesObj = $minutes->getObject();
            $lifecycle  = $minutesObj['lifecycle'] ?? 'draft';

            if ($lifecycle !== 'draft') {
                return new JSONResponse(
                    ['message' => 'Minutes must be in draft state to submit for approval.'],
                    Http::STATUS_CONFLICT
                );
            }

            // Update lifecycle to review.
            $updated = $objectService->updateFromArray(
                id: $minutesId,
                object: ['lifecycle' => 'review'],
                updateVersion: true,
                patch: true
            );

            return new JSONResponse(
                [
                    'lifecycle' => 'review',
                    'notified'  => 0,
                // Placeholder for actual notification count.
                ]
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Failed to submit for approval.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }//end try
    }//end submitForApproval()
}//end class
