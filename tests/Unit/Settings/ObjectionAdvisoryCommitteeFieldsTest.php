<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The fields that make an Awb 7:13 objection advisory committee expressible.
 *
 * Each assertion below stands for a gap measured against a real consuming app's
 * committee schema, so a regression here does not merely change decidiq — it
 * re-opens a hole that forces another app to keep a parallel model.
 */
class ObjectionAdvisoryCommitteeFieldsTest extends TestCase {
	/**
	 * The decoded base register.
	 *
	 * @return array<string, mixed> The register.
	 */
	private function register(): array {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/decidesk_register.json');
		$decoded = json_decode((string)$raw, true);
		$this->assertIsArray($decoded, 'The base register did not decode.');

		return $decoded;
	}

	/**
	 * Find a schema by key anywhere in the register.
	 *
	 * @param array<string, mixed> $node The current node.
	 * @param string $name The schema key.
	 *
	 * @return array<string, mixed>|null The schema, or null.
	 */
	private function findSchema(array $node, string $name): ?array {
		if (isset($node[$name]) === true
			&& is_array($node[$name]) === true
			&& isset($node[$name]['properties']) === true
		) {
			return $node[$name];
		}

		foreach ($node as $value) {
			if (is_array($value) === true) {
				$found = $this->findSchema(node: $value, name: $name);
				if ($found !== null) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * The four GovernanceBody fields exist with the specced shapes.
	 *
	 * @return void
	 */
	public function testGovernanceBodyCarriesTheCommitteeFields(): void {
		$schema = $this->findSchema(node: $this->register(), name: 'GovernanceBody');
		$this->assertNotNull($schema);
		$properties = $schema['properties'];

		foreach (['active', 'quorum', 'jurisdiction', 'statutoryBasis'] as $field) {
			$this->assertArrayHasKey($field, $properties, $field . ' is missing.');
			$this->assertArrayHasKey('title', $properties[$field], $field . ' has no title.');
		}

		$this->assertSame('boolean', $properties['active']['type']);
		$this->assertTrue($properties['active']['default'], 'A body created before this change must read as available.');

		$this->assertSame('integer', $properties['quorum']['type']);
		$this->assertSame(2, $properties['quorum']['minimum'], 'Awb 7:13 cannot be satisfied by fewer than two members.');

		$this->assertSame('string', $properties['jurisdiction']['type']);
		$this->assertSame('string', $properties['statutoryBasis']['type']);
	}

	/**
	 * `quorum` has NO default, and that absence is the assertion.
	 *
	 * A default of 0 would read as "no members are required for a valid
	 * sitting" — a confident wrong answer where "not specified" is the truth.
	 * This is the same failure mode as a defaulted read turning missing data
	 * into behaviour rather than into a question.
	 *
	 * @return void
	 */
	public function testQuorumHasNoDefault(): void {
		$schema = $this->findSchema(node: $this->register(), name: 'GovernanceBody');
		$this->assertNotNull($schema);

		$this->assertArrayNotHasKey(
			'default',
			$schema['properties']['quorum'],
			'quorum must not default: 0 would assert that no members are needed.'
		);
	}

	/**
	 * `statutoryBasis` is free text, not an enum.
	 *
	 * A closed vocabulary drawn from one country's law cannot express the
	 * instruments behind bodies in another, and a caller forced to choose from
	 * a list that does not fit will choose a wrong value — which is worse than
	 * the free text, because it then reads as data.
	 *
	 * @return void
	 */
	public function testStatutoryBasisIsNotAClosedVocabulary(): void {
		$schema = $this->findSchema(node: $this->register(), name: 'GovernanceBody');
		$this->assertNotNull($schema);

		$this->assertArrayNotHasKey('enum', $schema['properties']['statutoryBasis']);
	}

	/**
	 * `Membership.external` exists and is distinct from `independenceStatus`.
	 *
	 * @return void
	 */
	public function testMembershipRecordsExternalSeparatelyFromIndependence(): void {
		$schema = $this->findSchema(node: $this->register(), name: 'Membership');
		$this->assertNotNull($schema);
		$properties = $schema['properties'];

		$this->assertArrayHasKey('external', $properties);
		$this->assertSame('boolean', $properties['external']['type']);
		$this->assertFalse($properties['external']['default']);

		$this->assertArrayHasKey('independenceStatus', $properties, 'The existing field must survive.');
		$this->assertNotSame(
			$properties['external']['description'],
			$properties['independenceStatus']['description'],
			'Two fields that mean different things must not describe themselves the same way.'
		);
		$this->assertStringContainsString(
			'independenceStatus',
			$properties['external']['description'],
			'external must say what it is NOT, or a reader will use the wrong one.'
		);
		$this->assertStringContainsString(
			'external',
			$properties['independenceStatus']['description'],
			'independenceStatus must say what it is NOT, in the same way.'
		);
	}

	/**
	 * No existing object becomes invalid.
	 *
	 * Every field this change adds is optional. If any had landed in `required`,
	 * every governance body and membership already stored would fail validation
	 * on its next write.
	 *
	 * @return void
	 */
	public function testTheChangeIsPurelyAdditive(): void {
		$register = $this->register();

		$body = $this->findSchema(node: $register, name: 'GovernanceBody');
		$this->assertNotNull($body);
		$this->assertSame(['name', 'bodyType', 'domain'], $body['required']);

		$membership = $this->findSchema(node: $register, name: 'Membership');
		$this->assertNotNull($membership);
		$this->assertSame(['role'], $membership['required']);
	}
}
