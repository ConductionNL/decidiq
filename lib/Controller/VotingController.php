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
     * Constructor for VotingController.
     *
     * @param IRequest              $request               The request object
     * @param VotingService         $votingService         The voting service
     * @param OriPublicationService $oriPublicationService The ORI publication service
     * @param IUserSession          $userSession           The user session
     * @param IGroupManager         $groupManager          The group manager
     * @param IAppConfig            $appConfig             The app config
     * @param LoggerInterface       $logger                The logger
     * @param ParticipantResolver   $participantResolver   Per-meeting participant/role resolver
     * @param ContainerInterface    $container             DI container (lazy-loads ObjectService)
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
        private readonly ParticipantResolver $participantResolver,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the current user to hold the chair/secretary role for a specific meeting.
     *
     * When $meetingId is provided, checks via ParticipantResolver::hasRole() that
     * the caller holds a 'chair' or 'secretary' Participant role in that meeting's
     * governance body — preventing cross-body privilege escalation in multi-council
     * deployments.
     *
     * Falls back to the global chair_group / admin check when $meetingId is null.
     *
     * Returns a 403 JSONResponse when the check fails, null on success.
     *
     * @param string|null $meetingId UUID of the meeting to scope the role check (optional)
     *
     * @return JSONResponse|null
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    private function requireChairOrSecretary(?string $meetingId=null): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        // Per-meeting role check.
        if ($meetingId !== null) {
            $authorized = $this->participantResolver->hasRole(
                meetingId: $meetingId,
                nextcloudUid: $uid,
                roles: ['chair', 'secretary']
            );
            if ($authorized === false) {
                return new JSONResponse(['message' => 'Chair or secretary role required for this meeting'], Http::STATUS_FORBIDDEN);
            }

            return null;
        }

        // Fallback: global chair_group or system-admin check.
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
     * Require the current user to hold the CHAIR role (not secretary) for a meeting.
     *
     * Used for the chair's casting vote on a tied round (voting-system spec):
     * a casting vote is the chair's personal prerogative, so the secretary does
     * not suffice. Per-meeting check via ParticipantResolver::hasRole(); when the
     * meeting cannot be resolved, falls back to the existing global
     * chair_group/admin check. Fail closed: any failure yields a 403.
     *
     * @param string|null $meetingId UUID of the meeting to scope the role check (optional)
     *
     * @return JSONResponse|null A 403/401 response on failure, null when authorized
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function requireChair(?string $meetingId=null): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        if ($meetingId !== null) {
            $authorized = $this->participantResolver->hasRole(
                meetingId: $meetingId,
                nextcloudUid: $uid,
                roles: ['chair']
            );
            if ($authorized === false) {
                return new JSONResponse(['message' => 'Chair role required for a casting vote'], Http::STATUS_FORBIDDEN);
            }

            return null;
        }

        // Fallback: global chair_group or system-admin check (existing pattern).
        $chairGroup = $this->appConfig->getValueString('decidesk', 'chair_group', '');

        if ($chairGroup !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $chairGroup);
        } else {
            $authorized = $this->groupManager->isAdmin($uid);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Chair role required for a casting vote'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireChair()

    /**
     * Resolve the meeting UUID linked to a voting round via motion relations.
     *
     * Handles motion rounds (round → motion → meeting) and amendment rounds
     * (round → amendment → parent motion → meeting), honouring both the flat
     * `meeting` property and structured relation entries on the motion.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string|null The meeting UUID or null if not found
     */
    private function resolveMeetingIdFromVotingRound(string $votingRoundId): ?string
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $roundEntity   = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
            if ($roundEntity === null) {
                return null;
            }

            $round = $roundEntity->jsonSerialize();

            $motionId = null;
            foreach (($round['relations'] ?? []) as $relation) {
                $relSchema = ($relation['schema'] ?? '');
                if ($relSchema === 'motion') {
                    $motionId = ($relation['id'] ?? null);
                    break;
                }

                // Amendment rounds (motion-amendment spec): resolve the parent
                // motion so the per-meeting chair guard still applies.
                if ($relSchema === 'amendment') {
                    $amendmentId = ($relation['id'] ?? null);
                    if ($amendmentId !== null) {
                        $amendmentEntity = $objectService->find(id: $amendmentId, register: 'decidesk', schema: 'amendment');
                        if ($amendmentEntity !== null) {
                            $amendment = $amendmentEntity->jsonSerialize();
                            $parentRef = ($amendment['parentMotion'] ?? null);
                            if (is_string($parentRef) === true && $parentRef !== '') {
                                $motionId = $parentRef;
                            } else if (is_array($parentRef) === true) {
                                $motionId = ($parentRef['id'] ?? $parentRef['uuid'] ?? null);
                            }

                            if ($motionId === null) {
                                foreach (($amendment['relations'] ?? []) as $amendmentRel) {
                                    if (($amendmentRel['schema'] ?? '') === 'motion') {
                                        $motionId = ($amendmentRel['id'] ?? null);
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    break;
                }
            }//end foreach

            if ($motionId !== null) {
                $motionEntity = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
                if ($motionEntity !== null) {
                    $motion = $motionEntity->jsonSerialize();

                    // Flat meeting property (canonical UI shape).
                    $meetingRef = ($motion['meeting'] ?? null);
                    if (is_string($meetingRef) === true && $meetingRef !== '') {
                        return $meetingRef;
                    }

                    if (is_array($meetingRef) === true && (($meetingRef['id'] ?? $meetingRef['uuid'] ?? '') !== '')) {
                        return ($meetingRef['id'] ?? $meetingRef['uuid']);
                    }

                    foreach (($motion['relations'] ?? []) as $motionRel) {
                        if (($motionRel['schema'] ?? '') === 'meeting') {
                            return ($motionRel['id'] ?? null);
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Silently fall through to global check.
        }//end try

        return null;

    }//end resolveMeetingIdFromVotingRound()

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
        $motionId  = ($params['motionId'] ?? '');
        $meetingId = ($params['meetingId'] ?? '');

        // Per-meeting chair/secretary check: use the meetingId from the request body if present.
        $guardMeetingId = null;
        if ($meetingId !== '') {
            $guardMeetingId = $meetingId;
        }

        $guard = $this->requireChairOrSecretary(meetingId: $guardMeetingId);
        if ($guard !== null) {
            return $guard;
        }

        $votingMethod = ($params['votingMethod'] ?? 'for-against-abstain');
        $isSecret     = (bool) ($params['isSecret'] ?? false);
        $closedAt     = null;
        $presetParticipantIds = [];

        if (isset($params['closedAt']) === true && $params['closedAt'] !== '') {
            $closedAt = $params['closedAt'];
        }

        if (isset($params['presetParticipantIds']) === true && is_array($params['presetParticipantIds']) === true) {
            $presetParticipantIds = $params['presetParticipantIds'];
        }

        if ($motionId === '' || $meetingId === '') {
            return new JSONResponse(['message' => 'motionId and meetingId are required'], Http::STATUS_BAD_REQUEST);
        }

        // Configurable voting rules (voting-system spec) — validate against the
        // service enums up front so a bad value is a clean 400, never a 500.
        // process-configuration: an OMITTED rule param is passed as null so the
        // opening body's process-template default applies; an explicit param wins.
        $voteThreshold      = (isset($params['voteThreshold']) === true && $params['voteThreshold'] !== '') ? (string) $params['voteThreshold'] : null;
        $abstentionHandling = (isset($params['abstentionHandling']) === true && $params['abstentionHandling'] !== '') ? (string) $params['abstentionHandling'] : null;
        $tieBreakRule       = (isset($params['tieBreakRule']) === true && $params['tieBreakRule'] !== '') ? (string) $params['tieBreakRule'] : null;
        $governanceBodyId   = (isset($params['governanceBody']) === true && is_string($params['governanceBody']) === true && $params['governanceBody'] !== '') ? $params['governanceBody'] : null;

        if ($voteThreshold !== null && in_array($voteThreshold, VotingService::VOTE_THRESHOLDS, true) === false) {
            return new JSONResponse(['message' => 'voteThreshold must be one of: '.implode(', ', VotingService::VOTE_THRESHOLDS)], Http::STATUS_BAD_REQUEST);
        }

        if ($abstentionHandling !== null && in_array($abstentionHandling, VotingService::ABSTENTION_MODES, true) === false) {
            return new JSONResponse(['message' => 'abstentionHandling must be one of: '.implode(', ', VotingService::ABSTENTION_MODES)], Http::STATUS_BAD_REQUEST);
        }

        if ($tieBreakRule !== null && in_array($tieBreakRule, VotingService::TIE_BREAK_RULES, true) === false) {
            return new JSONResponse(['message' => 'tieBreakRule must be one of: '.implode(', ', VotingService::TIE_BREAK_RULES)], Http::STATUS_BAD_REQUEST);
        }

        $revoteOfRoundId = null;
        if (isset($params['revoteOfRound']) === true && is_string($params['revoteOfRound']) === true && $params['revoteOfRound'] !== '') {
            $revoteOfRoundId = $params['revoteOfRound'];
        }

        // Subject type (motion-amendment spec): validate up front so a bad value
        // is a clean 400, never a 500.
        $subjectType = (string) ($params['subjectType'] ?? 'motion');
        if (in_array($subjectType, ['motion', 'amendment'], true) === false) {
            return new JSONResponse(['message' => 'subjectType must be one of: motion, amendment'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $round = $this->votingService->openVotingRound(
                motionId: $motionId,
                meetingId: $meetingId,
                votingMethod: $votingMethod,
                isSecret: $isSecret,
                closedAt: $closedAt,
                presetParticipantIds: $presetParticipantIds,
                voteThreshold: $voteThreshold,
                abstentionHandling: $abstentionHandling,
                tieBreakRule: $tieBreakRule,
                revoteOfRoundId: $revoteOfRoundId,
                subjectType: $subjectType,
                governanceBodyId: $governanceBodyId
            );
            return new JSONResponse($round, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
        $meetingId = $this->resolveMeetingIdFromVotingRound(votingRoundId: $id);
        $guard     = $this->requireChairOrSecretary(meetingId: $meetingId);
        if ($guard !== null) {
            return $guard;
        }

        $params    = $this->request->getParams();
        $anonymise = isset($params['anonymise']) && $params['anonymise'] === true;

        $chairCasting = null;
        if (isset($params['chairCasting']) === true && $params['chairCasting'] !== '') {
            // The casting vote is the chair's personal prerogative — require the
            // chair role specifically (fail closed; secretary may close, not cast).
            $chairGuard = $this->requireChair(meetingId: $meetingId);
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
    #[NoAdminRequired]
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
            $this->votingService->revokeProxy(votingRoundId: $id, fromParticipantId: $fromParticipantId);
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end revokeProxy()
}//end class
