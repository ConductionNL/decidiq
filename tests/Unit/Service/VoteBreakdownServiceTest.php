<?php

/**
 * Unit tests for VoteBreakdownService: a round's result per member and per
 * faction.
 *
 * The ballots are built by the real VoteBallotFactory, so the test reads the
 * shape castVote() actually writes: the voter sits only in the ballot's
 * `relations`, never in a `caster` or `participant` field. That is why the
 * votes widget showed a dash for every voter.
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
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\VoteBallotFactory;
use OCA\Decidiq\Service\VoteBreakdownService;
use OCA\Decidiq\Service\VoterTokenSecret;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VoteBreakdownService.
 *
 * @spec openspec/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member
 */
class VoteBreakdownServiceTest extends TestCase {

	/**
	 * The members of the council: id => [name, faction].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const MEMBERS = [
		'p-anna' => ['Anna de Boer', 'GroenLinks'],
		'p-bas' => ['Bas Smit', 'GroenLinks'],
		'p-cor' => ['Cor Visser', 'VVD'],
		'p-dirk' => ['Dirk Jansen', 'VVD'],
		'p-eva' => ['Eva Mulder', 'VVD'],
	];

	/**
	 * Build a ballot the way castVote() does.
	 *
	 * @param string      $participantId The voter
	 * @param string      $value         for, against or abstain
	 * @param string|null $delegatorId   The member a proxy votes for
	 *
	 * @return array<string, mixed>
	 */
	private function ballot(string $participantId, string $value, ?string $delegatorId = null): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not in this test'));
		$factory = new VoteBallotFactory($container, $this->createMock(LoggerInterface::class), new VoterTokenSecret($container));

		$vote = $factory->buildVote(
			votingRoundId: 'round-1',
			participantId: $participantId,
			value: $value,
			isProxy: $delegatorId !== null,
			delegatorId: $delegatorId,
			isSecret: false,
			existingVote: null
		);
		unset($vote['@self']);
		return $vote;
	}//end ballot()

	/**
	 * Build the service around a round and its ballots.
	 *
	 * @param array<string, mixed>        $round   The voting round
	 * @param array<int, array|ObjectEntity> $ballots Ballots as stored
	 *
	 * @return VoteBreakdownService
	 */
	private function makeService(array $round, array $ballots): VoteBreakdownService {
		$roundEntity = new ObjectEntity();
		$roundEntity->setUuid('round-1');
		$roundEntity->setObject($round);

		$voteEntities = [];
		foreach ($ballots as $index => $ballot) {
			if ($ballot instanceof ObjectEntity || is_object($ballot) === true) {
				$voteEntities[] = $ballot;
				continue;
			}

			$entity = new ObjectEntity();
			$entity->setUuid('vote-' . $index);
			$entity->setObject($ballot);
			$voteEntities[] = $entity;
		}

		$people = [];
		foreach (self::MEMBERS as $id => [$name, $faction]) {
			$person = new ObjectEntity();
			$person->setUuid($id);
			$person->setObject(['displayName' => $name, 'party' => $faction]);
			$people[$id] = $person;
		}

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use ($roundEntity, $people): ?ObjectEntity {
				if ($id === 'round-1' && $schema === 'voting-round') {
					return $roundEntity;
				}

				if ($schema === 'participant') {
					return ($people[$id] ?? null);
				}

				return null;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($voteEntities): array {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? '') === 'vote' && ($filters[ObjectRelationFilter::RELATION_FILTER_FIELD] ?? '') === 'round-1') {
					return $voteEntities;
				}

				return [];
			}
		);

		return new VoteBreakdownService(objectService: $objectService, relationFilter: new ObjectRelationFilter());
	}//end makeService()

	/**
	 * Five ballots from two factions: every voter is named, and each faction
	 * shows its own counts.
	 *
	 * @return void
	 */
	public function testEachVoterIsNamedAndEachFactionShowsItsCounts(): void {
		$service = $this->makeService(
			round: ['isSecret' => false, 'votesFor' => 3, 'votesAgainst' => 2, 'votesAbstain' => 0],
			ballots: [
				$this->ballot('p-anna', 'for'),
				$this->ballot('p-bas', 'for'),
				$this->ballot('p-cor', 'against'),
				$this->ballot('p-dirk', 'against'),
				$this->ballot('p-eva', 'for'),
			]
		);

		$result = $service->forRound(roundId: 'round-1');

		self::assertFalse($result['secret']);
		self::assertSame(
			[
				['Anna de Boer', 'GroenLinks', 'for'],
				['Bas Smit', 'GroenLinks', 'for'],
				['Cor Visser', 'VVD', 'against'],
				['Dirk Jansen', 'VVD', 'against'],
				['Eva Mulder', 'VVD', 'for'],
			],
			array_map(static fn (array $m): array => [$m['name'], $m['faction'], $m['value']], $result['members'])
		);
		self::assertSame(
			[
				['faction' => 'GroenLinks', 'for' => 2, 'against' => 0, 'abstain' => 0],
				['faction' => 'VVD', 'for' => 1, 'against' => 2, 'abstain' => 0],
			],
			$result['factions']
		);
		self::assertSame(['for' => 3, 'against' => 2, 'abstain' => 0], $result['totals']);
		self::assertNotEmpty($result['members'][0]['castAt']);
		self::assertNull($result['members'][0]['castBy']);
	}//end testEachVoterIsNamedAndEachFactionShowsItsCounts()

	/**
	 * A secret round shows its totals and nothing per member or faction.
	 *
	 * @return void
	 */
	public function testASecretRoundShowsOnlyTotals(): void {
		$service = $this->makeService(
			round: ['isSecret' => true, 'votesFor' => 20, 'votesAgainst' => 9, 'votesAbstain' => 1],
			ballots: [$this->ballot('p-anna', 'for')]
		);

		$result = $service->forRound(roundId: 'round-1');

		self::assertTrue($result['secret']);
		self::assertSame([], $result['members']);
		self::assertSame([], $result['factions']);
		self::assertSame(['for' => 20, 'against' => 9, 'abstain' => 1], $result['totals']);
	}//end testASecretRoundShowsOnlyTotals()

	/**
	 * A proxy vote counts for the member it was cast for, under their faction.
	 *
	 * @return void
	 */
	public function testAProxyVoteCountsForTheMemberItWasCastFor(): void {
		$service = $this->makeService(
			round: ['isSecret' => false],
			ballots: [$this->ballot('p-cor', 'against', delegatorId: 'p-anna')]
		);

		$result = $service->forRound(roundId: 'round-1');

		self::assertSame('Anna de Boer', $result['members'][0]['name']);
		self::assertSame('GroenLinks', $result['members'][0]['faction']);
		self::assertSame('Cor Visser', $result['members'][0]['castBy']);
		self::assertSame([['faction' => 'GroenLinks', 'for' => 0, 'against' => 1, 'abstain' => 0]], $result['factions']);
	}//end testAProxyVoteCountsForTheMemberItWasCastFor()

	/**
	 * OpenRegister also serves relations flattened by property path
	 * (`relations.1.id`); the voter is read from that form too.
	 *
	 * @return void
	 */
	public function testTheVoterIsReadFromFlattenedRelations(): void {
		$flat = $this->createMock(ObjectEntity::class);
		$flat->method('jsonSerialize')->willReturn(
			[
				'value' => 'abstain',
				'@self' => [
					'id' => 'vote-flat',
					'relations' => [
						'relations.0.id' => 'round-1',
						'relations.0.schema' => 'voting-round',
						'relations.1.id' => 'p-dirk',
						'relations.1.schema' => 'participant',
					],
				],
			]
		);

		$service = $this->makeService(round: ['isSecret' => false], ballots: [$flat]);

		$result = $service->forRound(roundId: 'round-1');

		self::assertSame('Dirk Jansen', $result['members'][0]['name']);
		self::assertSame('abstain', $result['members'][0]['value']);
	}//end testTheVoterIsReadFromFlattenedRelations()

	/**
	 * A round the user cannot read gives nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRoundGivesNothing(): void {
		$service = $this->makeService(round: [], ballots: []);

		self::assertNull($service->forRound(roundId: 'round-2'));
	}//end testAnUnreadableRoundGivesNothing()
}//end class
