<?php

/**
 * Grants a joining member the groups of their role, and takes a leaving
 * member's groups away, for a member onboarding or offboarding.
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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeInterface;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Both actions run only when the secretariat confirms the step, and both
 * fail closed: when a group cannot be resolved or a write does not hold, the
 * step stays open, the error names the group, and nothing is reported as
 * done that was not checked afterwards. Groups come from the body's
 * `memberGroups`; groups outside it are never touched. Chair and signatory
 * scopes follow from the roster through the role-to-scope projection, so
 * this class never writes a scope group.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
class OnboardingProvisioningService {

	/**
	 * The onboarding schema.
	 *
	 * @var string
	 */
	public const ONBOARDING = 'member-onboarding';

	/**
	 * The offboarding schema.
	 *
	 * @var string
	 */
	public const OFFBOARDING = 'member-offboarding';

	/**
	 * The onboarding step that grants the groups.
	 *
	 * @var string
	 */
	public const STEP_ASSIGN = 'assign-groups';

	/**
	 * The offboarding step that takes the groups away.
	 *
	 * @var string
	 */
	public const STEP_REVOKE = 'revoke-groups';

	/**
	 * The admin setting listing the groups a body may map: comma separated group ids.
	 *
	 * A governance body record is edited by staff, not by an administrator, so
	 * the groups it maps are only provisioned or revoked when an administrator
	 * listed them here. Nothing listed means nothing is provisioned.
	 */
	public const ALLOWED_GROUPS_KEY = 'member_provisioning_groups';

	/**
	 * Groups that are never provisioned or revoked, whatever the setting says.
	 */
	public const PROTECTED_GROUPS = ['admin'];

	/**
	 * Per pathway schema: its groups step and the refusals when it is missing or done.
	 */
	private const STEP_TEXTS = [
		self::ONBOARDING  => [
			'step'     => self::STEP_ASSIGN,
			'notFound' => 'Onboarding not found.',
			'noStep'   => 'This onboarding has no step that assigns groups.',
			'done'     => 'The groups have already been assigned.',
		],
		self::OFFBOARDING => [
			'step'     => self::STEP_REVOKE,
			'notFound' => 'Offboarding not found.',
			'noStep'   => 'This offboarding has no step that revokes groups.',
			'done'     => 'The groups have already been revoked.',
		],
	];

	/**
	 * The refusal naming the groups an administrator did not allow.
	 */
	private const NOT_ALLOWED = 'These groups are not allowed for member provisioning: %s.'
		. ' An administrator lists the allowed groups in the Decidiq admin settings.';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store        The register's objects.
	 * @param IGroupManager       $groupManager The Nextcloud group manager.
	 * @param IUserManager        $userManager  The Nextcloud user manager.
	 * @param ITimeFactory        $time         The clock.
	 * @param LoggerInterface     $logger       The logger.
	 * @param IAppConfig|null     $appConfig    Holds the groups an administrator allows; null allows none.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * The body an onboarding or offboarding belongs to, for the access check.
	 *
	 * @param string $schema The schema, self::ONBOARDING or self::OFFBOARDING.
	 * @param string $id     The record uuid.
	 *
	 * @return string|null Null when the record does not exist; empty when it names no body.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function bodyOf(string $schema, string $id): ?string {
		$record = $this->store->find(schema: $schema, uuid: $id);
		if ($record === null) {
			return null;
		}

		$key = 'body';
		if ($schema === self::ONBOARDING) {
			$key = 'targetBody';
		}

		return PathwaySteps::relationId(value: ($record[$key] ?? null));
	}//end bodyOf()

	/**
	 * Add the joining member to the groups their role has on the body and
	 * complete the assign-groups step with the verified group list.
	 *
	 * @param string $onboardingId The onboarding uuid.
	 * @param string $actor        The uid confirming the step.
	 *
	 * @return PathwayResult
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function provision(string $onboardingId, string $actor): PathwayResult {
		$open = $this->openStep(schema: self::ONBOARDING, uuid: $onboardingId);
		if ($open instanceof PathwayResult) {
			return $open;
		}

		$user = $this->linkedUser(uid: (string) ($open['record']['ncAccount'] ?? ''));
		if ($user === null) {
			return PathwayResult::refused(message: 'Link the member to a Nextcloud account first.');
		}

		$groups = $this->provisionGroups(record: $open['record']);
		if ($groups instanceof PathwayResult) {
			return $groups;
		}

		$added      = $this->addToGroups(user: $user, groups: $groups);
		$unverified = $this->unverified(uid: $user->getUID(), groups: $groups);
		if ($unverified !== []) {
			$this->undo(user: $user, groups: $added);
			return PathwayResult::failed(
				message: sprintf('The member could not be added to: %s. No group was assigned.', implode(', ', $unverified))
			);
		}

		$granted = array_map(static fn (IGroup $group): string => $group->getGID(), $groups);
		$this->completeStep(
			open: $open,
			done: ['by' => $actor, 'note' => 'Groups: ' . implode(', ', $granted), 'reference' => implode(',', $granted)]
		);

		return PathwayResult::done(message: 'The groups have been assigned.', details: ['groups' => $granted]);
	}//end provision()

	/**
	 * Remove the leaving member from every group the body maps, end the
	 * membership on the offboarding's end date, and complete the
	 * revoke-groups step with the verified state.
	 *
	 * @param string $offboardingId The offboarding uuid.
	 * @param string $actor         The uid confirming the step.
	 *
	 * @return PathwayResult
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	public function revoke(string $offboardingId, string $actor): PathwayResult {
		$open = $this->openStep(schema: self::OFFBOARDING, uuid: $offboardingId);
		if ($open instanceof PathwayResult) {
			return $open;
		}

		$plan = $this->revokePlan(record: $open['record']);
		if ($plan instanceof PathwayResult) {
			return $plan;
		}

		$removed   = $this->removeFromGroups(user: $plan['user'], names: $plan['names']);
		$remaining = $this->stillIn(uid: $plan['user']->getUID(), names: $plan['names']);
		if ($remaining !== []) {
			return PathwayResult::failed(
				message: sprintf('The member is still in: %s. The step stays open.', implode(', ', $remaining))
			);
		}

		if ($this->endMembership(membershipId: $plan['membershipId'], endDate: $plan['endDate']) === false) {
			return PathwayResult::failed(
				message: 'The groups were revoked, but the membership could not be ended. The step stays open.'
			);
		}

		$note = 'Not in any group of this body any more.';
		if ($removed !== []) {
			$note = 'Removed from: ' . implode(', ', $removed) . '. ' . $note;
		}

		$this->completeStep(open: $open, done: ['by' => $actor, 'note' => $note]);

		return PathwayResult::done(
			message: 'The groups have been revoked and the membership ended.',
			details: ['removed' => $removed, 'endDate' => $plan['endDate']]
		);
	}//end revoke()

	/**
	 * The pathway record and its open groups step, or why there is none.
	 *
	 * @param string $schema The pathway schema: onboarding or offboarding.
	 * @param string $uuid   The pathway uuid.
	 *
	 * @return array{schema: string, uuid: string, record: array<string,mixed>, steps: list<array<string,mixed>>, index: int}|PathwayResult
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function openStep(string $schema, string $uuid): array|PathwayResult {
		$texts  = self::STEP_TEXTS[$schema];
		$record = $this->store->find(schema: $schema, uuid: $uuid);
		if ($record === null) {
			return PathwayResult::notFound(message: $texts['notFound']);
		}

		$steps = PathwaySteps::of(record: $record);
		$index = PathwaySteps::indexOf(steps: $steps, stepType: $texts['step']);
		if ($index === null) {
			return PathwayResult::refused(message: $texts['noStep']);
		}

		if (PathwaySteps::isCompleted(steps: $steps, index: $index) === true) {
			return PathwayResult::refused(message: $texts['done']);
		}

		return ['schema' => $schema, 'uuid' => $uuid, 'record' => $record, 'steps' => $steps, 'index' => $index];
	}//end openStep()

	/**
	 * Complete the open groups step of a pathway.
	 *
	 * @param array{schema: string, uuid: string, steps: list<array<string,mixed>>, index: int} $open The open step.
	 * @param array{by: string, note: string, reference?: string}                             $done Who completed it, and the note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function completeStep(array $open, array $done): void {
		$this->store->patch(
			schema: $open['schema'],
			data: [
				'steps' => PathwaySteps::complete(
					steps: $open['steps'],
					index: $open['index'],
					completedBy: $done['by'],
					completedAt: $this->now(),
					note: $done['note'],
					reference: ($done['reference'] ?? null)
				),
			],
			uuid: $open['uuid']
		);
	}//end completeStep()

	/**
	 * The groups an onboarding's role maps to, all allowed and existing, or why not.
	 *
	 * @param array<string,mixed> $record The onboarding.
	 *
	 * @return list<IGroup>|PathwayResult
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function provisionGroups(array $record): array|PathwayResult {
		$role   = (string) ($record['targetRole'] ?? '');
		$mapped = $this->mappedGroups(bodyId: PathwaySteps::relationId(value: ($record['targetBody'] ?? null)));
		$names  = ($mapped[$role] ?? []);
		if ($names === []) {
			return PathwayResult::refused(message: sprintf('No groups are mapped for the role "%s" on this body.', $role));
		}

		$refused = $this->refuseNotAllowed(names: $names, what: 'assigned');
		if ($refused !== null) {
			return $refused;
		}

		$resolved = $this->resolve(names: $names);
		if ($resolved['missing'] !== []) {
			return PathwayResult::refused(
				message: sprintf('These groups do not exist: %s. No group was assigned.', implode(', ', $resolved['missing']))
			);
		}

		return $resolved['groups'];
	}//end provisionGroups()

	/**
	 * What an offboarding revokes: its end date, membership, account and the body's groups, or why it cannot.
	 *
	 * @param array<string,mixed> $record The offboarding.
	 *
	 * @return array{endDate: string, membershipId: string, user: IUser, names: list<string>}|PathwayResult
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function revokePlan(array $record): array|PathwayResult {
		$endDate = trim((string) ($record['endDate'] ?? ''));
		if ($endDate === '') {
			return PathwayResult::refused(message: 'Set the end date of the offboarding first.');
		}

		$membershipId = PathwaySteps::relationId(value: ($record['membership'] ?? null));
		if ($membershipId === '' || $this->store->find(schema: 'membership', uuid: $membershipId) === null) {
			return PathwayResult::refused(message: 'This offboarding names no membership to end.');
		}

		$user = $this->personUser(personId: PathwaySteps::relationId(value: ($record['person'] ?? null)));
		if ($user === null) {
			return PathwayResult::refused(message: 'The member has no Nextcloud account to revoke.');
		}

		$names = $this->allMappedGroups(bodyId: PathwaySteps::relationId(value: ($record['body'] ?? null)));
		if ($names === []) {
			return PathwayResult::refused(message: 'No groups are mapped on this body, so access cannot be revoked and checked.');
		}

		return ($this->refuseNotAllowed(names: $names, what: 'revoked') ?? [
			'endDate'      => $endDate,
			'membershipId' => $membershipId,
			'user'         => $user,
			'names'        => $names,
		]);
	}//end revokePlan()

	/**
	 * The refusal for mapped groups an administrator did not allow, or null when all are allowed.
	 *
	 * @param list<string> $names The group ids.
	 * @param string       $what  What would have happened to them: assigned or revoked.
	 *
	 * @return PathwayResult|null
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function refuseNotAllowed(array $names, string $what): ?PathwayResult {
		$notAllowed = $this->notAllowed(names: $names);
		if ($notAllowed === []) {
			return null;
		}

		return PathwayResult::refused(
			message: sprintf(self::NOT_ALLOWED . ' No group was ' . $what . '.', implode(', ', $notAllowed))
		);
	}//end refuseNotAllowed()

	/**
	 * Add the member to each group they are not in yet.
	 *
	 * @param IUser        $user   The member.
	 * @param list<IGroup> $groups The groups.
	 *
	 * @return list<IGroup> The groups this run added.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function addToGroups(IUser $user, array $groups): array {
		$added = [];
		foreach ($groups as $group) {
			if ($group->inGroup($user) === true) {
				continue;
			}

			try {
				$group->addUser($user);
				$added[] = $group;
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq: adding a member to a group failed', ['exception' => $e, 'group' => $group->getGID()]);
			}
		}

		return $added;
	}//end addToGroups()

	/**
	 * The groups the member is not in after all, read back from the group manager.
	 *
	 * @param string       $uid    The member's uid.
	 * @param list<IGroup> $groups The groups.
	 *
	 * @return list<string> The group ids.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function unverified(string $uid, array $groups): array {
		$unverified = [];
		foreach ($groups as $group) {
			if ($this->groupManager->isInGroup($uid, $group->getGID()) === false) {
				$unverified[] = $group->getGID();
			}
		}

		return $unverified;
	}//end unverified()

	/**
	 * Remove the member from each named group they are in.
	 *
	 * @param IUser        $user  The member.
	 * @param list<string> $names The group ids.
	 *
	 * @return list<string> The group ids this run removed them from.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function removeFromGroups(IUser $user, array $names): array {
		$removed = [];
		foreach ($names as $name) {
			$group = $this->groupManager->get($name);
			if ($group === null || $group->inGroup($user) === false) {
				continue;
			}

			try {
				$group->removeUser($user);
				$removed[] = $name;
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq: removing a member from a group failed', ['exception' => $e, 'group' => $name]);
			}
		}

		return $removed;
	}//end removeFromGroups()

	/**
	 * The named groups the member is still in, read back from the group manager.
	 *
	 * @param string       $uid   The member's uid.
	 * @param list<string> $names The group ids.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function stillIn(string $uid, array $names): array {
		return array_values(
			array_filter($names, fn (string $name): bool => $this->groupManager->isInGroup($uid, $name) === true)
		);
	}//end stillIn()

	/**
	 * End the membership on the given date.
	 *
	 * @param string $membershipId The membership uuid.
	 * @param string $endDate      The end date.
	 *
	 * @return bool Whether the write held.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function endMembership(string $membershipId, string $endDate): bool {
		try {
			$this->store->patch(schema: 'membership', data: ['endDate' => $endDate], uuid: $membershipId);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: ending a membership on offboarding failed', ['exception' => $e]);
			return false;
		}
	}//end endMembership()

	/**
	 * The body's role to groups mapping, cleaned.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return array<string, list<string>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function mappedGroups(string $bodyId): array {
		if ($bodyId === '') {
			return [];
		}

		$body    = $this->store->find(schema: 'governance-body', uuid: $bodyId);
		$mapping = ($body['memberGroups'] ?? []);
		if (is_array($mapping) === false) {
			return [];
		}

		$clean = [];
		foreach ($mapping as $role => $groups) {
			if (is_string($role) === false || is_array($groups) === false) {
				continue;
			}

			$names = $this->groupNames(groups: $groups);
			if ($names !== []) {
				$clean[$role] = $names;
			}
		}

		return $clean;
	}//end mappedGroups()

	/**
	 * The non-empty group names of one role's mapping, trimmed and unique.
	 *
	 * @param array<mixed> $groups The mapped values.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function groupNames(array $groups): array {
		$names = [];
		foreach ($groups as $group) {
			if (is_string($group) === true && trim($group) !== '') {
				$names[] = trim($group);
			}
		}

		return array_values(array_unique($names));
	}//end groupNames()

	/**
	 * Every group the body maps, for any role.
	 *
	 * @param string $bodyId The governance body uuid.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function allMappedGroups(string $bodyId): array {
		$all = [];
		foreach ($this->mappedGroups(bodyId: $bodyId) as $groups) {
			$all = array_merge($all, $groups);
		}

		return array_values(array_unique($all));
	}//end allMappedGroups()

	/**
	 * The groups an administrator allows a body to map, without the protected ones.
	 *
	 * @return list<string> The group ids; empty without a setting or app config.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function allowedGroups(): array {
		if ($this->appConfig === null) {
			return [];
		}

		$raw     = $this->appConfig->getValueString(AppIdentity::APP_ID, self::ALLOWED_GROUPS_KEY, '');
		$allowed = [];
		foreach (explode(',', $raw) as $name) {
			$name = trim($name);
			if ($name === '' || in_array(strtolower($name), self::PROTECTED_GROUPS, true) === true || in_array($name, $allowed, true) === true) {
				continue;
			}

			$allowed[] = $name;
		}

		return $allowed;
	}//end allowedGroups()

	/**
	 * The mapped groups an administrator did not allow, or that are protected.
	 *
	 * @param list<string> $names The group ids the body maps.
	 *
	 * @return list<string> The refused group ids, in the order given.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function notAllowed(array $names): array {
		$allowed = $this->allowedGroups();
		$refused = [];
		foreach ($names as $name) {
			if (in_array($name, $allowed, true) === false && in_array($name, $refused, true) === false) {
				$refused[] = $name;
			}
		}

		return $refused;
	}//end notAllowed()

	/**
	 * Look every group up; the missing ones are named.
	 *
	 * @param list<string> $names The group ids.
	 *
	 * @return array{groups: list<IGroup>, missing: list<string>}
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function resolve(array $names): array {
		$groups  = [];
		$missing = [];
		foreach ($names as $name) {
			$group = $this->groupManager->get($name);
			if ($group === null) {
				$missing[] = $name;
				continue;
			}

			$groups[] = $group;
		}

		return ['groups' => $groups, 'missing' => $missing];
	}//end resolve()

	/**
	 * Take back the groups this run added, after a write that did not hold.
	 *
	 * @param IUser        $user   The member.
	 * @param list<IGroup> $groups The groups added in this run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function undo(IUser $user, array $groups): void {
		foreach ($groups as $group) {
			try {
				$group->removeUser($user);
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq: undoing a partial group assignment failed', ['exception' => $e, 'group' => $group->getGID()]);
			}
		}
	}//end undo()

	/**
	 * The Nextcloud account an onboarding links, when it exists.
	 *
	 * @param string $uid The linked uid.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	private function linkedUser(string $uid): ?IUser {
		$uid = trim($uid);
		if ($uid === '') {
			return null;
		}

		return $this->userManager->get($uid);
	}//end linkedUser()

	/**
	 * The Nextcloud account of a person, when it exists.
	 *
	 * @param string $personId The person uuid.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-008-fail-closed-de-provisioning-on-offboarding
	 */
	private function personUser(string $personId): ?IUser {
		if ($personId === '') {
			return null;
		}

		$person = $this->store->find(schema: 'person', uuid: $personId);
		return $this->linkedUser(uid: (string) ($person['nextcloudUserId'] ?? ''));
	}//end personUser()

	/**
	 * Now, as an ISO-8601 date-time.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-004-structured-checklist-steps-with-per-step-status
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DateTimeInterface::ATOM);
	}//end now()

}//end class
