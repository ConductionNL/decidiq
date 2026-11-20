<?php

/**
 * Matches an incoming roster against a body's current members.
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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\Pathway;

/**
 * People are matched by Nextcloud account, else by email, case-insensitively.
 * Every person and member carries its match keys (`uid:<account>`,
 * `email:<address>`); a roster person whose keys hit no member is joining, a
 * member whose keys hit no roster person is leaving. Pure: it reads nothing
 * and writes nothing.
 *
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
 */
final class RosterMatcher {

	/**
	 * The roster rows as people keyed by account or email; rows without either are ignored.
	 *
	 * @param array<int, mixed> $rows The incoming roster.
	 *
	 * @return array{people: array<string, array<string, mixed>>, ignored: list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function roster(array $rows): array {
		$people  = [];
		$ignored = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$uid   = trim((string) ($row['nextcloudUserId'] ?? ''));
			$email = strtolower(trim((string) ($row['email'] ?? '')));
			$keys  = $this->keys(uid: $uid, email: $email);
			if ($keys === []) {
				$ignored[] = ['name' => trim((string) ($row['name'] ?? '')), 'reason' => 'no account or email to match on'];
				continue;
			}

			$role = trim((string) ($row['role'] ?? ''));
			if ($role === '') {
				$role = 'member';
			}

			$people[$keys[0]] = [
				'name'            => trim((string) ($row['name'] ?? '')),
				'email'           => $email,
				'nextcloudUserId' => $uid,
				'role'            => $role,
				'keys'            => $keys,
			];
		}

		return ['people' => $people, 'ignored' => $ignored];
	}//end roster()

	/**
	 * Every match key of a list of people or members, as a set.
	 *
	 * @param iterable<array{keys: list<string>}> $items The people or members.
	 *
	 * @return array<string, true>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function keySet(iterable $items): array {
		$set = [];
		foreach ($items as $item) {
			foreach ($item['keys'] as $key) {
				$set[$key] = true;
			}
		}

		return $set;
	}//end keySet()

	/**
	 * The roster people who are no member yet and have no open onboarding.
	 *
	 * @param array<string, array<string,mixed>> $people  The roster people.
	 * @param array<string, mixed>              $context The members' keys (memberKeys), open onboardings (open), matched persons (persons).
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function joiningOf(array $people, array $context): array {
		$joining = [];
		foreach ($people as $key => $person) {
			if ($this->anyKey(keys: $person['keys'], set: $context['memberKeys']) === true) {
				continue;
			}

			$personId = $this->personIdFor(keys: $person['keys'], persons: $context['persons']);
			if ($personId !== '' && isset($context['open'][$personId]) === true) {
				continue;
			}

			$joining[] = [
				'key'             => $key,
				'name'            => $person['name'],
				'email'           => $person['email'],
				'nextcloudUserId' => $person['nextcloudUserId'],
				'role'            => $person['role'],
				'personId'        => $personId,
			];
		}

		return $joining;
	}//end joiningOf()

	/**
	 * The members who are not on the roster and have no open offboarding.
	 *
	 * @param list<array<string,mixed>>                                         $members The active members.
	 * @param array{rosterKeys: array<string,true>, open: array<string,true>} $context The roster's keys and the open offboardings.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function leavingOf(array $members, array $context): array {
		$leaving = [];
		foreach ($members as $member) {
			if ($this->anyKey(keys: $member['keys'], set: $context['rosterKeys']) === true
				|| isset($context['open'][$member['membershipId']]) === true
			) {
				continue;
			}

			$leaving[] = [
				'membershipId' => $member['membershipId'],
				'personId'     => $member['personId'],
				'name'         => $member['name'],
				'role'         => $member['role'],
			];
		}

		return $leaving;
	}//end leavingOf()

	/**
	 * A Person record's match keys.
	 *
	 * @param array<string,mixed> $person The person.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	public function personKeys(array $person): array {
		return $this->keys(
			uid: trim((string) ($person['nextcloudUserId'] ?? '')),
			email: strtolower(trim((string) ($person['email'] ?? '')))
		);
	}//end personKeys()

	/**
	 * The match keys of a person: account first, then email.
	 *
	 * @param string $uid   The Nextcloud uid.
	 * @param string $email The lower-cased email.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function keys(string $uid, string $email): array {
		$keys = [];
		if ($uid !== '') {
			$keys[] = 'uid:' . $uid;
		}

		if ($email !== '') {
			$keys[] = 'email:' . $email;
		}

		return $keys;
	}//end keys()

	/**
	 * Whether any of the keys is in the set.
	 *
	 * @param list<string>        $keys The keys.
	 * @param array<string, true> $set  The set.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function anyKey(array $keys, array $set): bool {
		foreach ($keys as $key) {
			if (isset($set[$key]) === true) {
				return true;
			}
		}

		return false;
	}//end anyKey()

	/**
	 * The person uuid matching one of the keys, or empty.
	 *
	 * @param list<string>          $keys    The keys.
	 * @param array<string, string> $persons Match key => person uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
	 */
	private function personIdFor(array $keys, array $persons): string {
		foreach ($keys as $key) {
			if (isset($persons[$key]) === true) {
				return $persons[$key];
			}
		}

		return '';
	}//end personIdFor()

}//end class
