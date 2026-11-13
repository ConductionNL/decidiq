<?php

/**
 * Contract of ParticipationPublicationService
 *
 * The public surface of ParticipationPublicationService that other components call, so they depend
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
 * Contract of ParticipationPublicationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ParticipationPublicationServiceInterface {

	/**
	 * Build + publish the PII-free summary for a closed budget round.
	 *
	 * Includes the greedy allocation ranking + participation count. Voter
	 * identities never appear (the allocation result carries only aggregate
	 * vote counts, not voter ids).
	 *
	 * @param string $budgetId The ParticipatoryBudget round UUID.
	 *
	 * @return array<string, mixed> Publication result (see publishSummary()).
	 *
	 * @throws RuntimeException When the round is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function publishBudgetResults(string $budgetId): array;

	/**
	 * Build + publish the PII-free summary for a closed consultation.
	 *
	 * Builds a digest of APPROVED reactions (body only — no submitterId, no
	 * pseudonymous token) plus the staff response, sets `publicationDate` (the
	 * RBAC published predicate), and routes to OpenCatalogi when installed.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $staffResponse The staff response text included in the summary.
	 *
	 * @return array<string, mixed> Publication result (see publishSummary()).
	 *
	 * @throws RuntimeException When the consultation is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function publishConsultationResults(string $consultationId, string $staffResponse = ''): array;

	/**
	 * Publish (set publicationDate on) a closed BoardEvaluation's aggregate
	 * scoreSummary (board-self-evaluation, REQ-EVAL-005). Reuses this
	 * service's generic publishSummary() — the same mechanism as
	 * publishBudgetResults()/publishConsultationResults() — instead of a new
	 * publication pathway. Raw EvaluationResponse objects are never touched
	 * or exposed by this method; scoreSummary is already threshold-suppressed
	 * by BoardEvaluationScoreService before it ever reaches this call.
	 *
	 * @param string $evaluationId The BoardEvaluation UUID.
	 *
	 * @return array<string, mixed> Publication result (see publishSummary()).
	 *
	 * @throws RuntimeException When the evaluation is not found or not closed.
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-005-dashboard-report-and-optional-publication-reuse-existing-surfaces
	 */
	public function publishEvaluationResults(string $evaluationId): array;

	/**
	 * Publish (set publicationDate on) a single approved reaction (moderator opt-in).
	 *
	 * Never blanket: the moderator publishes one reaction at a time. The
	 * reaction body carries no PII (the submitterId stays internal and is not
	 * part of the published predicate surface payload by convention).
	 *
	 * @param string $reactionId The approved reaction UUID.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is missing or not approved.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function publishReaction(string $reactionId): array;
}//end interface
