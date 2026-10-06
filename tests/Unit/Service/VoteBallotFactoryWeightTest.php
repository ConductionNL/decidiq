<?php

/**
 * Unit tests for the ballot weight VoteBallotFactory stamps (vot-09, #1376).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\Decidiq\Service\VoteBallotFactory;
use OCA\Decidiq\Service\VoterTokenSecret;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A weighted round counts each ballot with the member's voting weight; every
 * other method counts one.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoteBallotFactoryWeightTest extends TestCase {

	/**
	 * Build a factory over participants, memberships and a crosswalk map.
	 *
	 * @param array<string, array<string, mixed>> $objects     Objects by id: ['schema' => ..., 'object' => [...]]
	 * @param array<string, string>               $memberships Participant id => Membership id
	 *
	 * @return VoteBallotFactory
	 */
	private function factory(array $objects, array $memberships = []): VoteBallotFactory {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use ($objects): ?ObjectEntity {
				$row = ($objects[(string)$id] ?? null);
				if ($row === null || $row['schema'] !== $schema) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($row['object']);
				return $entity;
			}
		);

		$crosswalk = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$crosswalk->method('resolve')->willReturnCallback(
			static function (string $participantId) use ($memberships): ?array {
				if (isset($memberships[$participantId]) === false) {
					return null;
				}

				return ['person' => 'person-' . $participantId, 'membership' => $memberships[$participantId]];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(str_repeat('ab', 32));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $crosswalk, $appConfig): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				if ($id === IAppConfig::class) {
					return $appConfig;
				}

				if ($id === ParticipantToPersonMembershipResolver::class) {
					return $crosswalk;
				}

				throw new \RuntimeException('not wired in test: ' . $id);
			}
		);

		return new VoteBallotFactory($container, new NullLogger(), new VoterTokenSecret($container));
	}//end factory()

	/**
	 * Build a ballot for a participant.
	 *
	 * @param VoteBallotFactory $factory      The factory
	 * @param string            $votingMethod The round's votingMethod
	 * @param bool              $isSecret     Whether the round is secret
	 * @param string|null       $delegatorId  The member a proxy votes for
	 *
	 * @return array<string, mixed>
	 */
	private static function build(VoteBallotFactory $factory, string $votingMethod, bool $isSecret = false, ?string $delegatorId = null): array {
		return $factory->buildVote(
			votingRoundId: 'round-1',
			participantId: 'p-anna',
			value: 'for',
			isProxy: $delegatorId !== null,
			delegatorId: $delegatorId,
			isSecret: $isSecret,
			existingVote: null,
			votingMethod: $votingMethod
		);
	}//end build()

	/**
	 * The Membership's weight wins on a weighted round.
	 *
	 * @return void
	 */
	public function testAWeightedRoundUsesTheMembershipWeight(): void {
		$factory = $this->factory(
			objects: [
				'p-anna' => ['schema' => 'participant', 'object' => ['votingWeight' => 5]],
				'm-anna' => ['schema' => 'membership', 'object' => ['votingWeight' => 250]],
			],
			memberships: ['p-anna' => 'm-anna']
		);

		self::assertSame(250, self::build($factory, votingMethod: VoteBallotFactory::WEIGHTED_METHOD)['weight']);
	}//end testAWeightedRoundUsesTheMembershipWeight()

	/**
	 * Without a Membership weight the Participant's own weight applies.
	 *
	 * @return void
	 */
	public function testAWeightedRoundFallsBackToTheParticipantWeight(): void {
		$factory = $this->factory(
			objects: [
				'p-anna' => ['schema' => 'participant', 'object' => ['votingWeight' => 5]],
				'm-anna' => ['schema' => 'membership', 'object' => []],
			],
			memberships: ['p-anna' => 'm-anna']
		);

		self::assertSame(5, self::build($factory, votingMethod: VoteBallotFactory::WEIGHTED_METHOD)['weight']);
	}//end testAWeightedRoundFallsBackToTheParticipantWeight()

	/**
	 * No usable weight anywhere counts as one; negative or fractional
	 * weights are not used.
	 *
	 * @return void
	 */
	public function testAWeightedRoundWithoutAUsableWeightCountsOne(): void {
		$factory = $this->factory(
			objects: [
				'p-anna' => ['schema' => 'participant', 'object' => ['votingWeight' => -3]],
				'm-anna' => ['schema' => 'membership', 'object' => ['votingWeight' => 1.5]],
			],
			memberships: ['p-anna' => 'm-anna']
		);

		self::assertSame(1, self::build($factory, votingMethod: VoteBallotFactory::WEIGHTED_METHOD)['weight']);
	}//end testAWeightedRoundWithoutAUsableWeightCountsOne()

	/**
	 * Any other method is one member, one vote, whatever the weight.
	 *
	 * @return void
	 */
	public function testAnUnweightedRoundCountsOne(): void {
		$factory = $this->factory(
			objects: ['m-anna' => ['schema' => 'membership', 'object' => ['votingWeight' => 250]]],
			memberships: ['p-anna' => 'm-anna']
		);

		self::assertSame(1, self::build($factory, votingMethod: 'for-against-abstain')['weight']);
	}//end testAnUnweightedRoundCountsOne()

	/**
	 * A proxy ballot counts with the delegator's weight, not the proxy's.
	 *
	 * @return void
	 */
	public function testAProxyBallotCarriesTheDelegatorWeight(): void {
		$factory = $this->factory(
			objects: [
				'm-anna' => ['schema' => 'membership', 'object' => ['votingWeight' => 2]],
				'm-bas' => ['schema' => 'membership', 'object' => ['votingWeight' => 40]],
			],
			memberships: ['p-anna' => 'm-anna', 'p-bas' => 'm-bas']
		);

		self::assertSame(40, self::build($factory, votingMethod: VoteBallotFactory::WEIGHTED_METHOD, delegatorId: 'p-bas')['weight']);
	}//end testAProxyBallotCarriesTheDelegatorWeight()

	/**
	 * A secret weighted ballot is weighted but still names no participant.
	 *
	 * @return void
	 */
	public function testASecretWeightedBallotStaysAnonymous(): void {
		$factory = $this->factory(
			objects: ['m-anna' => ['schema' => 'membership', 'object' => ['votingWeight' => 7]]],
			memberships: ['p-anna' => 'm-anna']
		);

		$vote = self::build($factory, votingMethod: VoteBallotFactory::WEIGHTED_METHOD, isSecret: true);

		self::assertSame(7, $vote['weight']);
		self::assertSame([['register' => 'decidiq', 'schema' => 'voting-round', 'id' => 'round-1']], $vote['relations']);
		self::assertStringNotContainsString('p-anna', json_encode($vote));
	}//end testASecretWeightedBallotStaysAnonymous()
}//end class
