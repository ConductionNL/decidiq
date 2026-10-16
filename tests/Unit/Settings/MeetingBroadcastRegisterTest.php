<?php

/**
 * A public broadcast of a meeting is its own record with a declared lifecycle,
 * readable without an account once its publication date has passed
 * (live-public-livestream task 1).
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
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-001-a-public-broadcast-is-readable-without-an-account-once-it-goes-live
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
class MeetingBroadcastRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const WRITERS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * The schema with this slug, merged over the base register and every fragment.
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
	 * The municipality example set's seed objects per schema.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The seeds.
	 */
	private function seeds(): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The schema exists, carries what a resident may see, and is attached to the register.
	 *
	 * @return void
	 */
	public function testTheBroadcastSchemaIsDeclared(): void {
		$schema = $this->mergedSchema('meeting-broadcast');
		self::assertNotSame([], $schema, 'meeting-broadcast exists in the merged register');
		self::assertSame('schema:BroadcastEvent', ($schema['x-schema-org'] ?? null));
		self::assertSame('meeting', ($schema['properties']['meeting']['$ref'] ?? null));
		self::assertSame(['planned', 'testing', 'live', 'paused', 'ended'], ($schema['properties']['lifecycle']['enum'] ?? null));
		self::assertSame(['requested', 'unavailable', 'off'], ($schema['properties']['liveCaptions']['enum'] ?? null));
		foreach (['title', 'bodyName', 'scheduledDate', 'previewUrl', 'playerUrl', 'recordingUrl', 'testedAt', 'testedBy', 'testResult', 'testNote', 'publicWindows', 'captionTracks', 'publicationDate', 'depublicationDate'] as $property) {
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

		self::assertContains('meeting-broadcast', $listed, 'the decidiq register lists meeting-broadcast');
	}//end testTheBroadcastSchemaIsDeclared()

	/**
	 * The lifecycle is declared and `ended` is terminal.
	 *
	 * @return void
	 */
	public function testTheLifecycleIsDeclaredAndEndedIsTerminal(): void {
		$lifecycle = $this->mergedSchema('meeting-broadcast')['x-openregister-lifecycle'] ?? [];
		self::assertSame('lifecycle', ($lifecycle['field'] ?? null));
		self::assertSame('planned', ($lifecycle['initial'] ?? null));
		$pairs = array_map(static fn (array $t): string => $t['from'] . '>' . $t['to'], ($lifecycle['transitions'] ?? []));
		sort($pairs);
		self::assertSame(
			['live>ended', 'live>paused', 'paused>ended', 'paused>live', 'planned>live', 'planned>testing', 'testing>live', 'testing>planned'],
			$pairs
		);
		self::assertSame(['ended'], ($lifecycle['terminal'] ?? null));
		foreach ($pairs as $pair) {
			self::assertStringStartsNotWith('ended>', $pair, 'nothing leaves ended');
		}
	}//end testTheLifecycleIsDeclaredAndEndedIsTerminal()

	/**
	 * Anyone reads a broadcast once its publication date has passed; signed-in
	 * users read every one; only staff write.
	 *
	 * @return void
	 */
	public function testABroadcastIsPublicOnceItsPublicationDatePassed(): void {
		$auth = $this->mergedSchema('meeting-broadcast')['authorization'] ?? [];
		self::assertSame(
			[['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]], 'authenticated'],
			($auth['read'] ?? null)
		);
		foreach (['create', 'update', 'delete'] as $verb) {
			self::assertSame(self::WRITERS, ($auth[$verb] ?? null), $verb . ' is the secretariat and administrators only');
		}
	}//end testABroadcastIsPublicOnceItsPublicationDatePassed()

	/**
	 * Three seeds: an ended broadcast with two public windows and a released
	 * caption track, a planned one whose test found a problem, and a planned
	 * one not yet announced.
	 *
	 * @return void
	 */
	public function testTheMunicipalitySetSeedsThreeBroadcasts(): void {
		$seeds = $this->seeds();
		$meetings = array_column($seeds['meeting'], null, 'slug');
		$broadcasts = ($seeds['meeting-broadcast'] ?? []);
		self::assertCount(3, $broadcasts);

		$ended = array_values(array_filter($broadcasts, static fn (array $b): bool => $b['lifecycle'] === 'ended'));
		self::assertCount(1, $ended);
		self::assertCount(2, ($ended[0]['publicWindows'] ?? []));
		self::assertNotSame('', (string)($ended[0]['playerUrl'] ?? ''));
		self::assertNotSame('', (string)($ended[0]['recordingUrl'] ?? ''));
		self::assertSame('nl', ($ended[0]['captionTracks'][0]['language'] ?? null));

		$planned = array_values(array_filter($broadcasts, static fn (array $b): bool => $b['lifecycle'] === 'planned'));
		self::assertCount(2, $planned);
		$tested = array_values(array_filter($planned, static fn (array $b): bool => isset($b['testResult'])));
		self::assertSame('problems', ($tested[0]['testResult'] ?? null));
		$unannounced = array_values(array_filter($planned, static fn (array $b): bool => isset($b['publicationDate']) === false));
		self::assertCount(1, $unannounced, 'one broadcast is not yet public');

		foreach ($broadcasts as $broadcast) {
			self::assertArrayHasKey($broadcast['meeting'], $meetings, 'the broadcast names a seeded meeting');
			$this->assertValidSeed($broadcast);
		}
	}//end testTheMunicipalitySetSeedsThreeBroadcasts()

	/**
	 * Assert a seed validates against the merged schema.
	 *
	 * @param array<string, mixed> $object The seed
	 *
	 * @return void
	 */
	private function assertValidSeed(array $object): void {
		$schema = $this->mergedSchema('meeting-broadcast');
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
		self::assertTrue($result->isValid(), sprintf('The meeting-broadcast seed validates: %s', json_encode($object)));
	}//end assertValidSeed()
}//end class
