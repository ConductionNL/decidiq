<?php

/**
 * Contract of GovernanceBodyCommandService
 *
 * The public surface of GovernanceBodyCommandService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of GovernanceBodyCommandService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GovernanceBodyCommandServiceInterface {

	/**
	 * Raise or update a governance body and its roster.
	 *
	 * @param string $sourceApp App id of the producer.
	 * @param string $externalReference The producer's own id for the originating record.
	 * @param array<string, mixed> $body Body fields; MUST carry name, bodyType, domain and active.
	 * @param array<int, array<string, mixed>> $members Roster entries of {uid, role, external, label}.
	 *
	 * @return array{id: string, created: bool} The resolved id and whether it was minted here.
	 *
	 * @throws RuntimeException When the command is incomplete or a write fails.
	 *
	 * @spec openspec/specs/governance-body-events/spec.md
	 */
	public function upsert(
		string $sourceApp,
		string $externalReference,
		array $body,
		array $members = [],
	): array;
}//end interface
