<?php

/**
 * Contract of PlanningCycleGenerator
 *
 * The public surface of PlanningCycleGenerator that other components call, so they depend
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
 * Contract of PlanningCycleGenerator.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlanningCycleGeneratorInterface {

	/**
	 * The step payloads for a cycle, in template order.
	 *
	 * @param string               $cycleId  The cycle's UUID
	 * @param int                  $year     The cycle year
	 * @param array<string, mixed> $template The PlanningCycleTemplate
	 *
	 * @return array<int, array<string, mixed>> One planning-cycle-step payload per template step
	 *
	 * @spec openspec/specs/planning-cycle/spec.md#requirement-req-pcg-001-a-cycle-made-from-a-template-gets-its-steps
	 */
	public function stepsFor(string $cycleId, int $year, array $template): array;
}//end interface
