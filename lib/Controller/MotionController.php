<?php

/**
 * Decidesk Motion Controller
 *
 * Thin controller for motion lifecycle, co-signatory, and budget-impact endpoints.
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
 * Thin controller for motion lifecycle, co-signatory, and budget-impact endpoints.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionController extends Controller
{
    /**
     * Constructor for MotionController.
     *
     * @param IRequest      $request       The HTTP request
     * @param MotionService $motionService The motion service
     * @param IUserSession  $userSession   The user session
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MotionService $motionService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Transition a motion to a new lifecycle state.
     *
     * POST /api/motions/{id}/transition
     *
     * @param string $id The motion UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function transition(string $id): JSONResponse
    {
        $actor    = ($this->userSession->getUser()?->getUID() ?? '');
        $newState = $this->request->getParam('newState', '');

        if ($newState === '') {
            return new JSONResponse(['error' => 'newState is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'motion',
                newState: $newState,
                actorId: $actor,
            );
            return new JSONResponse(['status' => 'ok', 'newState' => $newState]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end transition()

    /**
     * Send co-signature requests to a list of participants.
     *
     * POST /api/motions/{id}/co-sign-request
     *
     * @param string $id The motion UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function coSignRequest(string $id): JSONResponse
    {
        $participantIds = $this->request->getParam('participantIds', []);

        if (empty($participantIds) === true) {
            return new JSONResponse(['error' => 'participantIds is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->requestCoSignature(motionId: $id, participantIds: $participantIds);
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end coSignRequest()

    /**
     * Confirm co-signature by the current user.
     *
     * POST /api/motions/{id}/co-sign-confirm
     *
     * @param string $id The motion UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function coSignConfirm(string $id): JSONResponse
    {
        $displayName = $this->request->getParam('displayName', '');
        if ($displayName === '') {
            $displayName = ($this->userSession->getUser()?->getDisplayName() ?? '');
        }

        if ($displayName === '') {
            return new JSONResponse(['error' => 'displayName is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->addCoSigner(motionId: $id, participantDisplayName: $displayName);
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end coSignConfirm()

    /**
     * Save or update budget impact data on a motion.
     *
     * POST /api/motions/{id}/budget-impact
     *
     * @param string $id The motion UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function budgetImpact(string $id): JSONResponse
    {
        $budgetLine  = $this->request->getParam('budgetLine', '');
        $amountDelta = (float) $this->request->getParam('amountDelta', 0);
        $rationale   = $this->request->getParam('rationale', '');

        if ($budgetLine === '') {
            return new JSONResponse(['error' => 'budgetLine is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->saveBudgetImpact(
                motionId: $id,
                budgetLine: $budgetLine,
                amountDelta: $amountDelta,
                rationale: $rationale,
            );
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end budgetImpact()

    /**
     * Transition an amendment to a new lifecycle state.
     *
     * POST /api/amendments/{id}/transition
     *
     * @param string $id The amendment UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function amendmentTransition(string $id): JSONResponse
    {
        $actor    = ($this->userSession->getUser()?->getUID() ?? '');
        $newState = $this->request->getParam('newState', '');

        if ($newState === '') {
            return new JSONResponse(['error' => 'newState is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'amendment',
                newState: $newState,
                actorId: $actor,
            );
            return new JSONResponse(['status' => 'ok', 'newState' => $newState]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end amendmentTransition()
}//end class
