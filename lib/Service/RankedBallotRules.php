<?php

/**
 * Decidiq Ranked Ballot Rules
 *
 * What a ranked preference round and a ranked ballot must look like
 * (voting-ranked-preference-ballot, REQ-PRF-001 and REQ-PRF-002, issue #1419).
 * Pure: the opener and the caster hand it the request and the round, and it
 * answers with the cleaned options or throws the reason.
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
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;

/**
 * Options of a ranked round, and the ranking a ballot on it must hold.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md
 */
class RankedBallotRules {

	/**
	 * The voting method of a ranked preference round.
	 *
	 * @var string
	 */
	public const METHOD = 'ranked-choice';

	/**
	 * The Vote.value of a ranked ballot.
	 *
	 * @var string
	 */
	public const VALUE = 'ranked';

	/**
	 * The fewest options a ranked round can have.
	 *
	 * @var int
	 */
	private const MIN_OPTIONS = 2;

	/**
	 * The most options a ranked round can have.
	 *
	 * @var int
	 */
	private const MAX_OPTIONS = 20;

	/**
	 * Check the options a round opens with, and clean them.
	 *
	 * A ranked round needs two to twenty options with unique keys and a
	 * label each, and cannot use the tie-break rule chair-decides, because a
	 * casting vote is for or against and cannot name an option. Any other
	 * method takes no options.
	 *
	 * @param string $votingMethod The round's voting method.
	 * @param array<int, mixed> $options The options as requested.
	 * @param string $tieBreakRule The round's resolved tie-break rule.
	 *
	 * @return array<int, array{key: string, label: string, person?: string}> The options to store.
	 *
	 * @throws InvalidArgumentException With the reason, when the options do not fit the method.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	public function openingOptions(string $votingMethod, array $options, string $tieBreakRule): array {
		if ($votingMethod !== self::METHOD) {
			if ($options !== []) {
				throw new InvalidArgumentException(message: 'Options can only be given for a ranked-choice round');
			}

			return [];
		}

		if ($tieBreakRule === 'chair-decides') {
			throw new InvalidArgumentException(message: 'A ranked-choice round cannot use the tie-break rule chair-decides');
		}

		if (count($options) < self::MIN_OPTIONS) {
			throw new InvalidArgumentException(message: 'A ranked-choice round needs at least two options');
		}

		if (count($options) > self::MAX_OPTIONS) {
			throw new InvalidArgumentException(message: 'A ranked-choice round can have at most twenty options');
		}

		$clean = [];
		foreach ($options as $option) {
			$entry = $this->option(option: $option);
			if (isset($clean[$entry['key']]) === true) {
				throw new InvalidArgumentException(message: 'Every option of a ranked-choice round needs its own key');
			}

			$clean[$entry['key']] = $entry;
		}

		return array_values($clean);
	}//end openingOptions()

	/**
	 * Check a ballot against its round and answer the Vote.value to store.
	 *
	 * On a ranked round the ballot must rank every option exactly once. On
	 * any other round a ranking is refused, and so is the value `ranked`.
	 *
	 * @param array<string, mixed> $round The open round.
	 * @param string $value The value cast.
	 * @param array<int, mixed>|null $ranking The ranking cast, or null.
	 *
	 * @return string The value to store.
	 *
	 * @throws InvalidArgumentException With the reason, when the ballot does not fit the round.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function ballotValue(array $round, string $value, ?array $ranking): string {
		if (($round['votingMethod'] ?? '') !== self::METHOD) {
			if ($ranking !== null || $value === self::VALUE) {
				throw new InvalidArgumentException(message: 'A ranking can only be cast on a ranked-choice round');
			}

			return $value;
		}

		$keys = [];
		foreach ((array)($round['options'] ?? []) as $option) {
			if (is_array($option) === true) {
				$keys[] = (string)($option['key'] ?? '');
			}
		}

		if ($ranking === null || (new BordaCount())->isFullRanking(ranking: $ranking, keys: $keys) === false) {
			throw new InvalidArgumentException(message: 'Every option must be ranked exactly once');
		}

		return self::VALUE;
	}//end ballotValue()

	/**
	 * Clean one option.
	 *
	 * @param mixed $option The option as requested.
	 *
	 * @return array{key: string, label: string, person?: string} The option.
	 *
	 * @throws InvalidArgumentException When it has no key or no label.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	private function option(mixed $option): array {
		$key = '';
		$label = '';
		$person = '';
		if (is_array($option) === true) {
			$key = trim((string)($option['key'] ?? ''));
			$label = trim((string)($option['label'] ?? ''));
			$person = trim((string)($option['person'] ?? ''));
		}

		if ($key === '' || $label === '') {
			throw new InvalidArgumentException(message: 'Every option of a ranked-choice round needs a key and a label');
		}

		$entry = ['key' => $key, 'label' => $label];
		if ($person !== '') {
			$entry['person'] = $person;
		}

		return $entry;
	}//end option()
}//end class
