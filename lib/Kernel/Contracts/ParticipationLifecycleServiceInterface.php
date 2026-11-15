<?php

/**
 * Contract of ParticipationLifecycleService
 *
 * The public surface of ParticipationLifecycleService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of ParticipationLifecycleService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ParticipationLifecycleServiceInterface {

	/**
	 * Transition a ParticipatoryBudget round to a new lifecycle status.
	 *
	 * @param string $budgetId The budget round UUID.
	 * @param string $newStatus The target status.
	 *
	 * @return array<string, mixed> The updated budget round object.
	 *
	 * @throws RuntimeException When the round is not found.
	 * @throws InvalidArgumentException When the transition is illegal (fail closed).
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function transitionBudgetRound(string $budgetId, string $newStatus): array;

	/**
	 * Transition a PublicConsultation to a new lifecycle status.
	 *
	 * Validates the transition against the legal state machine (fail closed)
	 * and persists the new status. The legacy 'summarised' value is normalised
	 * to 'results-published' before the transition is evaluated.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $newStatus The target status.
	 *
	 * @return array<string, mixed> The updated consultation object.
	 *
	 * @throws RuntimeException When the consultation is not found.
	 * @throws InvalidArgumentException When the transition is illegal (fail closed).
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function transitionConsultation(string $consultationId, string $newStatus): array;
}//end interface
