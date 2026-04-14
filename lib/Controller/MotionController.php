<?php

/**
 * Decidesk Motion Controller
 *
 * Thin REST controller for Motion and Amendment lifecycle operations.
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

/**
 * REST controller for Motion and Amendment lifecycle operations.
 *
 * Routes:
 *   POST /api/motions/{id}/transition       → transitionLifecycle
 *   POST /api/motions/{id}/co-sign-request  → requestCoSignature
 *   POST /api/motions/{id}/co-sign-confirm  → addCoSigner
 *   POST /api/motions/{id}/budget-impact    → saveBudgetImpact
 *   POST /api/amendments/{id}/transition    → transitionLifecycle
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
 */
class MotionController extends Controller
{

    /**
     * Constructor for the MotionController.
     *
     * @param IRequest      $request       The request object
     * @param MotionService $motionService The motion service
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private MotionService $motionService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Transition a Motion to a new lifecycle state.
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
        try {
            $params    = $this->request->getParams();
            $newState  = (string) ($params['newState'] ?? '');
            $actorId   = (string) ($params['actorId'] ?? '');

            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'motion',
                newState: $newState,
                actorId: $actorId,
            );

            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end transition()

    /**
     * Send co-signature invitation notifications to the specified Participants.
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
        try {
            $params         = $this->request->getParams();
            $participantIds = (array) ($params['participantIds'] ?? []);

            $this->motionService->requestCoSignature(
                motionId: $id,
                participantIds: $participantIds,
            );

            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end coSignRequest()

    /**
     * Confirm co-signature by appending the Participant's display name.
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
        try {
            $params      = $this->request->getParams();
            $displayName = (string) ($params['displayName'] ?? '');

            $this->motionService->addCoSigner(
                motionId: $id,
                participantDisplayName: $displayName,
            );

            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end coSignConfirm()

    /**
     * Save or update the budget impact note on a Motion.
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
        try {
            $params      = $this->request->getParams();
            $budgetLine  = (string) ($params['budgetLine'] ?? '');
            $amountDelta = (float) ($params['amountDelta'] ?? 0.0);
            $rationale   = (string) ($params['rationale'] ?? '');

            $this->motionService->saveBudgetImpact(
                motionId: $id,
                budgetLine: $budgetLine,
                amountDelta: $amountDelta,
                rationale: $rationale,
            );

            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end budgetImpact()

    /**
     * Transition an Amendment to a new lifecycle state.
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
        try {
            $params   = $this->request->getParams();
            $newState = (string) ($params['newState'] ?? '');
            $actorId  = (string) ($params['actorId'] ?? '');

            $this->motionService->transitionLifecycle(
                objectId: $id,
                objectType: 'amendment',
                newState: $newState,
                actorId: $actorId,
            );

            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end amendmentTransition()

}//end class
