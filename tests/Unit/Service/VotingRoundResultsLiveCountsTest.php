<?php

/**
 * Unit tests for VotingRoundResults::liveCounts() — the running tally of an
 * open round (vot-14, #1375).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * The panel polls an open round, whose own counts are written only on close;
 * liveCounts() reads the ballots cast so far and persists nothing.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundResultsLiveCountsTest extends TestCase {

	/**
	 * Build the results service over a round and a ballot set.
	 *
	 * @param array<string,mixed>|null       $round   The round, or null when unreadable
	 * @param array<int,array<string,mixed>> $ballots The vote objects
	 *
	 * @return VotingRoundResults
	 */
	private function results(?array $round, array $ballots): VotingRoundResults {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$entity = null;
		if ($round !== null) {
			$entity = $this->entity($round);
		}

		$objectService->method('find')->willReturn($entity);
		$objectService->method('findAll')->willReturn(array_map(fn (array $b): ObjectEntity => $this->entity($b), $ballots));
		// Nothing is written while the round is open.
		$objectService->expects($this->never())->method('saveObject');

		return new VotingRoundResults(
			motionService: $this->createMock(MotionService::class),
			participantResolver: $this->createMock(ParticipantResolver::class),
			objectService: $objectService,
		);

	}//end results()

	/**
	 * Wrap a payload in an ObjectEntity double.
	 *
	 * @param array<string,mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * A ballot in round-1 (or another round).
	 *
	 * @param string $value  The vote value
	 * @param int    $weight The vote weight
	 * @param string $round  The round the ballot belongs to
	 *
	 * @return array<string,mixed>
	 */
	private static function ballot(string $value, int $weight=1, string $round='round-1'): array {
		return [
			'value' => $value,
			'weight' => $weight,
			'relations' => [['schema' => 'voting-round', 'id' => $round]],
		];

	}//end ballot()

	/**
	 * The open round's ballots are counted, weighted, and other rounds' ignored.
	 *
	 * @return void
	 */
	public function testCountsTheBallotsCastSoFar(): void {
		$round = ['id' => 'round-1', 'votingMethod' => 'for-against-abstain', 'openedAt' => '2026-10-06T10:00:00Z'];
		$counts = $this->results($round, [
			self::ballot('for'),
			self::ballot('for', 2),
			self::ballot('against'),
			self::ballot('abstain'),
			self::ballot('for', 1, 'round-2'),
		])->liveCounts(votingRoundId: 'round-1');

		self::assertSame(['votesFor' => 3, 'votesAgainst' => 1, 'votesAbstain' => 1, 'cast' => 5], $counts);

	}//end testCountsTheBallotsCastSoFar()

	/**
	 * A ranked ballot counts as cast without touching the split.
	 *
	 * @return void
	 */
	public function testARankedBallotCountsAsCast(): void {
		$round = ['id' => 'round-1', 'votingMethod' => 'ranked-choice'];
		$counts = $this->results($round, [self::ballot('ranked'), self::ballot('ranked')])->liveCounts(votingRoundId: 'round-1');

		self::assertSame(['votesFor' => 0, 'votesAgainst' => 0, 'votesAbstain' => 0, 'cast' => 2], $counts);

	}//end testARankedBallotCountsAsCast()

	/**
	 * A show-of-hands round has no ballots: the entered counts are the tally.
	 *
	 * @return void
	 */
	public function testShowOfHandsReturnsTheEnteredCounts(): void {
		$round = ['id' => 'round-1', 'votingMethod' => 'show-of-hands', 'votesFor' => 4, 'votesAgainst' => 2, 'votesAbstain' => 0];
		$counts = $this->results($round, [])->liveCounts(votingRoundId: 'round-1');

		self::assertSame(['votesFor' => 4, 'votesAgainst' => 2, 'votesAbstain' => 0, 'cast' => 6], $counts);

	}//end testShowOfHandsReturnsTheEnteredCounts()

	/**
	 * A round the user cannot read yields null.
	 *
	 * @return void
	 */
	public function testAnUnreadableRoundIsNull(): void {
		self::assertNull($this->results(null, [self::ballot('for')])->liveCounts(votingRoundId: 'round-1'));

	}//end testAnUnreadableRoundIsNull()
}//end class
