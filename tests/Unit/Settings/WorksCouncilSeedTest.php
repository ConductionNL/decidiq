<?php

/**
 * The works-council example set carries the WOR trajecten the design describes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/works-council-consultation/design.md#seed-data
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * The ondernemingsraad with its members, and three trajecten: one overdue,
 * one in the constituency round, one finished with a decision that departs
 * from the advice, so both dashboard counts and the suspension term show on
 * a fresh example set.
 *
 * @coversNothing
 */
final class WorksCouncilSeedTest extends TestCase {

	/**
	 * The example set's seed objects by schema slug.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function objects(): array {
		$profile = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/works-council.json'), true, 512, JSON_THROW_ON_ERROR);

		return $profile['x-openregister']['seedData']['objects'];
	}

	/**
	 * The trajecten by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function requests(): array {
		return array_column(self::objects()['consultation-request'], null, 'slug');
	}

	public function testTheWorksCouncilIsABodyWithMembers(): void {
		$objects = self::objects();
		$body = $objects['governance-body'][0];

		self::assertSame('works-council', $body['bodyType']);
		$members = array_filter($objects['membership'], static fn (array $m): bool => ($m['governanceBody'] ?? $m['body'] ?? null) === $body['slug']);
		self::assertGreaterThanOrEqual(2, count($members));
		foreach (self::requests() as $request) {
			self::assertSame($body['slug'], $request['governanceBody']);
		}
	}

	public function testThreeTrajectenCoverTheFlow(): void {
		$requests = self::requests();

		self::assertSame(
			['adviesaanvraag-outsourcing-ict' => 'completed', 'adviesaanvraag-reorganisatie-logistiek' => 'consultationMeeting', 'instemmingsverzoek-werktijdenregeling' => 'constituencyConsultation'],
			array_map(static fn (array $r): string => $r['lifecycle'], $requests)
		);
		self::assertSame('consent-request', $requests['instemmingsverzoek-werktijdenregeling']['type']);
	}

	public function testOneOpenTrajectIsPastItsRequestedResponseDate(): void {
		$open = array_filter(
			self::requests(),
			static fn (array $r): bool => in_array($r['lifecycle'], ['completed', 'withdrawn', 'sent', 'decision-received'], true) === false
				&& ($r['requestedResponseDate'] ?? '9999-12-31') < '2026-05-01'
		);

		// Past by May 2026 already, so on any later fresh set too: the
		// "response past the requested date" count is never zero.
		self::assertSame(['adviesaanvraag-reorganisatie-logistiek'], array_keys($open));
	}

	public function testTheFinishedAdviceRequestCarriesADepartingDecision(): void {
		$done = self::requests()['adviesaanvraag-outsourcing-ict'];

		self::assertSame('adviceRequest', $done['type']);
		self::assertSame('advice-with-conditions', $done['responseOutcome']);
		self::assertSame('contrary-to-advice', $done['decisionOutcome']);
		self::assertSame('2026-03-10', $done['decisionDate']);
		self::assertArrayNotHasKey('suspensionTo', $done, 'The suspension term is derived on save, not seeded.');
		$calculation = MergedRegisterSchema::forSlug(slug: 'consultation-request')['x-openregister-calculations']['suspensionTo']['expression']['if'][0]['and'][1]['eq'][1];
		self::assertSame($done['decisionOutcome'], $calculation);
	}

	public function testEveryTrajectFitsTheShippedSchema(): void {
		foreach (self::requests() as $slug => $request) {
			unset($request['@self'], $request['slug']);
			self::assertSame([], MergedRegisterSchema::violations(slug: 'consultation-request', payload: $request), $slug);
		}
	}
}
