<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399)
 * ----------------------------------------
 * This stub stands in for the real entity only when the OpenRegister app is not
 * installed. Anything this stub declares that production does not — or declares
 * more loosely than production — creates a class of test double that production
 * can never produce, and the suite goes green on assertions that could never
 * hold. The stub is therefore kept in exact parity with, and only with, the
 * members decidiq actually touches.
 *
 * Matched against ConductionNL/openregister@origin/development, lib/Db/ObjectEntity.php:
 *
 *   class ObjectEntity extends Entity implements JsonSerializable   (line 148)
 *   public function getObject(): array                              (line 781)
 *   public function jsonSerialize(): array                          (line 885)
 *
 * DELIBERATELY ABSENT: getUuid()/setUuid(). Production declares them ONLY as
 * `@method` docblock tags (lib/Db/ObjectEntity.php lines 61-62) and serves them
 * through Entity::__call. They are therefore NOT real methods: `method_exists()`
 * is false for them and PHPUnit's `->method('getUuid')` raises
 * MethodCannotBeConfiguredException. An earlier revision of this stub declared
 * getUuid() as a concrete method, so mocks built against it passed locally and
 * exploded the moment OpenRegister was on the autoloader.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Stub for ObjectEntity; mirrors the production class's real member set.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method void setObject(?array $object)
 */
class ObjectEntity extends Entity implements \OCA\OpenRegister\Contract\ObjectEntityInterface, JsonSerializable {
	/**
	 * @return ?string
	 */
	public function getUuid(): ?string {
		return $this->uuid ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getRegister(): ?string {
		return $this->register ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getSchema(): ?string {
		return $this->schema ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getOrganisation(): ?string {
		return $this->organisation ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getOwner(): ?string {
		return $this->owner ?? null;
	}

	/**
	 * Unique identifier for the object.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * Register the object belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * Schema the object belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * Object data stored as an array.
	 *
	 * @var array<string,mixed>|null
	 */
	protected ?array $object = null;

	/**
	 * Register the field types, as the production entity does.
	 */
	public function __construct() {
		$this->addType('uuid', 'string');
		$this->addType('register', 'string');
		$this->addType('schema', 'string');
		$this->addType('object', 'json');

	}//end __construct()

	/**
	 * Return the object data with 'id' injected from the UUID.
	 *
	 * Mirrors production: the id is prepended, not merged over.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return array_merge(['id' => $this->uuid], ($this->object ?? []));
	}//end getObject()

	/**
	 * Return a JSON-serialisable representation of the entity.
	 *
	 * Mirrors the shape production emits: the payload, plus an '@self' metadata
	 * envelope, plus a top-level 'id' when the UUID is known.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$object = ($this->object ?? []);
		$object['@self'] = [
			'id' => $this->uuid,
			'name' => $this->uuid,
			'register' => $this->register,
			'schema' => $this->schema,
		];

		if ($this->uuid !== null) {
			$object['id'] = $this->uuid;
		}

		return $object;
	}//end jsonSerialize()

}//end class
