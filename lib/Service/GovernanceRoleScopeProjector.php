<?php

/**
 * Decidiq Governance Role Scope Projector
 *
 * Projects each GovernanceBody's signatory roster into OpenRegister RBAC scopes
 * — the OR/Nextcloud-native scope primitive is a group, so the projector
 * maintains two per-body Nextcloud groups from the body's Participant roster:
 *
 *   - `decidesk:body:{bodyId}:chair`      — members whose role is chair/chairman
 *   - `decidesk:body:{bodyId}:signatory`  — chair/chairman/vice-chairman/secretary
 *
 * This makes "who may sign / advance this body's objects" an OR-owned,
 * OR-queryable, OR-enforceable fact rather than a runtime graph walk. The
 * reconcile is idempotent and fails CLOSED: a missing scope membership denies,
 * never over-grants — so projection drift can only ever deny. Driven by a
 * Participant/Membership write listener and an idempotent repair-step backfill
 * (consume-or-rbac-authorization).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\GovernanceRoleScopeProjectorInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Reconciles per-body chair/signatory OR RBAC scopes from the Participant
 * roster. Idempotent, fails closed.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 */
class GovernanceRoleScopeProjector implements GovernanceRoleScopeProjectorInterface {

	/**
	 * Roles that grant chair scope membership.
	 *
	 * @var string[]
	 */
	private const CHAIR_ROLES = ['chair', 'chairman'];

	/**
	 * Roles that grant signatory scope membership (superset of chair).
	 *
	 * @var string[]
	 */
	private const SIGNATORY_ROLES = ['chair', 'chairman', 'vice-chairman', 'secretary'];

	/**
	 * Keys under which a member row may carry its GovernanceBody relation,
	 * checked in this order (relations map first, then the direct property).
	 *
	 * @var string[]
	 */
	private const BODY_RELATION_KEYS = ['governanceBody', 'GovernanceBody'];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager NC group manager (scope groups)
	 * @param IUserManager $userManager NC user manager (uid -> IUser)
	 * @param LoggerInterface $logger Diagnostic logger
	 * @param GovernanceScopeGuard $scopeGuard Owner of the canonical scope-group naming
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
		private readonly GovernanceScopeGuard $scopeGuard,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Reconcile both scope groups for a single body to its current roster.
	 *
	 * Idempotent: computes the desired chair/signatory member sets from the
	 * body's Participants and makes the group membership match exactly (adds
	 * missing, removes stale). A second run is a no-op. On any failure it logs
	 * and leaves the scopes unchanged (deny-biased) — never over-grants.
	 *
	 * @param string $bodyId GovernanceBody UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function reconcileBody(string $bodyId): void {
		if ($bodyId === '') {
			return;
		}

		try {
			[$chairUids, $signatoryUids] = $this->desiredMembers(bodyId: $bodyId);

			$this->syncScope(
				groupId: $this->scopeGuard->scopeGroupId(
					bodyId: $bodyId,
					scope: GovernanceScopeGuard::SCOPE_CHAIR
				),
				desiredUids: $chairUids
			);
			$this->syncScope(
				groupId: $this->scopeGuard->scopeGroupId(
					bodyId: $bodyId,
					scope: GovernanceScopeGuard::SCOPE_SIGNATORY
				),
				desiredUids: $signatoryUids
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'GovernanceRoleScopeProjector::reconcileBody failed; scopes left unchanged (deny-biased)',
				['exception' => $e->getMessage(), 'bodyId' => $bodyId]
			);
		}//end try
	}//end reconcileBody()

	/**
	 * Reconcile the body referenced by a Participant/Membership row.
	 *
	 * @param array<string, mixed> $row Serialised Participant/Membership object
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function reconcileFromMemberRow(array $row): void {
		$bodyId = $this->extractBodyId(row: $row);
		if ($bodyId !== null) {
			$this->reconcileBody(bodyId: $bodyId);
		}
	}//end reconcileFromMemberRow()

	/**
	 * Remove both scope groups of a body that no longer exists.
	 *
	 * A deleted body has no roster, so it has no scope; its groups would
	 * otherwise outlive it (an example set's remove left four behind).
	 *
	 * @param string $bodyId GovernanceBody UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-a-body-scope-group-exists-only-while-it-has-members-req-rbac-009
	 */
	public function removeBody(string $bodyId): void {
		if ($bodyId === '') {
			return;
		}

		foreach ([GovernanceScopeGuard::SCOPE_CHAIR, GovernanceScopeGuard::SCOPE_SIGNATORY] as $scope) {
			$this->removeScope(groupId: $this->scopeGuard->scopeGroupId(bodyId: $bodyId, scope: $scope));
		}
	}//end removeBody()

	/**
	 * Reconcile every GovernanceBody (backfill). Idempotent.
	 *
	 * @return int Number of bodies reconciled
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function reconcileAll(): int {
		$this->objectService->setRegister('decidiq');
		// 🔴 `governance-body`, HYPHENATED. This asked for `governancebody`, which
		// is not a slug this app declares and not one anything on the instance
		// carries, so findAll() threw and the ONLY caller —
		// ProjectGovernanceRoleScopes, a repair step — catches \Throwable and
		// downgrades it to a warning so a repair never fails an upgrade. The
		// backfill therefore reported "skipped" on every upgrade this app has
		// ever had, and REQ-RBAC-001 has never projected a single body.
		//
		// Measured 2026-08-31 on a live instance: 'Schema slug "governancebody"
		// is not carried by register "decidiq" (id 28), which carries 91
		// schema(s). 0 schema(s) elsewhere on this instance carry this slug.'
		// Every other setSchema() call in lib/ is hyphenated (voting-round,
		// engagement-record, citizen-vote, public-consultation); this was the one
		// that was not.
		$this->objectService->setSchema('governance-body');
		$bodies = $this->objectService->findAll(['filters' => ['_limit' => 9999]]);

		$count = 0;
		foreach ($bodies as $bodyEntity) {
			$body = $bodyEntity->jsonSerialize();
			$bodyId = (string)($body['id'] ?? ($body['@self']['id'] ?? ''));
			if ($bodyId !== '') {
				$this->reconcileBody(bodyId: $bodyId);
				$count++;
			}
		}

		return $count;
	}//end reconcileAll()

	/**
	 * Compute the desired chair + signatory Nextcloud UID sets for a body.
	 *
	 * @param string $bodyId GovernanceBody UUID
	 *
	 * @return array{0: string[], 1: string[]} [chairUids, signatoryUids]
	 */
	private function desiredMembers(string $bodyId): array {
		$memberRoles = $this->bodyMemberRoles(bodyId: $bodyId);

		return [
			$this->uidsHolding(memberRoles: $memberRoles, roles: self::CHAIR_ROLES),
			$this->uidsHolding(memberRoles: $memberRoles, roles: self::SIGNATORY_ROLES),
		];
	}//end desiredMembers()

	/**
	 * Every signatory-role participant of the body with an account, as uid and role, in roster order.
	 *
	 * @param string $bodyId GovernanceBody UUID
	 *
	 * @return list<array{uid: string, role: string}>
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function bodyMemberRoles(string $bodyId): array {
		$memberRoles = [];
		foreach ($this->signatoryParticipants() as $participantEntity) {
			$participant = $participantEntity->jsonSerialize();
			if ($this->extractBodyId(row: $participant) !== $bodyId) {
				continue;
			}

			$uid = (string)($participant['nextcloudUserId'] ?? '');
			if ($uid !== '') {
				$memberRoles[] = ['uid' => $uid, 'role' => (string)($participant['role'] ?? '')];
			}
		}

		return $memberRoles;
	}//end bodyMemberRoles()

	/**
	 * Read every participant holding a signatory role, across bodies.
	 *
	 * @return iterable<mixed> Participant entities
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function signatoryParticipants(): iterable {
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema('participant');
		return $this->objectService->findAll(
			[
				'filters' => [
					'role' => self::SIGNATORY_ROLES,
					'_limit' => 9999,
				],
			]
		);
	}//end signatoryParticipants()

	/**
	 * The distinct uids holding one of the roles, in first-seen order.
	 *
	 * @param list<array{uid: string, role: string}> $memberRoles The body's members and roles
	 * @param array<int, string>                     $roles       The roles that count
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function uidsHolding(array $memberRoles, array $roles): array {
		$uids = [];
		foreach ($memberRoles as $memberRole) {
			if (in_array($memberRole['role'], $roles, true) === true) {
				$uids[$memberRole['uid']] = $memberRole['uid'];
			}
		}

		return array_values($uids);
	}//end uidsHolding()

	/**
	 * Make a scope group's membership match the desired UID set exactly.
	 *
	 * A scope nobody holds has no group: an empty scope and a missing group
	 * both deny, and a group is only created when it gets a member.
	 *
	 * @param string $groupId Scope group id
	 * @param string[] $desiredUids Desired member UIDs
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-a-body-scope-group-exists-only-while-it-has-members-req-rbac-009
	 */
	private function syncScope(string $groupId, array $desiredUids): void {
		if ($desiredUids === []) {
			$this->removeScope(groupId: $groupId);
			return;
		}

		$group = $this->ensuredGroup(groupId: $groupId);
		if ($group === null) {
			return;
		}

		$currentUids = $this->pruneMembers(group: $group, desiredUids: $desiredUids);
		$this->addMembers(group: $group, uids: array_diff($desiredUids, array_keys($currentUids)));
	}//end syncScope()

	/**
	 * The scope group, created first when it does not exist yet.
	 *
	 * @param string $groupId NC group id
	 *
	 * @return IGroup|null The group, or null when it cannot be fetched
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function ensuredGroup(string $groupId): ?IGroup {
		if ($this->groupManager->groupExists($groupId) === false) {
			$this->groupManager->createGroup($groupId);
		}

		return $this->groupManager->get($groupId);
	}//end ensuredGroup()

	/**
	 * Remove the members who should no longer hold the scope; returns every uid it found.
	 *
	 * @param IGroup        $group       The scope group
	 * @param array<string> $desiredUids UIDs that should hold the scope
	 *
	 * @return array<string, true> The group's members before pruning, as a set
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function pruneMembers(IGroup $group, array $desiredUids): array {
		$desired = array_fill_keys($desiredUids, true);

		$currentUids = [];
		foreach ($group->getUsers() as $user) {
			$uid = $user->getUID();
			$currentUids[$uid] = true;
			if (isset($desired[$uid]) === false) {
				$group->removeUser($user);
			}
		}

		return $currentUids;
	}//end pruneMembers()

	/**
	 * Add the accounts that exist to the group, in order; unknown uids are skipped.
	 *
	 * @param IGroup        $group The scope group
	 * @param array<string> $uids  UIDs to add
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function addMembers(IGroup $group, array $uids): void {
		foreach ($uids as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null) {
				$group->addUser($user);
			}
		}
	}//end addMembers()

	/**
	 * Delete a scope group when it exists.
	 *
	 * @param string $groupId Scope group id
	 *
	 * @return void
	 */
	private function removeScope(string $groupId): void {
		$group = $this->groupManager->get($groupId);
		if ($group !== null) {
			$group->delete();
		}
	}//end removeScope()

	/**
	 * Extract the GovernanceBody UUID from a serialised member row (relations
	 * map or a direct governanceBody property).
	 *
	 * @param array<string, mixed> $row Serialised object
	 *
	 * @return string|null
	 */
	private function extractBodyId(array $row): ?string {
		$sources = [
			$this->relationsOf(row: $row),
			$row,
		];

		foreach ($sources as $source) {
			$bodyId = $this->bodyIdIn(source: $source);
			if ($bodyId !== null) {
				return $bodyId;
			}
		}

		return null;
	}//end extractBodyId()

	/**
	 * A row's relations map, or [] when it carries none.
	 *
	 * @param array<string, mixed> $row Participant or member object
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function relationsOf(array $row): array {
		$relations = ($row['relations'] ?? []);
		if (is_array($relations) === false) {
			$relations = [];
		}

		return $relations;
	}//end relationsOf()

	/**
	 * The first governance-body reference among one source's relation keys.
	 *
	 * @param array<string, mixed> $source The relations map or the row itself
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	private function bodyIdIn(array $source): ?string {
		foreach (self::BODY_RELATION_KEYS as $key) {
			$bodyId = $this->relationToId(value: ($source[$key] ?? null));
			if ($bodyId !== null) {
				return $bodyId;
			}
		}

		return null;
	}//end bodyIdIn()

	/**
	 * Reduce a relation value (uuid string, `{id: ...}` map, or list) to a UUID.
	 *
	 * @param mixed $value Raw relation value
	 *
	 * @return string|null The UUID, or null when the value carries none
	 */
	private function relationToId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value[0] ?? null));
		}

		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end relationToId()
}//end class
