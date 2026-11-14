<?php

/**
 * Contract of ListenerSchemaResolver
 *
 * The public surface of ListenerSchemaResolver that other components call, so they depend
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
 * Contract of ListenerSchemaResolver.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ListenerSchemaResolverInterface {

	/**
	 * Whether an entity belongs to the named schema.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event
	 * @param string $expectedSlug The schema slug to match
	 * @param array<string, mixed> $row Serialised payload, when the caller already has one
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return boolean True when the entity is an instance of that schema
	 */
	public function matchesSchema(?object $entity, string $expectedSlug, array $row = []): bool;

	/**
	 * Read a scalar off an entity through an accessor that may be magic.
	 *
	 * `method_exists()` answers false for every accessor `Entity::__call()`
	 * serves — including `getId()` and `getUuid()` — so it cannot be used to
	 * decide whether the read is available. `Entity::__call()` delegates to
	 * `Entity::getter()`, which throws `BadFunctionCallException` unless
	 * `property_exists()` holds; asking that question first keeps a normal miss
	 * off the exception path, and the try/catch covers everything else (a
	 * property the subclass declares but refuses to serve, a mapper entity with
	 * its own getter override).
	 *
	 * @param object|null $entity The entity to read from
	 * @param string $getter The accessor name, e.g. 'getSchema'
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string The value as a string, or '' when unavailable
	 */
	public function readValue(?object $entity, string $getter): string;

	/**
	 * Resolve the schema slug of an OpenRegister object entity.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event
	 * @param array<string, mixed> $row Serialised payload, when the caller already has one
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string The lower-cased schema slug, or '' when unresolvable
	 */
	public function schemaSlug(?object $entity, array $row = []): string;
}//end interface
