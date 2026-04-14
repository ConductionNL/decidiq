<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk Motion Controller
 *
 * Thin REST controller for motion and amendment lifecycle operations.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
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
 * Thin REST controller for motion and amendment lifecycle operations.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
 */
class MotionController extends Controller
{

    /**
     * Constructor for the MotionController.
     *
     * @param IRequest       $request       The request object
     * @param MotionService  $motionService The motion service
     * @param IUserSession   $userSession   The user session
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
     * Transition a motion to a new lifecycle state.
     *
     * POST /api/motions/{id}/transition
     *
     * @param string $id The motion object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function transitionMotion(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState', '');
        $actorId  = ($this->userSession->getUser()?->getUID() ?? '');

        if ($newState === '') {
            return new JSONResponse(['message' => 'newState is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->transitionLifecycle($id, 'motion', $newState, $actorId);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end transitionMotion()

    /**
     * Send co-signature invitation notifications to participants.
     *
     * POST /api/motions/{id}/co-sign-request
     *
     * @param string $id The motion object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function requestCoSign(string $id): JSONResponse
    {
        $participantIds = $this->request->getParam('participantIds', []);

        if (empty($participantIds) === true) {
            return new JSONResponse(['message' => 'participantIds is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->requestCoSignature($id, (array) $participantIds);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end requestCoSign()

    /**
     * Confirm co-signature from a participant.
     *
     * POST /api/motions/{id}/co-sign-confirm
     *
     * @param string $id The motion object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function confirmCoSign(string $id): JSONResponse
    {
        $displayName = $this->request->getParam('displayName', '');

        if ($displayName === '') {
            return new JSONResponse(['message' => 'displayName is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->addCoSigner($id, $displayName);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end confirmCoSign()

    /**
     * Save budget impact data as a structured note on a motion.
     *
     * POST /api/motions/{id}/budget-impact
     *
     * @param string $id The motion object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function saveBudgetImpact(string $id): JSONResponse
    {
        $budgetLine  = $this->request->getParam('budgetLine', '');
        $amountDelta = (float) $this->request->getParam('amountDelta', 0.0);
        $rationale   = $this->request->getParam('rationale', '');

        if ($budgetLine === '') {
            return new JSONResponse(['message' => 'budgetLine is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->saveBudgetImpact($id, $budgetLine, $amountDelta, $rationale);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end saveBudgetImpact()

    /**
     * Transition an amendment to a new lifecycle state.
     *
     * POST /api/amendments/{id}/transition
     *
     * @param string $id The amendment object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.2
     */
    public function transitionAmendment(string $id): JSONResponse
    {
        $newState = $this->request->getParam('newState', '');
        $actorId  = ($this->userSession->getUser()?->getUID() ?? '');

        if ($newState === '') {
            return new JSONResponse(['message' => 'newState is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->motionService->transitionLifecycle($id, 'amendment', $newState, $actorId);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end transitionAmendment()

}//end class
