<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decidesk Voting Controller
 *
 * Thin REST controller for voting round management: open, cast vote,
 * close, publish to ORI, grant and revoke proxy.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
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
use OCP\IUserSession;

/**
 * Thin REST controller for voting round management.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */
class VotingController extends Controller
{

    /**
     * Constructor for the VotingController.
     *
     * @param IRequest              $request               The request object
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IUserSession          $userSession           The user session
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function __construct(
        IRequest $request,
        private VotingService $votingService,
        private OriPublicationService $oriPublicationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Open a new voting round for a motion.
     *
     * POST /api/voting-rounds
     * Body: { motionId, votingMethod, isSecret, closedAt, meetingId }
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function open(): JSONResponse
    {
        $motionId     = $this->request->getParam('motionId', '');
        $votingMethod = $this->request->getParam('votingMethod', 'for-against-abstain');
        $isSecret     = (bool) $this->request->getParam('isSecret', false);
        $closedAt     = $this->request->getParam('closedAt', null) ?: null;
        $meetingId    = $this->request->getParam('meetingId', '');
        $actorId      = ($this->userSession->getUser()?->getUID() ?? '');

        if ($motionId === '') {
            return new JSONResponse(['message' => 'motionId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $round = $this->votingService->openVotingRound(
                motionId: $motionId,
                votingMethod: $votingMethod,
                isSecret: $isSecret,
                closedAt: $closedAt,
                actorId: $actorId,
                meetingId: $meetingId
            );
            return new JSONResponse($round, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            $statusCode = (str_contains($e->getMessage(), 'Quorum') === true)
                ? Http::STATUS_BAD_REQUEST
                : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['message' => $e->getMessage()], $statusCode);
        }

    }//end open()

    /**
     * Cast a vote in an open voting round.
     *
     * POST /api/voting-rounds/{id}/cast
     * Body: { participantId, value, isProxy, delegatorId }
     *
     * @param string $id The VotingRound ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function cast(string $id): JSONResponse
    {
        $participantId = $this->request->getParam('participantId', '');
        $value         = $this->request->getParam('value', '');
        $isProxy       = (bool) $this->request->getParam('isProxy', false);
        $delegatorId   = $this->request->getParam('delegatorId', null) ?: null;

        if ($participantId === '' || $value === '') {
            return new JSONResponse(
                ['message' => 'participantId and value are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (in_array($value, ['for', 'against', 'abstain'], true) === false) {
            return new JSONResponse(
                ['message' => 'value must be for, against, or abstain'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $vote = $this->votingService->castVote(
                votingRoundId: $id,
                participantId: $participantId,
                value: $value,
                isProxy: $isProxy,
                delegatorId: $delegatorId
            );
            return new JSONResponse($vote, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end cast()

    /**
     * Close a voting round and calculate results.
     *
     * POST /api/voting-rounds/{id}/close
     *
     * @param string $id The VotingRound ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function close(string $id): JSONResponse
    {
        $actorId = ($this->userSession->getUser()?->getUID() ?? '');

        try {
            $round = $this->votingService->closeVotingRound($id, $actorId);
            return new JSONResponse($round);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end close()

    /**
     * Publish voting round results to the ORI API.
     *
     * POST /api/voting-rounds/{id}/publish
     *
     * @param string $id The VotingRound ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function publish(string $id): JSONResponse
    {
        try {
            $this->oriPublicationService->publish($id);
            $status = $this->oriPublicationService->getPublicationStatus($id);
            return new JSONResponse(['status' => $status]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end publish()

    /**
     * Grant a proxy vote to another participant.
     *
     * POST /api/voting-rounds/{id}/proxy
     * Body: { fromParticipantId, toParticipantId }
     *
     * @param string $id The VotingRound ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function grantProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');
        $toParticipantId   = $this->request->getParam('toParticipantId', '');

        if ($fromParticipantId === '' || $toParticipantId === '') {
            return new JSONResponse(
                ['message' => 'fromParticipantId and toParticipantId are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->votingService->grantProxy($id, $fromParticipantId, $toParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end grantProxy()

    /**
     * Revoke a proxy vote before the round opens.
     *
     * DELETE /api/voting-rounds/{id}/proxy
     * Body: { fromParticipantId }
     *
     * @param string $id The VotingRound ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function revokeProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');

        if ($fromParticipantId === '') {
            return new JSONResponse(['message' => 'fromParticipantId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->votingService->revokeProxy($id, $fromParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end revokeProxy()

}//end class
