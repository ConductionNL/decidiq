<?php

/**
 * An AI summary of a meeting paper is a record with a review status: the
 * lifecycle is declared, members read shown summaries only, and the
 * municipality example set seeds one shown summary and one draft comparison
 * (agenda-ai-paper-summaries task 1).
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
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-001-an-ai-summary-is-a-record-with-a-review-status
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Reads the merged register and the municipality profile from disk.
 */
class PaperSummaryRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const WRITERS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * The schema with every fragment that declares it merged in.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed> The merged schema.
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
	 * The seed objects of the municipality profile, keyed by schema.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The seeds.
	 */
	private function seeds(): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The schema exists with the design's properties and is listed on the register.
	 *
	 * @return void
	 */
	public function testThePaperSummarySchemaIsDeclared(): void {
		$schema = $this->mergedSchema('paper-summary');
		self::assertNotSame([], $schema, 'paper-summary exists in the merged register');
		self::assertSame('schema:CreativeWork', ($schema['x-schema-org'] ?? null));
		self::assertSame(['agendaItem', 'paperFileId', 'kind', 'status'], ($schema['required'] ?? []));
		self::assertSame('agenda-item', ($schema['properties']['agendaItem']['$ref'] ?? null));
		self::assertSame(['summary', 'comparison'], ($schema['properties']['kind']['enum'] ?? null));
		self::assertSame(['requested', 'draft', 'shown', 'hidden', 'failed'], ($schema['properties']['status']['enum'] ?? null));
		foreach (['paperTitle', 'comparedFileId', 'comparedTitle', 'text', 'provider', 'taskId', 'chunked', 'reviewedBy', 'reviewedAt'] as $property) {
			self::assertArrayHasKey($property, $schema['properties']);
		}

		foreach ($schema['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), $key . ' has a title');
		}

		$listed = [];
		foreach ((glob(self::SETTINGS . 'register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$listed = array_merge($listed, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		self::assertContains('paper-summary', $listed, 'the decidiq register lists paper-summary');
	}//end testThePaperSummarySchemaIsDeclared()

	/**
	 * The review states are a declared transition map; a draft cannot go back
	 * to requested, and nothing leaves failed.
	 *
	 * @return void
	 */
	public function testTheReviewStatesAreADeclaredLifecycle(): void {
		$lifecycle = $this->mergedSchema('paper-summary')['x-openregister-lifecycle'] ?? [];
		self::assertSame('status', ($lifecycle['field'] ?? null));
		self::assertSame('requested', ($lifecycle['initial'] ?? null));
		$pairs = array_map(static fn (array $t): string => $t['from'] . '>' . $t['to'], ($lifecycle['transitions'] ?? []));
		sort($pairs);
		self::assertSame(
			['draft>hidden', 'draft>shown', 'hidden>shown', 'requested>draft', 'requested>failed', 'shown>hidden'],
			$pairs
		);
		self::assertNotContains('draft>requested', $pairs, 'a draft cannot be saved back to requested');
		self::assertSame(['failed'], ($lifecycle['terminal'] ?? null));
	}//end testTheReviewStatesAreADeclaredLifecycle()

	/**
	 * Members read shown summaries only; the secretariat and administrators read and write all.
	 *
	 * @return void
	 */
	public function testMembersReadShownSummariesOnly(): void {
		$auth = $this->mergedSchema('paper-summary')['authorization'] ?? [];
		self::assertSame(['create', 'read', 'update', 'delete'], array_keys($auth));
		self::assertNotContains('authenticated', $auth['read']);
		self::assertNotContains('decidesk-members', $auth['read'], 'members never read every summary');
		$groups = array_values(array_filter($auth['read'], 'is_string'));
		$rules = array_values(array_filter($auth['read'], 'is_array'));
		self::assertSame(self::WRITERS, $groups);
		self::assertSame([['group' => 'decidesk-members', 'match' => ['status' => 'shown']]], $rules);
		foreach (['create', 'update', 'delete'] as $verb) {
			self::assertSame(self::WRITERS, $auth[$verb], $verb . ' is the secretariat and administrators only');
		}
	}//end testMembersReadShownSummariesOnly()

	/**
	 * One shown summary and one draft comparison on the budget item, both on
	 * papers that item really has, and both valid against the schema.
	 *
	 * @return void
	 */
	public function testTheMunicipalitySetSeedsAShownSummaryAndADraftComparison(): void {
		$seeds = $this->seeds();
		$items = array_column($seeds['agenda-item'], null, 'slug');
		$people = array_column($seeds['person'], null, 'slug');
		$summaries = array_column(($seeds['paper-summary'] ?? []), null, 'kind');
		self::assertCount(2, ($seeds['paper-summary'] ?? []));

		$shown = $summaries['summary'] ?? [];
		self::assertSame('shown', ($shown['status'] ?? null));
		self::assertContains(($shown['reviewedBy'] ?? ''), array_column($people, 'name'), 'the reviewer is a seeded person, by name as the widget shows it');
		self::assertNotSame('', (string)($shown['reviewedAt'] ?? ''));

		$draft = $summaries['comparison'] ?? [];
		self::assertSame('draft', ($draft['status'] ?? null));
		self::assertArrayNotHasKey('reviewedBy', $draft, 'the draft is not reviewed yet');
		self::assertNotSame(($draft['paperFileId'] ?? null), ($draft['comparedFileId'] ?? null), 'a comparison names two papers');

		foreach ([$shown, $draft] as $summary) {
			$item = $items[$summary['agendaItem']] ?? null;
			self::assertNotNull($item, 'the summary names a seeded agenda item');
			$papers = array_merge(
				array_column(($item['paperRenditions'] ?? []), 'sourceFileId'),
				array_column(array_filter($seeds['digital-document'], static fn (array $d): bool => ($d['agendaItem'] ?? null) === $item['slug']), 'fileId')
			);
			self::assertContains($summary['paperFileId'], $papers, 'the summarised paper is attached to that item');
			self::assertNotSame('', (string)($summary['text'] ?? ''));
			$this->assertValidSeed($summary);
		}
	}//end testTheMunicipalitySetSeedsAShownSummaryAndADraftComparison()

	/**
	 * Assert a seed validates against the merged schema. Relations are seeded
	 * by slug, which OpenRegister's import resolves to a uuid, so their uuid
	 * format is not checked here.
	 *
	 * @param array<string, mixed> $object The seed
	 *
	 * @return void
	 */
	private function assertValidSeed(array $object): void {
		$schema = $this->mergedSchema('paper-summary');
		unset($object['@self'], $object['slug']);
		$properties = [];
		foreach ($schema['properties'] as $key => $property) {
			if (isset($property['$ref']) === true) {
				unset($property['format']);
			}

			unset($property['$ref'], $property['facetable']);
			$properties[$key] = $property;
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($object)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), sprintf('The paper-summary seed validates: %s', json_encode($object)));
	}//end assertValidSeed()
}//end class
