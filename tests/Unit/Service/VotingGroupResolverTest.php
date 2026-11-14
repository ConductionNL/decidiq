<?php

/**
 * Unit tests: a voting group becomes the meeting participants who may vote.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VotingGroupResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The real VotingGroupResolver and ParticipantResolver over an ObjectService
 * double that answers like OpenRegister.
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 */
class VotingGroupResolverTest extends TestCase {

	private const MEETING = 'meeting-1';
	private const BODY = 'body-1';

	/**
	 * Objects by id, as `find` answers them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * The participants `findAll` answers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $participants = [];

	/**
	 * An entity double serialising to the row.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * The resolver over the current objects and participants.
	 *
	 * @return VotingGroupResolver
	 */
	private function resolver(): VotingGroupResolver {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ObjectEntity {
				if (isset($this->objects[(string)$id]) === false) {
					throw new RuntimeException('not found');
				}

				return $this->entity($this->objects[(string)$id]);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			fn (): array => array_map(fn (array $row): ObjectEntity => $this->entity($row), $this->participants)
		);

		return new VotingGroupResolver(
			participants: new ParticipantResolver(logger: new NullLogger(), objectService: $objectService),
			objectService: $objectService
		);
	}//end resolver()

	/**
	 * A meeting of the body, eleven persons in a group, ten of them participants of the meeting.
	 *
	 * @param array<string, mixed> $group Fields to override on the group.
	 *
	 * @return void
	 */
	private function councilOfTen(array $group = []): void {
		$this->objects[self::MEETING] = ['id' => self::MEETING, '@self' => ['relations' => ['governanceBody' => self::BODY]]];
		$members = [];
		for ($i = 0; $i < 11; $i++) {
			$this->objects['person-' . $i] = ['id' => 'person-' . $i, 'name' => 'Member ' . $i, 'nextcloudUserId' => 'member-' . $i];
			$members[] = 'person-' . $i;
		}

		// The eleventh left the body: no participant of the meeting stands for her.
		$this->objects['person-10'] = ['id' => 'person-10', 'name' => 'Anna Jansen', 'email' => 'anna@example.org'];
		for ($i = 0; $i < 10; $i++) {
			$this->participants[] = [
				'id' => 'participant-' . $i,
				'nextcloudUserId' => 'member-' . $i,
				'@self' => ['relations' => ['governanceBody' => self::BODY]],
			];
		}

		// Someone of another body with the same e-mail address is not a candidate.
		$this->participants[] = ['id' => 'elsewhere', 'email' => 'anna@example.org', '@self' => ['relations' => ['governanceBody' => 'another-body']]];

		$this->objects['group-1'] = array_merge(
			['id' => 'group-1', 'name' => 'Commissie AZ', 'governanceBody' => self::BODY, 'members' => $members, 'active' => true],
			$group
		);
	}//end councilOfTen()

	/**
	 * Eleven persons, ten of them participants: ten are eligible and the eleventh is reported by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-005-the-chair-sees-who-a-voting-group-left-out
	 */
	public function testTenOfElevenAreEligibleAndTheEleventhIsReported(): void {
		$this->councilOfTen();

		$resolved = $this->resolver()->resolve(groupId: 'group-1', meetingId: self::MEETING);

		self::assertSame(array_map(static fn (int $i): string => 'participant-' . $i, range(0, 9)), $resolved['participantIds']);
		self::assertSame([['id' => 'person-10', 'name' => 'Anna Jansen']], $resolved['excluded']);
	}//end testTenOfElevenAreEligibleAndTheEleventhIsReported()

	/**
	 * A person without a Nextcloud user id is matched on the e-mail address, ignoring case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function testAPersonIsMatchedOnTheEmailAddressWhenThereIsNoUserId(): void {
		$this->councilOfTen(group: ['members' => ['person-x', 'person-x']]);
		$this->objects['person-x'] = ['id' => 'person-x', 'name' => 'Bas', 'email' => 'Bas@Example.org'];
		$this->participants[] = ['id' => 'participant-bas', 'email' => 'bas@example.org', '@self' => ['relations' => ['governanceBody' => self::BODY]]];

		$resolved = $this->resolver()->resolve(groupId: 'group-1', meetingId: self::MEETING);

		self::assertSame(['participant-bas'], $resolved['participantIds'], 'a person listed twice is one participant');
		self::assertSame([], $resolved['excluded']);
	}//end testAPersonIsMatchedOnTheEmailAddressWhenThereIsNoUserId()

	/**
	 * A group of another body, an inactive group and a missing group are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function testAGroupThatCannotBeUsedIsRefused(): void {
		$cases = [
			'The voting group belongs to another body.' => ['governanceBody' => 'another-body'],
			'The voting group is not active.' => ['active' => false],
		];
		foreach ($cases as $message => $group) {
			$this->objects = [];
			$this->participants = [];
			$this->councilOfTen(group: $group);
			try {
				$this->resolver()->resolve(groupId: 'group-1', meetingId: self::MEETING);
				self::fail('Resolved: ' . $message);
			} catch (InvalidArgumentException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}

		$this->expectExceptionObject(new InvalidArgumentException('The voting group does not exist.'));
		$this->resolver()->resolve(groupId: 'no-such-group', meetingId: self::MEETING);
	}//end testAGroupThatCannotBeUsedIsRefused()

	/**
	 * The round's own body, when the request names one, is the body the group must belong to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function testTheRoundsNamedBodyIsTheOneThatCounts(): void {
		$this->councilOfTen();

		$this->expectExceptionObject(new InvalidArgumentException('The voting group belongs to another body.'));
		$this->resolver()->resolve(groupId: 'group-1', meetingId: self::MEETING, bodyId: 'another-body');
	}//end testTheRoundsNamedBodyIsTheOneThatCounts()

	/**
	 * 🔴 A group whose members are none of them participants is refused, never widened to everyone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function testAGroupThatLeavesNobodyIsRefused(): void {
		$this->councilOfTen(group: ['members' => ['person-10', 'unreadable-person']]);

		$this->expectExceptionObject(new InvalidArgumentException('No member of this voting group is a participant of the meeting.'));
		$this->resolver()->resolve(groupId: 'group-1', meetingId: self::MEETING);
	}//end testAGroupThatLeavesNobodyIsRefused()
}//end class
