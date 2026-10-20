<?php

/**
 * The Zuiddrecht example set carries the concept Woo decision on dossiq's
 * in-progress Lindelaan case, as DqZaakBesluiten draws it: drafted by the
 * handler, the team lead's initials and the signature still to come.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped example-set fragment from disk.
 */
class ExampleSetWooConceptDecisionTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed> The fragment
	 */
	private static function fragment(): array {
		return json_decode((string)file_get_contents(self::FRAGMENT), true);
	}//end fragment()

	/**
	 * The concept decision is a draft Woo decision on the dossiq case, found
	 * by a lookup, and the fragment loads after dossiq's so the case exists.
	 *
	 * @return void
	 */
	public function testTheConceptDecisionPointsAtTheDossiqCase(): void {
		$fragment = self::fragment();
		$decision = null;
		foreach ($fragment['objects'] as $object) {
			if ($object['key'] === 'decision-woo-lindelaan-concept') {
				$decision = $object;
			}
		}

		$this->assertNotNull(actual: $decision);
		$this->assertContains(needle: 'dossiq', haystack: (array)($fragment['after'] ?? []));
		$this->assertSame(expected: 'draft', actual: $decision['data']['lifecycle']);
		$this->assertSame(expected: 'woo-decision', actual: $decision['data']['decisionType']);
		$this->assertArrayNotHasKey(key: 'decisionDate', array: $decision['data']);
		$this->assertSame(
			expected: ['dossiq', 'dossiq', 'case', '{{lookup:case}}'],
			actual: [$decision['data']['sourceApp'], $decision['data']['subjectRegister'], $decision['data']['subjectSchema'], $decision['data']['subjectId']]
		);
		$this->assertSame(expected: 'dossiq', actual: $decision['lookups']['case']['register']);
		$this->assertSame(expected: 'Verlichting fietspad Lindelaan', actual: $decision['lookups']['case']['where']['title']);
	}//end testTheConceptDecisionPointsAtTheDossiqCase()

	/**
	 * Drafted, initials active, signature and announcement still pending.
	 *
	 * @return void
	 */
	public function testTheStagesAwaitInitialsAndSignature(): void {
		$stages = [];
		foreach (self::fragment()['objects'] as $object) {
			if ($object['schema'] === 'decision-stage' && ($object['data']['decision'] ?? '') === '{{object:decision-woo-lindelaan-concept.id}}') {
				$stages[(int)$object['data']['sequence']] = [$object['data']['status'], $object['data']['method']];
			}
		}

		ksort($stages);
		$this->assertSame(
			expected: [1 => ['decided', 'manual'], 2 => ['active', 'manual'], 3 => ['pending', 'signature'], 4 => ['pending', 'manual']],
			actual: $stages
		);
	}//end testTheStagesAwaitInitialsAndSignature()
	/**
	 * The initials and the signature name someone, as the board does: the
	 * cast's team lead initials, the Woo coordinator signs under mandate.
	 *
	 * @return void
	 */
	public function testThePeopleOnTheStagesAreTheCastsStaff(): void {
		$byKey = [];
		foreach (self::fragment()['objects'] as $object) {
			$byKey[$object['key']] = $object;
		}

		$this->assertSame(expected: '{{object:person-marleen-postma.id}}', actual: $byKey['stage-woo-lindelaan-paraaf']['data']['assignedPerson']);
		$this->assertSame(expected: '{{object:person-thomas-kuiper.id}}', actual: $byKey['stage-woo-lindelaan-ondertekenen']['data']['assignedPerson']);
		$this->assertSame(expected: '{{cast:marleen.uid}}', actual: $byKey['person-marleen-postma']['data']['nextcloudUserId']);
		$this->assertSame(expected: '{{cast:thomas.uid}}', actual: $byKey['person-thomas-kuiper']['data']['nextcloudUserId']);
		$keys = array_keys($byKey);
		$this->assertLessThan(
			expected: array_search('stage-woo-lindelaan-paraaf', $keys, true),
			actual: array_search('person-marleen-postma', $keys, true)
		);
	}//end testThePeopleOnTheStagesAreTheCastsStaff()
}//end class
