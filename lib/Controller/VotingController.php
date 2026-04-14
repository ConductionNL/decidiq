<?php

/**
 * Decidesk Voting Controller
 *
 * Thin API controller for VotingRound management, vote casting, proxy delegation,
 * and ORI publication. All business logic is delegated to VotingService and
 * OriPublicationService.
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
 * Thin API controller for VotingRound open/close, vote casting, proxy and ORI publication.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */
class VotingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request               HTTP request
     * @param VotingService         $votingService         Voting business logic
     * @param OriPublicationService $oriPublicationService ORI publication service
     * @param IUserSession          $userSession           Current user session
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
     * Open a new VotingRound for a Motion.
     *
     * POST /api/voting-rounds
     * Body: { "motionId": "...", "votingMethod": "for-against-abstain", "isSecret": false, "closedAt": null, "meetingId": null }
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function open(): JSONResponse
    {
        $motionId     = $this->request->getParam('motionId', '');
        $votingMethod = $this->request->getParam('votingMethod', 'for-against-abstain');
        $isSecret     = (bool) $this->request->getParam('isSecret', false);
        $closedAtRaw  = $this->request->getParam('closedAt', null);
        if ($closedAtRaw !== null && $closedAtRaw !== '') {
            $closedAt = $closedAtRaw;
        } else {
            $closedAt = null;
        }

        $meetingIdRaw = $this->request->getParam('meetingId', null);
        if ($meetingIdRaw !== null && $meetingIdRaw !== '') {
            $meetingId = $meetingIdRaw;
        } else {
            $meetingId = null;
        }

        $actorId = $this->userSession->getUser()?->getUID() ?? '';

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
            if (str_contains($e->getMessage(), 'Quorum') === true) {
                $statusCode = Http::STATUS_BAD_REQUEST;
            } else {
                $statusCode = Http::STATUS_UNPROCESSABLE_ENTITY;
            }

            return new JSONResponse(['message' => $e->getMessage()], $statusCode);
        }
    }//end open()

    /**
     * Cast a vote in an open VotingRound.
     *
     * POST /api/voting-rounds/{id}/cast
     * Body: { "participantId": "...", "value": "for", "isProxy": false, "delegatorId": null }
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function cast(string $id): JSONResponse
    {
        $participantId  = $this->request->getParam('participantId', '');
        $value          = $this->request->getParam('value', '');
        $isProxy        = (bool) $this->request->getParam('isProxy', false);
        $delegatorIdRaw = $this->request->getParam('delegatorId', null);
        if ($delegatorIdRaw !== null && $delegatorIdRaw !== '') {
            $delegatorId = $delegatorIdRaw;
        } else {
            $delegatorId = null;
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
     * Close a VotingRound and compute results.
     *
     * POST /api/voting-rounds/{id}/close
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function close(string $id): JSONResponse
    {
        $actorId = $this->userSession->getUser()?->getUID() ?? '';

        try {
            $round = $this->votingService->closeVotingRound(votingRoundId: $id, actorId: $actorId);
            return new JSONResponse($round);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end close()

    /**
     * Publish VotingRound results to the ORI API.
     *
     * POST /api/voting-rounds/{id}/publish
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
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
     * Grant a proxy vote to another Participant.
     *
     * POST /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "...", "toParticipantId": "..." }
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function grantProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');
        $toParticipantId   = $this->request->getParam('toParticipantId', '');

        try {
            $this->votingService->grantProxy(
                votingRoundId: $id,
                fromParticipantId: $fromParticipantId,
                toParticipantId: $toParticipantId
            );
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end grantProxy()

    /**
     * Revoke a proxy delegation before the VotingRound opens.
     *
     * DELETE /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "..." }
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function revokeProxy(string $id): JSONResponse
    {
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');

        try {
            $this->votingService->revokeProxy(
                votingRoundId: $id,
                fromParticipantId: $fromParticipantId
            );
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end revokeProxy()
}//end class
