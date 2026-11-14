<?php

/**
 * Contract of OriSerializer
 *
 * The public surface of OriSerializer that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

/**
 * Contract of OriSerializer.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OriSerializerInterface {

	/**
	 * ORI JSON-LD context URL.
	 */
	public const ORI_CONTEXT = 'https://argu.co/ns/core';

	/**
	 * The ORI resource slug whose items are PublicationPayload objects.
	 */
	public const PUBLICATIONS_RESOURCE = 'publications';

	/**
	 * The ORI resource slug for commitments (followup-public-progress, fol-06).
	 */
	public const COMMITMENTS_RESOURCE = 'commitments';

	/**
	 * The ORI @type of a commitment.
	 */
	public const COMMITMENT_TYPE = 'Commitment';

	/**
	 * The resources whose public visibility is the publication window
	 * (publicationDate <= now, not depublished) rather than a lifecycle state.
	 *
	 * @var list<string>
	 */
	public const WINDOWED_RESOURCES = [
		self::PUBLICATIONS_RESOURCE,
		self::COMMITMENTS_RESOURCE,
	];

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
	public function isPayloadLive(array $object): bool;

	/**
	 * Serialize a Decidiq register object as a JSON-LD ORI resource.
	 *
	 * @param string $type The ORI @type label
	 * @param array<string, mixed> $object The OpenRegister object payload
	 *
	 * @return array<string, mixed> The serialized ORI resource
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-11
	 */
	public function serialize(string $type, array $object): array;

	/**
	 * Wrap fields a publication rule already allow-listed in the ORI envelope.
	 *
	 * Votes and vote events are not mapped through FIELD_RULES: their fields
	 * come from OriVotePublicationRule, which names exactly what an anonymous
	 * caller may read, so nothing of the stored ballot is added here.
	 *
	 * @param string               $type   The ORI @type label
	 * @param array<string, mixed> $fields The allow-listed fields, `id` first
	 *
	 * @return array<string, mixed> The serialized ORI resource
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function serializeAllowed(string $type, array $fields): array;

	/**
	 * Serialize a list of register objects into ORI JSON-LD items.
	 *
	 * @param string $resource The ORI resource slug
	 * @param string $type The ORI @type label for the collection
	 * @param iterable $objects The OpenRegister entities or payload arrays
	 *
	 * @return list<array<string, mixed>> The serialized ORI items
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-integration/tasks.md#task-11
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function serializeCollection(string $resource, string $type, iterable $objects): array;

	/**
	 * Serialize a PublicationPayload, letting it self-declare its ORI @type.
	 *
	 * Each payload declares its own ORI @type via `oriType` (Besluit /
	 * Vergadering / Verslag); the collection type is used as a fallback.
	 *
	 * @param array<string, mixed> $object The serialized PublicationPayload
	 * @param string $fallbackType The ORI @type to use when unset
	 *
	 * @return array<string, mixed> The serialized ORI resource
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function serializePayload(array $object, string $fallbackType): array;
}//end interface
