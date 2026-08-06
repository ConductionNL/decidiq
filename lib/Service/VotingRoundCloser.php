<?php
/**
 * Decidesk Voting Round Closer
 *
 * Implements the close-a-round path: optional chair casting vote, the tally,
 * stamping closedAt, the subject lifecycle transition, ORI publication and the
 * optional GDPR anonymisation of individual ballots.
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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The close-a-round path, extracted from VotingService.
 *
 * VotingService::closeVotingRound() was a 141-line method with a cyclomatic
 * complexity of 24 and an NPath complexity of 9936. The phases it ran through
 * are now named methods here.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundCloser
{
    /**
     * Constructor for the VotingRoundCloser.
     *
     * @param ContainerInterface    $container      The DI container (for ObjectService / FileService)
     * @param LoggerInterface       $logger         The logger
     * @param OriPublicationService $oriService     The ORI publication service
     * @param MotionService         $motionService  Drives the subject lifecycle transition
     * @param AmendmentOrderService $amendmentOrder Resolves an amendment's parent motion
     * @param ObjectRelationFilter  $relationFilter Exact-id scoping for relation-filtered result sets
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly OriPublicationService $oriService,
        private readonly MotionService $motionService,
        private readonly AmendmentOrderService $amendmentOrder,
        private readonly ObjectRelationFilter $relationFilter,
    ) {
    }//end __construct()

    /**
     * Close a VotingRound, optionally anonymising vote values.
     *
     * A chair casting vote must already have been persisted (see
     * applyChairCastingVote()) and the tally must already have been computed
     * from it — both happen in VotingService::closeVotingRound(), because that
     * ordering is what lets the tally resolve the tie.
     *
     * @param string              $votingRoundId The voting round UUID
     * @param bool                $anonymise     Whether to nullify individual vote values (GDPR)
     * @param array<string,mixed> $tally         The tally already computed for this round
     *
     * @return array<string,mixed> The closed voting round object
     *
     * @throws RuntimeException When the subject lifecycle transition is refused
     *
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function close(string $votingRoundId, bool $anonymise, array $tally): array
    {
        $round = $this->markClosed(votingRoundId: $votingRoundId);

        if ($round !== null) {
            $this->transitionSubject(
                round: $round,
                result: (string) ($tally['result'] ?? 'invalid'),
                votingRoundId: $votingRoundId
            );
        }

        $round = $this->publishOri(votingRoundId: $votingRoundId, round: $round);

        // Anonymise vote values if requested (sequence: tally -> publish -> anonymise).
        if ($anonymise === true) {
            $this->anonymiseVotes(votingRoundId: $votingRoundId);
        }

        return ($round ?? []);

    }//end close()

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
    public function applyChairCastingVote(string $votingRoundId, string $chairCasting): void
    {
        if (in_array($chairCasting, ['for', 'against'], true) === false) {
            throw new RuntimeException("Casting vote refused: value must be 'for' or 'against'");
        }

        $objectService = $this->objectService();
        $round         = $this->findRound(votingRoundId: $votingRoundId);
        if ($round === null) {
            throw new RuntimeException("VotingRound {$votingRoundId} not found");
        }

        if (($round['tieBreakRule'] ?? 'rejected') !== 'chair-decides') {
            throw new RuntimeException("Casting vote refused: this round's tie-break rule is not 'chair-decides'");
        }

        $round['chairCastingVote'] = $chairCasting;
        $objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

    }//end applyChairCastingVote()

    /**
     * Stamp closedAt on the round when it is not already closed.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string,mixed>|null The round, or null when it does not exist.
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function markClosed(string $votingRoundId): ?array
    {
        $round = $this->findRound(votingRoundId: $votingRoundId);
        if ($round === null) {
            return null;
        }

        if (($round['closedAt'] ?? null) === null) {
            $round['closedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $this->objectService()->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
        }

        return $round;

    }//end markClosed()

    /**
     * Transition the round's subject (motion or amendment) to its terminal state.
     *
     * #318: an InvalidArgumentException (a bad state-machine transition) is
     * re-thrown so the caller learns the round was closed but the subject
     * lifecycle could not be updated. Transient/infrastructure errors are
     * logged at ERROR level and swallowed so a network hiccup does not leave
     * the round un-closed.
     *
     * @param array<string,mixed> $round         The closed voting round
     * @param string              $result        The computed result
     * @param string              $votingRoundId The voting round UUID (for logging)
     *
     * @return void
     *
     * @throws RuntimeException When the state machine refuses the transition.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function transitionSubject(array $round, string $result, string $votingRoundId): void
    {
        $subject = $this->subjectOf(round: $round);
        if ($subject === null) {
            return;
        }

        // Only transition to defined terminal states via the guarded state machine.
        $subjectLifecycle = match ($result) {
            'adopted'  => 'adopted',
            'rejected' => 'rejected',
            default    => null,
        };

        if ($subjectLifecycle === null) {
            return;
        }

        try {
            $this->motionService->transitionLifecycle(
                objectId: $subject['id'],
                objectType: $subject['type'],
                newState: $subjectLifecycle,
                actorId: 'system',
            );

            $this->afterAdoption(subject: $subject, subjectLifecycle: $subjectLifecycle);
        } catch (InvalidArgumentException $e) {
            // State-machine violation: re-throw so the caller can surface it.
            // #318: Previously swallowed silently.
            throw new RuntimeException(
                'Stemronde gesloten maar motie kon niet worden bijgewerkt: '.$e->getMessage(),
                0,
                $e
            );
        } catch (Throwable $e) {
            // Transient infrastructure failure: log at ERROR level and continue.
            // #318: Previously logged at WARNING and lost in monitoring noise.
            $this->logger->error(
                'Decidesk: lifecycle transition after close failed — round is closed but motion state may be stale',
                ['votingRoundId' => $votingRoundId, 'motionId' => $subject['id'], 'error' => $e->getMessage()]
            );
        }//end try

    }//end transitionSubject()

    /**
     * Side effects that only apply to an adopted subject.
     *
     * @param array{type: string, id: string} $subject          The round's subject
     * @param string                          $subjectLifecycle The state it transitioned to
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function afterAdoption(array $subject, string $subjectLifecycle): void
    {
        if ($subjectLifecycle !== 'adopted') {
            return;
        }

        // Create dossier folder if an adopted MOTION.
        if ($subject['type'] === 'motion') {
            $motion      = $this->findObject(objectId: $subject['id'], schema: 'motion');
            $motionTitle = (string) ($motion['title'] ?? $subject['id']);
            $this->createDossierFolder(motionId: $subject['id'], motionTitle: $motionTitle);
        }

        // Adopted AMENDMENT: incorporate it into the parent motion text
        // (motion-amendment spec — "the final motion text MUST incorporate
        // all adopted amendments"). Fail-soft: a text-merge failure must
        // not undo the recorded vote result.
        if ($subject['type'] === 'amendment') {
            $this->incorporateAdoptedAmendment(amendmentId: $subject['id']);
        }

    }//end afterAdoption()

    /**
     * Pick the motion or amendment the round decides on.
     *
     * @param array<string,mixed> $round The voting round
     *
     * @return array{type: string, id: string}|null The subject, or null when absent.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function subjectOf(array $round): ?array
    {
        foreach (($round['relations'] ?? []) as $rel) {
            $relSchema = ($rel['schema'] ?? '');
            if ($relSchema !== 'motion' && $relSchema !== 'amendment') {
                continue;
            }

            $subjectId = ($rel['id'] ?? null);
            if ($subjectId === null) {
                return null;
            }

            return [
                'type' => $relSchema,
                'id'   => (string) $subjectId,
            ];
        }

        return null;

    }//end subjectOf()

    /**
     * Trigger ORI publication, attaching any failure to the round data.
     *
     * #318: infrastructure/network errors are logged at ERROR level (was INFO)
     * so they surface in monitoring. "Endpoint not configured" is still silent —
     * publish() returns without doing anything when no endpoint is set.
     *
     * @param string                   $votingRoundId The voting round UUID
     * @param array<string,mixed>|null $round         The closed round
     *
     * @return array<string,mixed>|null The round, possibly carrying oriPublicationError.
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function publishOri(string $votingRoundId, ?array $round): ?array
    {
        try {
            $this->oriService->publish($votingRoundId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Decidesk: ORI publication failed after round close',
                ['votingRoundId' => $votingRoundId, 'error' => $e->getMessage()]
            );

            // Attach ORI error to round data so caller can reflect it in the response.
            if ($round !== null) {
                $round['oriPublicationError'] = $e->getMessage();
            }
        }

        return $round;

    }//end publishOri()

    /**
     * Nullify every individual vote value in the round (GDPR anonymisation).
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
            $objectService = $this->objectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('vote');
            $voteEntities = $this->relationFilter->matching(
                entities: $objectService->findAll(
                    ['filters' => ObjectRelationFilter::filterFor(targetId: $votingRoundId)]
                ),
                schema: 'voting-round',
                targetId: $votingRoundId
            );

            foreach ($voteEntities as $voteEntity) {
                $vote          = $voteEntity->jsonSerialize();
                $vote['value'] = null;
                $objectService->saveObject(register: 'decidesk', schema: 'vote', object: $vote);
            }

            $this->logger->info('Decidesk: votes anonymised', ['votingRoundId' => $votingRoundId]);
        } catch (Throwable $e) {
            $this->logger->warning('Decidesk: vote anonymisation failed', ['error' => $e->getMessage()]);
        }

    }//end anonymiseVotes()

    /**
     * Create a dossier folder for an adopted motion via FileService.
     *
     * @param string $motionId    The motion UUID
     * @param string $motionTitle The motion title (used to compose folder path)
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function createDossierFolder(string $motionId, string $motionTitle): void
    {
        try {
            $fileService = $this->container->get('OCA\OpenRegister\Service\FileService');
            $slug        = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $motionTitle) ?? $motionId);
            $folderPath  = "motions/{$slug}-{$motionId}";
            $fileService->createFolder($folderPath);
            $this->logger->info('Decidesk: dossier folder created', ['path' => $folderPath, 'motionId' => $motionId]);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Decidesk: dossier folder creation failed',
                ['motionId' => $motionId, 'error' => $e->getMessage()]
            );
        }

    }//end createDossierFolder()

    /**
     * Incorporate an adopted amendment into its parent motion text (fail-soft).
     *
     * Resolves the amendment's parent motion (flat parentMotion property or
     * structured relation) and delegates to MotionService::applyAmendment(),
     * which appends the amendment as an annotated section of the motion text.
     * Failures are logged and never undo the recorded vote result.
     *
     * @param string $amendmentId The adopted amendment UUID
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function incorporateAdoptedAmendment(string $amendmentId): void
    {
        try {
            $amendment = $this->findObject(objectId: $amendmentId, schema: 'amendment');
            if ($amendment === null) {
                return;
            }

            $parentMotionId = $this->amendmentOrder->resolveParentMotionId(amendment: $amendment);
            if ($parentMotionId === null) {
                return;
            }

            $this->motionService->applyAmendment(motionId: $parentMotionId, amendmentId: $amendmentId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to incorporate adopted amendment into the parent motion text',
                ['amendmentId' => $amendmentId, 'error' => $e->getMessage()]
            );
        }

    }//end incorporateAdoptedAmendment()

    /**
     * Load a voting round as an array.
     *
     * @param string $votingRoundId The voting round UUID
     *
     * @return array<string,mixed>|null The round, or null when absent.
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function findRound(string $votingRoundId): ?array
    {
        return $this->findObject(objectId: $votingRoundId, schema: 'voting-round');

    }//end findRound()

    /**
     * Load any decidesk object as an array.
     *
     * @param string $objectId The object UUID
     * @param string $schema   The schema slug
     *
     * @return array<string,mixed>|null The object, or null when absent.
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function findObject(string $objectId, string $schema): ?array
    {
        $entity = $this->objectService()->find(id: $objectId, register: 'decidesk', schema: $schema);
        if ($entity === null) {
            return null;
        }

        return $entity->jsonSerialize();

    }//end findObject()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object The OpenRegister ObjectService.
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()
}//end class
