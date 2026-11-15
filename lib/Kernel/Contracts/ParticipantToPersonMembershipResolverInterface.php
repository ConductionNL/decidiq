<?php

/**
 * Contract of ParticipantToPersonMembershipResolver
 *
 * The public surface of ParticipantToPersonMembershipResolver that other components call, so they depend
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
 * Contract of ParticipantToPersonMembershipResolver.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ParticipantToPersonMembershipResolverInterface {

	/**
	 * Resolve a Participant UUID to its Person+Membership pair, matching or
	 * creating as needed (Decision 1, steps 1-3).
	 *
	 * Returns null only when the Participant itself cannot be loaded (unknown
	 * id, or OpenRegister unavailable) — every other outcome (matched or
	 * newly created) succeeds by design, since the resolver never merges two
	 * different people and always has a safe "create" fallback.
	 *
	 * @param string $participantId UUID of the Participant to resolve
	 *
	 * @return array{person: string, membership: string}|null
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-1-crosswalk-resolver--match-by-email-else-create
	 */
	public function resolve(string $participantId): ?array;
}//end interface
