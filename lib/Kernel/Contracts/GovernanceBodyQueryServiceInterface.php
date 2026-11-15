<?php

/**
 * Contract of GovernanceBodyQueryService
 *
 * The public surface of GovernanceBodyQueryService that other components call, so they depend
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
 * Contract of GovernanceBodyQueryService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GovernanceBodyQueryServiceInterface {

	/**
	 * Read one governance body the consumer raised, with its roster.
	 *
	 * Looks up by `$governanceBodyId` when given, otherwise by
	 * (`$sourceApp`, `$externalReference`). Either way a body whose
	 * `sourceApp` is not the asking app is NOT returned: the seam answers a
	 * consumer about its own committees, never about another app's.
	 *
	 * @param string $sourceApp         The asking app id.
	 * @param string $externalReference The asking app's reference, or ''.
	 * @param string $governanceBodyId  The body id, or ''.
	 *
	 * @return array<string, mixed>|null The body (id, the BODY_FIELDS it carries, and
	 *         `members`: list of {uid, name, role, external, label?, startDate?, endDate?}),
	 *         or null when there is none for this consumer.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/governance-body-events/spec.md#requirement-req-gbe-007-a-consumer-reads-its-governance-body-back-through-a-typed-event
	 */
	public function lookup(string $sourceApp, string $externalReference, string $governanceBodyId): ?array;
}//end interface
