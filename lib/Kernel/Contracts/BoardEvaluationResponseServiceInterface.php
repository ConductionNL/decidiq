<?php

/**
 * Contract of BoardEvaluationResponseService
 *
 * The public surface of BoardEvaluationResponseService that other components call, so they depend
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
 * Contract of BoardEvaluationResponseService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BoardEvaluationResponseServiceInterface {

	/**
	 * Resolve which Participant the logged-in user is FOR THIS EVALUATION.
	 *
	 * The identity a response is checked against is per-body, not per-person: a
	 * member who serves on two boards has two Participant objects, and an
	 * unscoped UID lookup returns whichever one the store lists first. Feeding
	 * that into submitResponse() compares an identity from one body against the
	 * invited roster of another, so the roster check rejects a genuinely
	 * invited member and the cycle's completion count never moves — with no
	 * error the member can act on, because the rejection is a legitimate-looking
	 * "not invited to this cycle".
	 *
	 * Scope to the evaluation's own governance body. Only when the evaluation
	 * carries no body at all does the unscoped lookup stand in; the roster check
	 * in submitResponse() remains the gate either way.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 * @param string $nextcloudUid The logged-in Nextcloud UID
	 *
	 * @return string|null The responding participant's UUID, or null when the
	 *                     user is not a participant of the evaluation's body
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function resolveResponder(string $evaluationId, string $nextcloudUid): ?string;

	/**
	 * Submit (or idempotently re-submit) one member's anonymous response.
	 *
	 * Enforces: the evaluation must be `open`; the participant must be on the
	 * invited roster; exactly one response per invited member (idempotent
	 * upsert keyed on the opaque HMAC token, mirroring VotingService's
	 * secret-round dedup). The response content never stores the
	 * participant id — only the opaque `responseToken`. Completion is
	 * recorded on the BoardEvaluation roster fields, never on the response.
	 *
	 * @param string $evaluationId UUID of the BoardEvaluation
	 * @param string $participantId UUID of the responding participant (never persisted on the response)
	 * @param array<int, array<string,mixed>> $answers Each with questionId, dimension, and optionally likertValue and freeText.
	 *
	 * @return array<string, mixed> Keys: success (bool), and optionally message (string)
	 *                              and response (array).
	 *
	 * @spec openspec/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
	 */
	public function submitResponse(string $evaluationId, string $participantId, array $answers): array;
}//end interface
