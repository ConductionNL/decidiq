<?php

/**
 * Contract of GovernanceScopeGuard
 *
 * The public surface of GovernanceScopeGuard that other components call, so they depend
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
 * Contract of GovernanceScopeGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GovernanceScopeGuardInterface {

	/**
	 * Authorize a QES signing initiation on a Minutes record: the actor must be
	 * in the owning body's signatory scope. Delegates to
	 * {@see self::isSignatoryForMinutes()}, which is the single implementation
	 * shared with the `verify` and `finalize` endpoints of the same flow.
	 *
	 * @param string $userId Nextcloud UID of the requester
	 * @param string $minutesId UUID of the Minutes record
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
	 */
	public function canInitiateSigning(string $userId, string $minutesId): bool;

	/**
	 * The single "is this actor a signatory on these minutes" determination:
	 * resolve Minutes -> Meeting -> GovernanceBody (data lookup, selects WHICH
	 * scope to consult), then consult the OR-projected signatory scope
	 * (`decidesk:body:{bodyId}:signatory` — the chair/chairman/vice-chairman/
	 * secretary superset). Fails CLOSED on an empty argument, an unresolvable
	 * hop, an unpopulated scope, or any OpenRegister error.
	 *
	 * Every endpoint of the QES signing flow consults this one method, so
	 * starting the flow (`initiate`), checking a signature against the trusted
	 * list for a specific Minutes record (`verify`), and completing it
	 * (`finalize`) can never drift to different authority levels.
	 *
	 * @param string $userId Nextcloud UID of the actor
	 * @param string $minutesId UUID of the Minutes record
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-sig-101-only-a-body-signatory-may-finalize-signed-minutes
	 */
	public function isSignatoryForMinutes(string $userId, string $minutesId): bool;

	/**
	 * The "is this actor a signatory" determination for any record that can be
	 * sent for signature: minutes (Minutes -> Meeting -> body), a meeting's
	 * decision list (Meeting -> body) and a motion (Decision -> Meeting ->
	 * body). Same scope as the minutes flow, so the same people sign; fails
	 * CLOSED on an unknown schema, an unresolvable hop or any error.
	 *
	 * @param string $userId Nextcloud UID of the actor
	 * @param string $schema minutes, meeting or decision
	 * @param string $subjectId UUID of the record
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	public function isSignatoryForSubject(string $userId, string $schema, string $subjectId): bool;

	/**
	 * True when the user is in a body's scope group; fails closed on an empty user or body.
	 *
	 * @param string $userId Nextcloud UID of the actor
	 * @param string $bodyId GovernanceBody UUID
	 * @param string $scope  GovernanceScopeGuard::SCOPE_CHAIR or ::SCOPE_SIGNATORY
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-005-fail-closed-authorization-is-preserved-end-to-end
	 */
	public function isInBodyScope(string $userId, string $bodyId, string $scope): bool;
}//end interface
