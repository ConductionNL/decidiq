<?php

/**
 * A position holder's remuneration is recorded per year, read only by the
 * secretariat and administrators until it is disclosed, seeded on the
 * supervisory board, and shown on the body and position hold pages
 * (bodies-director-remuneration tasks 1 to 3).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bodies-director-remuneration/specs/governance-bodies/spec.md#requirement-req-drm-001-a-position-holders-remuneration-is-recorded-per-year
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class MandateRemunerationRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const SRC = __DIR__ . '/../../../src/';

	private const WRITERS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * A schema as the merged register has it.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$files = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * An example set's seed objects, by schema slug.
	 *
	 * @param string $profile The profile file name without extension
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function seeds(string $profile): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/' . $profile . '.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * One manifest page by id, from the main manifest or a fragment.
	 *
	 * @param string $id The page id
	 *
	 * @return array<string, mixed>
	 */
	private function page(string $id): array {
		foreach (array_merge([self::SRC . 'manifest.json'], (glob(self::SRC . 'manifest.d/*.json') ?: [])) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['pages'] ?? []) as $page) {
				if (($page['id'] ?? null) === $id) {
					return $page;
				}
			}
		}

		return [];
	}//end page()

	/**
	 * A widget of a page by id, and whether the layout places it.
	 *
	 * @param array<string, mixed> $page The page
	 * @param string               $id   The widget id
	 *
	 * @return array<string, mixed>
	 */
	private function placedWidget(array $page, string $id): array {
		$placed = array_column(($page['config']['layout'] ?? []), 'widgetId');
		self::assertContains($id, $placed, sprintf('The layout of %s places %s', ($page['id'] ?? '?'), $id));
		foreach (($page['config']['widgets'] ?? []) as $widget) {
			if (($widget['id'] ?? null) === $id) {
				return $widget;
			}
		}

		self::fail(sprintf('%s has no widget %s', ($page['id'] ?? '?'), $id));
	}//end placedWidget()

	/**
	 * The schema exists with its relations, the required fields, and a title
	 * on every property (design, Schema).
	 *
	 * @return void
	 */
	public function testTheRemunerationSchemaIsDeclared(): void {
		$schema = $this->mergedSchema('mandate-remuneration');
		self::assertNotSame([], $schema, 'mandate-remuneration exists in the merged register');
		self::assertSame('schema:MonetaryAmount', ($schema['x-schema-org'] ?? null));
		self::assertSame(['positionHold', 'person', 'governanceBody', 'year'], ($schema['required'] ?? []));
		$refs = ['positionHold' => 'position-hold', 'person' => 'person', 'governanceBody' => 'governance-body', 'setByDecision' => 'decision'];
		foreach ($refs as $property => $target) {
			self::assertSame($target, ($schema['properties'][$property]['$ref'] ?? null), $property . ' points at ' . $target);
		}

		foreach (['year', 'fixedFee', 'meetingFee', 'expenseAllowance', 'currency', 'disclosed', 'publicationDate', 'note'] as $property) {
			self::assertArrayHasKey($property, $schema['properties']);
		}

		foreach ($schema['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), $key . ' has a title');
		}

		self::assertSame('EUR', ($schema['properties']['currency']['default'] ?? null));
		self::assertFalse(($schema['properties']['disclosed']['default'] ?? null));
	}//end testTheRemunerationSchemaIsDeclared()

	/**
	 * Members do not read each other's pay unless it is disclosed: the read
	 * rule names the secretariat, the administrators and a public rule on
	 * disclosed records whose publication date has passed, never
	 * `authenticated`; only the secretariat and administrators write.
	 *
	 * @return void
	 */
	public function testOnlyTheSecretariatReadsUndisclosedPay(): void {
		$auth = $this->mergedSchema('mandate-remuneration')['authorization'] ?? [];
		self::assertSame(['create', 'read', 'update', 'delete'], array_keys($auth));
		self::assertNotContains('authenticated', $auth['read']);
		self::assertNotContains('public', $auth['read']);
		$public = [];
		$groups = [];
		foreach ($auth['read'] as $rule) {
			if (is_array($rule) === true) {
				$public[] = $rule;
				continue;
			}

			$groups[] = $rule;
		}

		self::assertSame(self::WRITERS, $groups);
		self::assertSame([['group' => 'public', 'match' => ['disclosed' => true, 'publicationDate' => ['$lte' => '$now']]]], $public);
		foreach (['create', 'update', 'delete'] as $verb) {
			self::assertSame(self::WRITERS, $auth[$verb], $verb . ' is the secretariat and administrators only');
		}
	}//end testOnlyTheSecretariatReadsUndisclosedPay()

	/**
	 * The supervisory board of the corporate example carries this year's two
	 * disclosed records, 56000 EUR in fixed fees, each on a seeded position
	 * hold of that board; the association's treasurer has an undisclosed
	 * allowance; every seed validates against its merged schema.
	 *
	 * @return void
	 */
	public function testTheExampleSetsSeedTheDesignsRecords(): void {
		$corporate = $this->seeds('corporate');
		$holds = array_column(($corporate['position-hold'] ?? []), null, 'slug');
		$memberships = array_column($corporate['membership'], null, 'slug');
		$board = array_values(array_filter(($corporate['mandate-remuneration'] ?? []), static fn (array $r): bool => ($r['governanceBody'] ?? null) === 'rvc-waterschap-amstel' && ($r['year'] ?? null) === 2026));
		self::assertCount(2, $board);
		self::assertSame(56000, array_sum(array_column($board, 'fixedFee')));
		foreach ($board as $record) {
			self::assertSame('EUR', $record['currency']);
			self::assertTrue($record['disclosed']);
			self::assertSame('2026-04-30', $record['publicationDate']);
			$hold = $holds[$record['positionHold']] ?? null;
			self::assertNotNull($hold, 'the record names a seeded position hold');
			self::assertSame('rvc-waterschap-amstel', $hold['governanceBody']);
			self::assertSame($memberships[$hold['membership']]['person'], $record['person'], 'the record names the hold\'s person');
		}

		$treasurer = $this->seeds('association')['mandate-remuneration'] ?? [];
		self::assertCount(1, $treasurer);
		self::assertFalse($treasurer[0]['disclosed']);
		self::assertSame(600, $treasurer[0]['expenseAllowance']);

		foreach (['corporate', 'association'] as $profile) {
			foreach (['mandate-remuneration', 'position-hold', 'position-type', 'decision'] as $slug) {
				foreach (($this->seeds($profile)[$slug] ?? []) as $object) {
					if (str_contains((string)($object['slug'] ?? ''), 'bezoldiging') === true || $slug === 'mandate-remuneration' || $slug === 'position-hold') {
						$this->assertValidSeed(slug: $slug, object: $object);
					}
				}
			}
		}
	}//end testTheExampleSetsSeedTheDesignsRecords()

	/**
	 * The body page lists this year's records and their total; the position
	 * hold page lists every year, newest first (tasks 2 and 3).
	 *
	 * @return void
	 */
	public function testTheBodyAndHoldPagesShowTheRemuneration(): void {
		$body = $this->page('GovernanceBodyDetail');
		$list = $this->placedWidget($body, 'body-remuneration');
		self::assertSame('object-list', $list['type']);
		self::assertSame('mandate-remuneration', $list['content']['schema']);
		self::assertSame(['governanceBody' => '@objectId', 'year' => '@currentFiscalYear'], $list['content']['filter']);
		self::assertSame(['person', 'fixedFee', 'meetingFee', 'expenseAllowance', 'currency', 'disclosed'], array_column($list['content']['columns'], 'key'));

		$total = $this->placedWidget($body, 'body-remuneration-total');
		$entry = $total['content']['entries'][0] ?? [];
		self::assertSame(['sum', 'fixedFee', 'currency'], [$entry['metric'] ?? null, $entry['field'] ?? null, $entry['format'] ?? null]);
		self::assertSame(['governanceBody' => '@objectId', 'year' => '@currentFiscalYear', 'currency' => 'EUR'], $entry['filter']);

		$hold = $this->placedWidget($this->page('PositionHoldDetail'), 'hold-remuneration');
		self::assertSame(['positionHold' => '@objectId'], $hold['content']['filter']);
		self::assertSame(['field' => 'year', 'dir' => 'desc'], $hold['content']['sort']);
	}//end testTheBodyAndHoldPagesShowTheRemuneration()

	/**
	 * Assert a seed validates against the merged schema. Relations are seeded
	 * by slug, which OpenRegister's import resolves to a uuid, so their uuid
	 * format is not checked here.
	 *
	 * @param string               $slug   The schema slug
	 * @param array<string, mixed> $object The seed
	 *
	 * @return void
	 */
	private function assertValidSeed(string $slug, array $object): void {
		$schema = $this->mergedSchema($slug);
		unset($object['@self'], $object['slug']);
		$properties = [];
		foreach ($schema['properties'] as $key => $property) {
			if (isset($property['$ref']) === true) {
				unset($property['format']);
			}

			unset($property['$ref'], $property['facetable'], $property['items']['$ref']);
			$properties[$key] = $property;
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($object)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), sprintf('The %s seed validates: %s', $slug, json_encode($object)));
	}//end assertValidSeed()
}//end class
