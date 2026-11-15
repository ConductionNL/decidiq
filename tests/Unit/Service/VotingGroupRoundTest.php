<?php

/**
 * Unit tests: a round opened with a voting group (REQ-VGP-002, REQ-VGP-005).
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
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\Decidiq\Service\VotingOpenRequestHandler;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\Decidiq\Service\VotingRoundRequest;
use OCA\Decidiq\Service\VotingRoundRules;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The real VotingRoundOpener, VotingRoundPreflight, ParticipantResolver and
 * VotingGroupResolver over an ObjectService double that answers like
 * OpenRegister, as in VotingBodyRulesTest.
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 */
class VotingGroupRoundTest extends TestCase {

	private const MEETING = 'meeting-1';
	private const BODY = 'body-1';
	private const MOTION = 'motion-1';

	/**
	 * Rounds written to OpenRegister.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * An entity double serialising to the row.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * The opener over a body of twelve present members and a group of eleven
	 * persons, ten of whom are among them; the eleventh left the body.
	 *
	 * @param string $groupBody The body the group belongs to.
	 *
	 * @return VotingRoundOpener
	 */
	private function opener(string $groupBody = self::BODY): VotingRoundOpener {
		$this->saved = [];
		$objects = [
			self::MEETING => ['id' => self::MEETING, '@self' => ['relations' => ['governanceBody' => self::BODY]]],
			self::BODY => ['id' => self::BODY, 'name' => 'Gemeenteraad'],
		];
		$members = [];
		for ($i = 0; $i < 10; $i++) {
			$objects['person-' . $i] = ['id' => 'person-' . $i, 'name' => 'Member ' . $i, 'nextcloudUserId' => 'member-' . $i];
			$members[] = 'person-' . $i;
		}

		$objects['person-10'] = ['id' => 'person-10', 'name' => 'Anna Jansen', 'email' => 'anna@example.org'];
		$members[] = 'person-10';
		$objects['group-1'] = ['id' => 'group-1', 'name' => 'Commissie AZ', 'governanceBody' => $groupBody, 'members' => $members, 'active' => true];

		$participants = [];
		for ($i = 0; $i < 12; $i++) {
			$participants[] = $this->entity([
				'id' => 'p-' . $i,
				'nextcloudUserId' => 'member-' . $i,
				'role' => 'member',
				'attendanceStatus' => 'present',
				'leftAt' => null,
				'@self' => ['relations' => ['governanceBody' => self::BODY]],
			]);
		}

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id): ObjectEntity => $this->entity($objects[(string)$id] ?? ['id' => (string)$id, 'decisionType' => 'motion', 'lifecycle' => 'deliberating'])
		);
		$objectService->method('findAll')->willReturn($participants);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->entity($object);
			}
		);

		$logger = new NullLogger();
		$motionService = $this->createMock(MotionService::class);
		$resolver = new ParticipantResolver(logger: $logger, objectService: $objectService);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not wired'));

		return new VotingRoundOpener(
			motionService: $motionService,
			participantResolver: $resolver,
			preflight: new VotingRoundPreflight(
				logger: $logger,
				motionService: $motionService,
				participantResolver: $resolver,
				templateService: $this->createMock(ProcessTemplateService::class),
				objectService: $objectService,
			),
			notifier: new VotingOpenedNotifier(logger: $logger, participantResolver: $resolver, container: $container),
			objectService: $objectService,
		);
	}//end opener()

	/**
	 * Open a round, with or without a voting group.
	 *
	 * @param VotingRoundOpener $opener  The opener.
	 * @param string|null       $groupId The voting group, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function open(VotingRoundOpener $opener, ?string $groupId): array {
		$request = (new VotingRoundRequest(motionId: self::MOTION, meetingId: self::MEETING, votingMethod: 'for-against-abstain', isSecret: false))
			->opening(closedAt: null, presetParticipantIds: [], revoteOfRoundId: null, rules: new VotingRoundRules())
			->withVotingGroup(votingGroupId: $groupId);

		return $opener->openVotingRound(request: $request);
	}//end open()

	/**
	 * The participant ids a saved round names as eligible.
	 *
	 * @param array<string, mixed> $round The saved round.
	 *
	 * @return list<string>
	 */
	private static function eligible(array $round): array {
		$ids = [];
		foreach ($round['relations'] as $relation) {
			if ($relation['schema'] === 'participant') {
				$ids[] = $relation['id'];
			}
		}

		return $ids;
	}//end eligible()

	/**
	 * Scenario: the chair picks "Commissie AZ"; its ten members present in the meeting may vote, the one who left is named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-005-the-chair-sees-who-a-voting-group-left-out
	 */
	public function testARoundOpenedWithAGroupIsLimitedToItsMembers(): void {
		$round = $this->open($this->opener(), 'group-1');

		self::assertCount(1, $this->saved);
		self::assertSame(array_map(static fn (int $i): string => 'p-' . $i, range(0, 9)), self::eligible(round: $this->saved[0]));
		self::assertSame(['person-10'], $round['excludedPresetUuids']);
		self::assertSame([['id' => 'person-10', 'name' => 'Anna Jansen']], $round['excludedGroupMembers']);
	}//end testARoundOpenedWithAGroupIsLimitedToItsMembers()

	/**
	 * A group of another body is refused before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function testAGroupOfAnotherBodyIsRefusedAndNothingIsWritten(): void {
		$opener = $this->opener(groupBody: 'another-body');

		try {
			$this->open($opener, 'group-1');
			self::fail('A round opened with a group of another body.');
		} catch (InvalidArgumentException $e) {
			self::assertSame('The voting group belongs to another body.', $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testAGroupOfAnotherBodyIsRefusedAndNothingIsWritten()

	/**
	 * Scenario: no group and no presets — the round is open to the body as before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function testWithoutAGroupNothingChanges(): void {
		$round = $this->open($this->opener(), null);

		self::assertSame([], self::eligible(round: $this->saved[0]));
		self::assertArrayNotHasKey('excludedPresetUuids', $round);
		self::assertArrayNotHasKey('excludedGroupMembers', $round);
	}//end testWithoutAGroupNothingChanges()

	/**
	 * The request's `votingGroup` reaches the opener; naming a group and preset ids together is a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
	 */
	public function testTheOpenRequestCarriesTheGroup(): void {
		$voting = $this->createMock(VotingService::class);
		$seen = [];
		$voting->method('openVotingRound')->willReturnCallback(
			function (VotingRoundRequest $request) use (&$seen): array {
				$seen[] = $request->votingGroupId();
				return ['id' => 'round-1'];
			}
		);
		$handler = new VotingOpenRequestHandler(votingService: $voting);
		$base = ['motionId' => self::MOTION, 'meetingId' => self::MEETING];

		self::assertSame(201, $handler->handle(params: $base + ['votingGroup' => 'group-1'])->getStatus());
		self::assertSame(201, $handler->handle(params: $base)->getStatus());
		self::assertSame(['group-1', null], $seen);

		$both = $handler->handle(params: $base + ['votingGroup' => 'group-1', 'presetParticipantIds' => ['p-1']]);
		self::assertSame(400, $both->getStatus());
		self::assertSame(['message' => 'Choose a voting group or preset participants, not both'], $both->getData());
	}//end testTheOpenRequestCarriesTheGroup()
}//end class
