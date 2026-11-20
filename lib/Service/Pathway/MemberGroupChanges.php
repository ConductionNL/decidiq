<?php

/**
 * Adds a member to and removes a member from Nextcloud groups, and checks the result.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Pathway
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

namespace OCA\Decidiq\Service\Pathway;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Every change is checked against the group manager afterwards; a failed
 * change is logged and reported, never thrown.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
 */
final class MemberGroupChanges {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager The Nextcloud group manager.
	 * @param LoggerInterface $logger       The logger.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look every group up; the missing ones are named.
	 *
	 * @param list<string> $names The group ids.
	 *
	 * @return array{groups: list<IGroup>, missing: list<string>}
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function resolve(array $names): array {
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
	 * Add the member to each group they are not in yet.
	 *
	 * @param IUser        $user   The member.
	 * @param list<IGroup> $groups The groups.
	 *
	 * @return list<IGroup> The groups this run added.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning
	 */
	public function addToGroups(IUser $user, array $groups): array {
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
	public function unverified(string $uid, array $groups): array {
		$unverified = [];
		foreach ($groups as $group) {
			if ($this->groupManager->isInGroup($uid, $group->getGID()) === false) {
				$unverified[] = $group->getGID();
			}
		}

		return $unverified;
	}//end unverified()

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
	public function undo(IUser $user, array $groups): void {
		foreach ($groups as $group) {
			try {
				$group->removeUser($user);
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq: undoing a partial group assignment failed', ['exception' => $e, 'group' => $group->getGID()]);
			}
		}
	}//end undo()

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
	public function removeFromGroups(IUser $user, array $names): array {
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
	public function stillIn(string $uid, array $names): array {
		return array_values(
			array_filter($names, fn (string $name): bool => $this->groupManager->isInGroup($uid, $name) === true)
		);
	}//end stillIn()

}//end class
