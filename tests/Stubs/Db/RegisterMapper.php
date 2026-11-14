<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Db\RegisterMapper.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399): find() is copied from production,
 * ConductionNL/openregister@development (commit 70e5570),
 * lib/Db/RegisterMapper.php line 238. Only the method decidiq calls is
 * declared. This file is loaded by tests/bootstrap-unit.php and is NOT
 * scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stand-in for OpenRegister's register mapper; tests mock it.
 */
class RegisterMapper {
	/**
	 * Find a register by id or slug.
	 *
	 * @return Register
	 */
	public function find(
		string|int $id,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): Register {
		throw new \RuntimeException('RegisterMapper stub: mock find().');
	}
}
