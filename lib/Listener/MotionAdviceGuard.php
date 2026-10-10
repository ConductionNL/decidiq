<?php

/**
 * Decidiq Motion Advice Guard
 *
 * The rules a resident's advice on a motion answers to once its motion is
 * open (issue #1418, REQ-CAV-002): the value is voor, tegen or onthoud, the
 * vote names its voter, and a resident advises on a motion only once.
 * PortalCreateOpenParentGuardListener checks the open motion and then asks
 * this class, before the row is persisted.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

/**
 * Value, voter and one vote per resident for advice on a motion.
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
 */
class MotionAdviceGuard {

	/**
	 * The register citizen votes live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The values a resident's advice on a motion may take.
	 *
	 * @var array<int, string>
	 */
	private const VALUES = ['voor', 'tegen', 'onthoud'];

	/**
	 * Whether a citizen vote is advice on a motion. One without a motion is
	 * an advisory vote on a budget proposal, which AdvisoryVoteService guards.
	 *
	 * @param array<string, mixed> $row The raw citizen vote.
	 *
	 * @return bool True when the row names a motion.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
	 */
	public function appliesTo(array $row): bool {
		return self::scalar(row: $row, field: 'motionId') !== '';
	}//end appliesTo()

	/**
	 * The reason the advice is refused, or null when it may be stored. The
	 * duplicate lookup runs without RBAC, because a guard that cannot see the
	 * earlier vote would let the second one through.
	 *
	 * @param array<string, mixed> $row The raw citizen vote being created.
	 * @param object $objectService OpenRegister's ObjectService.
	 *
	 * @return string|null The refusal, or null when the vote is accepted.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
	 */
	public function refusal(array $row, object $objectService): ?string {
		if (in_array($row['voteValue'] ?? null, self::VALUES, true) === false) {
			return 'Your advice on a motion must be voor, tegen or onthoud';
		}

		$voterId = self::scalar(row: $row, field: 'voterId');
		if ($voterId === '') {
			return 'A vote on a motion must name its voter';
		}

		$motionId = self::scalar(row: $row, field: 'motionId');
		$earlier = $objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => 'citizen-vote',
					'motionId' => $motionId,
					'voterId' => $voterId,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($earlier as $entity) {
			$vote = self::normalise(row: $entity);
			if (self::scalar(row: $vote, field: 'voterId') === $voterId
				&& self::scalar(row: $vote, field: 'motionId') === $motionId
			) {
				return 'You have already given your advice on this motion';
			}
		}

		return null;
	}//end refusal()

	/**
	 * Read a field as a trimmed string, or '' when it is absent or not a scalar.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string $field The field name.
	 *
	 * @return string The value.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
	 */
	private static function scalar(array $row, string $field): string {
		$value = ($row[$field] ?? null);
		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end scalar()

	/**
	 * Collapse OpenRegister's entity-or-array shape into an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The array form.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open
	 */
	private static function normalise(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end normalise()
}//end class
