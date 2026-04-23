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
use OCA\Decidesk\Service\MinutesService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
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
     * @param MinutesService              $minutesService           The minutes service
     * @param IUserSession                $userSession              The current user session
     * @param IGroupManager               $groupManager             Group manager for role checks
     * @param ObjectService               $objectService            The object service for direct data access
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
        private ALVMinutesService $alvMinutesService,
        private ActionItemExtractionService $extractionService,
        private MinutesService $minutesService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private ObjectService $objectService,
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
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function generateDraft(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent information disclosure across governance bodies
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
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
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

        // Gate ALL lifecycle transitions behind admin — prevents cross-tenant manipulation
        // by arbitrary authenticated users regardless of the lifecycle value passed
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
     * Generate an ALV minutes draft.
     *
     * POST /api/minutes/{minutesId}/generate-alv
     *
     * Returns { "preview": "<generated ALV content>" } on success.
     * Returns 400 when the request is invalid.
     * Returns 401 when the request is not authenticated.
     * Returns 404 when the Minutes object is not found.
     * Returns 422 when the meeting is not an ALV type.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function generateALVDraft(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent unauthorised ALV draft generation (OWASP A01 / ADR-005).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may generate ALV minutes drafts.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $result = $this->alvMinutesService->generateALVDraft($minutesId);
            return new JSONResponse(['preview' => $result['content']]);
        } catch (MissingObjectException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $code = (int) $e->getCode();
            if ($code === 422) {
                return new JSONResponse(
                    ['message' => $e->getMessage()],
                    Http::STATUS_UNPROCESSABLE_ENTITY
                );
            }

            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end generateALVDraft()

    /**
     * Distribute approved ALV minutes to members.
     *
     * POST /api/minutes/{minutesId}/distribute
     *
     * Returns { "notified": N } on success.
     * Returns 401 when not authenticated.
     * Returns 403 when Minutes lifecycle is not approved or signed.
     * Returns 404 when the Minutes object is not found.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function distributeALVMinutes(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent bulk governance-body notifications by arbitrary users (OWASP A01 / ADR-005).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may distribute ALV minutes.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $count = $this->alvMinutesService->distribute($minutesId);
            return new JSONResponse(['notified' => $count]);
        } catch (MissingObjectException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $code = (int) $e->getCode();
            if ($code === 403) {
                return new JSONResponse(
                    ['message' => $e->getMessage()],
                    Http::STATUS_FORBIDDEN
                );
            }

            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end distributeALVMinutes()

    /**
     * Extract action item candidates from minutes content.
     *
     * POST /api/minutes/{minutesId}/extract-action-items
     *
     * Returns { "candidates": [ { "title": string, "suggestedAssignee": string|null }, ... ] }
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function extractActionItems(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent action item extraction on arbitrary minutes (OWASP A01 / ADR-005).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may extract action items.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            // Fetch the Minutes object to get content.
            $minutes = $this->objectService->findObject(
                register: 'decidesk',
                schema: 'Minutes',
                id: $minutesId
            );

            if ($minutes === null) {
                return new JSONResponse(
                    ['message' => 'Minutes not found.'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $content    = $minutes['content'] ?? '';
            $candidates = $this->extractionService->extractFromContent(content: $content);

            return new JSONResponse(['candidates' => $candidates]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => 'Internal server error.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end extractActionItems()

    /**
     * Save extracted action items after user confirmation.
     *
     * POST /api/minutes/{minutesId}/save-extracted-action-items
     *
     * Body: { "confirmed": [ { "title": string, "assignee"?: string, "dueDate"?: string }, ... ] }
     *
     * Returns { "saved": N } on success.
     * Returns 400 when the Minutes lifecycle is published.
     * Returns 404 when the Minutes object is not found.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveExtractedActionItems(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent arbitrary users creating ActionItem objects on any Minutes (OWASP A01 / ADR-005).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may save extracted action items.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $confirmed = $this->request->getParam('confirmed', []);

            // Fetch Minutes to verify lifecycle before saving.
            $minutes = $this->objectService->findObject(
                register: 'decidesk',
                schema: 'Minutes',
                id: $minutesId
            );

            if ($minutes === null) {
                return new JSONResponse(
                    ['message' => 'Minutes not found.'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $lifecycle = $minutes['lifecycle'] ?? null;
            if ($lifecycle === 'published') {
                return new JSONResponse(
                    ['message' => 'Cannot save action items for published minutes.'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $count = $this->extractionService->saveExtracted(
                minutesId: $minutesId,
                confirmed: $confirmed
            );

            return new JSONResponse(['saved' => $count]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => 'Internal server error.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end saveExtractedActionItems()

    /**
     * Submit Minutes for approval.
     *
     * POST /api/minutes/{minutesId}/submit-for-approval
     *
     * Transitions lifecycle from draft to review and sends approval notifications.
     *
     * Returns 200 with updated lifecycle on success.
     * Returns 400 when lifecycle is not draft.
     * Returns 404 when Minutes not found.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function submitForApproval(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Require admin rights to prevent non-admin users from submitting minutes for approval (OWASP A01 / ADR-005).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may submit minutes for approval.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            // Fetch Minutes.
            $minutes = $this->objectService->findObject(
                register: 'decidesk',
                schema: 'Minutes',
                id: $minutesId
            );

            if ($minutes === null) {
                return new JSONResponse(
                    ['message' => 'Minutes not found.'],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Verify lifecycle is draft.
            if (($minutes['lifecycle'] ?? null) !== 'draft') {
                return new JSONResponse(
                    ['message' => 'Minutes must be in draft state to submit for approval.'],
                    Http::STATUS_CONFLICT
                );
            }

            // Transition lifecycle to review.
            $minutes['lifecycle'] = 'review';
            $this->objectService->saveObject(
                register: 'decidesk',
                schema: 'Minutes',
                object: $minutes
            );

            // Send approval notifications.
            $notified = $this->minutesService->notifyApproversOnSubmit(
                minutesId: $minutesId,
                actorId: $user->getUID()
            );

            return new JSONResponse(
                    [
                        'lifecycle' => 'review',
                        'notified'  => $notified,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => 'Internal server error.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end submitForApproval()
}//end class
