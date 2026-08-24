<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399)
 * ----------------------------------------
 * This stub stands in for the real service only when the OpenRegister app is
 * not installed. Every signature below is copied verbatim from production; a
 * looser one here is not a convenience but a hole — PHPUnit generates its mock
 * from whichever class is on the autoloader, so a stub that returns `mixed`
 * lets `->willReturn([...])` stand in for a value production can never emit,
 * and the whole suite goes green on assertions that cannot hold in the app.
 *
 * Matched against ConductionNL/openregister@origin/development,
 * lib/Service/ObjectService.php (commit dc2e0b9eb):
 *
 *   setRegister()  line  443
 *   setSchema()    line  471
 *   find()         line  635
 *   findAll()      line  963
 *   saveObject()   line 1189
 *   deleteObject() line 1923
 *
 * Only the six methods decidiq actually calls are declared. A stub method that
 * production does NOT have is the same defect in the other direction: a mock
 * configured against it passes here and raises
 * "Call to undefined method" / MethodCannotBeConfiguredException in the app.
 *
 * This file is loaded by tests/bootstrap-unit.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * Stub for ObjectService, in signature parity with production.
 */
abstract class ObjectService {

	/**
	 * Set active register (fluent).
	 *
	 * Production: `public function setRegister(Register | string | int $register): static`
	 *
	 * @param Register|string|int $register Register entity, slug or ID
	 *
	 * @return static
	 */
	abstract public function setRegister(Register|string|int $register): static;

	/**
	 * Set active schema (fluent).
	 *
	 * Production: `public function setSchema(Schema | string | int $schema): static`
	 *
	 * @param Schema|string|int $schema Schema entity, slug or ID
	 *
	 * @return static
	 */
	abstract public function setSchema(Schema|string|int $schema): static;

	/**
	 * Fetch a single object by UUID or ID.
	 *
	 * Production returns `?ObjectEntity` — NOT `?object`. A test that hands the
	 * caller a bare stdClass or an anonymous JsonSerializable is asserting
	 * against a value the app can never receive.
	 *
	 * @param int|string $id Object UUID or integer ID
	 * @param array|null $_extend Properties to extend the object with
	 * @param bool $files Whether to include file information
	 * @param Register|string|int|null $register Register entity, slug or ID
	 * @param Schema|string|int|null $schema Schema entity, slug or ID
	 * @param bool $_rbac Whether to apply RBAC checks
	 * @param bool $_multitenancy Whether to apply organisation scoping
	 * @param bool $_render Whether to render the entity
	 *
	 * @return ObjectEntity|null
	 */
	abstract public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
	): ?ObjectEntity;

	/**
	 * Fetch all objects matching the given configuration.
	 *
	 * @param array<string,mixed> $config Query configuration
	 * @param bool $_rbac Whether to apply RBAC checks
	 * @param bool $_multitenancy Whether to apply organisation scoping
	 *
	 * @return array<mixed>
	 */
	abstract public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Save (create or update) an object.
	 *
	 * Production returns `ObjectEntity` — never an array. A mock returning the
	 * payload array it was handed proves nothing about how the app treats a
	 * real save result.
	 *
	 * @param array<string,mixed>|ObjectEntity $object Object data or entity
	 * @param array|null $extend Extend options
	 * @param Register|string|int|null $register Register entity, slug or ID
	 * @param Schema|string|int|null $schema Schema entity, slug or ID
	 * @param string|null $uuid Existing UUID for updates
	 * @param bool $_rbac Whether to apply RBAC checks
	 * @param bool $_multitenancy Whether to apply organisation scoping
	 * @param bool $silent Whether to suppress events
	 * @param array|null $uploadedFiles Files uploaded alongside the object
	 * @param IUser|null $currentUser The acting user
	 * @param bool $failIfExists Whether to fail on an existing object
	 *
	 * @return ObjectEntity
	 */
	abstract public function saveObject(
		array|ObjectEntity $object,
		?array $extend = [],
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
	): ObjectEntity;

	/**
	 * Delete (soft-archive) an object by UUID.
	 *
	 * Note the first parameter is a REQUIRED, non-nullable string in
	 * production — not `?string $uuid=null`.
	 *
	 * @param string $uuid Object UUID
	 * @param Register|string|int|null $register Register entity, slug or ID
	 * @param Schema|string|int|null $schema Schema entity, slug or ID
	 * @param bool $_rbac Whether to apply RBAC checks
	 * @param bool $_multitenancy Whether to apply organisation scoping
	 * @param bool $_retentionSweep Whether this is a retention sweep
	 * @param IUser|null $currentUser The acting user
	 *
	 * @return bool
	 */
	abstract public function deleteObject(
		string $uuid,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_retentionSweep = false,
		?IUser $currentUser = null,
	): bool;

}//end class
