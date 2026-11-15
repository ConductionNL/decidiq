<?php

/**
 * Contract of BoardEvaluationScoreService
 *
 * The public surface of BoardEvaluationScoreService that other components call, so they depend
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
 * Contract of BoardEvaluationScoreService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BoardEvaluationScoreServiceInterface {

	/**
	 * Close an evaluation cycle: fetch its responses, compute the score
	 * summary, and materialise it onto the BoardEvaluation together with the
	 * `closed` lifecycle + `closedAt`.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return array<string, mixed> Keys: success (bool), and optionally message (string)
	 *                              and evaluation (array).
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 */
	public function closeCycle(string $evaluationId): array;
}//end interface
