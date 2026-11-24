<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\SchemaMapper.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399): find() is copied from production,
 * ConductionNL/openregister@development (commit 70e5570),
 * lib/Db/SchemaMapper.php line 245. Only the method decidiq calls is
 * declared. This file is loaded by tests/bootstrap-unit.php and is NOT
 * scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stand-in for OpenRegister's schema mapper; tests mock it.
 */
class SchemaMapper {
	/**
	 * Find a schema by id or slug.
	 *
	 * @return Schema
	 */
	public function find(
		string|int $id,
		array|null $_extend = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): Schema {
		throw new \RuntimeException('SchemaMapper stub: mock find().');
	}
}
