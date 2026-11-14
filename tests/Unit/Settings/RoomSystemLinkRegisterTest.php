<?php

/**
 * The room system link in the register: the inbox schema, who may write it, the seat and the vote channel.
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
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the merged register as the import does: the main file, then every register.d fragment.
 *
 * @coversNothing
 */
class RoomSystemLinkRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * One schema with every fragment merged over it.
	 *
	 * @param string $slug The schema slug.
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
	 * The inbox schema exists, is attached to the register, and every property has a title.
	 *
	 * @return void
	 */
	public function testTheInboxSchemaIsDeclaredAndAttached(): void {
		$schema = $this->mergedSchema('room-system-message');

		self::assertSame(['speech', 'vote'], $schema['properties']['kind']['enum']);
		self::assertSame(['received', 'applied', 'rejected'], $schema['properties']['status']['enum']);
		self::assertSame(['for', 'against', 'abstain'], $schema['properties']['value']['enum']);
		self::assertSame(['meeting', 'kind', 'seat', 'externalId'], $schema['required']);
		foreach ($schema['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), $key . ' has a title');
		}

		$listed = [];
		foreach ((glob(self::SETTINGS . 'register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$listed = array_merge($listed, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		self::assertContains('room-system-message', $listed);
	}//end testTheInboxSchemaIsDeclaredAndAttached()

	/**
	 * Only the integriq synchronisation group creates messages; nobody updates or deletes them through the API.
	 *
	 * @return void
	 */
	public function testOnlyTheSynchronisationGroupCreatesMessages(): void {
		$authorization = $this->mergedSchema('room-system-message')['authorization'];

		self::assertSame(['integriq-synchronisation'], $authorization['create']);
		self::assertArrayNotHasKey('update', $authorization);
		self::assertArrayNotHasKey('delete', $authorization);
		self::assertNotContains('authenticated', $authorization['read']);
		self::assertNotContains('public', $authorization['read']);
	}//end testOnlyTheSynchronisationGroupCreatesMessages()

	/**
	 * A participant carries an optional seat, and a vote the channel it came through.
	 *
	 * @return void
	 */
	public function testTheSeatAndTheVoteChannelAreDeclared(): void {
		$participant = $this->mergedSchema('participant');
		$vote = $this->mergedSchema('vote');

		self::assertSame('string', $participant['properties']['roomSeat']['type']);
		self::assertNotContains('roomSeat', ($participant['required'] ?? []));
		self::assertSame('0.5.0', $participant['version']);
		self::assertSame(['app', 'email', 'room-system'], $vote['properties']['castVia']['enum']);
		self::assertSame('0.4.0', $vote['version']);
	}//end testTheSeatAndTheVoteChannelAreDeclared()

	/**
	 * The room system is a declared connection.
	 *
	 * @return void
	 */
	public function testTheRoomSystemIsAConnection(): void {
		$connections = json_decode((string)file_get_contents(self::SETTINGS . 'connections.json'), true)['connections'];
		$room = array_values(array_filter($connections, static fn (array $c): bool => $c['key'] === 'room-system'));

		self::assertCount(1, $room);
		self::assertSame('room-system', $room[0]['sourceTemplate']);
		self::assertSame('No room system is connected. Link a source to this connection to receive speaker markings and desk votes from the chamber.', $room[0]['unconfiguredMessage']);
	}//end testTheRoomSystemIsAConnection()
}//end class
