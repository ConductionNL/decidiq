<?php

/**
 * Contract of ReactionIntakeService
 *
 * The public surface of ReactionIntakeService that other components call, so they depend
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
 * Contract of ReactionIntakeService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReactionIntakeServiceInterface {

	/**
	 * Approve a pending reaction (staff action).
	 *
	 * Sets moderationStatus to 'approved' so the reaction surfaces in the
	 * consultation's reactions relation and becomes eligible for publication.
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string|null $reason Optional moderation note.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is not found.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function approveReaction(string $reactionId, ?string $reason = null): array;

	/**
	 * Reject a pending reaction (staff action).
	 *
	 * Sets moderationStatus to 'rejected' with a reason. The object is retained
	 * (soft-delete conventions) for audit and never counts toward
	 * submissionCount.
	 *
	 * @param string $reactionId The reaction UUID.
	 * @param string $reason The rejection reason.
	 *
	 * @return array<string, mixed> The updated reaction object.
	 *
	 * @throws RuntimeException When the reaction is not found.
	 * @throws InvalidArgumentException When no reason is given.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function rejectReaction(string $reactionId, string $reason): array;

	/**
	 * Submit a reaction on a consultation.
	 *
	 * Enforces: consultation open + deadline (server-side, independent of
	 * stored status), payload cap, anonymous gate (per-consultation
	 * anonymousReactionsAllowed), and moderation defaulting. Anonymous
	 * submissions receive a pseudonymous submitterId and are ALWAYS pending;
	 * authenticated submissions auto-approve only under post-moderation.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $body The reaction text.
	 * @param string|null $ncUid The authenticated Nextcloud UID, or null for anonymous.
	 * @param string|null $clientSeed A client fingerprint (e.g. remote addr) for the anon pseudonym; never stored raw.
	 *
	 * @return array<string, mixed> The created ConsultationReaction object.
	 *
	 * @throws RuntimeException When the consultation is not found, or closed or past its
	 *                          deadline (then the lifecycle service's window exception, a
	 *                          RuntimeException the responder answers with 400).
	 * @throws InvalidArgumentException When the body is empty/oversized or anonymous intake is not enabled.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 * @spec openspec/specs/p3-citizen-participation/spec.md
	 */
	public function submitReaction(string $consultationId, string $body, ?string $ncUid, ?string $clientSeed = null): array;
}//end interface
