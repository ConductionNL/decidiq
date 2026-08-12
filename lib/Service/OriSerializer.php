<?php

/**
 * Decidesk ORI 1.4 JSON-LD serializer
 *
 * Turns Decidesk/OpenRegister object payloads into ORI 1.4 JSON-LD resources
 * and evaluates the anonymous published-predicate window for PublicationPayload
 * objects. Extracted from OriController so the controller keeps only its HTTP
 * responsibility (REQ-ORI-001..004).
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use Throwable;

/**
 * ORI 1.4 JSON-LD serialization for Decidesk register objects.
 *
 * Field renaming follows ORI 1.4 conventions and is expressed declaratively in
 * the rule tables below: each ORI target key lists the source properties to try,
 * in order, and the first one present wins.
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-11
 */
class OriSerializer
{

    /**
     * ORI JSON-LD context URL.
     */
    public const ORI_CONTEXT = 'https://argu.co/ns/core';

    /**
     * The ORI resource slug whose items are PublicationPayload objects.
     */
    public const PUBLICATIONS_RESOURCE = 'publications';

    /**
     * Core ORI field rules: target key => ordered list of source properties.
     *
     * `title` → `name`, `scheduledDate` → `start_date`, `endDate` → `end_date`,
     * `lifecycle` → `status`, `meetingType`/`bodyType`/`motionType` →
     * `classification`.
     *
     * @var array<string, list<string>>
     */
    private const FIELD_RULES = [
        'name'           => ['title', 'name'],
        'start_date'     => ['scheduledDate'],
        'end_date'       => ['endDate'],
        'location'       => ['location'],
        'status'         => ['lifecycle'],
        'classification' => ['meetingType', 'bodyType', 'motionType'],
        'text'           => ['text'],
    ];

    /**
     * PublicationPayload-specific ORI field rules.
     *
     * Publish-decisions-via-opencatalogi task 5.2 — PublicationPayloads are
     * derived, allow-list objects (no UID, no voter identities, no contact
     * details by construction), so every field listed here is safe to surface on
     * the harvest feed.
     *
     * @var array<string, list<string>>
     */
    private const PAYLOAD_FIELD_RULES = [
        'schemaOrgType' => ['schemaOrgType'],
        'body'          => ['bodyName'],
        'outcome'       => ['outcome'],
        'decision_date' => ['decisionDate'],
        'legal_basis'   => ['legalBasis'],
        'vote_totals'   => ['voteTotals'],
        'meeting_date'  => ['meetingDate'],
        'agenda_items'  => ['agendaItems'],
        'content'       => ['content'],
        'attendance'    => ['attendance'],
        'published_at'  => ['publicationDate'],
    ];

    /**
     * ORI @type labels whose resources may expose an `email` property.
     *
     * C5: only expose email for Organisation-typed resources to prevent
     * accidental leakage of private contact details from other types.
     * popolo-decision-makers (owner-confirmed): Person email IS exposed on the
     * public ORI /persons output — elected/officeholder contact is
     * open-government transparency data, consistent with ORI/Popolo Person
     * serialization.
     *
     * @var list<string>
     */
    private const EMAIL_TYPES = [
        'Organization',
        'Person',
    ];

    /**
     * Serialize a list of register objects into ORI JSON-LD items.
     *
     * @param string   $resource The ORI resource slug
     * @param string   $type     The ORI @type label for the collection
     * @param iterable $objects  The OpenRegister entities or payload arrays
     *
     * @return list<array<string, mixed>> The serialized ORI items
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     * @spec openspec/specs/public-publication/spec.md
     */
    public function serializeCollection(string $resource, string $type, iterable $objects): array
    {
        $items = [];
        foreach ($objects as $object) {
            $objectArray = $this->normalise(object: $object);

            if ($resource !== self::PUBLICATIONS_RESOURCE) {
                $items[] = $this->serialize(type: $type, object: $objectArray);
                continue;
            }

            // Defence-in-depth published-predicate window: only emit payloads
            // that are live RIGHT NOW. A future-dated or already-depublished
            // payload must never appear in the harvest feed.
            if ($this->isPayloadLive(object: $objectArray) === false) {
                continue;
            }

            $items[] = $this->serializePayload(object: $objectArray, fallbackType: $type);
        }//end foreach

        return $items;

    }//end serializeCollection()

    /**
     * Serialize a PublicationPayload, letting it self-declare its ORI @type.
     *
     * Each payload declares its own ORI @type via `oriType` (Besluit /
     * Vergadering / Verslag); the collection type is used as a fallback.
     *
     * @param array<string, mixed> $object       The serialized PublicationPayload
     * @param string               $fallbackType The ORI @type to use when unset
     *
     * @return array<string, mixed> The serialized ORI resource
     *
     * @spec openspec/specs/public-publication/spec.md
     */
    public function serializePayload(array $object, string $fallbackType): array
    {
        $itemType = ($object['oriType'] ?? null);
        if (is_string($itemType) === false || $itemType === '') {
            $itemType = $fallbackType;
        }

        return $this->serialize(type: $itemType, object: $object);

    }//end serializePayload()

    /**
     * Serialize a Decidesk register object as a JSON-LD ORI resource.
     *
     * @param string               $type   The ORI @type label
     * @param array<string, mixed> $object The OpenRegister object payload
     *
     * @return array<string, mixed> The serialized ORI resource
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    public function serialize(string $type, array $object): array
    {
        $self = ($object['@self'] ?? []);
        // L1: prefer the entity's own uuid field over @self.id (which is an
        // internal OpenRegister reference and must not be surfaced publicly).
        $id = ($object['uuid'] ?? ($object['id'] ?? ($self['id'] ?? null)));

        $payload = [
            '@context' => self::ORI_CONTEXT,
            '@type'    => $type,
            'id'       => $id,
        ];

        $payload += $this->applyRules(object: $object, rules: self::FIELD_RULES);

        if (in_array(needle: $type, haystack: self::EMAIL_TYPES, strict: true) === true) {
            $payload += $this->applyRules(object: $object, rules: ['email' => ['email']]);
        }

        // Publish-decisions-via-opencatalogi task 5.2 — the payload self-declares
        // its ORI @type via oriType (Besluit / Vergadering / Verslag); its presence
        // also selects the PublicationPayload-specific field set.
        $oriType = ($object['oriType'] ?? null);
        if (is_string($oriType) === false || $oriType === '') {
            return $payload;
        }

        $payload['oriType'] = $oriType;

        return ($payload + $this->applyRules(object: $object, rules: self::PAYLOAD_FIELD_RULES));

    }//end serialize()

    /**
     * Evaluate the RBAC published-predicate window for a PublicationPayload.
     *
     * A payload is "live" — and therefore visible on the anonymous ORI harvest
     * feed — when its `publicationDate` is set and not in the future, AND its
     * `depublicationDate` is either unset or still in the future. This mirrors
     * the public-group `authorization.read` rule the PublicationPayload schema
     * declares (`publicationDate <= $now`); it is re-checked in PHP as
     * defence-in-depth so a misconfigured RBAC rule cannot leak future-dated or
     * already-depublished payloads through the harvest surface.
     *
     * @param array<string, mixed> $object The serialized PublicationPayload
     *
     * @return bool True when the payload is currently publicly visible
     *
     * @spec openspec/specs/public-publication/spec.md
     */
    public function isPayloadLive(array $object): bool
    {
        $now       = new DateTimeImmutable();
        $published = $this->parseDate(value: ($object['publicationDate'] ?? null));
        if ($published === null || $published > $now) {
            return false;
        }

        $depublished = $this->parseDate(value: ($object['depublicationDate'] ?? null));

        return ($depublished === null || $depublished > $now);

    }//end isPayloadLive()

    /**
     * Apply an ordered target => sources rule table to an object payload.
     *
     * For every target key the first source property that is set on the object
     * wins; targets with no matching source are omitted entirely.
     *
     * @param array<string, mixed>        $object The OpenRegister object payload
     * @param array<string, list<string>> $rules  Target key => ordered sources
     *
     * @return array<string, mixed> The mapped subset, in rule order
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    private function applyRules(array $object, array $rules): array
    {
        $mapped = [];
        foreach ($rules as $target => $sources) {
            foreach ($sources as $source) {
                if (isset($object[$source]) === true) {
                    $mapped[$target] = $object[$source];
                    break;
                }
            }
        }

        return $mapped;

    }//end applyRules()

    /**
     * Normalise an OpenRegister findAll() result entry to a property array.
     *
     * FindAll() yields ObjectEntity instances; jsonSerialize() gives the flat
     * property map (title, lifecycle, motionType, …). A raw (array) cast mangles
     * the entity's protected props, leaving the serializer with only @self/id.
     *
     * @param mixed $object The entity or payload array
     *
     * @return array<string, mixed> The flat property map
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    private function normalise(mixed $object): array
    {
        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            return $object->jsonSerialize();
        }

        return (array) $object;

    }//end normalise()

    /**
     * Parse an ISO-8601 / ATOM date value into a DateTimeImmutable.
     *
     * @param mixed $value The date-time value, if any
     *
     * @return DateTimeImmutable|null The parsed value, or null when absent or unparseable
     *
     * @spec openspec/specs/public-publication/spec.md
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }

    }//end parseDate()
}//end class
