<?php

/**
 * Decidiq Proxy Vote Service
 *
 * Service governing the proxy register of a meeting: listing proxies and
 * suspending or revoking them. Proxies are granted per voting round through
 * `ProxyDelegationService`; this service does not create them.
 *
 * Proxy rows are persisted on the `proxy-authorization` schema (meeting /
 * grantor / holder / proxyStatus / signatureStatus), the schema `board-proxy`
 * folds its approval-workflow concept into as of model-debt-cleanup-schema
 * (REQ-SDM-024) — `board-proxy` is retired (kept, not deleted, as historical
 * record; `MigrateBoardProxyToProxyAuthorization` migrates its existing rows).
 * `grantor`/`holder` reference `Person`, not the deprecated `Participant`
 * shim, so the transition guard resolves the caller's Participant UUID
 * through `ParticipantToPersonMembershipResolver` first (Decision 3).
 * Every transition is mirrored to the audit log (ADR-006).
 *
 * RBAC note: `transition()` passes `_rbac: false` to `saveObject()`. The
 * `proxy-authorization` schema's authorization block declares only `read`
 * (fragment 63), and a schema block that names some actions closes every
 * action it does not name (ADR-022) — so OpenRegister's own RBAC would
 * otherwise refuse `update` for every non-owner caller (a holder or
 * chair/clerk transitioning a proxy they did not register). This service
 * enforces REQ-BPV-002 (`isAuthorizedForTransition()`) itself before the
 * write, so passing `_rbac: false` there is this consumer declaring, per the
 * `ObjectServiceInterface` RBAC contract, that authorization already ran.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Proxy vote lifecycle service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
 */
class ProxyVoteService {

	/**
	 * Allowed proxy status values.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['pending-approval', 'active', 'suspended', 'revoked'];

	/**
	 * Slug of the OpenRegister schema storing proxy rows (was `board-proxy`
	 * — REQ-SDM-024, model-debt-cleanup-code Decision 3).
	 *
	 * @var string
	 */
	public const SCHEMA = 'proxy-authorization';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger
	 * @param AuditLogService $auditLogService Audit log dependency
	 * @param ParticipantResolver $participantResolver Resolves chair/clerk role membership for the authorization guard
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param ParticipantToPersonMembershipResolver $participantCrosswalk Resolves a Participant UUID to its Person/Membership pair (Decision 3)
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLogService,
		private readonly ParticipantResolver $participantResolver,
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantToPersonMembershipResolver $participantCrosswalk,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister participant UUID linked to a Nextcloud user ID.
	 *
	 * Returns null when no participant record is linked to this user (mirrors
	 * `MotionCoauthorService::resolveParticipantUuid()`).
	 *
	 * @param string $nextcloudUid Nextcloud UID
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-1
	 */
	private function resolveParticipantUuid(string $nextcloudUid): ?string {
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema('participant');
		$entities = $this->objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

		foreach ($entities as $participantEntity) {
			$participant = $participantEntity->jsonSerialize();
			return ($participant['uuid'] ?? $participant['id'] ?? null);
		}

		return null;
	}//end resolveParticipantUuid()

	/**
	 * Whether a Nextcloud UID holds a chair or clerk (secretary) role on the
	 * given meeting's GovernanceBody. Reuses `ParticipantResolver::hasRole()`
	 * rather than duplicating role-resolution logic (same convention used by
	 * `LiveMeetingController::requireChairOrAdmin()`).
	 *
	 * @param string $meetingId Meeting UUID
	 * @param string $uid Nextcloud UID to check
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-1
	 */
	private function isChairOrClerk(string $meetingId, string $uid): bool {
		return $this->participantResolver->hasRole(
			meetingId: $meetingId,
			nextcloudUid: $uid,
			roles: ['chair', 'secretary']
		);

	}//end isChairOrClerk()

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
	 * @spec openspec/changes/member-proxy-authorization/specs/member-proxy-authorization/spec.md#requirement-req-mpa-008-per-meeting-proxy-register-attachable-to-the-minutes
	 */
	public function isAuthorizedToList(string $meetingId, string $callerUid): bool {
		if ($meetingId === '' || $callerUid === '') {
			return false;
		}

		return $this->participantResolver->isParticipant(
			meetingId: $meetingId,
			nextcloudUid: $callerUid
		);
	}//end isAuthorizedToList()

	/**
	 * Authorize a proxy transition (suspend/revoke): the caller must be the
	 * proxy's grantor, the proxy's holder, a chair/clerk of the meeting's
	 * GovernanceBody, or an admin (`$callerUid = null`).
	 *
	 * `grantor`/`holder` on the stored row are `Person` UUIDs
	 * (model-debt-cleanup-code Decision 3); the caller's own identity is
	 * still resolved from their Nextcloud UID via the `Participant` shim
	 * (`resolveParticipantUuid()`, unchanged), so it is run back through the
	 * same crosswalk before the comparison.
	 *
	 * @param array<string, mixed> $proxy Serialised proxy row (grantor/holder/meeting)
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-3
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 */
	private function isAuthorizedForTransition(array $proxy, string $callerUid): bool {
		$callerParticipant = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
		$callerPersonId = null;
		if ($callerParticipant !== null) {
			$callerPersonId = ($this->participantCrosswalk->resolve(participantId: $callerParticipant)['person'] ?? null);
		}

		$grantorId = ($proxy['grantor'] ?? null);
		$holderId = ($proxy['holder'] ?? null);
		if ($callerPersonId !== null
			&& ($callerPersonId === $grantorId || $callerPersonId === $holderId)
		) {
			return true;
		}

		$meetingId = (string)($proxy['meeting'] ?? '');
		return $this->isChairOrClerk(meetingId: $meetingId, uid: $callerUid);
	}//end isAuthorizedForTransition()

	/**
	 * Return the proxies attached to a meeting. Optionally filtered by status.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string|null $status Filter by proxyStatus
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 *
	 * @return array{success: bool, proxies: array, count: int}
	 */
	public function forMeeting(string $meetingId, ?string $status = null): array {
		try {
			// ObjectService::prepareFindAllConfig() only reads the register/schema
			// context from filters.register / filters.schema — a TOP-LEVEL
			// 'register'/'schema' key is silently ignored, findAll() then runs with
			// no register/schema context and returns an empty array. It does not
			// throw, so the caller cannot tell "no proxies" from "never looked".
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => self::SCHEMA,
						'meeting' => $meetingId,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: ProxyVoteService::forMeeting failed',
				['exception' => $e->getMessage(), 'meeting' => $meetingId]
			);
			return [
				'success' => false,
				'proxies' => [],
				'count' => 0,
			];
		}//end try

		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === false || ($row['meeting'] ?? null) !== $meetingId) {
				continue;
			}

			if ($status !== null && ($row['proxyStatus'] ?? '') !== $status) {
				continue;
			}

			$out[] = $row;
		}

		return [
			'success' => true,
			'proxies' => $out,
			'count' => count($out),
		];

	}//end forMeeting()

	/**
	 * Transition a proxy to a new status. Mirrors the change to the audit
	 * log (proxy-created on `active`, proxy-revoked on `revoked`).
	 *
	 * @param string $proxyId UUID of the proxy row
	 * @param string $newStatus New status
	 * @param string $actor UUID of the user driving the transition (audit-log label)
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the authorization
	 *                               check (admin path). NOT equivalent to `$actor` — deriving
	 *                               `$actor` for the audit log does not by itself authorize the
	 *                               mutation.
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-3
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 *
	 * @return array{success: bool, proxy: array|null, message: string}
	 */
	public function transition(string $proxyId, string $newStatus, string $actor, ?string $callerUid = null): array {
		if (in_array($newStatus, self::STATUSES, true) === false) {
			return [
				'success' => false,
				'proxy' => null,
				'message' => 'Unknown proxy status: ' . $newStatus,
			];
		}

		try {
			$entity = $this->objectService->find(
				id: $proxyId,
				register: 'decidiq',
				schema: self::SCHEMA
			);
			if ($entity === null) {
				return [
					'success' => false,
					'proxy' => null,
					'message' => 'Proxy not found.',
				];
			}

			// `getObject(): array` is on ObjectEntityInterface — the
			// method_exists() probe could never be false.
			$current = $entity->getObject();

			if ($callerUid !== null && $this->isAuthorizedForTransition(proxy: $current, callerUid: $callerUid) === false) {
				return [
					'success' => false,
					'proxy' => null,
					'message' => 'Forbidden: only the proxy\'s grantor, its holder, a chair/clerk of the meeting, or an admin may change its status.',
				];
			}

			$previousStatus = (string)($current['proxyStatus'] ?? '');
			$merged = array_merge(
				$current,
				[
					'proxyStatus' => $newStatus,
					'lastTransitionAt' => gmdate('Y-m-d\TH:i:s\Z'),
					'lastTransitionBy' => $actor,
				]
			);

			// _rbac: false — isAuthorizedForTransition() above already enforced
			// REQ-BPV-002; see the class docblock's RBAC note.
			$saved = $this->objectService->saveObject(
				object: $merged,
				register: 'decidiq',
				schema: self::SCHEMA,
				uuid: $proxyId,
				_rbac: false
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: ProxyVoteService::transition failed',
				['exception' => $e->getMessage(), 'proxyId' => $proxyId]
			);
			return [
				'success' => false,
				'proxy' => null,
				'message' => 'Failed to transition proxy.',
			];
		}//end try

		// OpenRegister's saveObject() returns ObjectEntityInterface — is_object() could never
		// be false, so `$merged` was never the value used.
		$payload = (array)$saved->jsonSerialize();

		if ($newStatus === 'revoked') {
			$this->auditLogService->append(
				actor: $actor,
				action: 'proxy-revoked',
				objectUids: [$proxyId, (string)($current['meeting'] ?? '')],
				payload: ['previousStatus' => $previousStatus]
			);
		}

		return [
			'success' => true,
			'proxy' => $payload,
			'message' => 'Proxy now ' . $newStatus . '.',
		];

	}//end transition()

	/**
	 * Convenience: suspend a proxy (e.g. grantor joins remotely).
	 *
	 * @param string $proxyId UUID of the proxy
	 * @param string $actor UUID of the user driving the transition (audit-log label)
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the authorization check
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-3
	 *
	 * @return array{success: bool, proxy: array|null, message: string}
	 */
	public function suspend(string $proxyId, string $actor, ?string $callerUid = null): array {
		return $this->transition(proxyId: $proxyId, newStatus: 'suspended', actor: $actor, callerUid: $callerUid);
	}//end suspend()

	/**
	 * Convenience: revoke a proxy.
	 *
	 * @param string $proxyId UUID of the proxy
	 * @param string $actor UUID of the user driving the transition (audit-log label)
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the authorization check
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-3
	 *
	 * @return array{success: bool, proxy: array|null, message: string}
	 */
	public function revoke(string $proxyId, string $actor, ?string $callerUid = null): array {
		return $this->transition(proxyId: $proxyId, newStatus: 'revoked', actor: $actor, callerUid: $callerUid);
	}//end revoke()
}//end class
