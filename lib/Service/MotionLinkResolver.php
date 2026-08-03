<?php
/**
 * Decidesk Motion Link Resolver
 *
 * Reads and compares the object links a motion carries: the meeting it belongs
 * to, and whether a given amendment is a child of it. Both questions are asked
 * against data that exists in TWO shapes — a flat property (`meeting`,
 * `parentMotion`) written by the UI and the Newman fixtures, and a structured
 * `relations` entry written by OpenRegister — so the shape-tolerant matching
 * lives here once instead of being repeated per caller.
 *
 * Pure link reading: no lifecycle rules, no persistence, no authorization.
 * Extracted from MotionService (which had grown past the class-length budget)
 * and from MotionController (which was reaching into the DI container for
 * ObjectService just to answer "which meeting is this motion on?").
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
 * @spec openspec/specs/motion-amendment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Shape-tolerant reader for the meeting and parent-motion links on motions and
 * amendments.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class MotionLinkResolver
{
    /**
     * Construct the MotionLinkResolver.
     *
     * @param ContainerInterface $container The DI container for lazy-loading OR services
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {

    }//end __construct()

    /**
     * Resolve the meeting UUID linked to a motion.
     *
     * Returns null when the motion, or any meeting link on it, cannot be
     * resolved — callers treat that as "no meeting context".
     *
     * @param string $motionId The motion UUID
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string|null The meeting UUID or null if not found
     */
    public function resolveMeetingId(string $motionId): ?string
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $motionEntity  = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
            if ($motionEntity === null) {
                return null;
            }

            return $this->readMeetingLink(motion: $motionEntity->jsonSerialize());
        } catch (\Throwable) {
            // Silently fall through — callers treat null as "no meeting context".
            return null;
        }//end try

    }//end resolveMeetingId()

    /**
     * Read the meeting reference out of a serialised motion, either shape.
     *
     * @param array<string, mixed> $motion The serialised motion object
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string|null The meeting UUID or null if not linked
     */
    private function readMeetingLink(array $motion): ?string
    {
        // Flat meeting property (canonical UI shape).
        $meetingRef = ($motion['meeting'] ?? null);
        if (is_string($meetingRef) === true && $meetingRef !== '') {
            return $meetingRef;
        }

        if (is_array($meetingRef) === true && (($meetingRef['id'] ?? $meetingRef['uuid'] ?? '') !== '')) {
            return ($meetingRef['id'] ?? $meetingRef['uuid']);
        }

        foreach (($motion['relations'] ?? []) as $relation) {
            if (($relation['schema'] ?? '') === 'meeting') {
                return ($relation['id'] ?? null);
            }
        }

        return null;

    }//end readMeetingLink()

    /**
     * Serialize an ObjectService result item (entity or array) to an array.
     *
     * @param mixed $entity ObjectEntity or already-serialized array
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return array<string, mixed>|null Serialized object, or null when unusable
     */
    public function serializeAmendment(mixed $entity): ?array
    {
        if (is_array($entity) === true) {
            return $entity;
        }

        if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
            $serialized = $entity->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;

    }//end serializeAmendment()

    /**
     * Determine whether a serialized amendment references the given motion.
     *
     * Checks the flat `parentMotion` property (string or {id} object) and the
     * structured `relations` list.
     *
     * @param array<string, mixed> $amendment Serialized amendment object
     * @param string               $motionId  UUID of the motion
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return bool True when the amendment belongs to the motion
     */
    public function amendmentReferencesMotion(array $amendment, string $motionId): bool
    {
        if ($this->parentMotionMatches(amendment: $amendment, motionId: $motionId) === true) {
            return true;
        }

        foreach (($amendment['relations'] ?? []) as $relation) {
            if (is_array($relation) === true) {
                if ($this->relationEntryMatches(relation: $relation, motionId: $motionId) === true) {
                    return true;
                }

                continue;
            }

            if (is_string($relation) === true && $relation === $motionId) {
                return true;
            }
        }

        return false;

    }//end amendmentReferencesMotion()

    /**
     * Determine whether the flat `parentMotion` property points at the motion.
     *
     * Honours both the string form and the {id}/{uuid} object form.
     *
     * @param array<string, mixed> $amendment Serialized amendment object
     * @param string               $motionId  UUID of the motion
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return bool True when the parentMotion property matches
     */
    private function parentMotionMatches(array $amendment, string $motionId): bool
    {
        $parentRef = ($amendment['parentMotion'] ?? null);

        if (is_string($parentRef) === true) {
            return ($parentRef === $motionId);
        }

        if (is_array($parentRef) === true) {
            return ((($parentRef['id'] ?? $parentRef['uuid'] ?? '')) === $motionId);
        }

        return false;

    }//end parentMotionMatches()

    /**
     * Determine whether one structured relation entry points at the motion.
     *
     * A relation with no `schema` key is accepted (legacy shape); a relation
     * carrying a different schema is not.
     *
     * @param array<string, mixed> $relation One entry from the amendment's relations list
     * @param string               $motionId UUID of the motion
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return bool True when the relation references the motion
     */
    private function relationEntryMatches(array $relation, string $motionId): bool
    {
        $relId     = ($relation['id'] ?? $relation['uuid'] ?? '');
        $relSchema = ($relation['schema'] ?? null);

        return ($relId === $motionId && ($relSchema === null || $relSchema === 'motion'));

    }//end relationEntryMatches()
}//end class
