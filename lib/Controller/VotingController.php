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
 * Thin REST controller for voting round open/close, vote casting, and proxy management.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */
class VotingController extends Controller
{
    /**
     * Construct the VotingController.
     *
     * @param IRequest              $request               The request object
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IUserSession          $userSession           The user session
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function __construct(
        IRequest $request,
        private readonly VotingService $votingService,
        private readonly OriPublicationService $oriPublicationService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Get the current authenticated user ID.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
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
     * Open a new VotingRound for a motion.
     *
     * POST /api/voting-rounds
     * Body: { "motionId": "uuid", "votingMethod": "for-against-abstain", "isSecret": false, "closedAt": null }
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        $motionId     = $this->request->getParam('motionId', '');
        $votingMethod = $this->request->getParam('votingMethod', 'for-against-abstain');
        $isSecret     = (bool) $this->request->getParam('isSecret', false);
        $closedAt     = $this->request->getParam('closedAt', null);

        try {
            $round = $this->votingService->openVotingRound(
                $motionId,
                $votingMethod,
                $isSecret,
                $closedAt,
                $this->getActorId()
            );
            return new JSONResponse($round, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Quorum') === true) {
                $status = Http::STATUS_BAD_REQUEST;
            } else {
                $status = Http::STATUS_NOT_FOUND;
            }

            return new JSONResponse(['message' => $e->getMessage()], $status);
        }

    }//end create()

    /**
     * Cast a vote in an open VotingRound.
     *
     * POST /api/voting-rounds/{id}/cast
     * Body: { "participantId": "uuid", "value": "for", "isProxy": false, "delegatorId": null }
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
        $participantId = $this->request->getParam('participantId', '');
        $value         = $this->request->getParam('value', '');
        $isProxy       = (bool) $this->request->getParam('isProxy', false);
        $delegatorId   = $this->request->getParam('delegatorId', null);

        try {
            $vote = $this->votingService->castVote($id, $participantId, $value, $isProxy, $delegatorId);
            return new JSONResponse($vote, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end cast()

    /**
     * Close an open VotingRound and tally results.
     *
     * POST /api/voting-rounds/{id}/close
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
            $result = $this->votingService->closeVotingRound($id, $this->getActorId());
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

    }//end close()

    /**
     * Publish a closed VotingRound's results to the ORI API.
     *
     * POST /api/voting-rounds/{id}/publish
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
        $this->oriPublicationService->publish($id);
        $status = $this->oriPublicationService->getPublicationStatus($id);
        return new JSONResponse(['status' => $status]);

    }//end publish()

    /**
     * Grant a voting proxy from the current user to another Participant.
     *
     * POST /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "uuid", "toParticipantId": "uuid" }
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
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');
        $toParticipantId   = $this->request->getParam('toParticipantId', '');

        try {
            $this->votingService->grantProxy($id, $fromParticipantId, $toParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end grantProxy()

    /**
     * Revoke a voting proxy before the VotingRound opens.
     *
     * DELETE /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "uuid" }
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
        $fromParticipantId = $this->request->getParam('fromParticipantId', '');

        try {
            $this->votingService->revokeProxy($id, $fromParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end revokeProxy()
}//end class
