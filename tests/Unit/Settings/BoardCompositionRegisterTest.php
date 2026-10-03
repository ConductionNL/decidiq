<?php

/**
 * The register declares the competences a body needs, the competences its
 * members hold and the body's diversity targets, and the corporate example
 * set seeds them on the supervisory board (bodies-board-composition-skills-
 * and-diversity task 1).
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
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
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
class BoardCompositionRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * The verbs OpenRegister's authorization block knows; any other key makes
	 * the importer skip the schema.
	 */
	private const VERBS = ['create', 'read', 'update', 'delete'];

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
	 * The corporate example set's seed objects, by schema slug.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function corporateSeeds(): array {
		$profile = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/corporate.json'), true);
		return $profile['x-openregister']['seedData']['objects'];
	}//end corporateSeeds()

	/**
	 * Both schemas exist with their relations, the level enum and a title on
	 * every property; the body gains its targets (design D1, D4).
	 *
	 * @return void
	 */
	public function testTheCompetenceSchemasAndTheTargetsAreDeclared(): void {
		$competence = $this->mergedSchema('board-competence');
		$held       = $this->mergedSchema('member-competence');
		$body       = $this->mergedSchema('governance-body');

		self::assertSame(['governanceBody', 'name'], $competence['required'] ?? null);
		self::assertSame('governance-body', $competence['properties']['governanceBody']['$ref'] ?? null);
		self::assertSame(1, $competence['properties']['requiredHolders']['minimum'] ?? null);
		self::assertSame(1, $competence['properties']['requiredHolders']['default'] ?? null);
		self::assertTrue($competence['properties']['active']['default'] ?? null);

		self::assertSame(['membership', 'competence', 'level'], $held['required'] ?? null);
		self::assertSame('membership', $held['properties']['membership']['$ref'] ?? null);
		self::assertSame('board-competence', $held['properties']['competence']['$ref'] ?? null);
		self::assertSame(['basic', 'experienced', 'expert'], $held['properties']['level']['enum'] ?? null);
		self::assertSame('date-time', $held['properties']['confirmedAt']['format'] ?? null);

		$targets = ($body['properties']['diversityTargets'] ?? []);
		self::assertSame('array', $targets['type'] ?? null);
		self::assertSame(['gender', 'age-band', 'nationality', 'independence'], $targets['items']['properties']['dimension']['enum'] ?? null);
		self::assertSame(1, $targets['items']['properties']['minimumShare']['maximum'] ?? null);

		foreach (['board-competence' => $competence, 'member-competence' => $held] as $slug => $schema) {
			foreach ($schema['properties'] as $key => $property) {
				self::assertNotSame('', (string)($property['title'] ?? ''), sprintf('%s.%s has a title', $slug, $key));
			}
		}

		foreach ($targets['items']['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), sprintf('diversityTargets.%s has a title', $key));
		}
	}//end testTheCompetenceSchemasAndTheTargetsAreDeclared()

	/**
	 * The authorization blocks use only the four verbs, and leave the writes
	 * to MemberCompetenceController (design D2): the object API cannot set
	 * confirmedBy around the confirm check.
	 *
	 * @return void
	 */
	public function testTheBlocksUseOnlyTheFourVerbsAndKeepWritesClosed(): void {
		foreach (['board-competence', 'member-competence'] as $slug) {
			$block = ($this->mergedSchema($slug)['authorization'] ?? null);
			self::assertIsArray($block, $slug . ' declares an authorization block');
			self::assertSame([], array_diff(array_keys($block), self::VERBS), $slug . ' uses only create, read, update and delete');
			self::assertSame(['authenticated'], $block['read']);
			self::assertArrayNotHasKey('create', $block, $slug . ' leaves create to the controller');
			self::assertArrayNotHasKey('update', $block, $slug . ' leaves update to the controller');
		}
	}//end testTheBlocksUseOnlyTheFourVerbsAndKeepWritesClosed()

	/**
	 * The corporate example set seeds four competences, three member
	 * competences and the gender target on the supervisory board, and every
	 * seed validates against the merged schema.
	 *
	 * @return void
	 */
	public function testTheCorporateBoardIsSeededAsTheDesignSays(): void {
		$seeds = $this->corporateSeeds();

		$competences = array_values(array_filter(($seeds['board-competence'] ?? []), static fn (array $row): bool => ($row['governanceBody'] ?? '') === 'rvc-waterschap-amstel'));
		self::assertSame(
			['Finance and audit', 'Water management', 'Legal', 'IT and cybersecurity'],
			array_column($competences, 'name')
		);

		$held = ($seeds['member-competence'] ?? []);
		self::assertCount(3, $held);
		$bySlug = array_column($competences, null, 'slug');
		$rows = [];
		foreach ($held as $row) {
			$rows[] = [$row['membership'], $bySlug[$row['competence']]['name'] ?? '?', $row['level'], isset($row['confirmedAt'])];
		}

		self::assertSame(
			[
				['m-janneke-rvc', 'Finance and audit', 'expert', true],
				['m-jan-amstel', 'Legal', 'experienced', true],
				['m-mark-rvb', 'Water management', 'expert', false],
			],
			$rows
		);

		$board = array_values(array_filter($seeds['governance-body'], static fn (array $row): bool => ($row['slug'] ?? '') === 'rvc-waterschap-amstel'))[0];
		self::assertSame([['dimension' => 'gender', 'value' => 'female', 'minimumShare' => 0.33]], $board['diversityTargets'] ?? null);

		// The three seats are current, so the matrix and the figures count
		// three members (a membership with an endDate is a past one).
		foreach ($seeds['membership'] as $membership) {
			if (($membership['governanceBody'] ?? '') === 'rvc-waterschap-amstel') {
				self::assertArrayNotHasKey('endDate', $membership, $membership['slug'] . ' is a current seat');
			}
		}

		foreach (['board-competence' => $competences, 'member-competence' => $held, 'governance-body' => [$board]] as $slug => $objects) {
			foreach ($objects as $object) {
				$this->assertValidSeed(slug: $slug, object: $object);
			}
		}
	}//end testTheCorporateBoardIsSeededAsTheDesignSays()

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
