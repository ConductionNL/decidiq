<?php

/**
 * Decidesk Proxy Vote Service
 *
 * Service governing proxy votes on meetings. Proxies are registered by the
 * grantor (a member) and approved by the secretary; they are automatically
 * suspended when the grantor joins the meeting remotely, and revoked either
 * at meeting close or by secretary action.
 *
 * Proxy rows are persisted on the `board-proxy` schema (meetingKoppeling /
 * grantorKoppeling / holderKoppeling / proxyStatus), which is what this service
 * has always written. It previously pointed at the `vote` schema, whose required
 * `value` + `castAt` are not part of a delegation, so every registration threw
 * and returned 422; `board-proxy` is the schema the row actually describes.
 * Every transition is mirrored to the audit log (ADR-006).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Proxy vote lifecycle service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class ProxyVoteService {

	/**
	 * Allowed proxy status values.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['pending-approval', 'active', 'suspended', 'revoked'];

	/**
	 * Slug of the OpenRegister schema storing proxy rows.
	 *
	 * @var string
	 */
	public const SCHEMA = 'board-proxy';

	/**
	 * App config key holding the per-holder per-meeting ACTIVE-proxy cap.
	 *
	 * @var string
	 */
	public const MAX_PROXIES_CONFIG_KEY = 'max_proxies_per_holder';

	/**
	 * NL governance default: a member may hold at most 2 proxies per meeting.
	 *
	 * @var int
	 */
	public const MAX_PROXIES_DEFAULT = 2;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container
	 * @param LoggerInterface $logger Logger
	 * @param AuditLogService $auditLogService Audit log dependency
	 * @param ParticipantResolver $participantResolver Resolves chair/clerk role membership for the authorization guard
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly AuditLogService $auditLogService,
		private readonly ParticipantResolver $participantResolver,
		private readonly ObjectServiceInterface $objectService,
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
		$this->objectService->setRegister('decidesk');
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
	 * Authorize a proxy registration: the caller must be the grantor (self-delegation),
	 * a chair/clerk of the meeting's GovernanceBody, or an admin (admin bypass is
	 * signalled by the caller passing `$callerUid = null`, mirroring
	 * `MotionCoauthorController`'s convention).
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $grantorId UUID of the granting participant
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
	 */
	private function isAuthorizedToRegister(string $meetingId, string $grantorId, string $callerUid): bool {
		$callerParticipant = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
		if ($callerParticipant !== null && $callerParticipant === $grantorId) {
			return true;
		}

		return $this->isChairOrClerk(meetingId: $meetingId, uid: $callerUid);
	}//end isAuthorizedToRegister()

	/**
	 * Authorize a proxy transition (suspend/revoke): the caller must be the
	 * proxy's grantor, the proxy's holder, a chair/clerk of the meeting's
	 * GovernanceBody, or an admin (`$callerUid = null`).
	 *
	 * @param array<string, mixed> $proxy Serialised proxy row (grantorKoppeling/holderKoppeling/meetingKoppeling)
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-3
	 */
	private function isAuthorizedForTransition(array $proxy, string $callerUid): bool {
		$callerParticipant = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
		$grantorId = ($proxy['grantorIntegration'] ?? null);
		$holderId = ($proxy['holderIntegration'] ?? null);
		if ($callerParticipant !== null
			&& ($callerParticipant === $grantorId || $callerParticipant === $holderId)
		) {
			return true;
		}

		$meetingId = (string)($proxy['meetingIntegration'] ?? '');
		return $this->isChairOrClerk(meetingId: $meetingId, uid: $callerUid);
	}//end isAuthorizedForTransition()

	/**
	 * Register a proxy. The grantor (a board member) delegates their vote on
	 * the named meeting to a holder until the meeting closes or the proxy is
	 * revoked. The persisted row starts in `pending-approval`; the secretary
	 * must call approve() before the proxy counts toward quorum.
	 *
	 * Enforces the per-holder per-meeting cap on ACTIVE proxies (app config
	 * `decidesk`/`max_proxies_per_holder`, NL governance default 2). Fail
	 * closed: when the existing proxies cannot be counted, registration is
	 * rejected rather than allowed through.
	 *
	 * @param string $meetingId UUID of the board meeting
	 * @param string $grantorId UUID of the granting board member
	 * @param string $holderId UUID of the receiving board member
	 * @param array<string, mixed> $extra Optional fields: scope, expiresAt
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the
	 *                               authorization check (admin path, mirroring
	 *                               `MotionCoauthorController`'s convention)
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
	 *
	 * @return array{success: bool, proxy: array|null, message: string}
	 */
	public function register(string $meetingId, string $grantorId, string $holderId, array $extra = [], ?string $callerUid = null): array {
		$rejection = $this->validateRegistration(
			meetingId: $meetingId,
			grantorId: $grantorId,
			holderId: $holderId,
			callerUid: $callerUid
		);
		if ($rejection !== null) {
			return $this->registerFailure(message: $rejection);
		}

		// Per-member proxy limit (voting-system spec): count the holder's ACTIVE
		// proxies in this meeting and reject when the configured cap is reached.
		// Fail closed: an unreadable proxy list rejects the registration.
		$maxProxies = $this->maxProxiesPerHolder();
		$existing = $this->forMeeting(meetingId: $meetingId, status: 'active');
		if ($existing['success'] !== true) {
			return $this->registerFailure(
				message: 'Failed to verify existing proxies for the holder — registration refused.'
			);
		}

		$heldByHolder = count(
			array_filter(
				$existing['proxies'],
				static fn (array $proxyRow): bool => (($proxyRow['holderIntegration'] ?? null) === $holderId)
			)
		);

		if ($heldByHolder >= $maxProxies) {
			return $this->registerFailure(
				message: sprintf(
					'Maximum number of proxies reached: this member already holds %d of %d allowed proxies for this meeting.',
					$heldByHolder,
					$maxProxies
				)
			);
		}

		$row = [
			'meetingIntegration' => $meetingId,
			'grantorIntegration' => $grantorId,
			'holderIntegration' => $holderId,
			'scope' => (string)($extra['scope'] ?? 'all-resolutions'),
			'expiresAt' => (string)($extra['expiresAt'] ?? ''),
			'proxyStatus' => 'pending-approval',
			'registeredAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

		try {
			$saved = $this->objectService->saveObject(
				object: $row,
				register: 'decidesk',
				schema: self::SCHEMA
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: ProxyVoteService::register failed',
				['exception' => $e->getMessage(), 'meeting' => $meetingId]
			);
			return $this->registerFailure(message: 'Failed to register proxy.');
		}

		$payload = $row;
		if (is_object($saved) === true) {
			$payload = (array)$saved->jsonSerialize();
		}

		$this->auditLogService->append(
			actor: $grantorId,
			action: 'proxy-created',
			objectUids: [(string)($payload['id'] ?? $payload['uuid'] ?? ''), $meetingId],
			payload: ['grantor' => $grantorId, 'holder' => $holderId]
		);

		return [
			'success' => true,
			'proxy' => $payload,
			'message' => 'Proxy registered.',
		];

	}//end register()

	/**
	 * Validate the register() arguments and the caller's authorization.
	 *
	 * @param string $meetingId UUID of the board meeting
	 * @param string $grantorId UUID of the granting board member
	 * @param string $holderId UUID of the receiving board member
	 * @param string|null $callerUid Nextcloud UID of the caller; null bypasses the check
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/tasks.md#task-2
	 *
	 * @return string|null Rejection message, or null when the request may proceed
	 */
	private function validateRegistration(string $meetingId, string $grantorId, string $holderId, ?string $callerUid): ?string {
		if (in_array('', [$meetingId, $grantorId, $holderId], true) === true) {
			return 'meetingId, grantorId and holderId are required.';
		}

		if ($grantorId === $holderId) {
			return 'Grantor and holder must differ.';
		}

		if ($callerUid !== null
			&& $this->isAuthorizedToRegister(meetingId: $meetingId, grantorId: $grantorId, callerUid: $callerUid) === false
		) {
			return 'Forbidden: only the grantor, a chair/clerk of the meeting, or an admin may register this proxy.';
		}

		return null;
	}//end validateRegistration()

	/**
	 * Build the unsuccessful register() envelope.
	 *
	 * @param string $message Human-readable rejection reason
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 *
	 * @return array{success: bool, proxy: array|null, message: string}
	 */
	private function registerFailure(string $message): array {
		return [
			'success' => false,
			'proxy' => null,
			'message' => $message,
		];

	}//end registerFailure()

	/**
	 * Resolve the configured per-holder per-meeting ACTIVE-proxy cap.
	 *
	 * Reads app config `decidesk`/`max_proxies_per_holder`; values below 1 and
	 * resolution failures fall back to the NL governance default of 2 (a
	 * misconfigured cap never disables the limit — fail closed).
	 *
	 * @return int The maximum number of ACTIVE proxies one holder may hold per meeting
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function maxProxiesPerHolder(): int {
		try {
			$appConfig = $this->container->get(\OCP\IAppConfig::class);
			$value = $appConfig->getValueInt('decidesk', self::MAX_PROXIES_CONFIG_KEY, self::MAX_PROXIES_DEFAULT);
			if ($value >= 1) {
				return $value;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidesk: max_proxies_per_holder config lookup failed — using default',
				['exception' => $e->getMessage()]
			);
		}

		return self::MAX_PROXIES_DEFAULT;
	}//end maxProxiesPerHolder()

	/**
	 * Return the proxies attached to a meeting. Optionally filtered by status.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string|null $status Filter by proxyStatus
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
	 *
	 * @return array{success: bool, proxies: array, count: int}
	 */
	public function forMeeting(string $meetingId, ?string $status = null): array {
		try {
			// ObjectService::prepareFindAllConfig() only reads the register/schema
			// context from filters.register / filters.schema — a TOP-LEVEL
			// 'register'/'schema' key is silently ignored, findAll() then runs with
			// no register/schema context and returns an empty array. It does not
			// throw, so the caller cannot tell "no proxies" from "never looked",
			// and the per-holder cap below counted 0 every time (decidesk#443
			// follow-up: a third proxy was accepted at a cap of 2).
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => 'decidesk',
						'schema' => self::SCHEMA,
						'meetingIntegration' => $meetingId,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: ProxyVoteService::forMeeting failed',
				['exception' => $e->getMessage(), 'meeting' => $meetingId]
			);
			return [
				'success' => false,
				'proxies' => [],
				'count' => 0,
			];
		}//end try

		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === false || ($row['meetingIntegration'] ?? null) !== $meetingId) {
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
				register: 'decidesk',
				schema: self::SCHEMA
			);
			if ($entity === null) {
				return [
					'success' => false,
					'proxy' => null,
					'message' => 'Proxy not found.',
				];
			}

			$current = (array)$entity->jsonSerialize();
			if (method_exists($entity, 'getObject') === true) {
				$current = $entity->getObject();
			}

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

			$saved = $this->objectService->saveObject(
				object: $merged,
				register: 'decidesk',
				schema: self::SCHEMA,
				uuid: $proxyId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: ProxyVoteService::transition failed',
				['exception' => $e->getMessage(), 'proxyId' => $proxyId]
			);
			return [
				'success' => false,
				'proxy' => null,
				'message' => 'Failed to transition proxy.',
			];
		}//end try

		$payload = $merged;
		if (is_object($saved) === true) {
			$payload = (array)$saved->jsonSerialize();
		}

		if ($newStatus === 'revoked') {
			$this->auditLogService->append(
				actor: $actor,
				action: 'proxy-revoked',
				objectUids: [$proxyId, (string)($current['meetingIntegration'] ?? '')],
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
