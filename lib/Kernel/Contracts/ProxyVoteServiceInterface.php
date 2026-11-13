<?php

/**
 * Contract of ProxyVoteService
 *
 * The public surface of ProxyVoteService that other components call, so they depend
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
 * Contract of ProxyVoteService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProxyVoteServiceInterface {

	/**
	 * Return the proxies attached to a meeting. Optionally filtered by status.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string|null $status Filter by proxyStatus
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 *
	 * @return array{success: bool, proxies: array, count: int}
	 */
	public function forMeeting(string $meetingId, ?string $status = null): array;

	/**
	 * Authorize reading a meeting's proxy register: the caller must be a
	 * participant of that meeting's GovernanceBody (any role), or an admin
	 * (admin bypass is signalled at the call site by `$callerUid = null`,
	 * the same convention the write guards use).
	 *
	 * REQ-MPA-008 defines the audience in exactly those terms — "a participant
	 * with access opens the meeting's proxy register view" — and the register
	 * is personal data about board members (who handed their vote to whom, and
	 * whether the machtiging is signed), so it is not an instance-wide read.
	 * `ParticipantResolver::isParticipant()` is the app's single primitive for
	 * "has access to this meeting" (`MinutesAccessGuard::requireParticipant()`
	 * uses the same one), so the rule is not restated here.
	 *
	 * Fails CLOSED: a meeting whose GovernanceBody cannot be resolved yields
	 * an empty participant set and therefore a refusal for every non-admin.
	 *
	 * @param string $meetingId UUID of the meeting whose proxies are listed
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-12-member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-008-per-meeting-proxy-register-attachable-to-the-minutes
	 */
	public function isAuthorizedToList(string $meetingId, string $callerUid): bool;

	/**
	 * Convenience: revoke a proxy.
	 *
	 * @param string $proxyId UUID of the proxy
	 * @param string $actor UUID of the user driving the transition (audit-log label)
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the authorization check
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/archive/2026-11-12-board-proxy-vote-authorization-guard/tasks.md#task-3
	 *
	 * @return array{success: bool, proxy: array|null, message: string}
	 */
	public function revoke(string $proxyId, string $actor, ?string $callerUid = null): array;

	/**
	 * Convenience: suspend a proxy (e.g. grantor joins remotely).
	 *
	 * @param string $proxyId UUID of the proxy
	 * @param string $actor UUID of the user driving the transition (audit-log label)
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the authorization check
	 *
	 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/archive/2026-11-12-board-proxy-vote-authorization-guard/tasks.md#task-3
	 *
	 * @return array{success: bool, proxy: array|null, message: string}
	 */
	public function suspend(string $proxyId, string $actor, ?string $callerUid = null): array;
}//end interface
