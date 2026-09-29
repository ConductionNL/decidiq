<?php

/**
 * Decidiq Body Quorum
 *
 * How many members must be present for a meeting of a governance body.
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
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The quorum a meeting must reach, and whether its members reach it.
 *
 * The threshold is the meeting's own `quorumRequired`, else the body's
 * `quorum` (a member count), else the body's `quorumRule` applied to the
 * body's members. A body with none of these sets no quorum. Present means
 * marked present or represented by proxy; when nobody's attendance was taken,
 * every member who has not left counts, as before.
 *
 * Pure: no I/O, so VotingRoundOpener hands it the rows it already read.
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
 */
final class BodyQuorum {

	/**
	 * Quorum rule names and their fraction of the members, as a numerator
	 * over a denominator. `majority` and `simple-majority` are more than half.
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private const FRACTIONS = [
		'qualified-majority-two-thirds' => [2, 3],
		'two-thirds' => [2, 3],
		'qualified-majority-three-quarters' => [3, 4],
		'three-quarters' => [3, 4],
		'unanimous' => [1, 1],
	];

	/**
	 * Whether the members present reach the quorum.
	 *
	 * @param array<string, mixed>             $meeting      The meeting
	 * @param array<string, mixed>|null        $body         Its governance body, when known
	 * @param array<int, array<string, mixed>> $participants The body's participants
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function isMet(array $meeting, ?array $body, array $participants): bool {
		$members = array_values(
			array_filter($participants, static fn (array $p): bool => ($p['leftAt'] ?? null) === null)
		);

		$threshold = $this->threshold(meeting: $meeting, body: ($body ?? []), memberCount: count($members));
		if ($threshold === 0) {
			return true;
		}

		return $this->presentCount(members: $members) >= $threshold;

	}//end isMet()

	/**
	 * The number of members that must be present; 0 means no quorum.
	 *
	 * @param array<string, mixed> $meeting     The meeting
	 * @param array<string, mixed> $body        Its governance body
	 * @param int                  $memberCount The body's current members
	 *
	 * @return int
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	public function threshold(array $meeting, array $body, int $memberCount): int {
		foreach ([($meeting['quorumRequired'] ?? null), ($body['quorum'] ?? null)] as $count) {
			if (is_numeric($count) === true && (int)$count > 0) {
				return (int)$count;
			}
		}

		$rule = strtolower((string)($body['quorumRule'] ?? ''));
		if ($rule === '' || $memberCount === 0) {
			return 0;
		}

		if (isset(self::FRACTIONS[$rule]) === true) {
			[$numerator, $denominator] = self::FRACTIONS[$rule];
			return (int)ceil(($memberCount * $numerator) / $denominator);
		}

		// `majority`, `simple-majority` and any rule not named above: more than half.
		return intdiv($memberCount, 2) + 1;

	}//end threshold()

	/**
	 * Members present: marked present or proxy, or all of them when nobody's
	 * attendance was taken.
	 *
	 * @param array<int, array<string, mixed>> $members The current members
	 *
	 * @return int
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-002-votes-follow-the-body-rules
	 */
	private function presentCount(array $members): int {
		$taken = array_filter($members, static fn (array $m): bool => (string)($m['attendanceStatus'] ?? '') !== '');
		if ($taken === []) {
			return count($members);
		}

		return count(
			array_filter(
				$members,
				static fn (array $m): bool => in_array(($m['attendanceStatus'] ?? ''), ['present', 'proxy'], true)
			)
		);

	}//end presentCount()
}//end class
