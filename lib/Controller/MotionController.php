<?php

/**
 * Decidesk Motion Controller
 *
 * Thin controller exposing motion lifecycle, co-signature, and budget-impact endpoints.
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
 * Thin REST controller for motion lifecycle transitions and co-signature management.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
 */
class MotionController extends Controller
{
    /**
     * Construct the MotionController.
     *
     * @param IRequest      $request       The request object
     * @param MotionService $motionService The motion service
     * @param IUserSession  $userSession   The user session
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private readonly MotionService $motionService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Get the current authenticated user ID.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return string
     */
    private function getActorId(): string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        return 'system';

    }//end getActorId()

    /**
     * Transition a Motion to a new lifecycle state.
     *
     * POST /api/motions/{id}/transition
     * Body: { "newState": "debating" }
     *
     * @param string $id The Motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function transition(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState', '');

        try {
            $this->motionService->transitionLifecycle($id, 'motion', $newState, $this->getActorId());
            return new JSONResponse(['success' => true, 'newState' => $newState]);
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
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function coSignRequest(string $id): JSONResponse
    {
        $participantIds = $this->request->getParam('participantIds', []);
        if (is_array($participantIds) === false) {
            return new JSONResponse(['message' => 'participantIds must be an array'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->requestCoSignature($id, $participantIds);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end coSignRequest()

    /**
     * Confirm co-signature by appending the user's display name to coSigners.
     *
     * POST /api/motions/{id}/co-sign-confirm
     * Body: { "displayName": "M. de Vries" }
     *
     * @param string $id The Motion UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function coSignConfirm(string $id): JSONResponse
    {
        $displayName = $this->request->getParam('displayName', '');
        if (empty($displayName) === true) {
            $user = $this->userSession->getUser();
            if ($user !== null) {
                $displayName = $user->getDisplayName();
            } else {
                $displayName = '';
            }
        }

        try {
            $this->motionService->addCoSigner($id, $displayName);
            return new JSONResponse(['success' => true, 'displayName' => $displayName]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end coSignConfirm()

    /**
     * Save budget impact details as a structured note on a Motion.
     *
     * POST /api/motions/{id}/budget-impact
     * Body: { "budgetLine": "Programma 4", "amountDelta": 250000, "rationale": "..." }
     *
     * @param string $id The Motion UUID
     *
     * @NoAdminRequired
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
            $this->motionService->saveBudgetImpact($id, $budgetLine, $amountDelta, $rationale);
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
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return JSONResponse
     */
    public function amendmentTransition(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState', '');

        try {
            $this->motionService->transitionLifecycle($id, 'amendment', $newState, $this->getActorId());
            return new JSONResponse(['success' => true, 'newState' => $newState]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end amendmentTransition()
}//end class
