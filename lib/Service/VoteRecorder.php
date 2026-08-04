<?php
/**
 * Decidesk Vote Recorder
 *
 * Builds and persists the Vote object once every eligibility gate has passed:
 * the idempotency slug that turns a re-cast into an upsert, the relation set
 * (suppressed on secret rounds), the honest attendance annotation, and the
 * opaque dedup tokens a secret ballot needs.
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

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persistence of a single ballot.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoteRecorder
{

    /**
     * Idempotency slug and secret-ballot tokens.
     *
     * @var VoterTokenService
     */
    private readonly VoterTokenService $tokens;

    /**
     * Round-scoped Vote lookups and writes.
     *
     * @var VoteRepository
     */
    private readonly VoteRepository $votes;

    /**
     * ObjectEntity -> array normalisation for the save result.
     *
     * @var SavedObjectNormaliser
     */
    private readonly SavedObjectNormaliser $normaliser;

    /**
     * Constructor for VoteRecorder.
     *
     * @param ContainerInterface $container The DI container (lazy ObjectService resolution)
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        $this->tokens     = new VoterTokenService(container: $container);
        $this->votes      = new VoteRepository(container: $container);
        $this->normaliser = new SavedObjectNormaliser();

    }//end __construct()

    /**
     * Persist the ballot, overwriting the caster's previous ballot in this round.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string      $participantId The casting participant UUID
     * @param string      $value         for | against | abstain
     * @param bool        $isProxy       Whether the ballot is cast by proxy
     * @param string|null $delegatorId   The delegator UUID for a proxy ballot
     * @param bool        $isSecret      Whether the round is a secret ballot
     *
     * @return array<string, mixed> The created/updated Vote object
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function record(
        string $votingRoundId,
        string $participantId,
        string $value,
        bool $isProxy,
        ?string $delegatorId,
        bool $isSecret
    ): array {
        // Check for an existing ballot — a re-cast overwrites it.
        $existingVote = $this->votes->existingVote(
            participantId: $participantId,
            votingRoundId: $votingRoundId,
            isSecret: $isSecret
        );

        $relations = $this->buildRelations(
            votingRoundId: $votingRoundId,
            participantId: $participantId,
            isProxy: $isProxy,
            delegatorId: $delegatorId,
            isSecret: $isSecret
        );

        $slug = $this->tokens->idempotencySlug(
            votingRoundId: $votingRoundId,
            participantId: $participantId,
            isSecret: $isSecret,
            isProxy: $isProxy,
            delegatorId: $delegatorId
        );

        $vote = [
            '@self'     => ['slug' => $slug],
            'value'     => $value,
            'weight'    => 1,
            'isProxy'   => $isProxy,
            'castAt'    => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'castAs'    => $this->resolveCastAs(participantId: $participantId),
            'relations' => $relations,
        ];

        $vote = array_merge(
            $vote,
            $this->secretBallotTokens(
                votingRoundId: $votingRoundId,
                slug: $slug,
                isProxy: $isProxy,
                delegatorId: $delegatorId,
                isSecret: $isSecret
            )
        );

        if ($existingVote !== null) {
            $vote['id']   = ($existingVote['id'] ?? null);
            $vote['uuid'] = ($existingVote['uuid'] ?? null);
        }

        return $this->normaliser->toArray(saved: $this->votes->save(vote: $vote), fallback: $vote);

    }//end record()

    /**
     * Build the ballot's relation set.
     *
     * For non-secret rounds the ballot is linked to the casting participant (and,
     * for a proxy ballot, to the delegator). For secret rounds those relations are
     * omitted to preserve anonymity.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string      $participantId The casting participant UUID
     * @param bool        $isProxy       Whether the ballot is cast by proxy
     * @param string|null $delegatorId   The delegator UUID for a proxy ballot
     * @param bool        $isSecret      Whether the round is a secret ballot
     *
     * @return array<int, array<string, string>> The relation entries
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function buildRelations(
        string $votingRoundId,
        string $participantId,
        bool $isProxy,
        ?string $delegatorId,
        bool $isSecret
    ): array {
        $relations = [
            ['register' => 'decidesk', 'schema' => 'voting-round', 'id' => $votingRoundId],
        ];

        if ($isSecret === true) {
            return $relations;
        }

        $relations[] = ['register' => 'decidesk', 'schema' => 'participant', 'id' => $participantId];

        if ($isProxy === true && $delegatorId !== null) {
            $relations[] = ['register' => 'decidesk', 'schema' => 'participant', 'id' => $delegatorId, 'type' => 'delegator'];
        }

        return $relations;

    }//end buildRelations()

    /**
     * Build the opaque dedup tokens a secret ballot carries.
     *
     * The voter token replaces the suppressed participant relation for re-cast
     * detection; the delegator token enforces one proxy per round without storing
     * the delegator's identity next to the ballot.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string      $slug          The idempotency slug (the voter token on a secret round)
     * @param bool        $isProxy       Whether the ballot is cast by proxy
     * @param string|null $delegatorId   The delegator UUID for a proxy ballot
     * @param bool        $isSecret      Whether the round is a secret ballot
     *
     * @return array<string, string> The token properties to merge into the ballot
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function secretBallotTokens(
        string $votingRoundId,
        string $slug,
        bool $isProxy,
        ?string $delegatorId,
        bool $isSecret
    ): array {
        if ($isSecret === false) {
            return [];
        }

        $tokens = ['voterToken' => $slug];

        if ($isProxy === true && $delegatorId !== null) {
            $tokens['delegatorToken'] = $this->tokens->delegatorToken(
                delegatorId: $delegatorId,
                votingRoundId: $votingRoundId
            );
        }

        return $tokens;

    }//end secretBallotTokens()

    /**
     * Resolve the attendance mode to stamp on a ballot (remote-vote annotation).
     *
     * Honest recording only — reads the casting participant's participantType
     * ('in-person' | 'remote') and returns it; 'unknown' when the participant
     * cannot be resolved or the field is unset. No session-verification theater.
     * Carries no identity, so it is stamped on secret-ballot votes too.
     *
     * @param string $participantId The casting participant UUID
     *
     * @return string 'in-person' | 'remote' | 'unknown'
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function resolveCastAs(string $participantId): string
    {
        try {
            $entity = $this->objectService()->find(id: $participantId, register: 'decidesk', schema: 'participant');
            if ($entity !== null) {
                $participant = $entity->jsonSerialize();
                $type        = ($participant['participantType'] ?? null);
                if (in_array($type, ['in-person', 'remote'], true) === true) {
                    return $type;
                }
            }
        } catch (Throwable $e) {
            $this->logger->debug('Decidesk: castAs participant lookup failed', ['error' => $e->getMessage()]);
        }

        return 'unknown';

    }//end resolveCastAs()

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
