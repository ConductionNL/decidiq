<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk Voting Controller
 *
 * Thin controller for the voting round API.
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
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\VotingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin controller for the voting round API.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingController extends Controller
{
    /**
     * Constructor for the VotingController.
     *
     * @param IRequest              $request               The request object
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private VotingService $votingService,
        private OriPublicationService $oriPublicationService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Open a new voting round.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return JSONResponse
     */
    public function open(): JSONResponse
    {
        $motionId     = $this->request->getParam('motionId');
        $votingMethod = $this->request->getParam('votingMethod');
        $isSecret     = (bool) $this->request->getParam('isSecret');
        $closedAt     = $this->request->getParam('closedAt');

        try {
            $result = $this->votingService->openVotingRound($motionId, $votingMethod, $isSecret, $closedAt);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end open()

    /**
     * Cast a vote in a voting round.
     *
     * @NoAdminRequired
     *
     * @param string $id The voting round identifier
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return JSONResponse
     */
    public function cast(string $id): JSONResponse
    {
        $participantId = $this->request->getParam('participantId');
        $value         = $this->request->getParam('value');
        $isProxy       = (bool) $this->request->getParam('isProxy');
        $delegatorId   = $this->request->getParam('delegatorId');

        try {
            $result = $this->votingService->castVote($id, $participantId, $value, $isProxy, $delegatorId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end cast()

    /**
     * Close a voting round.
     *
     * @NoAdminRequired
     *
     * @param string $id The voting round identifier
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return JSONResponse
     */
    public function close(string $id): JSONResponse
    {
        $result = $this->votingService->closeVotingRound($id);

        return new JSONResponse($result);
    }//end close()

    /**
     * Publish voting results to ORI.
     *
     * @NoAdminRequired
     *
     * @param string $id The voting round identifier
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return JSONResponse
     */
    public function publish(string $id): JSONResponse
    {
        $this->oriPublicationService->publish($id);

        return new JSONResponse(['success' => true]);
    }//end publish()

    /**
     * Grant proxy voting rights.
     *
     * @NoAdminRequired
     *
     * @param string $id The voting round identifier
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return JSONResponse
     */
    public function grantProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId');
        $toParticipantId   = $this->request->getParam('toParticipantId');

        try {
            $result = $this->votingService->grantProxy($id, $fromParticipantId, $toParticipantId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end grantProxy()

    /**
     * Revoke proxy voting rights.
     *
     * @NoAdminRequired
     *
     * @param string $id The voting round identifier
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     *
     * @return JSONResponse
     */
    public function revokeProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId');

        try {
            $result = $this->votingService->revokeProxy($id, $fromParticipantId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end revokeProxy()
}//end class
