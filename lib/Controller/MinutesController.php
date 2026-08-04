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

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Exception\MissingRelationException;
use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Service\MinutesAccessGuard;
use OCA\Decidesk\Service\MinutesDocumentService;
use OCA\Decidesk\Service\MinutesErrorResponder;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\Decidesk\Service\MinutesService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
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
     * Translates a caught domain exception into this endpoint's documented status.
     *
     * @var MinutesErrorResponder
     */
    private readonly MinutesErrorResponder $errorResponder;

    /**
     * Constructor for MinutesController.
     *
     * Note: userId is intentionally NOT injected via DI. The Nextcloud container
     * caches service instances as singletons; resolving the UID at construction
     * time would freeze it as null when the container is first built in a cron or
     * pre-flight context. The UID is resolved per-request via $this->userSession.
     *
     * @param IRequest                    $request           The HTTP request
     * @param MinutesGenerationService    $generationService The generation service
     * @param ALVMinutesService           $alvMinutesService The ALV minutes service
     * @param ActionItemExtractionService $extractionService The extraction service
     * @param MinutesService              $minutesService    The minutes service
     * @param IUserSession                $userSession       The current user session
     * @param ObjectService               $objectService     The object service for direct data access
     * @param MinutesAccessGuard          $accessGuard       Per-object minutes authorisation
     * @param MinutesDocumentService      $documentService   Document generation + persistence service
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $generationService,
        private ALVMinutesService $alvMinutesService,
        private ActionItemExtractionService $extractionService,
        private MinutesService $minutesService,
        private IUserSession $userSession,
        private ObjectService $objectService,
        private readonly MinutesAccessGuard $accessGuard,
        private MinutesDocumentService $documentService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
        $this->errorResponder = new MinutesErrorResponder();
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
        return $this->accessGuard->requireChairOrAdmin(minutesId: $minutesId);

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
            $preview = $this->generationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\Exception $e) {
            // Order matters: MissingObjectException extends InvalidArgumentException
            // and MissingRelationException extends RuntimeException.
            return $this->errorResponder->translate(
                error: $e,
                statusMap: [
                    InvalidArgumentException::class => Http::STATUS_NOT_FOUND,
                    MissingRelationException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
                    RuntimeException::class         => Http::STATUS_SERVICE_UNAVAILABLE,
                ]
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

        $newLifecycle = $this->requestedLifecycle();
        if ($newLifecycle === null) {
            return new JSONResponse(
                ['message' => 'Missing or invalid lifecycle parameter.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $updated = $this->generationService->transition(
                minutesId: $minutesId,
                newLifecycle: $newLifecycle,
                displayName: $this->currentDisplayName(),
            );
            return new JSONResponse($updated);
        } catch (\Exception $e) {
            return $this->errorResponder->translate(
                error: $e,
                statusMap: [
                    MissingObjectException::class   => Http::STATUS_NOT_FOUND,
                    InvalidArgumentException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
                    RuntimeException::class         => Http::STATUS_SERVICE_UNAVAILABLE,
                ]
            );
        }//end try

    }//end transition()

    /**
     * The requested target lifecycle, or null when the parameter is unusable.
     *
     * @return string|null The requested lifecycle.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function requestedLifecycle(): ?string
    {
        $newLifecycle = $this->request->getParam('lifecycle');
        if (is_string($newLifecycle) === false || $newLifecycle === '') {
            return null;
        }

        return $newLifecycle;

    }//end requestedLifecycle()

    /**
     * The display name of the authenticated user, for server-side attribution.
     *
     * @return string The display name, or an empty string when anonymous.
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    private function currentDisplayName(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getDisplayName();

    }//end currentDisplayName()

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
            return $this->errorResponder->translateCode(
                error: $e,
                expectedCode: 422,
                matchedStatus: Http::STATUS_UNPROCESSABLE_ENTITY,
                fallbackStatus: Http::STATUS_BAD_REQUEST
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
            return $this->errorResponder->translateCode(
                error: $e,
                expectedCode: 403,
                matchedStatus: Http::STATUS_FORBIDDEN,
                fallbackStatus: Http::STATUS_BAD_REQUEST
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

    /**
     * Reject Minutes in review back to draft with a mandatory comment.
     *
     * POST /api/minutes/{minutesId}/reject
     *
     * Body: { "comment": "<why the minutes are rejected>" }
     *
     * Returns 200 with the updated Minutes object on success.
     * Returns 401/403 per the chair/secretary guard.
     * Returns 404 when the Minutes object is not found.
     * Returns 422 when the comment is missing or the lifecycle is not review.
     * Returns 503 when OpenRegister is unavailable.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     */
    #[NoAdminRequired]
    public function reject(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
        }

        $comment = $this->request->getParam('comment');
        if (is_string($comment) === false) {
            $comment = '';
        }

        $user = $this->userSession->getUser();

        try {
            $updated = $this->generationService->reject(
                minutesId: $minutesId,
                comment: $comment,
                userId: $user->getUID(),
            );
            return new JSONResponse($updated);
        } catch (\Exception $e) {
            return $this->errorResponder->translate(
                error: $e,
                statusMap: [
                    MissingObjectException::class   => Http::STATUS_NOT_FOUND,
                    InvalidArgumentException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
                    RuntimeException::class         => Http::STATUS_SERVICE_UNAVAILABLE,
                ]
            );
        }//end try

    }//end reject()

    /**
     * Generate a minutes document and persist it into the meeting folder.
     *
     * POST /api/minutes/{minutesId}/generate-document
     *
     * Body: { "format": "markdown" | "pdf" } (default: markdown)
     *
     * The PDF format uses Docudesk when its PdfService is resolvable; when it
     * is not, a markdown document is produced and the response carries
     * `docudesk: false` plus an explanatory note (honest fallback, never a
     * silent failure).
     *
     * Returns 200 with { path, format, docudesk, note? } on success.
     * Returns 401/403 per the chair/secretary guard.
     * Returns 404 when the Minutes object is not found.
     * Returns 422 when the format is unsupported or no Meeting is linked.
     * Returns 503 when OpenRegister or the Files backend is unavailable.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     */
    #[NoAdminRequired]
    public function generateDocument(string $minutesId): JSONResponse
    {
        $denied = $this->requireChairOrAdminForMinutes(minutesId: $minutesId);
        if ($denied !== null) {
            return $denied;
        }

        $format = $this->request->getParam('format', 'markdown');
        if (is_string($format) === false || $format === '') {
            $format = 'markdown';
        }

        try {
            $result = $this->documentService->generate(
                minutesId: $minutesId,
                format: $format,
                displayName: $this->currentDisplayName(),
            );
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponder->translate(
                error: $e,
                statusMap: [
                    MissingObjectException::class   => Http::STATUS_NOT_FOUND,
                    MissingRelationException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
                    InvalidArgumentException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
                    RuntimeException::class         => Http::STATUS_SERVICE_UNAVAILABLE,
                ]
            );
        }//end try

    }//end generateDocument()
}//end class
