<?php

/**
 * The Zuiddrecht example set publishes each agenda after its items are
 * written. An item written to a published agenda records a new agenda
 * version and stamps agendaPublishedAt with the write time, so a meeting
 * declared as published before its items are written would not keep the
 * publication date the set declares.
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
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-004-a-burst-of-agenda-edits-sends-one-notice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped example-set fragment from disk.
 */
class ExampleSetAgendaPublicationTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The meetings whose publication the given fragment would lose: a meeting
	 * declared with agendaPublishedAt and an agenda item written after it.
	 *
	 * @param array<string, mixed> $fragment The example-set fragment
	 *
	 * @return array<int, string> Meeting keys
	 */
	private static function restampedMeetings(array $fragment): array {
		$published = [];
		$restamped = [];
		foreach ($fragment['objects'] as $object) {
			if ($object['schema'] === 'meeting' && isset($object['data']['agendaPublishedAt']) === true) {
				$published[$object['key']] = true;
				continue;
			}

			if ($object['schema'] !== 'agenda-item') {
				continue;
			}

			$meeting = preg_replace('/^\{\{object:([^.}]+)\.id\}\}$/', '$1', (string)($object['data']['meeting'] ?? ''));
			if (isset($published[$meeting]) === true) {
				$restamped[$meeting] = true;
			}
		}

		return array_keys($restamped);
	}//end restampedMeetings()

	/**
	 * The fragment as decoded JSON.
	 *
	 * @return array<string, mixed>
	 */
	private static function fragment(): array {
		return json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
	}//end fragment()

	/**
	 * No meeting is declared published before its agenda items are written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-004-a-burst-of-agenda-edits-sends-one-notice
	 */
	public function testNoMeetingIsPublishedBeforeItsItems(): void {
		self::assertSame(expected: [], actual: self::restampedMeetings(fragment: self::fragment()));
	}//end testNoMeetingIsPublishedBeforeItsItems()

	/**
	 * Control: a meeting declared published with an item after it is caught.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-004-a-burst-of-agenda-edits-sends-one-notice
	 */
	public function testAMeetingPublishedBeforeItsItemsIsCaught(): void {
		$fragment = [
			'objects' => [
				['key' => 'meeting-a', 'schema' => 'meeting', 'data' => ['agendaPublishedAt' => '{{datetime:-3@tue 09:00}}']],
				['key' => 'meeting-b', 'schema' => 'meeting', 'data' => []],
				['key' => 'item-a', 'schema' => 'agenda-item', 'data' => ['meeting' => '{{object:meeting-a.id}}']],
				['key' => 'item-b', 'schema' => 'agenda-item', 'data' => ['meeting' => '{{object:meeting-b.id}}']],
			],
		];

		self::assertSame(expected: ['meeting-a'], actual: self::restampedMeetings(fragment: $fragment));
	}//end testAMeetingPublishedBeforeItsItemsIsCaught()

	/**
	 * Every meeting with agenda items is still published, by a change that
	 * names a meeting written earlier in the fragment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-004-a-burst-of-agenda-edits-sends-one-notice
	 */
	public function testEachAgendaIsPublishedByAChangeAfterItsItems(): void {
		$fragment = self::fragment();
		$meetings = [];
		foreach ($fragment['objects'] as $object) {
			if ($object['schema'] === 'meeting') {
				$meetings[$object['key']] = true;
			}
		}

		$published = [];
		foreach (($fragment['changes'] ?? []) as $change) {
			if (isset($change['set']['agendaPublishedAt']) === false) {
				continue;
			}

			self::assertSame(expected: 'meeting', actual: $change['schema'], message: $change['key']);
			self::assertArrayHasKey(key: (string)($change['object'] ?? ''), array: $meetings, message: $change['key']);
			self::assertMatchesRegularExpression(pattern: '/^\{\{datetime:-\d+@\w{3} \d\d:\d\d\}\}$/', string: $change['set']['agendaPublishedAt']);
			$published[] = $change['object'];
		}

		sort($published);
		self::assertSame(
			expected: ['meeting-college-1', 'meeting-college-2', 'meeting-college-3', 'meeting-raad-1', 'meeting-raad-2', 'meeting-raad-3'],
			actual: $published
		);
	}//end testEachAgendaIsPublishedByAChangeAfterItsItems()
}//end class
