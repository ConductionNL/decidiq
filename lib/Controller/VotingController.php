<?php

/**
 * Decidesk Voting Controller
 *
 * Thin REST controller for VotingRound lifecycle operations, vote casting,
 * proxy delegation, and ORI publication.
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

/**
 * REST controller for VotingRound lifecycle operations.
 *
 * Routes:
 *   POST   /api/voting-rounds               → openVotingRound
 *   POST   /api/voting-rounds/{id}/cast     → castVote
 *   POST   /api/voting-rounds/{id}/close    → closeVotingRound
 *   POST   /api/voting-rounds/{id}/publish  → OriPublicationService::publish
 *   POST   /api/voting-rounds/{id}/proxy    → grantProxy
 *   DELETE /api/voting-rounds/{id}/proxy    → revokeProxy
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */
class VotingController extends Controller
{

    /**
     * Constructor for the VotingController.
     *
     * @param IRequest               $request               The request object
     * @param VotingService          $votingService         The voting service
     * @param OriPublicationService  $oriPublicationService The ORI publication service
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
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
     * Open a new VotingRound for a Motion.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function openRound(): JSONResponse
    {
        try {
            $params       = $this->request->getParams();
            $motionId     = (string) ($params['motionId'] ?? '');
            $votingMethod = (string) ($params['votingMethod'] ?? 'for-against-abstain');
            $isSecret     = (bool) ($params['isSecret'] ?? false);
            $closedAt     = isset($params['closedAt']) ? (string) $params['closedAt'] : null;

            $round = $this->votingService->openVotingRound(
                motionId: $motionId,
                votingMethod: $votingMethod,
                isSecret: $isSecret,
                closedAt: $closedAt,
            );

            return new JSONResponse($round, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end openRound()

    /**
     * Cast a vote in an open VotingRound.
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function cast(string $id): JSONResponse
    {
        try {
            $params        = $this->request->getParams();
            $participantId = (string) ($params['participantId'] ?? '');
            $value         = (string) ($params['value'] ?? '');
            $isProxy       = (bool) ($params['isProxy'] ?? false);
            $delegatorId   = isset($params['delegatorId']) ? (string) $params['delegatorId'] : null;

            $vote = $this->votingService->castVote(
                votingRoundId: $id,
                participantId: $participantId,
                value: $value,
                isProxy: $isProxy,
                delegatorId: $delegatorId,
            );

            return new JSONResponse($vote, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end cast()

    /**
     * Close a VotingRound and calculate the result.
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function close(string $id): JSONResponse
    {
        try {
            $round = $this->votingService->closeVotingRound(votingRoundId: $id);

            return new JSONResponse($round);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end close()

    /**
     * Publish voting round results to the ORI API.
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function publish(string $id): JSONResponse
    {
        try {
            $this->oriPublicationService->publish(votingRoundId: $id);
            $status = $this->oriPublicationService->getPublicationStatus(votingRoundId: $id);

            return new JSONResponse(['status' => $status]);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end publish()

    /**
     * Grant a proxy voting right for a VotingRound.
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function grantProxy(string $id): JSONResponse
    {
        try {
            $params              = $this->request->getParams();
            $fromParticipantId   = (string) ($params['fromParticipantId'] ?? '');
            $toParticipantId     = (string) ($params['toParticipantId'] ?? '');

            $this->votingService->grantProxy(
                votingRoundId: $id,
                fromParticipantId: $fromParticipantId,
                toParticipantId: $toParticipantId,
            );

            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end grantProxy()

    /**
     * Revoke a proxy voting right before the VotingRound opens.
     *
     * @param string $id The VotingRound UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function revokeProxy(string $id): JSONResponse
    {
        try {
            $params            = $this->request->getParams();
            $fromParticipantId = (string) ($params['fromParticipantId'] ?? '');

            $this->votingService->revokeProxy(
                votingRoundId: $id,
                fromParticipantId: $fromParticipantId,
            );

            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end revokeProxy()

}//end class
