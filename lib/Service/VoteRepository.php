<?php
/**
 * Decidesk Vote Repository
 *
 * All Vote-object lookups scoped to a single voting round: the round's full
 * ballot set, the caller's existing ballot (secret and non-secret dedup keys),
 * and the one-proxy-per-round probes.
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
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Round-scoped access to Vote objects in OpenRegister.
 *
 * The OpenRegister `_relations.<schema>` filter matches any object that carries
 * a relation of that schema — it does NOT scope by the related id. Every read in
 * this class therefore re-checks the returned relations against the target id
 * through ObjectRelationFilter, so tally, quorum and dedup logic get an exact
 * match rather than "any vote that happens to reference some round".
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoteRepository
{

    /**
     * Exact-id scoping for relation-filtered result sets.
     *
     * @var ObjectRelationFilter
     */
    private readonly ObjectRelationFilter $relationFilter;

    /**
     * Opaque dedup tokens for secret rounds.
     *
     * @var VoterTokenService
     */
    private readonly VoterTokenService $tokens;

    /**
     * Constructor for VoteRepository.
     *
     * @param ContainerInterface $container The DI container (lazy ObjectService resolution)
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        $this->relationFilter = new ObjectRelationFilter();
        $this->tokens         = new VoterTokenService(container: $container);

    }//end __construct()

    /**
     * Return every Vote entity that genuinely belongs to the given round.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<int, mixed> The ObjectEntity result set
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function allInRound(string $votingRoundId): array
    {
        return $this->scopedTo(votingRoundId: $votingRoundId, extraFilters: []);

    }//end allInRound()

    /**
     * Find the participant's existing ballot in a round, if any.
     *
     * For secret rounds the participant relation is suppressed for anonymity, so
     * dedup is keyed on the deterministic voter token instead of the relation.
     *
     * @param string $participantId The voting participant UUID
     * @param string $votingRoundId The voting round UUID
     * @param bool   $isSecret      Whether the round is a secret ballot
     *
     * @return array<string, mixed>|null The serialised Vote, or null when none exists
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function existingVote(string $participantId, string $votingRoundId, bool $isSecret): ?array
    {
        foreach ($this->votesForVoter(participantId: $participantId, votingRoundId: $votingRoundId, isSecret: $isSecret) as $entity) {
            return $entity->jsonSerialize();
        }

        return null;

    }//end existingVote()

    /**
     * Whether a secret round already carries a proxy ballot for this delegator.
     *
     * @param string $delegatorId   The delegating participant UUID
     * @param string $votingRoundId The voting round UUID
     *
     * @return bool True when a proxy ballot is already registered
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function secretProxyExists(string $delegatorId, string $votingRoundId): bool
    {
        $token    = $this->tokens->delegatorToken(delegatorId: $delegatorId, votingRoundId: $votingRoundId);
        $existing = $this->scopedTo(votingRoundId: $votingRoundId, extraFilters: ['delegatorToken' => $token]);

        return empty($existing) === false;

    }//end secretProxyExists()

    /**
     * Whether an open round already carries a proxy ballot for this delegator.
     *
     * @param string $delegatorId   The delegating participant UUID
     * @param string $votingRoundId The voting round UUID
     *
     * @return bool True when a proxy ballot is already registered
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function openProxyExists(string $delegatorId, string $votingRoundId): bool
    {
        $existing = $this->scopedTo(votingRoundId: $votingRoundId, extraFilters: ['isProxy' => true]);

        foreach ($existing as $entity) {
            if ($this->isDelegatedBy(vote: $entity->jsonSerialize(), delegatorId: $delegatorId) === true) {
                return true;
            }
        }

        return false;

    }//end openProxyExists()

    /**
     * Persist a Vote object.
     *
     * @param array<string, mixed> $vote The Vote payload
     *
     * @return mixed The value returned by ObjectService::saveObject()
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function save(array $vote): mixed
    {
        return $this->objectService()->saveObject(register: 'decidesk', schema: 'vote', object: $vote);

    }//end save()

    /**
     * Resolve the ballots that identify the given voter in the given round.
     *
     * @param string $participantId The voting participant UUID
     * @param string $votingRoundId The voting round UUID
     * @param bool   $isSecret      Whether the round is a secret ballot
     *
     * @return array<int, mixed> The matching ObjectEntity result set
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function votesForVoter(string $participantId, string $votingRoundId, bool $isSecret): array
    {
        if ($isSecret === true) {
            $token = $this->tokens->voterToken(participantId: $participantId, votingRoundId: $votingRoundId);
            return $this->scopedTo(votingRoundId: $votingRoundId, extraFilters: ['voterToken' => $token]);
        }

        // Both _relations filters are schema-presence-only in OR, so scope to this
        // round first, then to this participant, to get an exact dedup match.
        $inRound = $this->scopedTo(votingRoundId: $votingRoundId, extraFilters: ['_relations.participant' => $participantId]);

        return $this->relationFilter->matching(entities: $inRound, schema: 'participant', targetId: $participantId);

    }//end votesForVoter()

    /**
     * Determine whether a serialised Vote was cast on behalf of the delegator.
     *
     * @param array<string, mixed> $vote        The serialised Vote object
     * @param string               $delegatorId The delegating participant UUID
     *
     * @return bool True when the vote carries a 'delegator' relation to $delegatorId
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function isDelegatedBy(array $vote, string $delegatorId): bool
    {
        foreach (($vote['relations'] ?? []) as $rel) {
            if (($rel['schema'] ?? '') === 'participant'
                && ($rel['id'] ?? '') === $delegatorId
                && ($rel['type'] ?? '') === 'delegator'
            ) {
                return true;
            }
        }

        return false;

    }//end isDelegatedBy()

    /**
     * Run a Vote query scoped to exactly one voting round.
     *
     * @param string               $votingRoundId The voting round UUID
     * @param array<string, mixed> $extraFilters  Additional OpenRegister filters
     *
     * @return array<int, mixed> The ObjectEntity result set, exact-scoped to the round
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function scopedTo(string $votingRoundId, array $extraFilters): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('vote');

        $filters = array_merge(['_relations.voting-round' => $votingRoundId], $extraFilters);
        $found   = $objectService->findAll(['filters' => $filters]);

        return $this->relationFilter->matching(entities: $found, schema: 'voting-round', targetId: $votingRoundId);

    }//end scopedTo()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object The OpenRegister ObjectService
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()
}//end class
