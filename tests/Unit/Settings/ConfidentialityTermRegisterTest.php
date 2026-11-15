<?php

/**
 * The register carries a restriction's imposing decision, planned end date
 * and the lapsed state.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\ConfidentialityRestrictions;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Register test for confidentiality-term-and-lapse task 1.
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
 */
class ConfidentialityTermRegisterTest extends TestCase {

	/**
	 * The four new fields exist and are optional.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date
	 */
	public function testTheRestrictionCarriesItsTermFields(): void {
		$schema     = MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction');
		$properties = $schema['properties'];

		self::assertSame(['Decision', 'uuid'], [$properties['imposingDecision']['$ref'], $properties['imposingDecision']['format']]);
		self::assertSame('date', $properties['liftBy']['format']);
		self::assertSame('date-time', $properties['sentToBodyAt']['format']);
		self::assertSame(['agenda-item', 'uuid'], [$properties['sentWithAgendaItem']['$ref'], $properties['sentWithAgendaItem']['format']]);
		self::assertSame([], array_intersect(['imposingDecision', 'liftBy', 'sentToBodyAt', 'sentWithAgendaItem'], $schema['required']));

		$payload = [
			'scope' => 'document',
			'ground' => 'ground-1',
			'imposedBy' => 'executive-board',
			'imposedByBody' => 'body-college',
			'imposedAt' => '2026-09-10T10:00:00+02:00',
			'lifecycle' => 'imposed',
			'imposingDecision' => 'decision-2026-0912',
			'liftBy' => '2027-07-01',
			'sentToBodyAt' => '2026-09-24T10:00:00+02:00',
			'sentWithAgendaItem' => 'item-raadsvoorstel',
		];
		self::assertSame([], MergedRegisterSchema::violations(slug: 'confidentiality-restriction', payload: $payload));

	}//end testTheRestrictionCarriesItsTermFields()

	/**
	 * Lapsed is a terminal state reached from imposed only, in the canonical dialect.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function testLapsedIsTerminalAndReachedFromImposedOnly(): void {
		$schema    = MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction');
		$lifecycle = $schema['x-openregister-lifecycle'];

		self::assertSame('imposed', $lifecycle['initial']);
		self::assertArrayNotHasKey('initialState', $lifecycle);
		self::assertContains('lapsed', $schema['properties']['lifecycle']['enum']);
		self::assertContains('lapsed', $lifecycle['states']);
		self::assertContains('lapsed', $lifecycle['terminal']);

		$into = array_values(array_filter($lifecycle['transitions'], static fn (array $t): bool => $t['to'] === 'lapsed'));
		$from = array_values(array_filter($lifecycle['transitions'], static fn (array $t): bool => $t['from'] === 'lapsed'));
		self::assertSame([['from' => 'imposed', 'to' => 'lapsed']], $into);
		self::assertSame([], $from);

		// Every state the map names is a value the field can hold, once.
		self::assertSame(array_unique($lifecycle['states']), $lifecycle['states']);
		self::assertSame([], array_diff($lifecycle['states'], $schema['properties']['lifecycle']['enum']));

	}//end testLapsedIsTerminalAndReachedFromImposedOnly()

	/**
	 * A lapsed restriction no longer blocks publication.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function testALapsedRestrictionIsNotActive(): void {
		$active = (new ReflectionClassConstant(ConfidentialityRestrictions::class, 'ACTIVE_STATES'))->getValue();

		self::assertSame(['imposed', 'ratified'], $active);
		self::assertNotContains('lapsed', $active);

	}//end testALapsedRestrictionIsNotActive()

	/**
	 * The secretariat hears when a restriction lapses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function testTheSecretariatIsNotifiedOnLapse(): void {
		$rule = (MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction')['x-openregister-notifications']['confidentialityLapsed'] ?? null);

		self::assertIsArray($rule);
		self::assertSame(['field' => 'lifecycle', 'operator' => 'equals', 'value' => 'lapsed'], $rule['trigger']['condition']);
		self::assertSame('updated', $rule['trigger']['type']);
		self::assertContains(['kind' => 'groups', 'groups' => ['decidiq-secretariat']], $rule['recipients']);

	}//end testTheSecretariatIsNotifiedOnLapse()

	/**
	 * The municipality set seeds the board's restriction and a lapsed one, both fitting the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board
	 */
	public function testTheMunicipalitySetSeedsTheBoardRestrictionAndALapsedOne(): void {
		$profile = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/municipality.json'),
			true
		);
		$seeded  = [];
		foreach (($profile['x-openregister']['seedData']['objects']['confidentiality-restriction'] ?? []) as $row) {
			$seeded[$row['slug']] = $row;
		}

		$board = $seeded['geheimhouding-onderbouwing-tarieven-grondexploitatie'];
		self::assertSame('imposed', $board['lifecycle']);
		self::assertSame('2026-09-24T10:00:00+02:00', $board['sentToBodyAt']);
		self::assertSame('2027-07-01', $board['liftBy']);
		self::assertSame('Na gunning van de grondexploitatie', $board['liftingConditions']);

		$lapsed = $seeded['geheimhouding-bijlage-aankoop-pand-vervallen'];
		self::assertSame('lapsed', $lapsed['lifecycle']);
		self::assertSame('raadsvergadering-2025-04-10', $lapsed['lapsedAtMeeting']);

		foreach ([$board, $lapsed] as $row) {
			// `@self` and `slug` are import envelope, not schema properties.
			$payload = array_diff_key($row, array_flip(['@self', 'slug']));
			self::assertSame([], MergedRegisterSchema::violations(slug: 'confidentiality-restriction', payload: $payload), $row['slug']);
			self::assertGreaterThanOrEqual(substr($row['imposedAt'], 0, 10), ($row['liftBy'] ?? '9999-12-31'));
		}

		// Both grounds the seeds use need ratification, which is what lets one lapse.
		$grounds = array_column($profile['x-openregister']['seedData']['objects']['confidentiality-ground'], 'requiresRatification', 'slug');
		self::assertTrue($grounds[$board['ground']]);
		self::assertTrue($grounds[$lapsed['ground']]);

	}//end testTheMunicipalitySetSeedsTheBoardRestrictionAndALapsedOne()
}//end class
