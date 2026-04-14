<?php

/**
 * Decidesk Voting Controller
 *
 * Thin controller for voting round management, vote casting, and proxy delegation.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
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
 * Thin controller for voting round management, vote casting, and proxy delegation.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingController extends Controller
{
    /**
     * Constructor for VotingController.
     *
     * @param IRequest              $request               The HTTP request
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IUserSession          $userSession           The user session
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
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
     * Body: { motionId, meetingId, votingMethod, isSecret, closedAt? }
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function open(): JSONResponse
    {
        $motionId     = $this->request->getParam('motionId', '');
        $meetingId    = $this->request->getParam('meetingId', '');
        $votingMethod = $this->request->getParam('votingMethod', 'for-against-abstain');
        $isSecret     = (bool) $this->request->getParam('isSecret', false);
        $closedAt     = $this->request->getParam('closedAt', null);
        $actorId      = ($this->userSession->getUser()?->getUID() ?? '');

        if ($motionId === '' || $meetingId === '') {
            return new JSONResponse(['error' => 'motionId and meetingId are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $round = $this->votingService->openVotingRound(
                motionId: $motionId,
                meetingId: $meetingId,
                votingMethod: $votingMethod,
                isSecret: $isSecret,
                closedAt: $closedAt,
                actorId: $actorId,
            );
            return new JSONResponse($round, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            $status = Http::STATUS_UNPROCESSABLE_ENTITY;
            if (str_contains($e->getMessage(), 'Quorum') === true) {
                $status = Http::STATUS_BAD_REQUEST;
            }

            return new JSONResponse(['error' => $e->getMessage()], $status);
        }

    }//end open()

    /**
     * Cast a vote in a voting round.
     *
     * POST /api/voting-rounds/{id}/cast
     * Body: { participantId, value, isProxy, delegatorId? }
     *
     * @param string $id The voting round UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function cast(string $id): JSONResponse
    {
        $participantId = $this->request->getParam('participantId', '');
        $value         = $this->request->getParam('value', '');
        $isProxy       = (bool) $this->request->getParam('isProxy', false);
        $delegatorId   = $this->request->getParam('delegatorId', null);

        if ($participantId === '' || $value === '') {
            return new JSONResponse(['error' => 'participantId and value are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $vote = $this->votingService->castVote(
                votingRoundId: $id,
                participantId: $participantId,
                value: $value,
                isProxy: $isProxy,
                delegatorId: $delegatorId,
            );
            return new JSONResponse($vote, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end cast()

    /**
     * Close a voting round and compute the result.
     *
     * POST /api/voting-rounds/{id}/close
     *
     * @param string $id The voting round UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function close(string $id): JSONResponse
    {
        $actorId = ($this->userSession->getUser()?->getUID() ?? '');

        try {
            $round = $this->votingService->closeVotingRound(votingRoundId: $id, actorId: $actorId);
            return new JSONResponse($round);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end close()

    /**
     * Publish voting round results to the ORI API.
     *
     * POST /api/voting-rounds/{id}/publish
     *
     * @param string $id The voting round UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function publish(string $id): JSONResponse
    {
        try {
            $this->oriPublicationService->publish($id);
            $status = $this->oriPublicationService->getPublicationStatus($id);
            return new JSONResponse(['status' => $status]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end publish()

    /**
     * Grant a proxy vote from one participant to another.
     *
     * POST /api/voting-rounds/{id}/proxy
     * Body: { fromParticipantId, toParticipantId }
     *
     * @param string $id The voting round UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function grantProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');
        $toParticipantId   = $this->request->getParam('toParticipantId', '');

        if ($fromParticipantId === '' || $toParticipantId === '') {
            return new JSONResponse(
                ['error' => 'fromParticipantId and toParticipantId are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->votingService->grantProxy(
                votingRoundId: $id,
                fromParticipantId: $fromParticipantId,
                toParticipantId: $toParticipantId,
            );
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end grantProxy()

    /**
     * Revoke a proxy before the voting round opens.
     *
     * DELETE /api/voting-rounds/{id}/proxy
     * Body: { fromParticipantId }
     *
     * @param string $id The voting round UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function revokeProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');

        if ($fromParticipantId === '') {
            return new JSONResponse(['error' => 'fromParticipantId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->votingService->revokeProxy(votingRoundId: $id, fromParticipantId: $fromParticipantId);
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end revokeProxy()
}//end class
