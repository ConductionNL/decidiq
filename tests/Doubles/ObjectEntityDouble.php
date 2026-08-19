<?php

/**
 * Contract-shaped ObjectEntity double for OpenRegister test doubles.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Doubles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Tests\Doubles;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * A plain payload wrapper satisfying ADR-084's ObjectEntityInterface.
 *
 * `ObjectServiceInterface::find()` / `saveObject()` return the *interface*, so a
 * hand-written store double must hand back something that really implements it
 * — an ad-hoc anonymous class with only `jsonSerialize()` type-errors at the
 * call site instead of exercising the code under test.
 */
final class ObjectEntityDouble implements ObjectEntityInterface {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $object       The object payload.
	 * @param string|null         $register     Register slug, when known.
	 * @param string|null         $schema       Schema slug, when known.
	 * @param string|null         $organisation Owning organisation, when known.
	 * @param string|null         $owner        Owning user, when known.
	 */
	public function __construct(
		private readonly array $object,
		private readonly ?string $register=null,
		private readonly ?string $schema=null,
		private readonly ?string $organisation=null,
		private readonly ?string $owner=null,
	) {
	}//end __construct()

	/**
	 * The object's UUID, taken from the payload when present.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		$uuid = ($this->object['uuid'] ?? $this->object['id'] ?? null);
		if ($uuid === null) {
			return null;
		}

		return (string)$uuid;
	}//end getUuid()

	/**
	 * The raw object payload.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return $this->object;
	}//end getObject()

	/**
	 * The register slug.
	 *
	 * @return string|null
	 */
	public function getRegister(): ?string {
		return $this->register;
	}//end getRegister()

	/**
	 * The schema slug.
	 *
	 * @return string|null
	 */
	public function getSchema(): ?string {
		return $this->schema;
	}//end getSchema()

	/**
	 * The owning organisation.
	 *
	 * @return string|null
	 */
	public function getOrganisation(): ?string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * The owning user.
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string {
		return $this->owner;
	}//end getOwner()

	/**
	 * Serialise like an ObjectEntity.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->object;
	}//end jsonSerialize()
}//end class
