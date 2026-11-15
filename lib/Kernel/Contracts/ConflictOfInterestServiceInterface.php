<?php

/**
 * Contract of ConflictOfInterestService
 *
 * The public surface of ConflictOfInterestService that other components call, so they depend
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

use OCA\Decidiq\Service\ConflictDeclaration;
use RuntimeException;

/**
 * Contract of ConflictOfInterestService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConflictOfInterestServiceInterface {

	/**
	 * Record a new declaration. Material declarations are mirrored to the
	 * audit log and the resulting object is returned alongside success status.
	 *
	 * @param ConflictDeclaration $declaration The Membership (was Participant — REQ-SDM-023), agenda item,
	 *                                         type, rationale, severity and initial action
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the
	 *                               authorization check (admin path, mirroring
	 *                               `ProxyVoteService`'s convention)
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/proposal.md#in-scope
	 * @spec openspec/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
	 * @return array{success: bool, declaration: array|null, message: string}
	 */
	public function declare(ConflictDeclaration $declaration, ?string $callerUid = null): array;

	/**
	 * Return the most-restrictive active conflict for a given member + agenda
	 * item pair, or null when none is on file. Restrictiveness ordering is
	 * "recused-from-vote" > "recused-from-discussion" > "disclosed-and-participated"
	 * > "no-action-needed".
	 *
	 * @param string $membershipId UUID of the member's Membership (was Participant)
	 * @param string $agendaItemId UUID of the agenda item
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/proposal.md#in-scope
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws RuntimeException When the declarations cannot be read (fail closed).
	 */
	public function getActiveConflicts(string $membershipId, string $agendaItemId): ?array;

	/**
	 * Authorize access to one member's conflict-of-interest declarations:
	 * the caller must be the declaring Membership themselves, or a chair/
	 * secretary of the relevant meeting's GovernanceBody. Used by both
	 * `declare()` (recording a new declaration about oneself) and the
	 * `forMember()` read endpoint — the two share the same rule. Thin
	 * delegate to `ConflictOfInterestAuthorizationGuard`, kept as a public
	 * method here so `ConflictOfInterestController::forMember()` can call it
	 * without taking a direct dependency on the guard.
	 *
	 * @param string $membershipId UUID of the Membership the declaration is about
	 * @param string $agendaItemId UUID of the agenda item the declaration concerns
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @spec openspec/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration
	 *
	 * @return bool
	 */
	public function isAuthorizedForMember(string $membershipId, string $agendaItemId, string $callerUid): bool;

	/**
	 * The Membership UUID of a Nextcloud user, or null when she has none.
	 *
	 * @param string $uid Nextcloud UID
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
	 */
	public function membershipForUser(string $uid): ?string;

	/**
	 * Update the action-taken on an existing declaration.
	 *
	 * @param string $declarationId UUID of the conflict-of-interest record
	 * @param string $actionTaken One of self::ACTIONS
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the
	 *                               authorization check (admin path, mirroring
	 *                               `ProxyVoteService`'s convention). NOT equivalent
	 *                               to trusting the caller for the action itself —
	 *                               recording the action taken is a presiding-officer
	 *                               act, so a non-null caller must be chair/secretary.
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-2.2
	 * @spec openspec/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken
	 *
	 * @return array{success: bool, declaration: array|null, message: string}
	 */
	public function recordAction(string $declarationId, string $actionTaken, ?string $callerUid = null): array;
}//end interface
