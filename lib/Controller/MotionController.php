<?php

/**
 * Decidesk Motion Controller
 *
 * Thin API controller for motion lifecycle, co-signatory, and budget impact operations.
 * All business logic is delegated to MotionService.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MotionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin API controller for Motion lifecycle and co-signatory operations.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
 */
class MotionController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest      $request       The HTTP request
     * @param MotionService $motionService The motion business logic service
     * @param IUserSession  $userSession   Current user session
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private MotionService $motionService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Transition a Motion to a new lifecycle state.
     *
     * POST /api/motions/{id}/transition
     * Body: { "newState": "debating" }
     *
     * @param string $id The Motion UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function transition(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState', '');
        $actorId  = $this->userSession->getUser()?->getUID() ?? '';

        try {
            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'motion',
                newState: $newState,
                actorId: $actorId
            );
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end transition()

    /**
     * Send co-signature invitation notifications to Participants.
     *
     * POST /api/motions/{id}/co-sign-request
     * Body: { "participantIds": ["uuid1", "uuid2"] }
     *
     * @param string $id The Motion UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function coSignRequest(string $id): JSONResponse
    {
        $participantIds = $this->request->getParam('participantIds', []);

        try {
            $this->motionService->requestCoSignature(motionId: $id, participantIds: $participantIds);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end coSignRequest()

    /**
     * Confirm a co-signature and append the display name to the Motion's coSigners.
     *
     * POST /api/motions/{id}/co-sign-confirm
     * Body: { "displayName": "M. de Vries" }
     *
     * @param string $id The Motion UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function coSignConfirm(string $id): JSONResponse
    {
        $displayName = $this->request->getParam('displayName', '');

        try {
            $this->motionService->addCoSigner(motionId: $id, participantDisplayName: $displayName);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end coSignConfirm()

    /**
     * Save or update the budget impact note on a Motion.
     *
     * POST /api/motions/{id}/budget-impact
     * Body: { "budgetLine": "...", "amountDelta": 250000, "rationale": "..." }
     *
     * @param string $id The Motion UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function budgetImpact(string $id): JSONResponse
    {
        $budgetLine  = $this->request->getParam('budgetLine', '');
        $amountDelta = (float) $this->request->getParam('amountDelta', 0);
        $rationale   = $this->request->getParam('rationale', '');

        try {
            $this->motionService->saveBudgetImpact(
                motionId: $id,
                budgetLine: $budgetLine,
                amountDelta: $amountDelta,
                rationale: $rationale
            );
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end budgetImpact()

    /**
     * Transition an Amendment to a new lifecycle state.
     *
     * POST /api/amendments/{id}/transition
     * Body: { "newState": "debating" }
     *
     * @param string $id The Amendment UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function amendmentTransition(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState', '');
        $actorId  = $this->userSession->getUser()?->getUID() ?? '';

        try {
            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'amendment',
                newState: $newState,
                actorId: $actorId
            );
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end amendmentTransition()
}//end class
