<?php

/**
 * Contract of PublicationConfigService
 *
 * The public surface of PublicationConfigService that other components call, so they depend
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
 * Contract of PublicationConfigService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicationConfigServiceInterface {

	/**
	 * Read the full publication configuration blob.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed> Map of bodyId => body config.
	 */
	public function getAll(): array;

	/**
	 * Persist the full publication configuration blob.
	 *
	 * Validates policy + attendance enums; unknown values are dropped to keep
	 * the stored blob well-formed.
	 *
	 * @param array<string,mixed> $config Map of bodyId => body config.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed> The normalised, persisted config.
	 */
	public function save(array $config): array;

	/**
	 * Valid per-type publication policy values.
	 *
	 * @var string[]
	 */
	public const POLICIES = ['manual-only', 'prompt-on-transition'];

	/**
	 * Valid attendance-rendering policy values for minutes payloads.
	 *
	 * @var string[]
	 */
	public const ATTENDANCE_POLICIES = ['counts', 'role-holders'];
}//end interface
