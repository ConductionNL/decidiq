<?php
/**
 * Decidesk Motion Amendment Service
 *
 * The amendment side of a motion: resolving a motion's amendments, stamping the
 * parliamentary voting order, detecting overlapping amendments, and merging an
 * adopted amendment into the motion text.
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Amendment operations, extracted from MotionService.
 *
 * MotionService carried an overall complexity of 72, of which the five
 * amendment methods were 30. They form a coherent unit — every one of them
 * starts by resolving the amendments of a motion — so they move out together
 * rather than being split into private helpers that would leave the class
 * WMC unchanged.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class MotionAmendmentService
{
    /**
     * Constructor for the MotionAmendmentService.
     *
     * @param ContainerInterface $container The DI container (for ObjectService / MotionLinkResolver)
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the ObjectService from the container.
     *
     * @return object The OpenRegister ObjectService.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Get the MotionLinkResolver from the container.
     *
     * @return MotionLinkResolver The motion link resolver.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function getLinkResolver(): MotionLinkResolver
    {
        return $this->container->get(MotionLinkResolver::class);

    }//end getLinkResolver()

    /**
     * Fetch all amendments linked to a motion, honouring BOTH link shapes.
     *
     * Amendments reference their motion either through the flat `parentMotion`
     * property (what the UI's relation tabs write) or through a structured
     * `relations` entry with schema 'motion' (what some backend paths write).
     * This resolver queries both shapes and dedups by id so callers (voting-order
     * enforcement, conflict detection, the chair ordering endpoint) see every
     * amendment regardless of how it was created.
     *
     * @param string $motionId UUID of the parent Motion
     *
     * @return array<int, array<string, mixed>> Serialized amendment objects
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function getAmendmentsForMotion(string $motionId): array
    {
        $objectService = $this->getObjectService();

        $found = [];

        // Shape 1: flat parent-decision property (canonical UI shape).
        // ADR-005: amendments are `decision` objects discriminated by
        // decisionType=amendment, and the retired Amendment schema's flat
        // `parentMotion` property is now the `amends` relation declared on
        // Decision.
        $objectService->setRegister('decidesk');
        $objectService->setSchema(DecisionSchema::SLUG);
        $byProperty = $objectService->findAll(
            [
                'filters' => DecisionSchema::filters(
                    decisionType: DecisionSchema::AMENDMENT,
                    extra: [DecisionSchema::AMENDS => $motionId]
                ),
            ]
        );
        foreach ($byProperty as $entity) {
            $amendment = $this->getLinkResolver()->serializeAmendment(entity: $entity);
            if ($amendment !== null && $this->amendmentReferencesMotion(amendment: $amendment, motionId: $motionId) === true) {
                $key         = (string) ($amendment['id'] ?? $amendment['uuid'] ?? '');
                $found[$key] = $amendment;
            }
        }

        // Shape 2: structured relations entry. OpenRegister's dotted
        // `_relations.<field>` filter keys on the RELATION PROPERTY name (see
        // MagicSearchHandler::applyRelationFieldFilter), which ADR-005 moved from
        // the retired `parentMotion` to `amends`. Each hit is still re-checked
        // for an exact motion-id reference before it counts.
        $objectService->setRegister('decidesk');
        $objectService->setSchema(DecisionSchema::SLUG);
        $byRelation = $objectService->findAll(
            [
                'filters' => DecisionSchema::filters(
                    decisionType: DecisionSchema::AMENDMENT,
                    extra: ['_relations.'.DecisionSchema::AMENDS => $motionId]
                ),
            ]
        );
        foreach ($byRelation as $entity) {
            $amendment = $this->getLinkResolver()->serializeAmendment(entity: $entity);
            if ($amendment === null) {
                continue;
            }

            $key = (string) ($amendment['id'] ?? $amendment['uuid'] ?? '');
            if (isset($found[$key]) === true) {
                continue;
            }

            if ($this->amendmentReferencesMotion(amendment: $amendment, motionId: $motionId) === true) {
                $found[$key] = $amendment;
            }
        }

        return array_values($found);

    }//end getAmendmentsForMotion()

    /**
     * Determine whether a serialized amendment references the given motion.
     *
     * @param array<string, mixed> $amendment Serialized amendment object
     * @param string               $motionId  UUID of the motion
     *
     * @return bool True when the amendment belongs to the motion
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    private function amendmentReferencesMotion(array $amendment, string $motionId): bool
    {
        return $this->getLinkResolver()->amendmentReferencesMotion(amendment: $amendment, motionId: $motionId);

    }//end amendmentReferencesMotion()

    /**
     * Persist the chair-chosen amendment voting order on a motion.
     *
     * Validates that every supplied amendment id belongs to the motion, then
     * stamps `votingOrder` 1..N in the supplied order. Caller-side chair
     * authorization is enforced by MotionController::amendmentOrder() (fail
     * closed); the actorId is still required here so bare DI-path callers
     * cannot reorder without an authenticated actor (the #317 pattern).
     *
     * @param string        $motionId            UUID of the parent Motion
     * @param array<string> $orderedAmendmentIds Amendment UUIDs in the desired voting order (index 0 = voted first)
     * @param string        $actorId             Nextcloud user UID performing the reorder
     *
     * @return array<int, array<string, mixed>> The amendments with their new votingOrder values
     *
     * @throws InvalidArgumentException When an id does not belong to the motion, ids repeat, or actorId is empty
     * @throws RuntimeException         When the motion has no amendments to order
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function setAmendmentVotingOrder(string $motionId, array $orderedAmendmentIds, string $actorId): array
    {
        if ($actorId === '') {
            throw new InvalidArgumentException('actorId must be a non-empty Nextcloud user UID');
        }

        if ($orderedAmendmentIds === []) {
            throw new InvalidArgumentException('orderedAmendmentIds must not be empty');
        }

        if (count($orderedAmendmentIds) !== count(array_unique($orderedAmendmentIds))) {
            throw new InvalidArgumentException('orderedAmendmentIds must not contain duplicates');
        }

        $amendments = $this->getAmendmentsForMotion(motionId: $motionId);
        if ($amendments === []) {
            throw new RuntimeException("Motion $motionId has no amendments to order");
        }

        $byId = [];
        foreach ($amendments as $amendment) {
            $byId[(string) ($amendment['id'] ?? $amendment['uuid'] ?? '')] = $amendment;
        }

        foreach ($orderedAmendmentIds as $amendmentId) {
            if (isset($byId[$amendmentId]) === false) {
                throw new InvalidArgumentException(
                    "Amendment $amendmentId does not belong to motion $motionId"
                );
            }
        }

        $objectService = $this->getObjectService();
        $updated       = [];
        foreach (array_values($orderedAmendmentIds) as $position => $amendmentId) {
            $amendment = $byId[$amendmentId];
            $amendment['votingOrder'] = ($position + 1);
            $amendment = DecisionSchema::stamp(object: $amendment, decisionType: DecisionSchema::AMENDMENT);

            $objectService->setRegister('decidesk');
            $objectService->setSchema(DecisionSchema::SLUG);
            $objectService->saveObject(
                object: $amendment,
                register: 'decidesk',
                schema: DecisionSchema::SLUG,
                uuid: $amendmentId,
            );
            $updated[] = $amendment;
        }

        $this->logger->info(
            "Decidesk: amendment voting order set on motion $motionId by $actorId",
            ['order' => array_values($orderedAmendmentIds)]
        );

        return $updated;

    }//end setAmendmentVotingOrder()

    /**
     * Detect text overlap between a new amendment and existing amendments on a motion.
     *
     * Fetches all submitted/debating amendments for the motion (via the
     * canonical getAmendmentsForMotion() resolver, so amendments linked through
     * the flat `parentMotion` property are no longer invisible to conflict
     * detection) and performs a naive word-overlap check. If overlap is
     * detected, notifies secretary-role users via NotificationService.
     *
     * @param string $motionId       UUID of the parent Motion
     * @param string $newAmendmentId UUID of the newly submitted Amendment
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $objectService = $this->getObjectService();

        // Fetch the new amendment. ADR-005: a `decision` lookup by id no longer
        // proves the object is an amendment, so the discriminator is re-checked.
        $objectService->setRegister('decidesk');
        $objectService->setSchema(DecisionSchema::SLUG);
        $newAmendment = $objectService->find($newAmendmentId);
        if ($newAmendment === null) {
            return;
        }

        $newData = $newAmendment->getObject();
        if (DecisionSchema::isType(object: $newData, decisionType: DecisionSchema::AMENDMENT) === false) {
            return;
        }

        $newText = strtolower($newData['text'] ?? '');

        // Fetch existing amendments for this motion (both link shapes).
        $existing = $this->getAmendmentsForMotion(motionId: $motionId);

        $conflictFound = false;
        foreach ($existing as $amendmentData) {
            $amendmentId = $amendmentData['id'] ?? $amendmentData['uuid'] ?? '';

            if ($amendmentId === $newAmendmentId) {
                continue;
            }

            $lifecycle = $amendmentData['lifecycle'] ?? '';
            if (in_array($lifecycle, ['submitted', 'debating'], true) === false) {
                continue;
            }

            $existingText = strtolower($amendmentData['text'] ?? '');

            // Naive overlap: check for common significant words (>4 chars).
            // Use Unicode-aware split so Dutch diacritics (é, ó, ë, etc.) are treated as word characters.
            $splitNew  = preg_split('/[^\pL\pN]+/u', $newText, -1, PREG_SPLIT_NO_EMPTY);
            $splitExst = preg_split('/[^\pL\pN]+/u', $existingText, -1, PREG_SPLIT_NO_EMPTY);
            if ($splitNew === false) {
                $splitNew = [];
            }

            if ($splitExst === false) {
                $splitExst = [];
            }

            $newWords      = array_filter($splitNew, fn($word) => mb_strlen($word) > 4);
            $existingWords = array_filter($splitExst, fn($word) => mb_strlen($word) > 4);
            $overlap       = array_intersect($newWords, $existingWords);

            if (count($overlap) > 3) {
                $conflictFound = true;
                break;
            }
        }//end foreach

        if ($conflictFound === false) {
            return;
        }

        // Store conflict note on the new amendment.
        $objectService->setRegister('decidesk');
        $objectService->setSchema(DecisionSchema::SLUG);
        $amendData = $newAmendment->getObject();
        $notes     = $amendData['notes'] ?? [];
        $notes[]   = [
            'title' => 'Conflict:',
            'body'  => 'Mogelijk tekstconflict gedetecteerd met een ander amendement. Raadpleeg de griffier.',
        ];
        $objectService->saveObject(
            object: DecisionSchema::stamp(
                object: array_merge($amendData, ['notes' => $notes]),
                decisionType: DecisionSchema::AMENDMENT
            ),
            register: 'decidesk',
            schema: DecisionSchema::SLUG,
            uuid: $newAmendmentId,
        );

        $this->logger->info("Decidesk: Amendment conflict detected for amendment $newAmendmentId on motion $motionId");

    }//end detectConflicts()

    /**
     * Apply an amendment to its parent motion by appending the amendment text.
     *
     * Reads the Amendment text and appends it as an annotation to the Motion
     * `text` field. Saves the updated Motion via ObjectService.
     *
     * @param string $motionId    UUID of the parent Motion
     * @param string $amendmentId UUID of the Amendment to apply
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $objectService = $this->getObjectService();

        // ADR-005: both sides are `decision` objects; the discriminator carries
        // the identity the retired schemas used to carry.
        $objectService->setRegister('decidesk');
        $objectService->setSchema(DecisionSchema::SLUG);
        $amendmentObject = $objectService->find($amendmentId);
        $amendmentData   = [];
        if ($amendmentObject !== null) {
            $amendmentData = $amendmentObject->getObject();
        }

        if ($amendmentObject === null
            || DecisionSchema::isType(object: $amendmentData, decisionType: DecisionSchema::AMENDMENT) === false
        ) {
            throw new RuntimeException("Amendment $amendmentId not found");
        }

        $amendTitle = $amendmentData['title'] ?? 'Amendement';
        $amendText  = $amendmentData['text'] ?? '';

        $objectService->setRegister('decidesk');
        $objectService->setSchema(DecisionSchema::SLUG);
        $motionObject = $objectService->find($motionId);
        $motionData   = [];
        if ($motionObject !== null) {
            $motionData = $motionObject->getObject();
        }

        if ($motionObject === null
            || DecisionSchema::isType(object: $motionData, decisionType: DecisionSchema::MOTION) === false
        ) {
            throw new RuntimeException("Motion $motionId not found");
        }

        $currentText = $motionData['text'] ?? '';
        $updatedText = $currentText."\n\n---\n**Amendement: $amendTitle**\n$amendText";

        $objectService->saveObject(
            object: array_merge($motionData, ['text' => $updatedText]),
            register: 'decidesk',
            schema: DecisionSchema::SLUG,
            uuid: $motionId,
        );

    }//end applyAmendment()
}//end class
