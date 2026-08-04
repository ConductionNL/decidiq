<?php
/**
 * Decidesk Voting Subject Outcome Applier
 *
 * Propagates a closed round's result to the motion or amendment it decided:
 * the guarded lifecycle transition, the dossier folder for an adopted motion,
 * and the text incorporation of an adopted amendment.
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

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Subject-lifecycle consequences of a closed voting round.
 *
 * #318: a state-machine violation is re-thrown so the caller learns the round
 * was closed but the subject lifecycle could not be updated. Transient
 * infrastructure errors are logged at ERROR level and swallowed, so a network
 * hiccup does not leave the round un-closed — but they surface in monitoring.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/motion-amendment/spec.md
 */
class VotingSubjectOutcomeApplier
{

    /**
     * Resolves an amendment's parent motion.
     *
     * @var AmendmentOrderService
     */
    private readonly AmendmentOrderService $amendmentOrder;

    /**
     * Constructor for VotingSubjectOutcomeApplier.
     *
     * @param ContainerInterface $container     The DI container
     * @param LoggerInterface    $logger        The logger
     * @param MotionService      $motionService The motion service for lifecycle transitions
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly MotionService $motionService,
    ) {
        $this->amendmentOrder = new AmendmentOrderService(
            container: $container,
            motionService: $motionService
        );

    }//end __construct()

    /**
     * Transition the round's subject to the terminal state the result implies.
     *
     * Only 'adopted' and 'rejected' are defined terminal states; every other
     * result (including 'tied' and 'invalid') leaves the subject untouched.
     *
     * @param array<string, mixed> $round         The serialised voting round
     * @param string               $result        The computed voting result
     * @param string               $votingRoundId The voting round UUID (for log context)
     *
     * @return void
     *
     * @throws RuntimeException When the state machine refuses the transition (#318)
     *
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function apply(array $round, string $result, string $votingRoundId): void
    {
        $subject = $this->resolveSubject(round: $round);
        if ($subject === null) {
            return;
        }

        $lifecycle = match ($result) {
            'adopted'  => 'adopted',
            'rejected' => 'rejected',
            default    => null,
        };

        if ($lifecycle === null) {
            return;
        }

        $this->transition(
            subjectId: $subject['id'],
            subjectType: $subject['type'],
            lifecycle: $lifecycle,
            votingRoundId: $votingRoundId
        );

    }//end apply()

    /**
     * Resolve the motion or amendment the round decided.
     *
     * @param array<string, mixed> $round The serialised voting round
     *
     * @return array{type: string, id: string}|null The subject, or null when unresolvable
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function resolveSubject(array $round): ?array
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

            return ['type' => $relSchema, 'id' => $subjectId];
        }

        return null;

    }//end resolveSubject()

    /**
     * Run the guarded lifecycle transition and its adoption side effects.
     *
     * @param string $subjectId     The motion or amendment UUID
     * @param string $subjectType   'motion' | 'amendment'
     * @param string $lifecycle     The terminal lifecycle state
     * @param string $votingRoundId The voting round UUID (for log context)
     *
     * @return void
     *
     * @throws RuntimeException When the state machine refuses the transition (#318)
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function transition(string $subjectId, string $subjectType, string $lifecycle, string $votingRoundId): void
    {
        try {
            $this->motionService->transitionLifecycle(
                objectId: $subjectId,
                objectType: $subjectType,
                newState: $lifecycle,
                actorId: 'system',
            );

            $this->afterAdoption(subjectType: $subjectType, subjectId: $subjectId, lifecycle: $lifecycle);
        } catch (InvalidArgumentException $e) {
            // State-machine violation: re-throw so the caller can surface it (#318).
            throw new RuntimeException(
                'Stemronde gesloten maar motie kon niet worden bijgewerkt: '.$e->getMessage(),
                0,
                $e
            );
        } catch (Throwable $e) {
            // Transient infrastructure failure: log at ERROR level and continue (#318).
            $this->logger->error(
                'Decidesk: lifecycle transition after close failed — round is closed but motion state may be stale',
                ['votingRoundId' => $votingRoundId, 'motionId' => $subjectId, 'error' => $e->getMessage()]
            );
        }//end try

    }//end transition()

    /**
     * Run the side effects that only an adopted subject triggers.
     *
     * @param string $subjectType 'motion' | 'amendment'
     * @param string $subjectId   The motion or amendment UUID
     * @param string $lifecycle   The terminal lifecycle state just applied
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function afterAdoption(string $subjectType, string $subjectId, string $lifecycle): void
    {
        if ($lifecycle !== 'adopted') {
            return;
        }

        if ($subjectType === 'motion') {
            $this->createDossierFolder(motionId: $subjectId, motionTitle: $this->motionTitle(motionId: $subjectId));
            return;
        }

        if ($subjectType === 'amendment') {
            $this->incorporateAdoptedAmendment(amendmentId: $subjectId);
        }

    }//end afterAdoption()

    /**
     * Read a motion's title, falling back to its UUID.
     *
     * @param string $motionId The motion UUID
     *
     * @return string The motion title
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function motionTitle(string $motionId): string
    {
        $entity = $this->objectService()->find(id: $motionId, register: 'decidesk', schema: 'motion');
        $motion = null;
        if ($entity !== null) {
            $motion = $entity->jsonSerialize();
        }

        return (string) ($motion['title'] ?? $motionId);

    }//end motionTitle()

    /**
     * Create a dossier folder for an adopted motion via FileService (fail-soft).
     *
     * @param string $motionId    The motion UUID
     * @param string $motionTitle The motion title (used to compose the folder path)
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
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
            $this->logger->warning('Decidesk: dossier folder creation failed', ['motionId' => $motionId, 'error' => $e->getMessage()]);
        }

    }//end createDossierFolder()

    /**
     * Incorporate an adopted amendment into its parent motion text (fail-soft).
     *
     * Resolves the amendment's parent motion (flat parentMotion property or
     * structured relation) and delegates to MotionService::applyAmendment(), which
     * appends the amendment as an annotated section of the motion text. Failures
     * are logged and never undo the recorded vote result.
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
            $entity = $this->objectService()->find(id: $amendmentId, register: 'decidesk', schema: 'amendment');
            if ($entity === null) {
                return;
            }

            $parentMotionId = $this->amendmentOrder->resolveParentMotionId(amendment: $entity->jsonSerialize());
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
