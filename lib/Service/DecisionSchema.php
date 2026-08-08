<?php
/**
 * Decidesk Decision Schema Vocabulary
 *
 * The ADR-005 mapping from the retired sibling schemas onto the unified
 * `decision` supertype, in one place.
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

/**
 * ADR-005: `Decision` is the single universal supertype.
 *
 * `motion`, `amendment` and `resolution` are no longer schemas — they are values
 * of the `decisionType` discriminator on the one `Decision` entity. The three
 * schemas were deleted from `lib/Settings/decidesk_register.json`, and
 * `tests/Unit/RegisterJsonTest.php` asserts their absence.
 *
 * Every OpenRegister call that used to name one of those slugs must now name
 * `decision` AND carry the discriminator: as a `filters` entry when querying,
 * and as an object property when writing. Naming a deleted slug is not a silent
 * miss — `ObjectService::setSchema()` rethrows `DoesNotExistException`, which is
 * neither `InvalidArgumentException` nor `RuntimeException`, so it escapes the
 * controllers' catch clauses and the endpoint answers 500 where it owes 404/400.
 *
 * The frontend already carries the same mapping: `src/store/store.js`
 * registers the logical `motion` / `amendment` object types against the
 * `decision` schema.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
final class DecisionSchema
{

    /**
     * The unified Decision schema slug that replaced motion/amendment/resolution.
     */
    public const SLUG = 'decision';

    /**
     * The discriminator property carrying the former schema identity.
     */
    public const DISCRIMINATOR = 'decisionType';

    /**
     * `decisionType` for a parliamentary motion (was the `motion` schema).
     */
    public const MOTION = 'motion';

    /**
     * `decisionType` for an amendment to a motion (was the `amendment` schema).
     */
    public const AMENDMENT = 'amendment';

    /**
     * `decisionType` for a corporate resolution (was the `resolution` schema).
     */
    public const RESOLUTION = 'resolution';

    /**
     * The property linking an amendment decision to the motion decision it amends.
     *
     * ADR-005 / `unify-decision-supertype` design D3 retire the Amendment schema's
     * flat `parentMotion` property in favour of the `amends` relation declared on
     * `Decision` ("Used by decisionType=amendment to point at its parent motion
     * decision (replaces the retired Amendment → Motion relation)").
     */
    public const AMENDS = 'amends';

    /**
     * Retired schema slug => the `decisionType` value that replaced it (ADR-005 table).
     *
     * @var array<string, string>
     */
    public const RETIRED_SCHEMA_TYPES = [
        'motion'     => self::MOTION,
        'amendment'  => self::AMENDMENT,
        'resolution' => self::RESOLUTION,
    ];

    /**
     * Read the discriminator off a serialized decision.
     *
     * @param array<string, mixed> $object A serialized OpenRegister object
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string The `decisionType`, or '' when the object carries none
     */
    public static function typeOf(array $object): string
    {
        return (string) ($object[self::DISCRIMINATOR] ?? '');

    }//end typeOf()

    /**
     * Test whether a serialized decision carries the given discriminator value.
     *
     * Used where an id used to be scoped by the schema it was fetched from: a
     * `decision` lookup by id no longer proves the object is a motion, so the
     * caller must re-establish that itself.
     *
     * @param array<string, mixed> $object       A serialized OpenRegister object
     * @param string               $decisionType The required discriminator value
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return bool True when the object is a decision of that type
     */
    public static function isType(array $object, string $decisionType): bool
    {
        return self::typeOf($object) === $decisionType;

    }//end isType()

    /**
     * Build the `findAll()` filter set selecting one decision type.
     *
     * @param string              $decisionType The discriminator value to select
     * @param array<string,mixed> $extra        Additional filters to merge in
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return array<string, mixed> The filter set
     */
    public static function filters(string $decisionType, array $extra=[]): array
    {
        return array_merge($extra, [self::DISCRIMINATOR => $decisionType]);

    }//end filters()

    /**
     * Stamp the discriminator onto an object about to be written.
     *
     * `decisionType` is `required` on the Decision schema, so a write that omits
     * it fails hard validation; an existing value is never overwritten.
     *
     * @param array<string, mixed> $object       The object payload to write
     * @param string               $decisionType The discriminator value to ensure
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return array<string, mixed> The payload carrying a `decisionType`
     */
    public static function stamp(array $object, string $decisionType): array
    {
        if (($object[self::DISCRIMINATOR] ?? '') !== '') {
            return $object;
        }

        $object[self::DISCRIMINATOR] = $decisionType;
        return $object;

    }//end stamp()
}//end class
