<?php

/**
 * Contract of BoardEvaluationReportService
 *
 * The public surface of BoardEvaluationReportService that other components call, so they depend
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

use OCA\Decidiq\Exception\MissingObjectException;

/**
 * Contract of BoardEvaluationReportService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BoardEvaluationReportServiceInterface {

	/**
	 * Generate the evaluation report (markdown canonical, Docudesk PDF
	 * opportunistic) and persist it under "Decidesk/<body>/Evaluations/<cycle>".
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation (must be closed or published)
	 *
	 * @throws MissingObjectException When the evaluation cannot be found
	 *
	 * @return array<string, mixed> Keys: path, format, docudesk, and optionally note.
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function generate(string $evaluationId): array;
}//end interface
