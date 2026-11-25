<?php

/**
 * The integrity disclosures register: ancillary positions, declared gifts and
 * the per-body integrity policy, and what the example sets seed for them.
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

use DateTimeImmutable;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Register and seed tests for interests-and-integrity tasks 1 and 3.
 *
 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/tasks.md#task-1-register-fragment-62--nevenfunctie-geschenk-integriteitsbeleid-schemas-with-declarative-dialects
 */
class IntegrityDisclosuresRegisterTest extends TestCase {

	/**
	 * The public read rule both disclosures carry: anonymous once published.
	 *
	 * @var array<string, mixed>
	 */
	private const PUBLIC_READ = ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']], 'crossOrganisation' => true];

	/**
	 * An ancillary position records who holds which role where, whether it is
	 * paid and when it was declared; nothing it carries is private.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-001-nevenfunctie-schema-on-openregister
	 */
	public function testTheAncillaryPositionCarriesItsFields(): void {
		$schema = MergedRegisterSchema::forSlug(slug: 'ancillary-position');

		self::assertSame(expected: 'schema:Role', actual: $schema['x-schema-org']);
		foreach (['person', 'governanceBody', 'organisation', 'role', 'remunerated', 'declaredAt', 'lifecycle'] as $field) {
			self::assertContains(needle: $field, haystack: $schema['required']);
		}

		self::assertSame(expected: 'boolean', actual: $schema['properties']['remunerated']['type']);
		self::assertSame(expected: 'Person', actual: $schema['properties']['person']['$ref']);
		foreach ($schema['properties'] as $name => $property) {
			self::assertNotSame(expected: '', actual: (string)($property['title'] ?? ''), message: $name . ' has no title');
		}

		self::assertSame(expected: [], actual: self::privateLookingFields(properties: $schema['properties']));

	}//end testTheAncillaryPositionCarriesItsFields()

	/**
	 * A declaration without its organisation, role, paid flag or person is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-001-nevenfunctie-schema-on-openregister
	 */
	public function testAnIncompleteDeclarationIsRefused(): void {
		$complete = [
			'person'         => 'person-1',
			'governanceBody' => 'body-1',
			'organisation'   => 'Stichting Welzijn',
			'role'           => 'Bestuurslid',
			'remunerated'    => false,
			'startDate'      => '2024-03-01',
			'declaredAt'     => '2024-03-05',
			'lifecycle'      => 'reported',
		];
		self::assertSame(expected: [], actual: MergedRegisterSchema::violations(slug: 'ancillary-position', payload: $complete));

		foreach (['organisation', 'role', 'remunerated', 'person'] as $missing) {
			$refused = MergedRegisterSchema::violations(slug: 'ancillary-position', payload: array_diff_key($complete, [$missing => true]));
			self::assertContains(needle: 'missing required property ' . $missing, haystack: $refused);
		}

	}//end testAnIncompleteDeclarationIsRefused()

	/**
	 * Declared, then public or internal, then ended; ended is final.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative
	 */
	public function testTheDisclosureLifecycleIsDeclared(): void {
		$schema    = MergedRegisterSchema::forSlug(slug: 'ancillary-position');
		$lifecycle = $schema['x-openregister-lifecycle'];

		self::assertSame(expected: 'reported', actual: $lifecycle['initial']);
		self::assertArrayNotHasKey(key: 'initialState', array: $lifecycle);
		self::assertSame(expected: ['reported', 'public', 'internal', 'ended'], actual: $lifecycle['states']);
		self::assertSame(expected: ['ended'], actual: $lifecycle['terminal']);
		self::assertSame(expected: [], actual: array_values(array_filter($lifecycle['transitions'], static fn (array $t): bool => $t['from'] === 'ended')));
		self::assertContains(needle: ['from' => 'reported', 'to' => 'public'], haystack: $lifecycle['transitions']);
		self::assertContains(needle: ['from' => 'reported', 'to' => 'internal'], haystack: $lifecycle['transitions']);
		self::assertSame(expected: $lifecycle['states'], actual: $schema['properties']['lifecycle']['enum']);

	}//end testTheDisclosureLifecycleIsDeclared()

	/**
	 * A declared gift records the giver, its value and what was decided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-005-geschenk-schema-on-openregister
	 */
	public function testTheDeclaredGiftCarriesItsFields(): void {
		$schema = MergedRegisterSchema::forSlug(slug: 'declared-gift');

		self::assertSame(expected: 'schema:Action', actual: $schema['x-schema-org']);
		foreach (['recipient', 'governanceBody', 'type', 'giver', 'estimatedValue', 'decision'] as $field) {
			self::assertContains(needle: $field, haystack: $schema['required']);
		}

		self::assertSame(expected: ['accepted', 'refused', 'transferred'], actual: $schema['properties']['decision']['enum']);
		self::assertSame(expected: 'number', actual: $schema['properties']['estimatedValue']['type']);
		self::assertSame(expected: [], actual: self::privateLookingFields(properties: $schema['properties']));

	}//end testTheDeclaredGiftCarriesItsFields()

	/**
	 * Both disclosures are anonymously readable only once published; signed-in
	 * users read them all. No unconditional public read exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-004-public-nevenfunctiesregister-via-the-or-published-predicate-on-the-live-object
	 */
	public function testThePublicReadsOnlyPublishedDisclosures(): void {
		foreach (['ancillary-position', 'declared-gift'] as $slug) {
			$read = MergedRegisterSchema::forSlug(slug: $slug)['authorization']['read'];

			self::assertContains(needle: self::PUBLIC_READ, haystack: $read, message: $slug);
			self::assertContains(needle: 'authenticated', haystack: $read, message: $slug);
			self::assertNotContains(needle: 'public', haystack: $read, message: $slug);
		}

	}//end testThePublicReadsOnlyPublishedDisclosures()

	/**
	 * The Dutch-named schemas are retired, not deleted, and the per-body
	 * integrity policy lives on the body's governance configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-003-per-body-integrity-policy-with-body-type-defaults
	 */
	public function testTheOldSchemasAreRetiredAndThePolicyFoldsIn(): void {
		foreach (['nevenfunctie', 'geschenk', 'integriteitsbeleid'] as $slug) {
			$schema = MergedRegisterSchema::forSlug(slug: $slug);
			self::assertFalse(condition: $schema['x-openregister']['active'], message: $slug);
			self::assertFalse(condition: $schema['x-openregister']['hardDelete'], message: $slug);
		}

		$configuration = MergedRegisterSchema::forSlug(slug: 'body-governance-configuration')['properties'];
		foreach (['ancillaryPositionDisclosureDefault', 'giftThresholdAmount', 'giftsPublic', 'integrityNotificationGroup'] as $field) {
			self::assertArrayHasKey(key: $field, array: $configuration);
		}

	}//end testTheOldSchemasAreRetiredAndThePolicyFoldsIn()

	/**
	 * Across the example sets: two policies (the council public with a EUR 50
	 * threshold, the supervisory board internal with EUR 100), the five kinds
	 * of ancillary position and the three gift decisions, each fitting its
	 * schema; nothing internal or merely declared is published, and one
	 * review is stale so the dashboard has something to show.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/tasks.md#task-3-seed-data--policy-nevenfuncties-and-geschenken-across-both-seeded-bodies
	 */
	public function testTheExampleSetsSeedEveryKindOfDisclosure(): void {
		$positions = self::seeded(slug: 'ancillary-position');
		$gifts     = self::seeded(slug: 'declared-gift');
		$policies  = array_column(self::seeded(slug: 'body-governance-configuration'), null, 'governanceBody');

		self::assertSame(expected: ['public', 50, true], actual: self::policyOf(policy: ($policies['gemeenteraad-amsterdam'] ?? [])));
		self::assertSame(expected: ['internal', 100, false], actual: self::policyOf(policy: ($policies['rvc-waterschap-amstel'] ?? [])));

		$kinds = array_column($positions, 'lifecycle');
		if (in_array(true, array_column($positions, 'qualitateQua'), true) === true) {
			$kinds[] = 'q.q.';
		}
		foreach (['public', 'q.q.', 'reported', 'internal', 'ended'] as $kind) {
			self::assertContains(needle: $kind, haystack: $kinds);
		}

		$decisions = array_column($gifts, 'estimatedValue', 'decision');
		self::assertLessThan(expected: 50, actual: $decisions['accepted']);
		self::assertGreaterThanOrEqual(expected: 50, actual: $decisions['refused']);
		self::assertArrayHasKey(key: 'transferred', array: $decisions);

		foreach ($positions as $row) {
			$payload = array_diff_key($row, ['@self' => true, 'slug' => true]);
			self::assertSame(expected: [], actual: self::requiredMissing(slug: 'ancillary-position', payload: $payload), message: $row['slug']);
			if (in_array($row['lifecycle'], ['reported', 'internal'], true) === true) {
				self::assertEmpty(actual: ($row['publicationDate'] ?? null), message: $row['slug'] . ' would be public through the predicate');
			}
		}

		foreach ($gifts as $row) {
			$payload = array_diff_key($row, ['@self' => true, 'slug' => true]);
			self::assertSame(expected: [], actual: self::requiredMissing(slug: 'declared-gift', payload: $payload), message: $row['slug']);
		}

		$yearAgo = (new DateTimeImmutable('today -12 months'))->format('Y-m-d');
		$stale   = array_filter(
			$positions,
			static fn (array $row): bool => $row['lifecycle'] !== 'ended' && (string)($row['reviewedAt'] ?? $row['declaredAt']) < $yearAgo
		);
		self::assertNotSame(expected: [], actual: $stale, message: 'no stale review for the dashboard to count');

	}//end testTheExampleSetsSeedEveryKindOfDisclosure()

	/**
	 * Property names that look like pay, private contact data or internal remarks.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return list<string>
	 */
	private static function privateLookingFields(array $properties): array {
		return array_values(
			array_filter(
				array_keys($properties),
				static fn (string $name): bool => preg_match('/salary|remunerationAmount|amountPaid|phone|email|address|internalRemark|privateNote/i', $name) > 0
			)
		);

	}//end privateLookingFields()

	/**
	 * The required-property refusals of a payload.
	 *
	 * @param string               $slug    The schema slug.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return list<string>
	 */
	private static function requiredMissing(string $slug, array $payload): array {
		return array_values(
			array_filter(
				MergedRegisterSchema::violations(slug: $slug, payload: $payload),
				static fn (string $line): bool => str_starts_with($line, 'missing required') === true
			)
		);

	}//end requiredMissing()

	/**
	 * A policy as [disclosure default, gift threshold, gifts public].
	 *
	 * @param array<string, mixed> $policy The seeded governance configuration.
	 *
	 * @return array{0: mixed, 1: mixed, 2: mixed}
	 */
	private static function policyOf(array $policy): array {
		return [$policy['ancillaryPositionDisclosureDefault'] ?? null, $policy['giftThresholdAmount'] ?? null, $policy['giftsPublic'] ?? null];

	}//end policyOf()

	/**
	 * Every object of a schema across the example sets.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function seeded(string $slug): array {
		$rows = [];
		foreach (glob(__DIR__ . '/../../../lib/Settings/profiles/*.json') as $file) {
			$profile = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach (($profile['x-openregister']['seedData']['objects'][$slug] ?? []) as $row) {
				$rows[] = $row;
			}
		}

		return $rows;

	}//end seeded()

}//end class
