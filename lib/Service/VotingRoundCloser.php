<?php
/**
 * Decidesk Voting Round Closer
 *
 * The close sequence of a voting round: the optional chair casting vote, the
 * final tally, the close timestamp, the subject lifecycle consequences, the ORI
 * publication trigger, and the optional GDPR anonymisation of ballot values.
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Closing a voting round.
 *
 * The two public entry points differ only in whether ballot values are nulled
 * afterwards; they exist as separate named methods rather than one method with a
 * boolean flag, so the destructive variant is explicit at every call site.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundCloser
{

    /**
     * Round-scoped Vote lookups and writes (anonymisation).
     *
     * @var VoteRepository
     */
    private readonly VoteRepository $votes;

    /**
     * Constructor for VotingRoundCloser.
     *
     * @param ContainerInterface          $container  The DI container
     * @param LoggerInterface             $logger     The logger
     * @param OriPublicationService       $oriService The ORI publication service
     * @param VotingRoundResults          $results    The tally / result computation
     * @param VotingSubjectOutcomeApplier $outcome    Subject lifecycle consequences
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly OriPublicationService $oriService,
        private readonly VotingRoundResults $results,
        private readonly VotingSubjectOutcomeApplier $outcome,
    ) {
        $this->votes = new VoteRepository(container: $container);

    }//end __construct()

    /**
     * Close a VotingRound, keeping individual ballot values intact.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string|null $chairCasting  Optional chair casting vote ('for'|'against') resolving a tie
     *
     * @return array<string, mixed> The closed voting round object
     *
     * @throws RuntimeException When the casting vote is not permitted (fail closed)
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function close(string $votingRoundId, ?string $chairCasting): array
    {
        return $this->closeRound(votingRoundId: $votingRoundId, chairCasting: $chairCasting, anonymise: false);

    }//end close()

    /**
     * Close a VotingRound and nullify the individual ballot values (GDPR).
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string|null $chairCasting  Optional chair casting vote ('for'|'against') resolving a tie
     *
     * @return array<string, mixed> The closed voting round object
     *
     * @throws RuntimeException When the casting vote is not permitted (fail closed)
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function closeAnonymised(string $votingRoundId, ?string $chairCasting): array
    {
        return $this->closeRound(votingRoundId: $votingRoundId, chairCasting: $chairCasting, anonymise: true);

    }//end closeAnonymised()

    /**
     * The shared close sequence.
     *
     * Order is load-bearing: tally -> close -> subject transition -> publish ->
     * anonymise. Anonymisation must come last, because it destroys the values the
     * tally and the publication read.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string|null $chairCasting  Optional chair casting vote resolving a tie
     * @param bool        $anonymise     Whether to nullify individual ballot values
     *
     * @return array<string, mixed> The closed voting round object
     *
     * @throws RuntimeException When the casting vote is not permitted (fail closed)
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function closeRound(string $votingRoundId, ?string $chairCasting, bool $anonymise): array
    {
        if ($chairCasting !== null) {
            $this->applyChairCastingVote(votingRoundId: $votingRoundId, chairCasting: $chairCasting);
        }

        $tally = $this->results->tally(votingRoundId: $votingRoundId);
        $round = $this->stampClosedAt(round: $this->loadRound(votingRoundId: $votingRoundId));

        if ($round !== null) {
            $this->outcome->apply(
                round: $round,
                result: ($tally['result'] ?? 'invalid'),
                votingRoundId: $votingRoundId
            );
        }

        $round = $this->publishToOri(votingRoundId: $votingRoundId, round: $round);

        if ($anonymise === true) {
            $this->anonymiseVotes(votingRoundId: $votingRoundId);
        }

        return ($round ?? []);

    }//end closeRound()

    /**
     * Validate and persist the chair's casting vote on a tied round (fail closed).
     *
     * Only permitted when the round exists, its tieBreakRule is 'chair-decides',
     * and the value is 'for' or 'against'. Persisted as chairCastingVote so the
     * subsequent tally resolves the tie and the audit trail shows the resolution.
     *
     * @param string $votingRoundId The voting round UUID
     * @param string $chairCasting  The casting vote value ('for'|'against')
     *
     * @return void
     *
     * @throws RuntimeException When the casting vote is not permitted
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function applyChairCastingVote(string $votingRoundId, string $chairCasting): void
    {
        if (in_array($chairCasting, ['for', 'against'], true) === false) {
            throw new RuntimeException("Casting vote refused: value must be 'for' or 'against'");
        }

        $round = $this->loadRound(votingRoundId: $votingRoundId);
        if ($round === null) {
            throw new RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['tieBreakRule'] ?? 'rejected') !== 'chair-decides') {
            throw new RuntimeException("Casting vote refused: this round's tie-break rule is not 'chair-decides'");
        }

        $round['chairCastingVote'] = $chairCasting;
        $this->objectService()->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

    }//end applyChairCastingVote()

    /**
     * Stamp the close timestamp on a round that is not already closed.
     *
     * @param array<string, mixed>|null $round The serialised voting round, or null when unknown
     *
     * @return array<string, mixed>|null The round with closedAt applied
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function stampClosedAt(?array $round): ?array
    {
        if ($round === null) {
            return null;
        }

        if (($round['closedAt'] ?? null) !== null) {
            return $round;
        }

        $round['closedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $this->objectService()->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

        return $round;

    }//end stampClosedAt()

    /**
     * Trigger ORI publication, recording a failure on the round (#318).
     *
     * A missing endpoint is not an error — publish() returns silently when none is
     * configured. Real configuration, protocol and infrastructure failures are
     * logged at ERROR level so they surface in monitoring, and the message is
     * attached to the round so the caller can reflect it in the response.
     *
     * @param string                    $votingRoundId The voting round UUID
     * @param array<string, mixed>|null $round         The serialised voting round
     *
     * @return array<string, mixed>|null The round, carrying oriPublicationError on failure
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function publishToOri(string $votingRoundId, ?array $round): ?array
    {
        try {
            $this->oriService->publish($votingRoundId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Decidesk: ORI publication failed after round close',
                ['votingRoundId' => $votingRoundId, 'error' => $e->getMessage()]
            );

            if ($round !== null) {
                $round['oriPublicationError'] = $e->getMessage();
            }
        }

        return $round;

    }//end publishToOri()

    /**
     * Nullify every ballot value in the round (fail-soft).
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function anonymiseVotes(string $votingRoundId): void
    {
        try {
            foreach ($this->votes->allInRound(votingRoundId: $votingRoundId) as $voteEntity) {
                $vote          = $voteEntity->jsonSerialize();
                $vote['value'] = null;
                $this->votes->save(vote: $vote);
            }

            $this->logger->info('Decidesk: votes anonymised', ['votingRoundId' => $votingRoundId]);
        } catch (Throwable $e) {
            $this->logger->warning('Decidesk: vote anonymisation failed', ['error' => $e->getMessage()]);
        }

    }//end anonymiseVotes()

    /**
     * Load a voting round, or null when it does not exist.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string, mixed>|null The serialised voting round
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function loadRound(string $votingRoundId): ?array
    {
        $entity = $this->objectService()->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
        if ($entity === null) {
            return null;
        }

        return $entity->jsonSerialize();

    }//end loadRound()

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
