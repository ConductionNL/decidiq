<?php

/**
 * Register and example-set tests for the member profile: a membership carries
 * a portfolio, a body decides whether its members' votes are public.
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
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-002-a-membership-carries-the-members-portfolio
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the member-profile register fragment and its seeds.
 *
 * @coversNothing
 *
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-002-a-membership-carries-the-members-portfolio
 */
class MemberProfileRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * The merged schema for a slug (base register plus every register.d fragment).
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
	 * Validation errors of an object against a merged schema (empty = valid).
	 *
	 * @param array<string, mixed> $data   The object
	 * @param array<string, mixed> $schema The merged schema
	 *
	 * @return string
	 */
	private function errors(array $data, array $schema): string {
		$properties = $schema['properties'];
		foreach ($properties as $key => $property) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable'], $properties[$key]['inversedBy']);
		}

		unset($data['@self'], $data['slug']);
		// The import resolves a reference written as a slug to the object's uuid.
		foreach ($schema['properties'] as $key => $property) {
			if (isset($property['$ref'], $data[$key]) === true && is_string($data[$key]) === true) {
				$data[$key] = '6f1c1c38-4d3c-4d0e-9a55-2b8b2b1f0e01';
			}
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties]))
		);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode((new \Opis\JsonSchema\Errors\ErrorFormatter())->format($result->error()));
	}//end errors()

	/**
	 * A profile's seed objects of one schema, by slug.
	 *
	 * @param string $profile The profile file name without extension
	 * @param string $schema  The schema slug
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function seeds(string $profile, string $schema): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/' . $profile . '.json'), true);
		$objects = [];
		foreach (($doc['x-openregister']['seedData']['objects'][$schema] ?? []) as $object) {
			$objects[$object['slug']] = $object;
		}

		return $objects;
	}//end seeds()

	/**
	 * The two new properties exist with a title, portfolio as a list of strings,
	 * publishVotingRecords as a boolean defaulting to false.
	 *
	 * @return void
	 */
	public function testThePortfolioAndThePublicationChoiceAreDeclared(): void {
		$portfolio = ($this->mergedSchema(slug: 'membership')['properties']['portfolio'] ?? []);
		self::assertSame('array', ($portfolio['type'] ?? null));
		self::assertSame('string', ($portfolio['items']['type'] ?? null));
		self::assertNotEmpty($portfolio['title'] ?? '');

		$publish = ($this->mergedSchema(slug: 'governance-body')['properties']['publishVotingRecords'] ?? []);
		self::assertSame('boolean', ($publish['type'] ?? null));
		self::assertFalse($publish['default'] ?? null);
		self::assertNotEmpty($publish['title'] ?? '');
	}//end testThePortfolioAndThePublicationChoiceAreDeclared()

	/**
	 * Scenario "An alderman's portfolio" and REQ-MPR-005: the executive board
	 * membership carries two subjects, the council membership none; the council
	 * publishes voting records, the corporate board does not. Every touched seed
	 * validates against the merged schema.
	 *
	 * @return void
	 */
	public function testTheExampleSetsShowBothCases(): void {
		$memberships = $this->seeds(profile: 'municipality', schema: 'membership');
		$college = ($memberships['m-femke-college-amsterdam'] ?? []);
		self::assertSame('college-van-b-en-w-amsterdam', ($college['governanceBody'] ?? null));
		self::assertSame('femke-halsema', ($college['person'] ?? null));
		self::assertSame(['Public order and safety', 'Communication'], ($college['portfolio'] ?? null));
		self::assertArrayNotHasKey('portfolio', $memberships['m-femke-amsterdam']);
		self::assertArrayNotHasKey('portfolio', $memberships['m-marie-amsterdam']);

		$bodies = $this->seeds(profile: 'municipality', schema: 'governance-body');
		self::assertTrue($bodies['gemeenteraad-amsterdam']['publishVotingRecords'] ?? null);
		self::assertFalse($this->seeds(profile: 'corporate', schema: 'governance-body')['raad-van-commissarissen-acme-bv']['publishVotingRecords'] ?? false);

		self::assertSame('', $this->errors(data: $college, schema: $this->mergedSchema(slug: 'membership')));
		self::assertSame('', $this->errors(data: $bodies['gemeenteraad-amsterdam'], schema: $this->mergedSchema(slug: 'governance-body')));
		self::assertNotSame('', $this->errors(data: ['role' => 'member', 'portfolio' => 'Finance'], schema: $this->mergedSchema(slug: 'membership')), 'A portfolio is a list, not a string.');
	}//end testTheExampleSetsShowBothCases()
}//end class
