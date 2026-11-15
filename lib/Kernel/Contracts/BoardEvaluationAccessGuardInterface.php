<?php

/**
 * Contract of BoardEvaluationAccessGuard
 *
 * The public surface of BoardEvaluationAccessGuard that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of BoardEvaluationAccessGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BoardEvaluationAccessGuardInterface {

	/**
	 * Require membership of the evaluating body (any role), its chair or
	 * secretary, or an NC admin.
	 *
	 * Used by `report()`, which generates the cycle's report document into the
	 * body's Files folder. Deliberately WIDER than
	 * `requireChairOrSecretary()`: REQ-EVAL-005 names no actor for report
	 * generation, the document carries only the aggregate `scoreSummary` that
	 * every member already reads on the results tab, and the "Generate report"
	 * button sits on the same tab for every member — narrowing it to the
	 * presiding officers would take a capability away from board members who
	 * have it today. It is still not instance-wide: a board self-evaluation
	 * report is that board's internal document, and the caller-supplied
	 * evaluation id must not let a user off the body materialise it.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return JSONResponse|null Null when authorised, a 401/403 JSONResponse otherwise
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function requireBodyMember(string $evaluationId): ?JSONResponse;

	/**
	 * Require the evaluating body's chair or secretary (or an NC admin).
	 *
	 * Used by `close()` and `publish()`: both write the `lifecycle` property,
	 * whose OpenRegister `authorization.update` rule names exactly
	 * `chairUserId` / `secretaryUserId`. The rule is therefore identical to
	 * the one OR enforces on the write — this only moves the refusal forward
	 * to the HTTP boundary, where it can be a 403 instead of a 422 (close) or
	 * a silently-unpublished 200 (publish).
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 *
	 * @return JSONResponse|null Null when authorised, a 401/403 JSONResponse otherwise
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-006-one-mode-adaptive-entity-across-governance-domains
	 */
	public function requireChairOrSecretary(string $evaluationId): ?JSONResponse;
}//end interface
