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
	 * The concept decision is a draft Woo decision on the Lindelaan case that
	 * portaliq's fragment writes, found by its portal reference, and the fragment
	 * loads after portaliq's so the case exists.
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
		$this->assertContains(needle: 'portaliq', haystack: (array)($fragment['after'] ?? []));
		$this->assertSame(expected: 'draft', actual: $decision['data']['lifecycle']);
		$this->assertSame(expected: 'woo-decision', actual: $decision['data']['decisionType']);
		$this->assertArrayNotHasKey(key: 'decisionDate', array: $decision['data']);
		$this->assertSame(
			expected: ['dossiq', 'dossiq', 'case', '{{lookup:case}}'],
			actual: [$decision['data']['sourceApp'], $decision['data']['subjectRegister'], $decision['data']['subjectSchema'], $decision['data']['subjectId']]
		);
		$this->assertSame(expected: 'dossiq', actual: $decision['lookups']['case']['register']);
		// The case is portaliq's, found by the portal reference only it carries.
		$this->assertSame(expected: ['externalReference' => 'MZ-ZDR-2026-0412'], actual: $decision['lookups']['case']['where']);
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
	 * The people on the stages are the cast's Woo team, as the board draws
	 * them: Pieter Jansen drafted it and signs under mandate, Anouk Bakker,
	 * the team lead, initials it.
	 *
	 * @return void
	 */
	public function testThePeopleOnTheStagesAreTheCastsWooTeam(): void {
		$byKey = [];
		foreach (self::fragment()['objects'] as $object) {
			$byKey[$object['key']] = $object;
		}

		$this->assertSame(expected: '{{object:person-pieter-jansen.id}}', actual: $byKey['stage-woo-lindelaan-opgesteld']['data']['registeredBy']);
		$this->assertSame(expected: '{{object:person-anouk-bakker.id}}', actual: $byKey['stage-woo-lindelaan-paraaf']['data']['assignedPerson']);
		$this->assertSame(expected: '{{object:person-pieter-jansen.id}}', actual: $byKey['stage-woo-lindelaan-ondertekenen']['data']['assignedPerson']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $byKey['person-pieter-jansen']['data']['nextcloudUserId']);
		$this->assertSame(expected: '{{cast:anouk.uid}}', actual: $byKey['person-anouk-bakker']['data']['nextcloudUserId']);
		$keys = array_keys($byKey);
		foreach (['person-pieter-jansen', 'person-anouk-bakker'] as $person) {
			$this->assertLessThan(expected: array_search('stage-woo-lindelaan-opgesteld', $keys, true), actual: array_search($person, $keys, true));
		}
	}//end testThePeopleOnTheStagesAreTheCastsWooTeam()

	/**
	 * Pieter holds the mandate for Woo decisions up to "deels openbaar"; "niet
	 * openbaar" stays with the team lead. The allocation is in force and rests
	 * on the college's mandate decision, so the authority check finds it.
	 *
	 * @return void
	 */
	public function testPieterHoldsTheWooMandate(): void {
		$byKey = [];
		foreach (self::fragment()['objects'] as $object) {
			$byKey[$object['key']] = $object;
		}

		$mandate = $byKey['mandaat-woo-besluiten-pieter'];
		$this->assertSame(expected: 'bevoegdheidstoedeling', actual: $mandate['schema']);
		$this->assertSame(expected: 'mandate', actual: $mandate['data']['type']);
		$this->assertSame(expected: 'effective', actual: $mandate['data']['status']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $mandate['data']['delegateUser']);
		$this->assertSame(expected: ['woo-decision'], actual: $mandate['data']['acts']);
		$this->assertSame(expected: 'Woo-besluiten', actual: $mandate['data']['subject']);
		$this->assertStringContainsString(needle: 'deels openbaar', haystack: $mandate['data']['beperkingen']);
		$this->assertStringContainsString(needle: 'teamleider', haystack: $mandate['data']['beperkingen']);
		$this->assertSame(expected: '{{object:decision-mandaatbesluit-woo.id}}', actual: $mandate['data']['decision']);
		$this->assertSame(expected: 'woo-decision', actual: $byKey['decision-woo-lindelaan-concept']['data']['decisionType'], message: 'the act the mandate covers');
	}//end testPieterHoldsTheWooMandate()
}//end class
