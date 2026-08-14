<?php

/**
 * Decidesk Governance Scope Guard
 *
 * Thin consumer of the OpenRegister-projected governance RBAC scopes
 * (per-body Nextcloud groups `decidesk:body:{bodyId}:chair` and
 * `decidesk:body:{bodyId}:signatory`, maintained by
 * GovernanceRoleScopeProjector). It answers "may this actor sign / advance
 * this body's objects?" by evaluating membership of the OR-owned scope group —
 * the identical determination OpenRegister's PropertyRbacHandler makes
 * internally (`in_array($group, $userGroups)`), sourced from the projected
 * scope rather than re-derived by walking OR objects and matching a role enum.
 *
 * Replaces the retired app-local MinutesAuthorizationService and the NC-UID
 * chair comparison in MeetingService (consume-or-rbac-authorization). Body
 * resolution (Minutes -> Meeting -> GovernanceBody) is pure data lookup used
 * only to pick WHICH scope to consult; the authorization decision itself is the
 * scope-group membership check. Fails CLOSED on any ambiguity (unresolved body,
 * missing scope, OR error) — never a silent skip (avoids gate-8
 * unsafe-auth-resolver).
 *
 * Deviation (documented): OpenRegister property-RBAC cannot template a group
 * name with a per-object id, so the final scope-membership evaluation is
 * performed at the app boundary consuming the OR-projected scope group instead
 * of by OR's ObjectService write path returning 403. The authorization *fact*
 * (role -> scope) is OR/NC-owned and projected; this guard is a consumer, not a
 * parallel role-resolution service.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Consumes the OR-projected per-body governance scopes to authorize signatory
 * and chair actions. Fail-closed.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
 */
class GovernanceScopeGuard {

	/**
	 * Scope suffix for the chair scope (chair/chairman only).
	 *
	 * @var string
	 */
	public const SCOPE_CHAIR = 'chair';

	/**
	 * Scope suffix for the signatory scope (chair/chairman/vice-chairman/
	 * secretary superset).
	 *
	 * @var string
	 */
	public const SCOPE_SIGNATORY = 'signatory';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService)
	 * @param IGroupManager $groupManager NC group manager (scope membership)
	 * @param LoggerInterface $logger Diagnostic logger
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Build the canonical per-body scope group id.
	 *
	 * @param string $bodyId GovernanceBody UUID
	 * @param string $scope self::SCOPE_CHAIR | self::SCOPE_SIGNATORY
	 *
	 * @return string
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function scopeGroupId(string $bodyId, string $scope): string {
		return 'decidesk:body:' . $bodyId . ':' . $scope;
	}//end scopeGroupId()

	/**
	 * True when the user is a member of the body's scope group.
	 *
	 * Fails closed: an empty user, empty body, or a non-existent/empty scope
	 * group denies. This is the exact determination OpenRegister's
	 * PropertyRbacHandler makes internally, sourced from the projected scope.
	 *
	 * @param string $userId Nextcloud UID of the actor
	 * @param string $bodyId GovernanceBody UUID
	 * @param string $scope self::SCOPE_CHAIR | self::SCOPE_SIGNATORY
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-005-fail-closed-authorization-is-preserved-end-to-end
	 */
	public function isInBodyScope(string $userId, string $bodyId, string $scope): bool {
		if ($userId === '' || $bodyId === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $this->scopeGroupId(bodyId: $bodyId, scope: $scope));
	}//end isInBodyScope()

	/**
	 * Authorize a QES signing initiation on a Minutes record: the actor must be
	 * in the owning body's signatory scope. Resolves Minutes -> Meeting ->
	 * GovernanceBody (data lookup) to select the scope, then consults the
	 * OR-projected signatory scope. Fails closed on any lookup failure.
	 *
	 * @param string $userId Nextcloud UID of the requester
	 * @param string $minutesId UUID of the Minutes record
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service
	 */
	public function canInitiateSigning(string $userId, string $minutesId): bool {
		if ($userId === '' || $minutesId === '') {
			return false;
		}

		try {
			$bodyId = $this->resolveBodyIdForMinutes(minutesId: $minutesId);
			if ($bodyId === null) {
				return false;
			}

			return $this->isInBodyScope(userId: $userId, bodyId: $bodyId, scope: self::SCOPE_SIGNATORY);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'GovernanceScopeGuard::canInitiateSigning failed; denying',
				['exception' => $e->getMessage(), 'minutesId' => $minutesId, 'userId' => $userId]
			);
			return false;
		}//end try
	}//end canInitiateSigning()

	/**
	 * Resolve the owning GovernanceBody UUID for a Minutes record by walking
	 * Minutes -> Meeting -> GovernanceBody. Pure data resolution (selects the
	 * scope to consult); returns null when any hop is missing.
	 *
	 * @param string $minutesId UUID of the Minutes record
	 *
	 * @return string|null
	 */
	private function resolveBodyIdForMinutes(string $minutesId): ?string {
		$minutesEntity = $this->objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
		if ($minutesEntity === null) {
			return null;
		}

		$meetingId = $this->extractRelation(record: $minutesEntity->jsonSerialize(), key: 'Meeting');
		if ($meetingId === null) {
			return null;
		}

		$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
		if ($meetingEntity === null) {
			return null;
		}

		return $this->extractRelation(record: $meetingEntity->jsonSerialize(), key: 'GovernanceBody');
	}//end resolveBodyIdForMinutes()

	/**
	 * Extract the first related entity UUID from a serialised object, checking
	 * both the `relations` map and a direct camelCase property.
	 *
	 * @param array<string, mixed> $record The serialised object
	 * @param string $key Relation key (e.g. 'Meeting')
	 *
	 * @return string|null
	 */
	private function extractRelation(array $record, string $key): ?string {
		$candidates = [];

		$relations = ($record['relations'] ?? []);
		if (is_array($relations) === true && isset($relations[$key]) === true) {
			$candidates[] = $relations[$key];
		}

		// Also accept a direct lcfirst property (e.g. 'governanceBody').
		$direct = lcfirst($key);
		if (isset($record[$direct]) === true) {
			$candidates[] = $record[$direct];
		}

		foreach ($candidates as $value) {
			if (is_array($value) === true) {
				$value = ($value['id'] ?? ($value[0] ?? null));
			}

			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end extractRelation()
}//end class
