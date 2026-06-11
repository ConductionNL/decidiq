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
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
     * @param ParticipantResolver         $participantResolver      Participant resolver for meeting-based role checks
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
        private ParticipantResolver $participantResolver,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Require chair, secretary, or NC admin role for operations on a Minutes record.
     *
     * Resolves the associated meeting via the minutes relations map and checks
     * participant records for a chair or secretary role, mirroring AgendaController.
     *
     * @param string $minutesId UUID of the Minutes object
     *
     * @return JSONResponse|null Null if authorised, a 401/403 JSONResponse otherwise.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function requireChairOrAdminForMinutes(string $minutesId): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $userId = $user->getUID();

        if ($this->groupManager->isAdmin($userId) === true) {
            return null;
        }

        // Resolve the meeting UUID from the minutes object.
        $minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
        if ($minutesEntity === null) {
            // Let the calling method handle the 404; return null so the caller can produce the 404 response.
            return null;
        }

        $minutes   = $minutesEntity->jsonSerialize();
        $meetingId = null;

        $meetingRelation = $minutes['relations']['meeting'] ?? $minutes['meeting'] ?? null;
        if ($meetingRelation !== null) {
            if (is_array($meetingRelation) === true) {
                $meetingId = $meetingRelation['id'] ?? null;
            } else {
                $meetingId = (string) $meetingRelation;
            }
        }

        if ($meetingId === null || $meetingId === '') {
            // No meeting linked — deny non-admins.
            return new JSONResponse(
                ['message' => 'Forbidden: could not resolve meeting for authorisation.'],
                Http::STATUS_FORBIDDEN
            );
        }

        if ($this->participantResolver->hasRole(
            meetingId: $meetingId,
            nextcloudUid: $userId,
            roles: ['chair', 'secretary'],
        ) === true
        ) {
            return null;
        }

        return new JSONResponse(
            ['message' => 'Forbidden: chair or secretary role required for this minutes record.'],
            Http::STATUS_FORBIDDEN
        );

    }//end requireChairOrAdminForMinutes()

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
    #[NoAdminRequired]
    public function generateDraft(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
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
    #[NoAdminRequired]
    public function transition(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
        }

        $user = $this->userSession->getUser();

        $newLifecycle = $this->request->getParam('lifecycle');
        if ($newLifecycle === null || is_string($newLifecycle) === false || $newLifecycle === '') {
            return new JSONResponse(
                ['message' => 'Missing or invalid lifecycle parameter.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $displayName = '';
        if ($user !== null) {
            $displayName = $user->getDisplayName();
        }

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
    #[NoAdminRequired]
    public function generateALVDraft(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
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
    #[NoAdminRequired]
    public function distributeALVMinutes(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
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
    #[NoAdminRequired]
    public function extractActionItems(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            // Fetch the Minutes object to get content.
            $minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
            $minutes       = null;
            if ($minutesEntity !== null) {
                $minutes = $minutesEntity->jsonSerialize();
            }

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
    #[NoAdminRequired]
    public function saveExtractedActionItems(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $confirmed = $this->request->getParam('confirmed', []);

            // Fetch Minutes to verify lifecycle before saving.
            $minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
            $minutes       = null;
            if ($minutesEntity !== null) {
                $minutes = $minutesEntity->jsonSerialize();
            }

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
    #[NoAdminRequired]
    public function submitForApproval(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
        }

        $user = $this->userSession->getUser();

        try {
            // Fetch Minutes.
            $minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
            $minutes       = null;
            if ($minutesEntity !== null) {
                $minutes = $minutesEntity->jsonSerialize();
            }

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
                schema: 'minutes',
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
