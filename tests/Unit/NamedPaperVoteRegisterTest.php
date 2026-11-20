<?php

/**
 * The register declares the roll call method and the recorded vote fields, and the example set carries a roll call.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-001-a-chair-opens-a-roll-call-round-whose-votes-are-recorded-by-name
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fragment 132 unions `roll-call` into the base enum and adds `entryMode` and `recordedBy` to Vote.
 */
class NamedPaperVoteRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../lib/Settings';

	/**
	 * Decode one JSON file under lib/Settings.
	 *
	 * @param string $path The path below lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode(json: (string)file_get_contents(filename: self::SETTINGS . '/' . $path), associative: true, flags: JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The fragment's schemas.
	 *
	 * @return array<string, mixed>
	 */
	private static function fragment(): array {
		return self::json(path: 'register.d/132-named-paper-vote-entry.json')['components']['schemas'];
	}//end fragment()

	/**
	 * The merged method list keeps the four methods and adds roll-call.
	 *
	 * @return void
	 */
	public function testRollCallIsAddedToTheFourMethods(): void {
		$base = self::json(path: 'decidesk_register.json')['components']['schemas']['VotingRound']['properties']['votingMethod']['enum'];
		$added = self::fragment()['VotingRound']['properties']['votingMethod']['enum'];
		$merged = array_values(array_unique(array_merge($base, $added)));

		self::assertSame(expected: ['roll-call'], actual: $added, message: 'the fragment only adds; fragments union list values');
		foreach (['for-against-abstain', 'ranked-choice', 'weighted', 'show-of-hands', 'roll-call'] as $method) {
			self::assertContains(needle: $method, haystack: $merged);
		}
	}//end testRollCallIsAddedToTheFourMethods()

	/**
	 * A vote says how it was entered and by whom, defaulting to cast.
	 *
	 * @return void
	 */
	public function testAVoteDeclaresEntryModeAndRecordedBy(): void {
		$properties = self::fragment()['Vote']['properties'];

		self::assertSame(expected: ['cast', 'recorded'], actual: $properties['entryMode']['enum']);
		self::assertSame(expected: 'cast', actual: $properties['entryMode']['default']);
		self::assertSame(expected: 'string', actual: $properties['recordedBy']['type']);
		self::assertNotSame(expected: '', actual: $properties['entryMode']['title']);
		self::assertNotSame(expected: '', actual: $properties['recordedBy']['title']);
	}//end testAVoteDeclaresEntryModeAndRecordedBy()

	/**
	 * The fragment's versions are above every other declaration of the two schemas.
	 *
	 * @return void
	 */
	public function testTheVersionsMoveUp(): void {
		$highest = ['VotingRound' => '0.0.0', 'Vote' => '0.0.0'];
		$files = array_merge([self::SETTINGS . '/decidesk_register.json'], (glob(self::SETTINGS . '/register.d/*.json') ?: []));
		foreach ($files as $file) {
			if (str_ends_with($file, '132-named-paper-vote-entry.json') === true) {
				continue;
			}

			$schemas = (json_decode(json: (string)file_get_contents(filename: $file), associative: true)['components']['schemas'] ?? []);
			foreach (array_keys($highest) as $name) {
				$version = (string)($schemas[$name]['version'] ?? '0.0.0');
				if (version_compare($version, $highest[$name], '>') === true) {
					$highest[$name] = $version;
				}
			}
		}

		foreach ($highest as $name => $version) {
			self::assertTrue(
				condition: version_compare((string)self::fragment()[$name]['version'], $version, '>'),
				message: $name . ' must move above ' . $version
			);
		}
	}//end testTheVersionsMoveUp()

	/**
	 * The municipality example set carries the roll call round and its three recorded votes.
	 *
	 * @return void
	 */
	public function testTheExampleSetCarriesARollCall(): void {
		$objects = self::json(path: 'profiles/municipality.json')['x-openregister']['seedData']['objects'];
		$rounds = array_column($objects['voting-round'], null, 'slug');
		$votes = array_column($objects['vote'], null, 'slug');

		self::assertSame(expected: 'roll-call', actual: $rounds['stemming-hoofdelijk-parkeerbeleid']['votingMethod']);
		self::assertSame(expected: [2, 1, 'adopted'], actual: [
			$rounds['stemming-hoofdelijk-parkeerbeleid']['votesFor'],
			$rounds['stemming-hoofdelijk-parkeerbeleid']['votesAgainst'],
			$rounds['stemming-hoofdelijk-parkeerbeleid']['result'],
		]);
		foreach (['stem-hoofdelijk-doornbos' => 'for', 'stem-hoofdelijk-bakker' => 'for', 'stem-hoofdelijk-yilmaz' => 'against'] as $slug => $value) {
			self::assertSame(expected: [$value, 'recorded', 'griffier'], actual: [$votes[$slug]['value'], $votes[$slug]['entryMode'], $votes[$slug]['recordedBy']]);
		}
	}//end testTheExampleSetCarriesARollCall()
}//end class
