<?php

/**
 * Unit tests for RecordedVoteWriter: a roll call recorded by name, refused as a whole when wrong.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\RecordedVotesRefusedException;
use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\RecordedVoteWriter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Doornbos and Bakker for, Yilmaz against, recorded by the griffier.
 */
class RecordedVoteWriterTest extends TestCase {

	private const ROUND = 'aaaaaaaa-0000-4000-8000-000000000001';

	private const DOORNBOS = 'dddddddd-0000-4000-8000-000000000001';

	private const BAKKER = 'bbbbbbbb-0000-4000-8000-000000000002';

	private const YILMAZ = 'cccccccc-0000-4000-8000-000000000003';

	private const LEFT = 'eeeeeeee-0000-4000-8000-000000000004';

	/**
	 * The stored votes, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $votes = [];

	/**
	 * How many votes were written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * The writer over one round, the meeting's participants and the stored votes.
	 *
	 * @param array<string, mixed> $round The round's fields over an open public roll call.
	 *
	 * @return RecordedVoteWriter
	 */
	private function writer(array $round = []): RecordedVoteWriter {
		$roundEntity = $this->createMock(ObjectEntity::class);
		$roundEntity->method('jsonSerialize')->willReturn(
			array_merge(['id' => self::ROUND, 'votingMethod' => 'roll-call', 'isSecret' => false, 'openedAt' => '2026-03-12T20:35:00Z', 'meeting' => 'meeting-1'], $round)
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($roundEntity);
		$objectService->method('findAll')->willReturnCallback(
			function (): array {
				$entities = [];
				foreach ($this->votes as $vote) {
					$entity = $this->createMock(ObjectEntity::class);
					$entity->method('jsonSerialize')->willReturn($vote);
					$entities[] = $entity;
				}

				return $entities;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->writes++;
				$uuid = (string)($object['uuid'] ?? ('vote-'.count($this->votes)));
				$object['uuid'] = $uuid;
				$object['id'] = $uuid;
				$this->votes[$uuid] = $object;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);

		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('resolveMeetingParticipants')->willReturn(
			[
				['id' => self::DOORNBOS, 'party' => 'GroenLinks'],
				['id' => self::BAKKER, 'party' => 'VVD'],
				['id' => self::YILMAZ, 'party' => 'PvdA', 'leftAt' => ''],
				['id' => self::LEFT, 'party' => 'CDA', 'leftAt' => '2026-01-01'],
			]
		);
		$amendmentOrder = $this->createMock(AmendmentOrderService::class);
		$amendmentOrder->method('resolveMeetingIdForRound')->willReturn('meeting-1');

		return new RecordedVoteWriter($objectService, $participants, $amendmentOrder, $this->createMock(ContainerInterface::class), new NullLogger());
	}//end writer()

	/**
	 * The paper roll call's three choices.
	 *
	 * @param string $yilmaz Yilmaz's value.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function sheet(string $yilmaz = 'against'): array {
		return [
			['participantId' => self::DOORNBOS, 'value' => 'for'],
			['participantId' => self::BAKKER, 'value' => 'for'],
			['participantId' => self::YILMAZ, 'value' => $yilmaz],
		];
	}//end sheet()

	/**
	 * The value per participant of the stored votes.
	 *
	 * @return array<string, string>
	 */
	private function byParticipant(): array {
		$values = [];
		foreach ($this->votes as $vote) {
			foreach ($vote['relations'] as $relation) {
				if (($relation['schema'] ?? '') === 'participant') {
					$values[$relation['id']] = $vote['value'];
				}
			}
		}

		ksort($values);
		return $values;
	}//end byParticipant()

	/**
	 * Run a write that must be refused; return the refusal.
	 *
	 * @param callable $write The write.
	 *
	 * @return RecordedVotesRefusedException
	 */
	private function refused(callable $write): RecordedVotesRefusedException {
		try {
			$write();
		} catch (RecordedVotesRefusedException $refusal) {
			return $refusal;
		}

		$this->fail('The list was not refused.');
	}//end refused()

	/**
	 * The griffier enters a paper roll call: three recorded votes naming their participant.
	 *
	 * @return void
	 */
	public function testTheGriffierEntersAPaperRollCall(): void {
		$result = $this->writer()->record(votingRoundId: self::ROUND, entries: self::sheet(), heldAt: '2026-03-12T21:40:00+01:00', recordedBy: 'griffier1');

		$this->assertSame(['votingRoundId' => self::ROUND, 'recorded' => 3], $result);
		$this->assertSame([self::BAKKER => 'for', self::YILMAZ => 'against', self::DOORNBOS => 'for'], $this->byParticipant());
		foreach ($this->votes as $vote) {
			$this->assertSame(['recorded', 'griffier1', '2026-03-12T21:40:00+01:00'], [$vote['entryMode'], $vote['recordedBy'], $vote['castAt']]);
		}
	}//end testTheGriffierEntersAPaperRollCall()

	/**
	 * A corrected list replaces the earlier recorded votes and never adds to them.
	 *
	 * @return void
	 */
	public function testACorrectionDoesNotDoubleCount(): void {
		$writer = $this->writer();
		$writer->record(votingRoundId: self::ROUND, entries: self::sheet(), heldAt: null, recordedBy: 'griffier1');
		$writer->record(votingRoundId: self::ROUND, entries: self::sheet(yilmaz: 'abstain'), heldAt: null, recordedBy: 'griffier1');

		$this->assertCount(3, $this->votes);
		$this->assertSame('abstain', $this->byParticipant()[self::YILMAZ]);
	}//end testACorrectionDoesNotDoubleCount()

	/**
	 * A person outside the meeting, one who left, a repeat or a wrong value: 400 naming them, nothing written.
	 *
	 * @return void
	 */
	public function testAWrongEntryRefusesTheWholeList(): void {
		$entries = array_merge(
			self::sheet(),
			[
				['participantId' => 'ffffffff-0000-4000-8000-000000000009', 'value' => 'for'],
				['participantId' => self::LEFT, 'value' => 'for'],
			]
		);

		$refusal = $this->refused(fn () => $this->writer()->record(votingRoundId: self::ROUND, entries: $entries, heldAt: null, recordedBy: 'griffier1'));

		$this->assertSame(400, $refusal->status);
		$this->assertSame(['ffffffff-0000-4000-8000-000000000009', self::LEFT], $refusal->participantIds);
		$this->assertSame(0, $this->writes);

		$twice = array_merge(self::sheet(), [['participantId' => self::BAKKER, 'value' => 'against']]);
		$this->assertSame([self::BAKKER], $this->refused(fn () => $this->writer()->record(votingRoundId: self::ROUND, entries: $twice, heldAt: null, recordedBy: 'g'))->participantIds);
		$badValue = [['participantId' => self::BAKKER, 'value' => 'maybe']];
		$this->assertSame([self::BAKKER], $this->refused(fn () => $this->writer()->record(votingRoundId: self::ROUND, entries: $badValue, heldAt: null, recordedBy: 'g'))->participantIds);
		$this->assertSame(0, $this->writes);
	}//end testAWrongEntryRefusesTheWholeList()

	/**
	 * A member's own vote is not overwritten: 409 naming them, nothing written.
	 *
	 * @return void
	 */
	public function testAMembersOwnVoteIsNotOverwritten(): void {
		$this->votes['own'] = [
			'uuid' => 'own',
			'value' => 'for',
			'relations' => [
				['register' => 'decidiq', 'schema' => 'voting-round', 'id' => self::ROUND],
				['register' => 'decidiq', 'schema' => 'participant', 'id' => self::BAKKER],
			],
		];

		$refusal = $this->refused(fn () => $this->writer()->record(votingRoundId: self::ROUND, entries: self::sheet(), heldAt: null, recordedBy: 'griffier1'));

		$this->assertSame([409, [self::BAKKER]], [$refusal->status, $refusal->participantIds]);
		$this->assertSame(0, $this->writes);
		$this->assertSame('for', $this->votes['own']['value']);
	}//end testAMembersOwnVoteIsNotOverwritten()

	/**
	 * A secret, a closed, an unopened or a non roll call round takes no names.
	 *
	 * @return void
	 */
	public function testOnlyAnOpenPublicRollCallTakesNames(): void {
		foreach ([
			'secret' => ['isSecret' => true],
			'closed' => ['closedAt' => '2026-03-12T20:40:00Z'],
			'unopened' => ['openedAt' => null],
			'show of hands' => ['votingMethod' => 'show-of-hands'],
		] as $case => $round) {
			$refusal = $this->refused(fn () => $this->writer(round: $round)->record(votingRoundId: self::ROUND, entries: self::sheet(), heldAt: null, recordedBy: 'g'));
			$this->assertSame(400, $refusal->status, $case);
		}

		$this->assertSame(
			'A secret ballot cannot be recorded by name.',
			$this->refused(fn () => $this->writer(round: ['isSecret' => true])->record(votingRoundId: self::ROUND, entries: self::sheet(), heldAt: null, recordedBy: 'g'))->getMessage()
		);
		$this->assertSame(0, $this->writes);
	}//end testOnlyAnOpenPublicRollCallTakesNames()
}//end class
