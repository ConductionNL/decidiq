<?php

/**
 * What the public projection of a voting round shows, and when it shows nothing.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VotingRoundProjection;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\VotingRoundProjection
 */
class VotingRoundProjectionTest extends TestCase {

	/**
	 * Every find(): "register:schema/id".
	 *
	 * @var list<string>
	 */
	private array $finds = [];

	/**
	 * The projection over stored objects.
	 *
	 * @param array<string, array> $objects Object data by "schema/id".
	 *
	 * @return VotingRoundProjection The projection.
	 */
	private function projection(array $objects): VotingRoundProjection {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($objects): ?ObjectEntity {
				$this->finds[] = $register . ':' . $schema . '/' . $id;
				if (isset($objects[$schema . '/' . $id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject($objects[$schema . '/' . $id]);

				return $entity;
			}
		);

		return new VotingRoundProjection($store);
	}//end projection()

	/**
	 * A published open round shows its motion title, tallies and leading option.
	 *
	 * @return void
	 */
	public function testAPublishedRoundShowsItsTallyAndMotion(): void {
		$state = $this->projection([
			'voting-round/r-1' => [
				'lifecycle' => 'published',
				'votingMethod' => 'show-of-hands',
				'votesFor' => '7',
				'votesAgainst' => 3,
				'votesAbstain' => 1,
				'openedAt' => '2026-09-01T10:00:00Z',
				'relations' => [['schema' => 'agenda-item', 'id' => 'ai-1'], ['schema' => 'motion', 'id' => 'mo-1']],
			],
			'decision/mo-1' => ['title' => 'Motion on parking'],
		])->publicState('r-1');

		self::assertSame(
			[
				'motionTitle' => 'Motion on parking',
				'votingMethod' => 'show-of-hands',
				'isOpen' => true,
				'votesFor' => 7,
				'votesAgainst' => 3,
				'votesAbstain' => 1,
				'preselectedOption' => 'for',
				'openedAt' => '2026-09-01T10:00:00Z',
			],
			$state
		);
		self::assertSame(['decidiq:voting-round/r-1', 'decidiq:decision/mo-1'], $this->finds);
	}//end testAPublishedRoundShowsItsTallyAndMotion()

	/**
	 * Secret, unpublished and missing rounds show nothing.
	 *
	 * @return void
	 */
	public function testSecretUnpublishedAndMissingRoundsShowNothing(): void {
		$projection = $this->projection([
			'voting-round/secret' => ['lifecycle' => 'published', 'isSecret' => true],
			'voting-round/open' => ['lifecycle' => 'open'],
			'voting-round/status' => ['status' => 'published', 'closedAt' => '2026-09-01T11:00:00Z'],
		]);

		self::assertNull($projection->publicState('secret'));
		self::assertNull($projection->publicState('open'));
		self::assertNull($projection->publicState('gone'));

		$state = $projection->publicState('status');
		self::assertNotNull($state, 'a status of published counts when there is no lifecycle');
		self::assertFalse($state['isOpen']);
		self::assertSame('', $state['motionTitle']);
		self::assertSame('', $state['votingMethod']);
		self::assertNull($state['openedAt']);
	}//end testSecretUnpublishedAndMissingRoundsShowNothing()

	/**
	 * Only a strict leader is preselected.
	 *
	 * @return void
	 */
	public function testOnlyAStrictLeaderIsPreselected(): void {
		$projection = $this->projection([
			'voting-round/against' => ['lifecycle' => 'published', 'votesFor' => 1, 'votesAgainst' => 4, 'votesAbstain' => 2],
			'voting-round/abstain' => ['lifecycle' => 'published', 'votesFor' => 1, 'votesAgainst' => 1, 'votesAbstain' => 2],
			'voting-round/tie' => ['lifecycle' => 'published', 'votesFor' => 3, 'votesAgainst' => 3, 'votesAbstain' => 1],
			'voting-round/none' => ['lifecycle' => 'published'],
		]);

		self::assertSame('against', $projection->publicState('against')['preselectedOption']);
		self::assertSame('abstain', $projection->publicState('abstain')['preselectedOption']);
		self::assertNull($projection->publicState('tie')['preselectedOption']);
		self::assertNull($projection->publicState('none')['preselectedOption']);
	}//end testOnlyAStrictLeaderIsPreselected()

	/**
	 * A motion relation without an id, or a motion that is gone, gives an empty title.
	 *
	 * @return void
	 */
	public function testAnUnresolvableMotionGivesAnEmptyTitle(): void {
		$projection = $this->projection([
			'voting-round/no-id' => ['lifecycle' => 'published', 'relations' => [['schema' => 'motion'], ['schema' => 'motion', 'id' => 'mo-2']]],
			'voting-round/gone' => ['lifecycle' => 'published', 'relations' => [['schema' => 'motion', 'id' => 'mo-9']]],
		]);

		self::assertSame('', $projection->publicState('no-id')['motionTitle']);
		self::assertSame('', $projection->publicState('gone')['motionTitle']);
		self::assertSame(
			['decidiq:voting-round/no-id', 'decidiq:voting-round/gone', 'decidiq:decision/mo-9'],
			$this->finds,
			'the first motion relation decides; one without an id is not looked up'
		);
	}//end testAnUnresolvableMotionGivesAnEmptyTitle()
}//end class
