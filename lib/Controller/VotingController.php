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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ProxyDelegationService;
use OCA\Decidesk\Service\VotingOpenRequestParser;
use OCA\Decidesk\Service\VotingRoundGuard;
use OCA\Decidesk\Service\VotingRoundRules;
use OCA\Decidesk\Service\VotingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin controller for voting round API endpoints.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */
class VotingController extends Controller
{

    /**
     * Per-meeting authorisation guard for the voting endpoints.
     *
     * @var VotingRoundGuard
     */
    private readonly VotingRoundGuard $guard;

    /**
     * Request parsing + validation for opening a voting round.
     *
     * @var VotingOpenRequestParser
     */
    private readonly VotingOpenRequestParser $openParser;

    /**
     * Proxy (volmacht) grant / revoke on a voting round.
     *
     * @var ProxyDelegationService
     */
    private readonly ProxyDelegationService $proxyService;

    /**
     * Constructor for VotingController.
     *
     * @param IRequest              $request               The request object
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IUserSession          $userSession           The user session
     * @param IGroupManager         $groupManager          The group manager (handed to the guard)
     * @param IAppConfig            $appConfig             The app config (handed to the guard)
     * @param LoggerInterface       $logger                The logger
     * @param ParticipantResolver   $participantResolver   Role resolver (handed to the guard)
     * @param ContainerInterface    $container             DI container (handed to the guard)
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
        IGroupManager $groupManager,
        IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        ParticipantResolver $participantResolver,
        ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

        $this->guard = new VotingRoundGuard(
            userSession: $userSession,
            groupManager: $groupManager,
            appConfig: $appConfig,
            participantResolver: $participantResolver,
            container: $container
        );

        $this->openParser = new VotingOpenRequestParser();

        $this->proxyService = new ProxyDelegationService(
            container: $container,
            logger: $logger
        );

    }//end __construct()

    /**
     * Open a new VotingRound.
     *
     * POST /api/voting-rounds
     * Body: { "motionId": "uuid", "meetingId": "uuid", "votingMethod": "for-against-abstain",
     *         "isSecret": false, "closedAt": null, "presetParticipantIds": ["uuid1", "uuid2"],
     *         "voteThreshold": "simple-majority", "abstentionHandling": "exclude",
     *         "tieBreakRule": "rejected", "revoteOfRound": "uuid|null",
     *         "subjectType": "motion|amendment" }
     *
     * For subjectType=amendment, motionId carries the AMENDMENT UUID; the
     * parliamentary ordering rules (amendments before the motion, chair-set
     * order) are enforced server-side by VotingService (fail closed).
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function open(): JSONResponse
    {
        $params    = $this->request->getParams();
        $meetingId = ($params['meetingId'] ?? '');

        // Per-meeting chair/secretary check: use the meetingId from the request body if present.
        $guardMeetingId = null;
        if ($meetingId !== '') {
            $guardMeetingId = $meetingId;
        }

        $guard = $this->guard->requireChairOrSecretary(meetingId: $guardMeetingId);
        if ($guard !== null) {
            return $guard;
        }

        $request = $this->openParser->parse(params: $params);
        if ($request['error'] !== null) {
            return new JSONResponse(['message' => $request['error']], Http::STATUS_BAD_REQUEST);
        }

        $round = $request['payload'];

        try {
            $opened = $this->votingService->openVotingRound(
                motionId: $round['motionId'],
                meetingId: $round['meetingId'],
                votingMethod: $round['votingMethod'],
                isSecret: $round['isSecret'],
                closedAt: $round['closedAt'],
                presetParticipantIds: $round['presetIds'],
                revoteOfRoundId: $round['revoteOfRoundId'],
                roundRules: new VotingRoundRules(
                    voteThreshold: $round['voteThreshold'],
                    abstentionHandling: $round['abstentionHandling'],
                    tieBreakRule: $round['tieBreakRule'],
                    subjectType: $round['subjectType'],
                    governanceBodyId: $round['governanceBodyId']
                )
            );
            return new JSONResponse($opened, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }//end try

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
     * @spec openspec/specs/user-settings/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
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
                delegatorId: $delegatorId,
                callerUid: $nextcloudUid
            );
            return new JSONResponse($vote, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end cast()

    /**
     * Close a VotingRound, optionally anonymising votes.
     *
     * POST /api/voting-rounds/{id}/close
     * Body: { "anonymise": true|false, "chairCasting": "for"|"against" (optional) }
     *
     * When `chairCasting` is present it is the chair's casting vote resolving a
     * tie under tieBreakRule 'chair-decides'. It requires the per-meeting CHAIR
     * role (secretary does not suffice) and is refused on rounds with a
     * different tie-break rule (fail closed in the service).
     *
     * @param string $id The voting round UUID
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     * @spec openspec/specs/voting-system/spec.md
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function close(string $id): JSONResponse
    {
        // Resolve the meeting from this voting round's motion chain for per-meeting auth.
        $meetingId = $this->guard->resolveMeetingIdFromVotingRound(votingRoundId: $id);
        $guard     = $this->guard->requireChairOrSecretary(meetingId: $meetingId);
        if ($guard !== null) {
            return $guard;
        }

        $params    = $this->request->getParams();
        $anonymise = isset($params['anonymise']) && $params['anonymise'] === true;

        $chairCasting = null;
        if (isset($params['chairCasting']) === true && $params['chairCasting'] !== '') {
            // The casting vote is the chair's personal prerogative — require the
            // chair role specifically (fail closed; secretary may close, not cast).
            $chairGuard = $this->guard->requireChair(meetingId: $meetingId);
            if ($chairGuard !== null) {
                return $chairGuard;
            }

            $chairCasting = (string) $params['chairCasting'];
            if (in_array($chairCasting, ['for', 'against'], true) === false) {
                return new JSONResponse(['message' => 'chairCasting must be for or against'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $round = $this->votingService->closeVotingRound(votingRoundId: $id, anonymise: $anonymise, chairCasting: $chairCasting);
            return new JSONResponse($round);
        } catch (\RuntimeException $e) {
            // Casting-vote refusals are client errors; a missing round is a 404.
            if (str_contains($e->getMessage(), 'not found') === true) {
                return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
    #[NoAdminRequired]
    public function publish(string $id): JSONResponse
    {
        $guard = $this->guard->requireChairOrSecretary();
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
    #[NoAdminRequired]
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
            $this->proxyService->grantProxy(
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
    #[NoAdminRequired]
    public function tally(string $id): JSONResponse
    {
        $guard = $this->guard->requireChairOrSecretary();
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
    #[NoAdminRequired]
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
            $this->proxyService->revokeProxy(votingRoundId: $id, fromParticipantId: $fromParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end revokeProxy()
}//end class
