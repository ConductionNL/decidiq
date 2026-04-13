<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Decidesk Motion Controller
 *
 * Thin controller for the motion lifecycle API.
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
 * Thin controller for the motion lifecycle API.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionController extends Controller
{
    /**
     * Constructor for the MotionController.
     *
     * @param IRequest      $request       The request object
     * @param MotionService $motionService The motion service
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
     * Transition a motion to a new lifecycle state.
     *
     * @param string $id The motion identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function transition(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState');
        $actorId  = $this->request->getParam('actorId');

        try {
            $result = $this->motionService->transitionLifecycle($id, 'motion', $newState, $actorId);
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end transition()

    /**
     * Request co-signatures for a motion.
     *
     * @param string $id The motion identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function coSignRequest(string $id): JSONResponse
    {
        $participantIds = $this->request->getParam('participantIds');

        $result = $this->motionService->requestCoSignature($id, $participantIds);

        return new JSONResponse(['success' => true, 'data' => $result]);
    }//end coSignRequest()

    /**
     * Confirm a co-signature on a motion.
     *
     * @param string $id The motion identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function coSignConfirm(string $id): JSONResponse
    {
        $displayName = $this->request->getParam('displayName');

        $result = $this->motionService->addCoSigner($id, $displayName);

        return new JSONResponse($result);
    }//end coSignConfirm()

    /**
     * Save budget impact for a motion.
     *
     * @param string $id The motion identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function budgetImpact(string $id): JSONResponse
    {
        $budgetLine  = $this->request->getParam('budgetLine');
        $amountDelta = (float) $this->request->getParam('amountDelta');
        $rationale   = $this->request->getParam('rationale');

        $result = $this->motionService->saveBudgetImpact($id, $budgetLine, $amountDelta, $rationale);

        return new JSONResponse($result);
    }//end budgetImpact()

    /**
     * Transition an amendment to a new lifecycle state.
     *
     * @param string $id The amendment identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function amendmentTransition(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState');
        $actorId  = $this->request->getParam('actorId');

        try {
            $result = $this->motionService->transitionLifecycle($id, 'amendment', $newState, $actorId);
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end amendmentTransition()
}//end class
