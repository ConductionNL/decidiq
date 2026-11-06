<?php

/**
 * A substitute takes a member's seat in one meeting: the substitution is a
 * record of its own, a participant has a standing seat, and the municipality
 * example seeds the audit committee meeting of 4 March with one ended swap
 * (bodies-substitute-mandate-swap task 1).
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
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
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
class MandateSubstitutionRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

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
	 * The municipality example's seed objects, by schema slug.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function seeds(): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The substitution schema has the properties of design D1, the four
	 * required ones, a title on each, its relations, and is in the register.
	 *
	 * @return void
	 */
	public function testTheSubstitutionSchemaIsDeclared(): void {
		$schema = $this->mergedSchema('mandate-substitution');
		self::assertNotSame([], $schema, 'A mandate-substitution schema is declared');
		self::assertSame(['meeting', 'outgoingParticipant', 'incomingParticipant', 'startedAt'], $schema['required']);
		self::assertSame(
			['meeting', 'outgoingParticipant', 'incomingParticipant', 'seatNumber', 'party', 'role', 'votingWeight', 'startedAt', 'endedAt', 'recordedBy', 'reason'],
			array_keys($schema['properties'])
		);
		foreach ($schema['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), sprintf('%s has a title', $key));
		}

		self::assertSame('meeting', $schema['properties']['meeting']['$ref']);
		self::assertSame('participant', $schema['properties']['outgoingParticipant']['$ref']);
		self::assertSame('participant', $schema['properties']['incomingParticipant']['$ref']);

		$registered = [];
		foreach ((glob(self::SETTINGS . 'register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$registered = array_merge($registered, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		self::assertContains('mandate-substitution', $registered);
	}//end testTheSubstitutionSchemaIsDeclared()

	/**
	 * Only the service writes a substitution: every member reads it, and the
	 * write verbs are left out so the object API cannot go around the guards.
	 *
	 * @return void
	 */
	public function testOnlyTheServiceWritesASubstitution(): void {
		$authorization = $this->mergedSchema('mandate-substitution')['authorization'] ?? null;
		self::assertSame(['read' => ['authenticated']], $authorization);
	}//end testOnlyTheServiceWritesASubstitution()

	/**
	 * A participant declares its standing seat.
	 *
	 * @return void
	 */
	public function testAParticipantHasASeat(): void {
		$seat = $this->mergedSchema('participant')['properties']['seatNumber'] ?? [];
		self::assertSame('integer', ($seat['type'] ?? null));
		self::assertNotSame('', (string)($seat['title'] ?? ''));
	}//end testAParticipantHasASeat()

	/**
	 * The municipality example has the committee meeting, its three
	 * participants and one ended substitution, and each validates (design,
	 * Seed data).
	 *
	 * @return void
	 */
	public function testTheExampleSeedsTheCommitteeSwap(): void {
		$seeds = $this->seeds();
		$meetings = array_column($seeds['meeting'], null, 'slug');
		$participants = array_column($seeds['participant'], null, 'slug');
		self::assertSame('auditcommissie-provincie-nh', ($meetings['auditcommissie-2026-03-04']['governanceBody'] ?? null));

		self::assertSame(['member', 'VVD', 3], [$participants['pt-bos-auditcommissie']['role'], $participants['pt-bos-auditcommissie']['party'], $participants['pt-bos-auditcommissie']['seatNumber']]);
		self::assertSame(['member', 'D66', 4], [$participants['pt-kaya-auditcommissie']['role'], $participants['pt-kaya-auditcommissie']['party'], $participants['pt-kaya-auditcommissie']['seatNumber']]);
		self::assertSame('observer', $participants['pt-de-wit-auditcommissie']['role']);

		$swaps = ($seeds['mandate-substitution'] ?? []);
		self::assertCount(1, $swaps);
		$swap = $swaps[0];
		self::assertSame(['auditcommissie-2026-03-04', 'pt-bos-auditcommissie', 'pt-de-wit-auditcommissie'], [$swap['meeting'], $swap['outgoingParticipant'], $swap['incomingParticipant']]);
		self::assertSame([3, 'VVD', 'member', 1], [$swap['seatNumber'], $swap['party'], $swap['role'], $swap['votingWeight']]);
		self::assertNotEmpty($swap['endedAt'], 'the seeded swap has ended and stays on record');

		$this->assertValidSeed(slug: 'meeting', object: $meetings['auditcommissie-2026-03-04']);
		foreach (['pt-bos-auditcommissie', 'pt-kaya-auditcommissie', 'pt-de-wit-auditcommissie'] as $slug) {
			$this->assertValidSeed(slug: 'participant', object: $participants[$slug]);
		}

		$this->assertValidSeed(slug: 'mandate-substitution', object: $swap);
	}//end testTheExampleSeedsTheCommitteeSwap()

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
