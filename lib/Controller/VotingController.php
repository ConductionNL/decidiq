<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
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

use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\VotingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for the voting round API.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class VotingController extends DecideskController
{
    /**
     * Constructor for the VotingController.
     *
     * @param IRequest              $request               The request object
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IUserSession          $userSession           The user session
     * @param IGroupManager         $groupManager          The group manager
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private VotingService $votingService,
        private OriPublicationService $oriPublicationService,
        IUserSession $userSession,
        IGroupManager $groupManager,
    ) {
        parent::__construct(request: $request, userSession: $userSession, groupManager: $groupManager);
    }//end __construct()

    /**
     * Open a new voting round.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function open(): JSONResponse
    {
        if ($this->isChairOrAdmin() === false) {
            return new JSONResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $actorId      = $user->getUID();
        $motionId     = $this->request->getParam('motionId');
        $votingMethod = $this->request->getParam('votingMethod');
        $isSecret     = (bool) $this->request->getParam('isSecret');
        $closedAt     = $this->request->getParam('closedAt');

        try {
            $result = $this->votingService->openVotingRound(
                $motionId,
                $votingMethod,
                $isSecret,
                $closedAt,
                $actorId
            );
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Invalid voting round parameters'], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => 'Voting round operation failed'], Http::STATUS_BAD_REQUEST);
        }
    }//end open()

    /**
     * Cast a vote in a voting round.
     *
     * The participant identity is resolved from the authenticated session;
     * any participantId supplied in the request body is ignored.
     *
     * @param string $id The voting round identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function cast(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $participantId = $user->getUID();
        $value         = $this->request->getParam('value');
        $isProxy       = (bool) $this->request->getParam('isProxy');
        $delegatorId   = $this->request->getParam('delegatorId');

        try {
            $result = $this->votingService->castVote($id, $participantId, $value, $isProxy, $delegatorId);
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Invalid vote parameters'], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => 'Vote casting failed'], Http::STATUS_BAD_REQUEST);
        }
    }//end cast()

    /**
     * Close a voting round.
     *
     * @param string $id The voting round identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function close(string $id): JSONResponse
    {
        if ($this->isChairOrAdmin() === false) {
            return new JSONResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
        }

        $result = $this->votingService->closeVotingRound($id);

        return new JSONResponse($result);
    }//end close()

    /**
     * Publish voting results to ORI.
     *
     * @param string $id The voting round identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function publish(string $id): JSONResponse
    {
        if ($this->isChairOrAdmin() === false) {
            return new JSONResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
        }

        $this->oriPublicationService->publish($id);

        return new JSONResponse(['success' => true]);
    }//end publish()

    /**
     * Grant proxy voting rights.
     *
     * The fromParticipantId is resolved from the authenticated session;
     * any fromParticipantId supplied in the request body is ignored.
     *
     * @param string $id The voting round identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function grantProxy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fromParticipantId = $user->getUID();
        $toParticipantId   = $this->request->getParam('toParticipantId');

        try {
            $this->votingService->grantProxy($id, $fromParticipantId, $toParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new JSONResponse(['error' => 'Proxy grant failed'], Http::STATUS_BAD_REQUEST);
        }
    }//end grantProxy()

    /**
     * Revoke proxy voting rights.
     *
     * The fromParticipantId is resolved from the authenticated session;
     * any fromParticipantId supplied in the request body is ignored.
     *
     * @param string $id The voting round identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function revokeProxy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fromParticipantId = $user->getUID();

        try {
            $this->votingService->revokeProxy($id, $fromParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => 'Proxy revocation failed'], Http::STATUS_BAD_REQUEST);
        }
    }//end revokeProxy()

    /**
     * Save manually entered show-of-hands totals on a voting round.
     *
     * Used when votingMethod is 'show-of-hands' and the chair enters totals
     * directly rather than recording individual votes.
     *
     * @param string $id The voting round identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function handsCount(string $id): JSONResponse
    {
        if ($this->isChairOrAdmin() === false) {
            return new JSONResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
        }

        $votesFor     = (int) $this->request->getParam('votesFor', 0);
        $votesAgainst = (int) $this->request->getParam('votesAgainst', 0);
        $votesAbstain = (int) $this->request->getParam('votesAbstain', 0);

        $result = $this->votingService->saveHandsCount($id, $votesFor, $votesAgainst, $votesAbstain);

        return new JSONResponse($result);
    }//end handsCount()
}//end class
