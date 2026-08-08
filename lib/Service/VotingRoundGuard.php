<?php
/**
 * Decidesk Voting Round Guard
 *
 * Per-meeting authorisation for the voting endpoints: the chair/secretary and
 * chair-only role checks, plus the motion-chain resolution that tells those
 * checks which meeting a voting round belongs to. Extracted from
 * VotingController so the controller stays a thin HTTP shell.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Authorisation guard for the voting-round endpoints.
 *
 * Every check fails CLOSED: an unresolvable meeting falls back to the global
 * chair_group / system-admin check, and any failure yields a 401/403 response
 * instead of an exception.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
 */
class VotingRoundGuard
{
    /**
     * Constructor for VotingRoundGuard.
     *
     * @param IUserSession        $userSession         The user session
     * @param IGroupManager       $groupManager        The group manager
     * @param IAppConfig          $appConfig           The app config
     * @param ParticipantResolver $participantResolver Per-meeting participant/role resolver
     * @param ContainerInterface  $container           DI container (lazy-loads ObjectService)
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly ParticipantResolver $participantResolver,
        private readonly ContainerInterface $container,
    ) {
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
     * @param string|null $meetingId UUID of the meeting to scope the role check (optional)
     *
     * @return JSONResponse|null A 401/403 response on failure, null when authorised
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    public function requireChairOrSecretary(?string $meetingId=null): ?JSONResponse
    {
        return $this->requireRoles(
            meetingId: $meetingId,
            roles: ['chair', 'secretary'],
            meetingDenial: 'Chair or secretary role required for this meeting',
            globalDenial: 'Chair or secretary role required'
        );

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
     * @return JSONResponse|null A 401/403 response on failure, null when authorised
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function requireChair(?string $meetingId=null): ?JSONResponse
    {
        return $this->requireRoles(
            meetingId: $meetingId,
            roles: ['chair'],
            meetingDenial: 'Chair role required for a casting vote',
            globalDenial: 'Chair role required for a casting vote'
        );

    }//end requireChair()

    /**
     * Shared role check: per-meeting when a meeting is known, global otherwise.
     *
     * @param string|null $meetingId     UUID of the meeting to scope the role check
     * @param string[]    $roles         Participant roles that satisfy the check
     * @param string      $meetingDenial Message for a failed per-meeting check
     * @param string      $globalDenial  Message for a failed global check
     *
     * @return JSONResponse|null A 401/403 response on failure, null when authorised
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    private function requireRoles(
        ?string $meetingId,
        array $roles,
        string $meetingDenial,
        string $globalDenial
    ): ?JSONResponse {
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
                roles: $roles
            );
            if ($authorized === false) {
                return new JSONResponse(['message' => $meetingDenial], Http::STATUS_FORBIDDEN);
            }

            return null;
        }

        if ($this->isGloballyAuthorized(uid: $uid) === false) {
            return new JSONResponse(['message' => $globalDenial], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireRoles()

    /**
     * Fallback authority check: the configured chair_group, or system admin.
     *
     * @param string $uid The Nextcloud user id
     *
     * @return bool True when the user holds global chair authority
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.2
     */
    private function isGloballyAuthorized(string $uid): bool
    {
        $chairGroup = $this->appConfig->getValueString('decidesk', 'chair_group', '');

        if ($chairGroup !== '') {
            return $this->groupManager->isInGroup($uid, $chairGroup);
        }

        return $this->groupManager->isAdmin($uid);

    }//end isGloballyAuthorized()

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
    public function resolveMeetingIdFromVotingRound(string $votingRoundId): ?string
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $round = $this->findData(objectService: $objectService, id: $votingRoundId, schema: 'voting-round');
            if ($round === null) {
                return null;
            }

            $motionId = $this->resolveMotionId(objectService: $objectService, round: $round);
            if ($motionId === null) {
                return null;
            }

            // ADR-005: the motion is a `decision` discriminated by decisionType.
            $motion = $this->findData(objectService: $objectService, id: $motionId, schema: DecisionSchema::SLUG);
            if ($motion === null) {
                return null;
            }

            return $this->meetingIdFromMotion(motion: $motion);
        } catch (\Throwable) {
            // Silently fall through to global check.
            return null;
        }//end try

    }//end resolveMeetingIdFromVotingRound()

    /**
     * Fetch one object and return its serialised data, or null when absent.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param mixed  $id            The object identifier
     * @param string $schema        The decidesk schema slug
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return array<string,mixed>|null The object data, or null when not found
     */
    private function findData(object $objectService, mixed $id, string $schema): ?array
    {
        $entity = $objectService->find(id: $id, register: 'decidesk', schema: $schema);
        if ($entity === null) {
            return null;
        }

        return $entity->jsonSerialize();

    }//end findData()

    /**
     * Resolve the motion a voting round votes on, following an amendment round
     * up to its parent motion.
     *
     * @param object              $objectService The OpenRegister ObjectService
     * @param array<string,mixed> $round         The voting-round data
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return mixed The motion identifier, or null when it cannot be resolved
     */
    private function resolveMotionId(object $objectService, array $round): mixed
    {
        foreach (($round['relations'] ?? []) as $relation) {
            $relSchema = ($relation['schema'] ?? '');
            if ($relSchema === 'motion') {
                return ($relation['id'] ?? null);
            }

            // Amendment rounds (motion-amendment spec): resolve the parent
            // motion so the per-meeting chair guard still applies.
            if ($relSchema === 'amendment') {
                return $this->motionIdFromAmendment(
                    objectService: $objectService,
                    amendmentId: ($relation['id'] ?? null)
                );
            }
        }

        return null;

    }//end resolveMotionId()

    /**
     * Resolve an amendment's parent motion, from either the `parentMotion`
     * property or a structured motion relation.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param mixed  $amendmentId   The amendment identifier
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return mixed The parent motion identifier, or null when absent
     */
    private function motionIdFromAmendment(object $objectService, mixed $amendmentId): mixed
    {
        if ($amendmentId === null) {
            return null;
        }

        // ADR-005: the amendment is a `decision` discriminated by decisionType,
        // and its parent link is the `amends` relation that replaced the retired
        // Amendment schema's `parentMotion` property.
        $amendment = $this->findData(objectService: $objectService, id: $amendmentId, schema: DecisionSchema::SLUG);
        if ($amendment === null
            || DecisionSchema::isType(object: $amendment, decisionType: DecisionSchema::AMENDMENT) === false
        ) {
            return null;
        }

        $parentRef = ($amendment[DecisionSchema::AMENDS] ?? null);
        if (is_string($parentRef) === true && $parentRef !== '') {
            return $parentRef;
        }

        if (is_array($parentRef) === true) {
            $parentId = ($parentRef['id'] ?? $parentRef['uuid'] ?? null);
            if ($parentId !== null) {
                return $parentId;
            }
        }

        return $this->relationId(relations: ($amendment['relations'] ?? []), schema: 'motion');

    }//end motionIdFromAmendment()

    /**
     * Resolve the meeting a motion belongs to, from the flat `meeting` property
     * (canonical UI shape) or a structured meeting relation.
     *
     * @param array<string,mixed> $motion The motion data
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string|null The meeting UUID, or null when absent
     */
    private function meetingIdFromMotion(array $motion): ?string
    {
        $meetingRef = ($motion['meeting'] ?? null);
        if (is_string($meetingRef) === true && $meetingRef !== '') {
            return $meetingRef;
        }

        if (is_array($meetingRef) === true && (($meetingRef['id'] ?? $meetingRef['uuid'] ?? '') !== '')) {
            return ($meetingRef['id'] ?? $meetingRef['uuid']);
        }

        return $this->relationId(relations: ($motion['relations'] ?? []), schema: 'meeting');

    }//end meetingIdFromMotion()

    /**
     * First relation entry of the given schema, as its identifier.
     *
     * @param mixed  $relations The relations collection
     * @param string $schema    The schema slug to look for
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return mixed The related identifier, or null when there is no such relation
     */
    private function relationId(mixed $relations, string $schema): mixed
    {
        foreach ($relations as $relation) {
            if (($relation['schema'] ?? '') === $schema) {
                return ($relation['id'] ?? null);
            }
        }

        return null;

    }//end relationId()
}//end class
