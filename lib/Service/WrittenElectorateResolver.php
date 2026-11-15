<?php

/**
 * Takes the snapshot of the members a written round is put to.
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
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * The electorate of a written round: every membership of the body that is
 * active on the day, whose role is not observer or guest. A member whose
 * person has no Nextcloud user id cannot answer, so it is listed as
 * unreachable instead.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */
class WrittenElectorateResolver {

	/**
	 * Membership roles that never answer a written round.
	 *
	 * @var list<string>
	 */
	public const NON_VOTING_ROLES = ['observer', 'guest'];

	/**
	 * Constructor.
	 *
	 * @param WrittenRoundStore $store Reads memberships and persons.
	 */
	public function __construct(
		private readonly WrittenRoundStore $store,
	) {
	}//end __construct()

	/**
	 * The electorate of a body on a day, and the members who cannot answer.
	 *
	 * @param string            $bodyId The governance body.
	 * @param DateTimeImmutable $day    The day the round opens.
	 *
	 * @return array{
	 *     electorate: list<array{membership: string, person: string, userId: string, votingWeight: float, name: string}>,
	 *     unreachable: list<string>
	 * }
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
	 */
	public function resolve(string $bodyId, DateTimeImmutable $day): array {
		$electorate  = [];
		$unreachable = [];
		foreach ($this->entitledMemberships(bodyId: $bodyId, day: $day) as $membershipId => $membership) {
			$member = $this->memberOf(membership: $membership, membershipId: (string)$membershipId);
			if ($member['userId'] === '') {
				$unreachable[] = $member['name'];
				continue;
			}

			$electorate[] = $member;
		}

		return ['electorate' => $electorate, 'unreachable' => $unreachable];
	}//end resolve()

	/**
	 * The body's memberships entitled on the day, once each, by membership uuid.
	 *
	 * @param string            $bodyId The governance body.
	 * @param DateTimeImmutable $day    The day the round opens.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function entitledMemberships(string $bodyId, DateTimeImmutable $day): array {
		$entitled = [];
		foreach ($this->store->findAll(schema: 'membership', filters: ['governanceBody' => $bodyId]) as $membership) {
			$membershipId = WrittenRoundStore::idOf(value: ($membership['id'] ?? null));
			if ($membershipId === null || isset($entitled[$membershipId]) === true || self::isEntitled(membership: $membership, day: $day) === false) {
				continue;
			}

			$entitled[$membershipId] = $membership;
		}

		return $entitled;
	}//end entitledMemberships()

	/**
	 * One electorate entry for a membership: its person, user, weight and name.
	 *
	 * @param array<string, mixed> $membership   The membership.
	 * @param string               $membershipId Its uuid.
	 *
	 * @return array{membership: string, person: string, userId: string, votingWeight: float, name: string}
	 */
	private function memberOf(array $membership, string $membershipId): array {
		$personId = (WrittenRoundStore::idOf(value: ($membership['person'] ?? null)) ?? '');
		$person   = $this->person(personId: $personId);

		return [
			'membership' => $membershipId,
			'person' => $personId,
			'userId' => trim((string)($person['nextcloudUserId'] ?? '')),
			'votingWeight' => max(0.0, (float)($membership['votingWeight'] ?? 1)),
			'name' => (self::nameOf(person: $person, membership: $membership) ?? $membershipId),
		];
	}//end memberOf()

	/**
	 * Whether a membership answers a round opened on the day: started, not
	 * ended, and not an observer or guest.
	 *
	 * @param array<string, mixed> $membership The membership.
	 * @param DateTimeImmutable    $day        The day.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
	 */
	public static function isEntitled(array $membership, DateTimeImmutable $day): bool {
		if (in_array((string)($membership['role'] ?? 'member'), self::NON_VOTING_ROLES, true) === true) {
			return false;
		}

		$today = $day->format('Y-m-d');
		$start = self::dateOf(value: ($membership['startDate'] ?? null));
		if ($start !== null && $start > $today) {
			return false;
		}

		$end = self::dateOf(value: ($membership['endDate'] ?? null));

		return $end === null || $end >= $today;
	}//end isEntitled()

	/**
	 * The person of a membership, or an empty array when it cannot be read.
	 *
	 * @param string $personId The person.
	 *
	 * @return array<string, mixed>
	 */
	private function person(string $personId): array {
		if ($personId === '') {
			return [];
		}

		try {
			return ($this->store->find(id: $personId, schema: 'person') ?? []);
		} catch (Throwable) {
			return [];
		}
	}//end person()

	/**
	 * A readable name for a member, or null when neither names the member.
	 *
	 * @param array<string, mixed> $person     The person.
	 * @param array<string, mixed> $membership The membership.
	 *
	 * @return string|null
	 */
	private static function nameOf(array $person, array $membership): ?string {
		foreach ([($person['name'] ?? null), ($person['fullName'] ?? null), ($person['displayName'] ?? null), ($membership['label'] ?? null)] as $name) {
			if (is_string($name) === true && trim($name) !== '') {
				return trim($name);
			}
		}

		return self::fullNameOf(person: $person);
	}//end nameOf()

	/**
	 * The person's first and last name, or null when it has neither.
	 *
	 * @param array<string, mixed> $person The person.
	 *
	 * @return string|null
	 */
	private static function fullNameOf(array $person): ?string {
		$parts = array_filter(
			[($person['firstName'] ?? ''), ($person['lastName'] ?? '')],
			static fn ($part): bool => is_string($part) === true && $part !== ''
		);
		if ($parts !== []) {
			return implode(' ', $parts);
		}

		return null;
	}//end fullNameOf()

	/**
	 * The Y-m-d day of a stored date, or null when it holds none.
	 *
	 * @param mixed $value The stored date.
	 *
	 * @return string|null
	 */
	private static function dateOf(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return substr(trim($value), 0, 10);
	}//end dateOf()
}//end class
