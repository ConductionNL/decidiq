<?php

/**
 * Decidesk Voting Controller
 *
 * Thin REST controller for voting round management, vote casting, and proxy delegation.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Thin controller for voting round API endpoints.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */
class VotingController extends Controller
{
    /**
     * Constructor for VotingController.
     *
     * @param IRequest              $request               The request object
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IUserSession          $userSession           The user session
     * @param IGroupManager         $groupManager          The group manager
     * @param IAppConfig            $appConfig             The app config
     * @param LoggerInterface       $logger                The logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function __construct(
        IRequest $request,
        private readonly VotingService $votingService,
        private readonly OriPublicationService $oriPublicationService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the current user to hold the chair/secretary governance role.
     *
     * By default, system-admin membership (IGroupManager::isAdmin) is used as a
     * stand-in for the governance role. Organisations that want a dedicated group
     * (e.g. "decidesk-chairs") can set the `chair_group` app config key; when set,
     * membership of that group is checked instead of the system-admin group.
     *
     * Returns a 403 JSONResponse when the check fails, null on success.
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    private function requireChairOrSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Chair or secretary role required'], Http::STATUS_FORBIDDEN);
        }

        $uid        = $user->getUID();
        $chairGroup = $this->appConfig->getValueString('decidesk', 'chair_group', '');

        if ($chairGroup !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $chairGroup);
        } else {
            $authorized = $this->groupManager->isAdmin($uid);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Chair or secretary role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireChairOrSecretary()

    /**
     * Open a new VotingRound.
     *
     * POST /api/voting-rounds
     * Body: { "motionId": "uuid", "meetingId": "uuid", "votingMethod": "for-against-abstain", "isSecret": false, "closedAt": null }
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function open(): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params       = $this->request->getParams();
        $motionId     = ($params['motionId'] ?? '');
        $meetingId    = ($params['meetingId'] ?? '');
        $votingMethod = ($params['votingMethod'] ?? 'for-against-abstain');
        $isSecret     = (bool) ($params['isSecret'] ?? false);
        $closedAt     = null;
        if (isset($params['closedAt']) === true && $params['closedAt'] !== '') {
            $closedAt = $params['closedAt'];
        }

        if ($motionId === '' || $meetingId === '') {
            return new JSONResponse(['message' => 'motionId and meetingId are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $round = $this->votingService->openVotingRound(
                motionId: $motionId,
                meetingId: $meetingId,
                votingMethod: $votingMethod,
                isSecret: $isSecret,
                closedAt: $closedAt
            );
            return new JSONResponse($round, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end open()

    /**
     * Cast a vote in a VotingRound.
     *
     * POST /api/voting-rounds/{id}/cast
     * Body: { "participantId": "uuid", "value": "for", "isProxy": false, "delegatorId": null }
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function cast(string $id): JSONResponse
    {
        // Derive participant identity from the authenticated session — never trust client input.
        $nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
        if ($nextcloudUid === '') {
            return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Resolve the OpenRegister participant UUID for this Nextcloud user.
        $participantId = $this->votingService->resolveParticipantUuid($nextcloudUid);
        if ($participantId === null) {
            return new JSONResponse(['message' => 'Geen deelnemersprofiel gevonden voor de ingelogde gebruiker'], Http::STATUS_FORBIDDEN);
        }

        $params      = $this->request->getParams();
        $value       = ($params['value'] ?? '');
        $isProxy     = (bool) ($params['isProxy'] ?? false);
        $delegatorId = null;
        if (isset($params['delegatorId']) === true && $params['delegatorId'] !== '') {
            $delegatorId = $params['delegatorId'];
        }

        if ($value === '') {
            return new JSONResponse(['message' => 'value is required'], Http::STATUS_BAD_REQUEST);
        }

        if (in_array($value, ['for', 'against', 'abstain'], true) === false) {
            return new JSONResponse(['message' => 'value must be for, against, or abstain'], Http::STATUS_BAD_REQUEST);
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
     * Close a VotingRound.
     *
     * POST /api/voting-rounds/{id}/close
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function close(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $round = $this->votingService->closeVotingRound(votingRoundId: $id);
            return new JSONResponse($round);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end close()

    /**
     * Publish VotingRound result to ORI.
     *
     * POST /api/voting-rounds/{id}/publish
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function publish(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $this->oriPublicationService->publish(votingRoundId: $id);
            $status = $this->oriPublicationService->getPublicationStatus(votingRoundId: $id);
            return new JSONResponse(['status' => $status]);
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: ORI publication failed', ['votingRoundId' => $id, 'error' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Publication failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end publish()

    /**
     * Grant proxy delegation.
     *
     * POST /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "uuid", "toParticipantId": "uuid" }
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function proxy(string $id): JSONResponse
    {
        // Resolve the Nextcloud UID to an OpenRegister participant UUID before storing —
        // the proxy record must reference the same identifier type as castVote() uses.
        $nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
        if ($nextcloudUid === '') {
            return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fromParticipantId = $this->votingService->resolveParticipantUuid($nextcloudUid);
        if ($fromParticipantId === null) {
            return new JSONResponse(['message' => 'Geen deelnemersprofiel gevonden'], Http::STATUS_FORBIDDEN);
        }

        $params          = $this->request->getParams();
        $toParticipantId = ($params['toParticipantId'] ?? '');

        if ($toParticipantId === '') {
            return new JSONResponse(['message' => 'toParticipantId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->votingService->grantProxy(
                votingRoundId: $id,
                fromParticipantId: $fromParticipantId,
                toParticipantId: $toParticipantId
            );
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end proxy()

    /**
     * Save a show-of-hands aggregate tally for an open VotingRound.
     *
     * POST /api/voting-rounds/{id}/tally
     * Body: { "votesFor": int, "votesAgainst": int, "votesAbstain": int }
     *
     * Restricted to chair/secretary. Only valid for show-of-hands rounds.
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function tally(string $id): JSONResponse
    {
        $guard = $this->requireChairOrSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params       = $this->request->getParams();
        $votesFor     = (int) ($params['votesFor'] ?? 0);
        $votesAgainst = (int) ($params['votesAgainst'] ?? 0);
        $votesAbstain = (int) ($params['votesAbstain'] ?? 0);

        try {
            $round = $this->votingService->saveShowOfHandsTally(
                votingRoundId: $id,
                votesFor: $votesFor,
                votesAgainst: $votesAgainst,
                votesAbstain: $votesAbstain,
            );
            return new JSONResponse($round);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end tally()

    /**
     * Revoke proxy delegation.
     *
     * DELETE /api/voting-rounds/{id}/proxy
     * Body: { "fromParticipantId": "uuid" }
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     *
     * @return JSONResponse
     */
    public function revokeProxy(string $id): JSONResponse
    {
        // Resolve the Nextcloud UID to an OpenRegister participant UUID — must match
        // the identifier type stored by proxy() when the grant was created.
        $nextcloudUid = $this->userSession->getUser()?->getUID() ?? '';
        if ($nextcloudUid === '') {
            return new JSONResponse(['message' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fromParticipantId = $this->votingService->resolveParticipantUuid($nextcloudUid);
        if ($fromParticipantId === null) {
            return new JSONResponse(['message' => 'Geen deelnemersprofiel gevonden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->votingService->revokeProxy(votingRoundId: $id, fromParticipantId: $fromParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end revokeProxy()
}//end class
