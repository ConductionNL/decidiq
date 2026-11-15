<?php

/**
 * The Zuiddrecht example set carries a live participatory budget round and a
 * live public consultation, as DcBudgetronde and DcPubliekeConsultatie draw
 * them: the closed Wijkbudget 2027 with its ten proposals, and the open
 * consultation on the parking regulation with reactions waiting for moderation.
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
class ExampleSetParticipationTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The fragment's objects by key, in file order.
	 *
	 * @return array<string, array<string, mixed>> The objects
	 */
	private static function objects(): array {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$byKey    = [];
		foreach ($fragment['objects'] as $object) {
			$byKey[$object['key']] = $object;
		}

		return $byKey;
	}//end objects()

	/**
	 * The objects of one schema that point at a parent through one field.
	 *
	 * @param string $schema The child schema.
	 * @param string $field  The field holding the parent reference.
	 * @param string $parent The parent's key.
	 *
	 * @return array<int, array<string, mixed>> The children's data
	 */
	private static function children(string $schema, string $field, string $parent): array {
		$children = [];
		foreach (self::objects() as $object) {
			if ($object['schema'] === $schema && ($object['data'][$field] ?? '') === '{{object:'.$parent.'.id}}') {
				$children[] = $object['data'];
			}
		}

		return $children;
	}//end children()

	/**
	 * The budget round is closed with its results published, and owned by the college.
	 *
	 * @return void
	 */
	public function testTheBudgetRoundIsClosedWithPublishedResults(): void {
		$round = self::objects()['budget-round-wijkbudget-2027'] ?? null;

		$this->assertNotNull(actual: $round);
		$this->assertSame(expected: 'participatory-budget', actual: $round['schema']);
		$this->assertSame(expected: 'Wijkbudget Zuiddrecht 2027', actual: $round['data']['name']);
		$this->assertSame(
			expected: ['closed', true, 250000],
			actual: [$round['data']['status'], $round['data']['resultsPublished'], $round['data']['totalAmount']]
		);
		$this->assertSame(expected: '{{object:body-college.id}}', actual: $round['data']['governanceBody']);
	}//end testTheBudgetRoundIsClosedWithPublishedResults()

	/**
	 * Ten proposals; the four awarded ones add up to the 240,000 the result names.
	 *
	 * @return void
	 */
	public function testTheAwardedProposalsAddUpToTheResult(): void {
		$proposals = self::children(schema: 'budget-proposal', field: 'participatoryBudget', parent: 'budget-round-wijkbudget-2027');
		$awarded   = array_filter($proposals, fn (array $p): bool => $p['status'] === 'awarded');

		$this->assertCount(expectedCount: 10, haystack: $proposals);
		$this->assertCount(expectedCount: 4, haystack: $awarded);
		$this->assertSame(expected: 240000, actual: array_sum(array_column($awarded, 'requestedAmount')));
	}//end testTheAwardedProposalsAddUpToTheResult()

	/**
	 * The consultation is open, pre-moderated, and informs a draft decision.
	 *
	 * @return void
	 */
	public function testTheConsultationIsOpenAndPreModerated(): void {
		$objects      = self::objects();
		$consultation = $objects['consultation-parkeren-binnenstad'] ?? null;

		$this->assertNotNull(actual: $consultation);
		$this->assertSame(expected: 'public-consultation', actual: $consultation['schema']);
		$this->assertSame(
			expected: ['open', 'citizen-participation', 'pre-moderation', true],
			actual: [
				$consultation['data']['status'],
				$consultation['data']['consultationType'],
				$consultation['data']['moderationPolicy'],
				$consultation['data']['anonymousReactionsAllowed'],
			]
		);
		$this->assertSame(expected: '{{object:decision-parkeren-binnenstad-concept.id}}', actual: $consultation['data']['decision']);
		$this->assertSame(expected: 'draft', actual: $objects['decision-parkeren-binnenstad-concept']['data']['lifecycle']);
	}//end testTheConsultationIsOpenAndPreModerated()

	/**
	 * Reactions in all three moderation states, with two waiting.
	 *
	 * @return void
	 */
	public function testTheReactionsWaitForModeration(): void {
		$reactions = self::children(schema: 'consultation-reaction', field: 'consultation', parent: 'consultation-parkeren-binnenstad');
		$states    = array_count_values(array_column($reactions, 'moderationStatus'));
		ksort($states);

		$this->assertSame(expected: ['approved' => 3, 'pending' => 2, 'rejected' => 1], actual: $states);
	}//end testTheReactionsWaitForModeration()
}//end class
