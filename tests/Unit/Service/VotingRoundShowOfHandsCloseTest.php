<?php

/**
 * Closing a show-of-hands round keeps the totals the chair entered.
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
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-005-closing-a-show-of-hands-round-keeps-the-totals-the-chair-entered
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * A show-of-hands round has no vote objects; its saved totals are the count.
 */
class VotingRoundShowOfHandsCloseTest extends TestCase {

	/**
	 * Every round the tally saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The results service over one stored round and the given votes.
	 *
	 * @param array<string, mixed>               $round The stored round.
	 * @param array<int, array<string, mixed>>   $votes The round's vote objects.
	 *
	 * @return VotingRoundResults
	 */
	private function results(array $round, array $votes): VotingRoundResults {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($round);

		$voteEntities = [];
		foreach ($votes as $vote) {
			$voteEntity = $this->createMock(ObjectEntity::class);
			$voteEntity->method('jsonSerialize')->willReturn($vote);
			$voteEntities[] = $voteEntity;
		}

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('findAll')->willReturn($voteEntities);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$saved = $this->createMock(ObjectEntity::class);
				$saved->method('jsonSerialize')->willReturn($object);
				return $saved;
			}
		);

		return new VotingRoundResults(
			motionService: $this->createMock(MotionService::class),
			participantResolver: $this->createMock(ParticipantResolver::class),
			objectService: $objectService
		);
	}//end results()

	/**
	 * 14 for, 9 against, 2 abstain survive the close, and the result is adopted, not invalid.
	 *
	 * @return void
	 */
	public function testTotalsSurviveTheClose(): void {
		$round = [
			'id' => 'round-1',
			'votingMethod' => 'show-of-hands',
			'votesFor' => 14,
			'votesAgainst' => 9,
			'votesAbstain' => 2,
			'voteThreshold' => 'simple-majority',
			'abstentionHandling' => 'exclude',
			'tieBreakRule' => 'rejected',
		];

		$tally = $this->results(round: $round, votes: [])->tally(votingRoundId: 'round-1');

		$this->assertSame([14, 9, 2], [$tally['votesFor'], $tally['votesAgainst'], $tally['votesAbstain']]);
		$this->assertSame('adopted', $tally['result']);
		$stored = end($this->saved);
		$this->assertSame([14, 9, 2, 'adopted'], [$stored['votesFor'], $stored['votesAgainst'], $stored['votesAbstain'], $stored['result']]);
	}//end testTotalsSurviveTheClose()

	/**
	 * A for-against-abstain round is still counted from its votes, whatever totals it held.
	 *
	 * @return void
	 */
	public function testAForAgainstAbstainRoundIsStillCountedFromItsVotes(): void {
		$round = [
			'id' => 'round-2',
			'votingMethod' => 'for-against-abstain',
			'votesFor' => 99,
			'votesAgainst' => 0,
			'votesAbstain' => 0,
			'voteThreshold' => 'simple-majority',
			'abstentionHandling' => 'exclude',
			'tieBreakRule' => 'rejected',
		];
		$votes = [
			['value' => 'for', 'weight' => 1, 'relations' => [['register' => 'decidiq', 'schema' => 'voting-round', 'id' => 'round-2']]],
			['value' => 'against', 'weight' => 1, 'relations' => [['register' => 'decidiq', 'schema' => 'voting-round', 'id' => 'round-2']]],
			['value' => 'against', 'weight' => 1, 'relations' => [['register' => 'decidiq', 'schema' => 'voting-round', 'id' => 'round-2']]],
		];

		$tally = $this->results(round: $round, votes: $votes)->tally(votingRoundId: 'round-2');

		$this->assertSame([1, 2, 0], [$tally['votesFor'], $tally['votesAgainst'], $tally['votesAbstain']]);
		$this->assertSame('rejected', $tally['result']);
	}//end testAForAgainstAbstainRoundIsStillCountedFromItsVotes()
}//end class
