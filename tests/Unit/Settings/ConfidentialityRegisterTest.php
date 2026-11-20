<?php

/**
 * The confidentiality register: restrictions and their grounds, the embargo
 * fields on a paper, the guard on lifting before ratification, and what the
 * example sets seed for them.
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

use OCA\Decidiq\Listener\LiftAfterRatificationGuard;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * Register and seed tests for embargo-geheimhouding tasks 1 and 2.
 *
 * @spec openspec/changes/embargo-geheimhouding/tasks.md#task-1-register-fragment-65--schemas--declarative-dialects--embargo-properties
 */
class ConfidentialityRegisterTest extends TestCase {

	/**
	 * A restriction records its ground, who imposed it, when, and on what.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testARestrictionCarriesItsGroundAndTarget(): void {
		$schema     = MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction');
		$properties = $schema['properties'];

		foreach (['scope', 'ground', 'imposedBy', 'imposedByBody', 'imposedAt', 'lifecycle'] as $field) {
			self::assertContains(needle: $field, haystack: $schema['required']);
		}

		self::assertSame(expected: 'confidentiality-ground', actual: $properties['ground']['$ref']);
		self::assertSame(expected: ['document', 'item', 'decision'], actual: $properties['scope']['enum']);
		self::assertSame(expected: 'digital-document', actual: $properties['targetDocument']['$ref']);
		self::assertSame(expected: 'agenda-item', actual: $properties['targetAgendaItem']['$ref']);
		self::assertSame(expected: 'Decision', actual: $properties['targetDecision']['$ref']);

	}//end testARestrictionCarriesItsGroundAndTarget()

	/**
	 * Imposed, then ratified, then lifted, with the direct lift and the lapse;
	 * lifted and lapsed are final.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testTheLifecycleIsDeclaredInTheCanonicalDialect(): void {
		$lifecycle = MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction')['x-openregister-lifecycle'];

		self::assertSame(expected: 'lifecycle', actual: $lifecycle['field']);
		self::assertSame(expected: 'imposed', actual: $lifecycle['initial']);
		self::assertArrayNotHasKey(key: 'initialState', array: $lifecycle);
		self::assertEqualsCanonicalizing(expected: ['lapsed', 'dissolved'], actual: $lifecycle['terminal']);
		foreach ([['imposed', 'ratified'], ['ratified', 'dissolved'], ['imposed', 'dissolved']] as [$from, $to]) {
			self::assertContains(needle: ['from' => $from, 'to' => $to], haystack: $lifecycle['transitions']);
		}

		$fromTerminal = array_filter($lifecycle['transitions'], static fn (array $t): bool => in_array($t['from'], $lifecycle['terminal'], true));
		self::assertSame(expected: [], actual: array_values($fromTerminal));

	}//end testTheLifecycleIsDeclaredInTheCanonicalDialect()

	/**
	 * The direct lift is guarded on every restriction update, because the
	 * map cannot read whether the ground needs ratification.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testTheDirectLiftIsGuardedOnUpdate(): void {
		self::assertContains(
			needle: ['event' => ObjectUpdatingEvent::class, 'listener' => LiftAfterRatificationGuard::class, 'schemas' => ['confidentiality-restriction']],
			haystack: SaveGuardSubscriptions::ALL
		);

	}//end testTheDirectLiftIsGuardedOnUpdate()

	/**
	 * Ratification coming due and the lift are announced, in Dutch and English.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	public function testRatificationAndLiftAreAnnounced(): void {
		$rules = MergedRegisterSchema::forSlug(slug: 'confidentiality-restriction')['x-openregister-notifications'];

		self::assertSame(expected: ['lifecycle' => 'imposed'], actual: $rules['geheimhoudingBekrachtigingDue']['trigger']['filter']);
		self::assertSame(expected: 'dissolved', actual: $rules['geheimhoudingOpgeheven']['trigger']['condition']['value']);
		foreach ($rules as $name => $rule) {
			self::assertNotSame(expected: '', actual: (string)($rule['subject']['nl'] ?? ''), message: $name);
			self::assertNotSame(expected: '', actual: (string)($rule['subject']['en'] ?? ''), message: $name);
		}

	}//end testRatificationAndLiftAreAnnounced()

	/**
	 * A ground names its citation, the pre-2023 article when there was one,
	 * a category and whether ratification is needed; the Dutch-named schemas
	 * are retired, not deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-002-configurable-ground-list-with-dual-gemeentewet-article-labels
	 */
	public function testAGroundIsConfigurableData(): void {
		$ground = MergedRegisterSchema::forSlug(slug: 'confidentiality-ground');

		self::assertSame(expected: ['name', 'citation', 'category', 'requiresRatification', 'active'], actual: $ground['required']);
		self::assertArrayHasKey(key: 'legacyCitation', array: $ground['properties']);
		self::assertArrayHasKey(key: 'builtIn', array: $ground['properties']);

		foreach (['geheimhouding', 'geheimhouding-grond'] as $slug) {
			$retired = MergedRegisterSchema::forSlug(slug: $slug);
			self::assertFalse(condition: $retired['x-openregister']['active'], message: $slug);
			self::assertFalse(condition: $retired['x-openregister']['hardDelete'], message: $slug);
		}

	}//end testAGroundIsConfigurableData()

	/**
	 * The embargo fields on a paper are optional, so every existing paper
	 * still validates; a paper is not under embargo unless set so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/tasks.md#task-1-register-fragment-65--schemas--declarative-dialects--embargo-properties
	 */
	public function testTheEmbargoFieldsAreAdditive(): void {
		$schema = MergedRegisterSchema::forSlug(slug: 'digital-document');

		self::assertSame(expected: [], actual: array_intersect(['embargoUntil', 'embargoActive', 'embargoAudience'], $schema['required']));
		self::assertSame(expected: 'date-time', actual: $schema['properties']['embargoUntil']['format']);
		self::assertFalse(condition: $schema['properties']['embargoActive']['default']);

		foreach (self::seeded(slug: 'digital-document') as $paper) {
			$payload = array_diff_key($paper, ['@self' => true, 'slug' => true]);
			$refused = array_filter(
				MergedRegisterSchema::violations(slug: 'digital-document', payload: $payload),
				static fn (string $line): bool => str_contains($line, 'embargo') === true || str_starts_with($line, 'missing required') === true
			);
			self::assertSame(expected: [], actual: array_values($refused), message: $paper['slug']);
		}

	}//end testTheEmbargoFieldsAreAdditive()

	/**
	 * Across the example sets: eight grounds with the pre-2023 Gemeentewet
	 * articles, both Woo categories and one other statutory ground, all built
	 * in; restrictions imposed, lifted and lapsed; and one paper still under
	 * embargo next to one released.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/tasks.md#task-2-seed-data--grounds-with-dual-article-labels--example-geheimhoudingen--embargo-documents
	 */
	public function testTheExampleSetsSeedEveryState(): void {
		$grounds = self::seeded(slug: 'confidentiality-ground');

		self::assertCount(expectedCount: 8, haystack: $grounds);
		self::assertSame(expected: [true], actual: array_values(array_unique(array_column($grounds, 'builtIn'))));
		$legacy = array_filter(array_column($grounds, 'legacyCitation'));
		foreach (['voorheen art. 25', 'voorheen art. 55', 'voorheen art. 86'] as $article) {
			self::assertContains(needle: $article, haystack: $legacy);
		}

		$categories = array_column($grounds, 'category');
		foreach (['woo-absolute', 'woo-relative', 'other'] as $category) {
			self::assertContains(needle: $category, haystack: $categories);
		}

		$states = array_column(self::seeded(slug: 'confidentiality-restriction'), 'lifecycle');
		foreach (['imposed', 'dissolved', 'lapsed'] as $state) {
			self::assertContains(needle: $state, haystack: $states);
		}

		$now       = date(DATE_ATOM);
		$embargoed = array_filter(self::seeded(slug: 'digital-document'), static fn (array $paper): bool => isset($paper['embargoUntil']) === true);
		$locked    = array_filter($embargoed, static fn (array $paper): bool => $paper['embargoActive'] === true && $paper['embargoUntil'] > $now);
		$released  = array_filter($embargoed, static fn (array $paper): bool => $paper['embargoActive'] === false && $paper['embargoUntil'] < $now);
		self::assertCount(expectedCount: 1, haystack: $locked);
		self::assertCount(expectedCount: 1, haystack: $released);

	}//end testTheExampleSetsSeedEveryState()

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
