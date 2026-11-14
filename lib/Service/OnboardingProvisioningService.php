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
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCP\AppFramework\Utility\ITimeFactory;
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
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store        The register's objects.
	 * @param IGroupManager       $groupManager The Nextcloud group manager.
	 * @param IUserManager        $userManager  The Nextcloud user manager.
	 * @param ITimeFactory        $time         The clock.
	 * @param LoggerInterface     $logger       The logger.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
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
		$record = $this->store->find(schema: self::ONBOARDING, uuid: $onboardingId);
		if ($record === null) {
			return PathwayResult::notFound(message: 'Onboarding not found.');
		}

		$steps = PathwaySteps::of(record: $record);
		$index = PathwaySteps::indexOf(steps: $steps, stepType: self::STEP_ASSIGN);
		if ($index === null) {
			return PathwayResult::refused(message: 'This onboarding has no step that assigns groups.');
		}

		if (PathwaySteps::isCompleted(steps: $steps, index: $index) === true) {
			return PathwayResult::refused(message: 'The groups have already been assigned.');
		}

		$user = $this->linkedUser(uid: (string) ($record['ncAccount'] ?? ''));
		if ($user === null) {
			return PathwayResult::refused(message: 'Link the member to a Nextcloud account first.');
		}

		$role   = (string) ($record['targetRole'] ?? '');
		$mapped = $this->mappedGroups(bodyId: PathwaySteps::relationId(value: ($record['targetBody'] ?? null)));
		$names  = ($mapped[$role] ?? []);
		if ($names === []) {
			return PathwayResult::refused(message: sprintf('No groups are mapped for the role "%s" on this body.', $role));
		}

		$resolved = $this->resolve(names: $names);
		if ($resolved['missing'] !== []) {
			return PathwayResult::refused(
				message: sprintf('These groups do not exist: %s. No group was assigned.', implode(', ', $resolved['missing']))
			);
		}

		$uid   = $user->getUID();
		$added = [];
		foreach ($resolved['groups'] as $group) {
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

		$unverified = [];
		foreach ($resolved['groups'] as $group) {
			if ($this->groupManager->isInGroup($uid, $group->getGID()) === false) {
				$unverified[] = $group->getGID();
			}
		}

		if ($unverified !== []) {
			$this->undo(user: $user, groups: $added);
			return PathwayResult::failed(
				message: sprintf('The member could not be added to: %s. No group was assigned.', implode(', ', $unverified))
			);
		}

		$granted = array_map(static fn (IGroup $group): string => $group->getGID(), $resolved['groups']);
		$this->store->patch(
			schema: self::ONBOARDING,
			data: [
				'steps' => PathwaySteps::complete(
					steps: $steps,
					index: $index,
					completedBy: $actor,
					completedAt: $this->now(),
					note: 'Groups: ' . implode(', ', $granted),
					reference: implode(',', $granted)
				),
			],
			uuid: $onboardingId
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
		$record = $this->store->find(schema: self::OFFBOARDING, uuid: $offboardingId);
		if ($record === null) {
			return PathwayResult::notFound(message: 'Offboarding not found.');
		}

		$steps = PathwaySteps::of(record: $record);
		$index = PathwaySteps::indexOf(steps: $steps, stepType: self::STEP_REVOKE);
		if ($index === null) {
			return PathwayResult::refused(message: 'This offboarding has no step that revokes groups.');
		}

		if (PathwaySteps::isCompleted(steps: $steps, index: $index) === true) {
			return PathwayResult::refused(message: 'The groups have already been revoked.');
		}

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

		$uid     = $user->getUID();
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

		$remaining = [];
		foreach ($names as $name) {
			if ($this->groupManager->isInGroup($uid, $name) === true) {
				$remaining[] = $name;
			}
		}

		if ($remaining !== []) {
			return PathwayResult::failed(
				message: sprintf('The member is still in: %s. The step stays open.', implode(', ', $remaining))
			);
		}

		try {
			$this->store->patch(schema: 'membership', data: ['endDate' => $endDate], uuid: $membershipId);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: ending a membership on offboarding failed', ['exception' => $e]);
			return PathwayResult::failed(
				message: 'The groups were revoked, but the membership could not be ended. The step stays open.'
			);
		}

		$note = 'Not in any group of this body any more.';
		if ($removed !== []) {
			$note = 'Removed from: ' . implode(', ', $removed) . '. ' . $note;
		}

		$this->store->patch(
			schema: self::OFFBOARDING,
			data: [
				'steps' => PathwaySteps::complete(
					steps: $steps,
					index: $index,
					completedBy: $actor,
					completedAt: $this->now(),
					note: $note
				),
			],
			uuid: $offboardingId
		);

		return PathwayResult::done(
			message: 'The groups have been revoked and the membership ended.',
			details: ['removed' => $removed, 'endDate' => $endDate]
		);
	}//end revoke()

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

			$names = [];
			foreach ($groups as $group) {
				if (is_string($group) === true && trim($group) !== '') {
					$names[] = trim($group);
				}
			}

			if ($names !== []) {
				$clean[$role] = array_values(array_unique($names));
			}
		}

		return $clean;
	}//end mappedGroups()

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
