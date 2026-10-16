<?php

/**
 * The meeting schema stores its chair as the id of a participant, and the
 * meetings list resolves it as one. Every chair in the Zuiddrecht example set
 * therefore points at a participant of the meeting's own body.
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
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
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
class ExampleSetMeetingChairTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The meeting keys whose chair is not a reference to a participant of the
	 * meeting's own governance body.
	 *
	 * @param array<string, mixed> $fragment The example-set fragment
	 *
	 * @return array<int, string> Meeting keys
	 */
	private static function badChairs(array $fragment): array {
		$participantBody = [];
		foreach ($fragment['objects'] as $object) {
			if ($object['schema'] === 'participant') {
				$participantBody[$object['key']] = (string)($object['data']['governanceBody'] ?? '');
			}
		}

		$bad = [];
		foreach ($fragment['objects'] as $object) {
			if ($object['schema'] !== 'meeting' || isset($object['data']['chair']) === false) {
				continue;
			}

			$participant = preg_replace('/^\{\{object:([^.}]+)\.id\}\}$/', '$1', (string)$object['data']['chair']);
			if (($participantBody[$participant] ?? null) !== ($object['data']['governanceBody'] ?? '')) {
				$bad[] = $object['key'];
			}
		}

		return $bad;
	}//end badChairs()

	/**
	 * Every meeting chair in the shipped fragment is a participant of its body.
	 *
	 * @return void
	 *
	 * @spec exclude example-set data integrity; no requirement covers the demo fragment
	 */
	public function testEveryChairIsAParticipantOfTheMeetingsBody(): void {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame(expected: [], actual: self::badChairs(fragment: $fragment));
	}//end testEveryChairIsAParticipantOfTheMeetingsBody()

	/**
	 * Control: a chair given as a name, or as a participant of another body,
	 * is caught.
	 *
	 * @return void
	 *
	 * @spec exclude example-set data integrity; no requirement covers the demo fragment
	 */
	public function testANameOrAnotherBodysParticipantIsCaught(): void {
		$fragment = [
			'objects' => [
				['key' => 'p-a', 'schema' => 'participant', 'data' => ['governanceBody' => '{{object:body-a.id}}']],
				['key' => 'm-ok', 'schema' => 'meeting', 'data' => ['governanceBody' => '{{object:body-a.id}}', 'chair' => '{{object:p-a.id}}']],
				['key' => 'm-name', 'schema' => 'meeting', 'data' => ['governanceBody' => '{{object:body-a.id}}', 'chair' => 'Ingrid Tuinman']],
				['key' => 'm-other', 'schema' => 'meeting', 'data' => ['governanceBody' => '{{object:body-b.id}}', 'chair' => '{{object:p-a.id}}']],
			],
		];

		self::assertSame(expected: ['m-name', 'm-other'], actual: self::badChairs(fragment: $fragment));
	}//end testANameOrAnotherBodysParticipantIsCaught()
}//end class
