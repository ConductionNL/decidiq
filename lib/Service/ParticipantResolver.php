<?php
/**
 * Decidesk Participant Resolver Service
 *
 * Centralised resolver that translates a meeting UUID into its participant list
 * via the canonical schema path: meeting → governanceBody → participants.
 *
 * All five previously-diverging participant-filter sites (AgendaController,
 * MinutesController, LiveMeetingController, VotingService, DecideskToolProvider)
 * delegate to this service so that schema correctness is enforced in one place.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves participants for a meeting through the canonical schema path.
 *
 * The participant schema has no direct `relations.meeting` field; the canonical
 * path is: meeting.relations[schema=governance-body] → governanceBodyId →
 * participants where relations.governance-body = governanceBodyId.
 *
 * Every call that previously filtered participants by the non-existent
 * `@self.relations.meeting` filter is routed through this service instead.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
 */
class ParticipantResolver
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (lazy-loads ObjectService)
     * @param LoggerInterface    $logger    PSR-3 logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Resolve the governance-body UUID linked to a meeting.
     *
     * Returns null when the meeting cannot be found or has no governance body.
     *
     * @param string $meetingId Meeting UUID
     *
     * @return string|null The governance-body UUID or null
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function resolveGovernanceBodyId(string $meetingId): ?string
    {
        $objectService = $this->objectService();
        $meetingEntity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
        if ($meetingEntity === null) {
            return null;
        }

        $meeting = $meetingEntity->jsonSerialize();

        // OpenRegister serialises relations in two shapes: a structured list
        // ([{ 'schema' => ..., 'id' => ... }, ...]) and a flat object keyed by the
        // source field name ({ 'governanceBody' => '<id>', ... }). Meetings carry
        // the body link in the flat form, so both must be honoured.
        $relations = ($meeting['@self']['relations'] ?? ($meeting['relations'] ?? []));
        if (is_array($relations) === true) {
            foreach ($relations as $key => $relation) {
                if (is_array($relation) === true) {
                    if (($relation['schema'] ?? '') === 'governance-body') {
                        return ($relation['id'] ?? null);
                    }

                    continue;
                }

                // Flat form: the field name is the relation target (e.g. 'governanceBody').
                if (is_string($relation) === true && $key === 'governanceBody') {
                    return $relation;
                }
            }
        }

        return null;

    }//end resolveGovernanceBodyId()

    /**
     * Return all participant objects for a meeting.
     *
     * Resolves meeting → governanceBody → participants. Returns an empty array
     * when the meeting or governance body cannot be resolved.
     *
     * @param string $meetingId Meeting UUID
     *
     * @return array<int, array<string, mixed>> Serialised participant objects
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function resolveMeetingParticipants(string $meetingId): array
    {
        $governanceBodyId = $this->resolveGovernanceBodyId(meetingId: $meetingId);
        if ($governanceBodyId === null) {
            $this->logger->warning(
                'Decidesk ParticipantResolver: no governance body linked to meeting',
                ['meetingId' => $meetingId]
            );
            return [];
        }

        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');

        // NOTE: objects created via the standard OpenRegister object API store the
        // governance-body link as a FLAT relation keyed by the source field name —
        // `@self.relations.governanceBody` (camelCase) — NOT under the schema-slug
        // key `governance-body`. The `_relations.governance-body` findAll filter
        // therefore matches nothing for OR-object-API-created participants, which
        // previously returned an empty participant list and 403'd seeded chairs.
        //
        // Read the full participant set for the register/schema and filter in PHP
        // via relationsReference(), which honours both the structured relation list
        // and the flat field-keyed map. This mirrors how resolveGovernanceBodyId()
        // already reads the flat relation shape.
        $entities = $objectService->findAll([]);

        $participants = [];
        foreach ($entities as $entity) {
            $participant = $entity->jsonSerialize();
            if ($this->relationsReference(object: $participant, schema: 'governance-body', targetId: $governanceBodyId) === false) {
                continue;
            }

            $participants[] = $participant;
        }

        return $participants;

    }//end resolveMeetingParticipants()

    /**
     * Determine whether a serialised object references $targetId via a relation.
     *
     * Honours both the structured (`{"relations.N.id": "...",
     * "relations.N.schema": "..."}`) and the legacy flat (`{"<field>": "<id>"}`)
     * relation shapes returned by OpenRegister.
     *
     * @param array<string, mixed> $object   The serialised object (jsonSerialize()).
     * @param string               $schema   The expected related schema slug.
     * @param string               $targetId The related UUID to look for.
     *
     * @return bool True when $targetId is referenced.
     */
    private function relationsReference(array $object, string $schema, string $targetId): bool
    {
        $relations = ($object['@self']['relations'] ?? ($object['relations'] ?? []));
        if (is_array($relations) === false) {
            return false;
        }

        foreach ($relations as $value) {
            if (is_array($value) === true) {
                $relSchema = ($value['schema'] ?? null);
                $relId     = ($value['id'] ?? null);
                if ($relId === $targetId && ($relSchema === null || $relSchema === $schema)) {
                    return true;
                }

                continue;
            }

            if (is_string($value) === true && $value === $targetId) {
                return true;
            }
        }

        return false;

    }//end relationsReference()

    /**
     * Check whether a Nextcloud UID is a participant in a meeting.
     *
     * Uses nextcloudUserId field on participant (canonical, set by PR #323).
     * Falls back to legacy `owner` field for pre-migration records.
     *
     * @param string $meetingId    Meeting UUID
     * @param string $nextcloudUid Nextcloud user ID to check
     *
     * @return bool True when the user is a participant in this meeting
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function isParticipant(string $meetingId, string $nextcloudUid): bool
    {
        foreach ($this->resolveMeetingParticipants(meetingId: $meetingId) as $p) {
            $uid   = $p['nextcloudUserId'] ?? null;
            $owner = $p['owner'] ?? null;

            if (($uid !== null && $uid === $nextcloudUid)
                || ($uid === null && $owner !== null && $owner === $nextcloudUid)
            ) {
                return true;
            }
        }

        return false;

    }//end isParticipant()

    /**
     * Check whether a Nextcloud UID holds one of the given roles in a meeting.
     *
     * Useful for access-control checks that require chair, secretary, or other
     * role membership (e.g. requireChairOrAdmin in controllers).
     *
     * @param string        $meetingId    Meeting UUID
     * @param string        $nextcloudUid Nextcloud user ID to check
     * @param array<string> $roles        Accepted roles (e.g. ['chair', 'secretary'])
     *
     * @return bool True when the user holds at least one of the given roles
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2
     */
    public function hasRole(string $meetingId, string $nextcloudUid, array $roles): bool
    {
        foreach ($this->resolveMeetingParticipants(meetingId: $meetingId) as $p) {
            $uid   = $p['nextcloudUserId'] ?? null;
            $owner = $p['owner'] ?? null;
            $role  = $p['role'] ?? null;

            $isCallerMatch = ($uid !== null && $uid === $nextcloudUid)
                || ($uid === null && $owner !== null && $owner === $nextcloudUid);

            if ($isCallerMatch === true && in_array(needle: $role, haystack: $roles, strict: true) === true) {
                return true;
            }
        }

        return false;

    }//end hasRole()
}//end class
