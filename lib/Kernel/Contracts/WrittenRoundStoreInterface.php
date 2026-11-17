<?php

/**
 * Contract of WrittenRoundStore
 *
 * The public surface of WrittenRoundStore that other components call, so they depend
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
 * Contract of WrittenRoundStore.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WrittenRoundStoreInterface {

	/**
	 * Every object of a schema matching the filters, as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md
	 */
	public function findAll(string $schema, array $filters): array;
}//end interface
