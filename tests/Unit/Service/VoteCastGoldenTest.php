<?php

/**
 * Golden test for casting a vote.
 *
 * Casts ballots through VotingService::castVote() and PaperVoteRecorder over
 * open, closed, unopened, missing, secret and ranked rounds: plain votes, a
 * re-cast over an existing ballot, non-members, a non-voting role, recused
 * members, proxy votes with and without a grant, a delegator already
 * registered, and the acting user's absence delegation for the right and the
 * wrong caller. It records every object-service call by parameter name, every
 * delegation and recusal check with the user it ran for, and the saved ballot
 * (castAt masked). The expected values live in
 * tests/fixtures/vote-cast-golden.json; run with RECORD_VOTE_CAST_GOLDEN=1 to
 * write them, then run again without it.
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use ArrayObject;
use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\PaperVoteRecorder;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\RecusalGuard;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundProjection;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Golden test for casting a vote.
 *
 * @covers \OCA\Decidiq\Service\VotingService
 * @covers \OCA\Decidiq\Service\VoteCastingService
 * @covers \OCA\Decidiq\Service\VoteBallotFactory
 * @covers \OCA\Decidiq\Service\PaperVoteRecorder
 * @uses   \OCA\Decidiq\Service\VoteCastGuard
 * @uses   \OCA\Decidiq\Service\VoteCast
 * @uses   \OCA\Decidiq\Service\VoterTokenSecret
 * @uses   \OCA\Decidiq\Service\SeatHolderGuard
 * @uses   \OCA\Decidiq\Service\SubstitutionResolver
 * @uses   \OCA\Decidiq\Service\AmendmentOrderService
 * @uses   \OCA\Decidiq\Service\ObjectRelationFilter
 * @uses   \OCA\Decidiq\Service\RankedBallotRules
 * @uses   \OCA\Decidiq\Service\BordaCount
 * @uses \OCA\Decidiq\Service\MeetingScopedRecords
 * @uses \OCA\Decidiq\Service\ReferenceId
 */
class VoteCastGoldenTest extends TestCase {
	use VotingFacadeCalls;


	private const GOLDEN = __DIR__ . '/../../fixtures/vote-cast-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every cast matches the recording.
	 *
	 * @return void
	 */
	public function testEveryCastMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$store     = new ArrayObject($this->store());
			$caster    = $this->caster(store: $store);
			try {
				if (isset($case['paper']) === true) {
					$returns = ['returns' => (new PaperVoteRecorder(caster: $caster))->recordVotes(...$case['paper'])];
				} else {
					$returns = ['returns' => $this->castVoteOn($this->facade(caster: $caster), ...$case['args'])];
				}
			} catch (Throwable $e) {
				$returns = ['throws' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = $this->masked(value: $returns) + ['calls' => $this->log];
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_VOTE_CAST_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEveryCastMatchesTheRecording()

	/**
	 * The cases: the facade's named arguments, or a paper recording.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$plain = ['votingRoundId' => 'round-open', 'participantId' => 'p-1', 'value' => 'for', 'isProxy' => false, 'delegatorId' => null, 'callerUid' => 'alice'];
		$proxy = ['isProxy' => true, 'participantId' => 'p-1'] + $plain;

		return [
			'plain-for'                    => ['args' => $plain],
			'plain-against-no-caller'      => ['args' => ['value' => 'against', 'callerUid' => null] + $plain],
			'plain-abstain-default-caller' => ['args' => ['votingRoundId' => 'round-open', 'participantId' => 'p-1', 'value' => 'abstain', 'isProxy' => false, 'delegatorId' => null]],
			'recast-over-existing'         => ['args' => ['participantId' => 'p-3'] + $plain],
			'not-a-member'                 => ['args' => ['participantId' => 'p-9'] + $plain],
			'non-voting-role'              => ['args' => ['participantId' => 'p-guest'] + $plain],
			'substituted-out'              => ['args' => ['participantId' => 'p-out'] + $plain],
			'substitute-holds-seat'        => ['args' => ['participantId' => 'p-sub'] + $plain],
			'recused-caster'               => ['args' => ['participantId' => 'p-recused'] + $plain],
			'proxy-with-grant'             => ['args' => ['delegatorId' => 'p-2'] + $proxy],
			'proxy-grant-wrong-direction'  => ['args' => ['participantId' => 'p-2', 'delegatorId' => 'p-1'] + $proxy],
			'proxy-absence-of-caster'      => ['args' => ['participantId' => 'p-6', 'delegatorId' => 'p-5'] + $proxy],
			'proxy-absence-acting-user'    => ['args' => ['delegatorId' => 'p-5', 'callerUid' => 'alice'] + $proxy],
			'proxy-absence-forged-user'    => ['args' => ['delegatorId' => 'p-5', 'callerUid' => 'mallory'] + $proxy],
			'proxy-absence-empty-user'     => ['args' => ['delegatorId' => 'p-5', 'callerUid' => ''] + $proxy],
			'proxy-absence-no-user'        => ['args' => ['delegatorId' => 'p-5', 'callerUid' => null] + $proxy],
			'proxy-already-registered'     => ['args' => ['delegatorId' => 'p-4'] + $proxy],
			'proxy-recused-delegator'      => ['args' => ['delegatorId' => 'p-recused'] + $proxy],
			'proxy-for-non-member'         => ['args' => ['delegatorId' => 'p-9'] + $proxy],
			'proxy-for-non-voting-role'    => ['args' => ['delegatorId' => 'p-guest'] + $proxy],
			'proxy-by-non-member'          => ['args' => ['participantId' => 'p-9', 'delegatorId' => 'p-1'] + $proxy],
			'proxy-without-delegator'      => ['args' => ['delegatorId' => null] + $proxy],
			'delegator-without-proxy'      => ['args' => ['delegatorId' => 'p-2'] + $plain],
			'secret-plain'                 => ['args' => ['votingRoundId' => 'round-secret'] + $plain],
			'secret-recast'                => ['args' => ['votingRoundId' => 'round-secret', 'participantId' => 'p-3'] + $plain],
			'secret-proxy-with-grant'      => ['args' => ['votingRoundId' => 'round-secret', 'delegatorId' => 'p-2'] + $proxy],
			'secret-proxy-registered'      => ['args' => ['votingRoundId' => 'round-secret', 'delegatorId' => 'p-4'] + $proxy],
			'no-motion-round'              => ['args' => ['votingRoundId' => 'round-loose', 'participantId' => 'p-anyone'] + $plain],
			'closed-round'                 => ['args' => ['votingRoundId' => 'round-closed'] + $plain],
			'unopened-round'               => ['args' => ['votingRoundId' => 'round-unopened'] + $plain],
			'missing-round'                => ['args' => ['votingRoundId' => 'round-none'] + $plain],
			'ranked-full'                  => ['args' => ['votingRoundId' => 'round-ranked', 'value' => 'for', 'ranking' => ['b', 'a', 'c']] + $plain],
			'ranked-numeric-keys'          => ['args' => ['votingRoundId' => 'round-ranked-numeric', 'value' => 'ranked', 'ranking' => [2, 1]] + $plain],
			'ranked-partial'               => ['args' => ['votingRoundId' => 'round-ranked', 'ranking' => ['a', 'b']] + $plain],
			'ranked-missing'               => ['args' => ['votingRoundId' => 'round-ranked'] + $plain],
			'ranking-on-plain-round'       => ['args' => ['ranking' => ['a']] + $plain],
			'ranked-value-on-plain-round'  => ['args' => ['value' => 'ranked'] + $plain],
			'ranked-proxy'                 => ['args' => ['votingRoundId' => 'round-ranked', 'delegatorId' => 'p-2', 'ranking' => ['c', 'b', 'a']] + $proxy],
			'paper'                        => [
				'paper' => [
					'votingRoundId' => 'round-open',
					'votes'         => [
						['participant' => ' p-1 ', 'value' => 'for'],
						['participant' => 'p-9', 'value' => 'against'],
						['participant' => 'p-2', 'value' => 'maybe'],
						['participant' => '', 'value' => 'for'],
						'junk',
						['participant' => 'p-recused', 'value' => 'abstain'],
						['participant' => 'p-3', 'value' => 'against'],
					],
					'callerUid'     => 'chair-uid',
				],
			],
			'paper-closed'                 => ['paper' => ['votingRoundId' => 'round-closed', 'votes' => [['participant' => 'p-1', 'value' => 'for']], 'callerUid' => 'chair-uid']],
		];
	}//end cases()

	/**
	 * The seeded objects, keyed by id.
	 *
	 * @return array<string, array{schema: string, object: array<string, mixed>}>
	 */
	private function store(): array {
		$motion = [['register' => 'decidiq', 'schema' => 'motion', 'id' => 'mot-1']];
		$grants = [
			['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'p-2', 'toParticipantId' => 'p-1'])],
			['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'p-4', 'toParticipantId' => 'p-1'])],
			['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'p-recused', 'toParticipantId' => 'p-1'])],
			['title' => 'Note', 'body' => json_encode(['fromParticipantId' => 'p-5', 'toParticipantId' => 'p-1'])],
			['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'p-9', 'toParticipantId' => 'p-1'])],
			['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'p-guest', 'toParticipantId' => 'p-1'])],
			['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'p-1', 'toParticipantId' => 'p-9'])],
		];
		$open = ['openedAt' => '2026-06-12T10:00:00+00:00', 'closedAt' => null, 'relations' => $motion, 'notes' => $grants];
		$options = [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'], ['key' => 'c', 'label' => 'C']];

		return [
			'round-open'           => ['schema' => 'voting-round', 'object' => ['id' => 'round-open', 'isSecret' => false] + $open],
			'round-secret'         => ['schema' => 'voting-round', 'object' => ['id' => 'round-secret', 'isSecret' => true] + $open],
			'round-loose'          => ['schema' => 'voting-round', 'object' => ['id' => 'round-loose', 'relations' => []] + $open],
			'round-closed'         => ['schema' => 'voting-round', 'object' => ['id' => 'round-closed', 'closedAt' => '2020-01-01T00:00:00+00:00'] + $open],
			'round-unopened'       => ['schema' => 'voting-round', 'object' => ['id' => 'round-unopened', 'openedAt' => null] + $open],
			'round-ranked'         => ['schema' => 'voting-round', 'object' => ['id' => 'round-ranked', 'votingMethod' => 'ranked-choice', 'options' => $options] + $open],
			'round-ranked-numeric' => ['schema' => 'voting-round', 'object' => ['id' => 'round-ranked-numeric', 'votingMethod' => 'ranked-choice', 'options' => [['key' => '1'], ['key' => '2']]] + $open],
			'mot-1'                => ['schema' => 'decision', 'object' => ['id' => 'mot-1', 'meeting' => 'meet-1']],
			'p-1'                  => ['schema' => 'participant', 'object' => ['id' => 'p-1', 'participantType' => 'remote']],
			'p-2'                  => ['schema' => 'participant', 'object' => ['id' => 'p-2', 'participantType' => 'in-person']],
			'p-3'                  => ['schema' => 'participant', 'object' => ['id' => 'p-3', 'participantType' => 'hybrid']],
			'vote-p3'              => ['schema' => 'vote', 'object' => ['id' => 'vote-p3', 'uuid' => 'uuid-p3', 'value' => 'for', 'relations' => [['schema' => 'voting-round', 'id' => 'round-open'], ['schema' => 'participant', 'id' => 'p-3']]]],
			'vote-p4'              => ['schema' => 'vote', 'object' => ['id' => 'vote-p4', 'isProxy' => true, 'relations' => [['schema' => 'voting-round', 'id' => 'round-open'], ['schema' => 'participant', 'id' => 'p-7'], ['schema' => 'participant', 'id' => 'p-4', 'type' => 'delegator']]]],
			'vote-secret-p3'       => ['schema' => 'vote', 'object' => ['id' => 'vote-secret-p3', 'uuid' => 'uuid-sp3', 'voterToken' => hash_hmac('sha256', 'p-3:round-secret', 'golden-secret'), 'relations' => [['schema' => 'voting-round', 'id' => 'round-secret']]]],
			'vote-secret-p4'       => ['schema' => 'vote', 'object' => ['id' => 'vote-secret-p4', 'isProxy' => true, 'delegatorToken' => hash_hmac('sha256', 'p-4:proxy:round-secret', 'golden-secret'), 'relations' => [['schema' => 'voting-round', 'id' => 'round-secret']]]],
			'sub-1'                => ['schema' => 'mandate-substitution', 'object' => ['id' => 'sub-1', 'meeting' => 'meet-1', 'outgoingParticipant' => 'p-out', 'incomingParticipant' => ['id' => 'p-sub'], 'startedAt' => '2026-06-12T09:00:00+00:00']],
			'sub-2'                => ['schema' => 'mandate-substitution', 'object' => ['id' => 'sub-2', 'meeting' => 'meet-1', 'outgoingParticipant' => 'p-2', 'incomingParticipant' => 'p-6', 'endedAt' => '2026-06-12T09:30:00+00:00']],
		];
	}//end store()

	/**
	 * The casting service over the store, with every collaborator recording.
	 *
	 * @param ArrayObject $store The seeded objects
	 *
	 * @return VoteCastingService
	 */
	private function caster(ArrayObject $store): VoteCastingService {
		$objects = $this->objectService(store: $store);

		$prefs = $this->createMock(NotificationPreferenceService::class);
		$prefs->method('hasActiveDelegationTo')->willReturnCallback(
			function (string $delegatorId, string $delegateId): bool {
				$this->log[] = ['delegation' => [$delegatorId, $delegateId]];
				return $delegatorId === 'p-5' && in_array($delegateId, ['alice', 'p-6'], true);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('golden-secret');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $prefs, $appConfig): object {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $objects,
					NotificationPreferenceService::class => $prefs,
					IAppConfig::class => $appConfig,
					default => throw new RuntimeException('not wired: ' . $id),
				};
			}
		);

		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('resolveMeetingParticipants')->willReturnCallback(
			function (string $meetingId): array {
				$this->log[] = ['participants' => $meetingId];
				$rows = [];
				foreach (['p-1', 'p-2', 'p-3', 'p-4', 'p-5', 'p-6', 'p-recused', 'p-out'] as $id) {
					$rows[] = ['id' => $id, 'role' => 'member'];
				}

				$rows[] = ['id' => 'p-guest', 'role' => 'guest'];
				$rows[] = ['id' => 'p-sub', 'role' => 'observer'];
				return $rows;
			}
		);

		$recusal = $this->createMock(RecusalGuard::class);
		$recusal->method('assertNotRecused')->willReturnCallback(
			function (array $round, string $participantId): void {
				$this->log[] = ['recusal' => [$round['id'] ?? null, $participantId]];
				if ($participantId === 'p-recused') {
					throw new RuntimeException('recused');
				}
			}
		);

		$motions = $this->createMock(MotionService::class);

		return new VoteCastingService(
			logger: new NullLogger(),
			participantResolver: $participants,
			amendmentOrder: new AmendmentOrderService(motionService: $motions, objectService: $objects),
			relationFilter: new ObjectRelationFilter(),
			objectService: $objects,
			container: $container,
			recusal: $recusal,
		);
	}//end caster()

	/**
	 * The voting facade around a caster; every other collaborator is inert.
	 *
	 * @param VoteCastingService $caster The real caster
	 *
	 * @return VotingService
	 */
	private function facade(VoteCastingService $caster): VotingService {
		return new VotingService(
			opener: $this->createMock(VotingRoundOpener::class),
			caster: $caster,
			closer: $this->createMock(VotingRoundCloser::class),
			results: $this->createMock(VotingRoundResults::class),
			projection: $this->createMock(VotingRoundProjection::class),
			participants: $this->createMock(ParticipantUuidLookup::class),
		);
	}//end facade()

	/**
	 * An object-service double over the store that records every call.
	 *
	 * @param ArrayObject $store The seeded objects
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(ArrayObject $store): ObjectServiceInterface {
		$schema  = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$slug;
				return $objects;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($store): ?ObjectEntity {
				$this->log[] = ['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]];
				$row = ($store[(string)$id] ?? null);
				if ($row === null || ($schema !== null && $row['schema'] !== $schema)) {
					return null;
				}

				return $this->entity(object: $row['object']);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($store, &$schema): array {
				$this->log[] = ['findAll' => ['schema' => $schema, 'config' => $config]];
				$out    = [];
				$wanted = (string)($config['filters']['schema'] ?? $schema);
				foreach ($store as $row) {
					if ($row['schema'] !== $wanted) {
						continue;
					}

					$matches = true;
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === true || str_starts_with((string)$key, '_relations') === true) {
							continue;
						}

						if (($row['object'][$key] ?? null) !== $value) {
							$matches = false;
						}
					}

					if ($matches === true) {
						$out[] = $this->entity(object: $row['object']);
					}
				}

				return $out;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null) use ($store): ObjectEntity {
				$this->log[] = ['save' => ['register' => $register, 'schema' => $schema, 'object' => $this->masked(value: $object)]];
				$store['saved-' . count($store)] = ['schema' => (string)$schema, 'object' => $object];
				return $this->entity(object: $object + ['saved' => true]);
			}
		);

		return $objects;
	}//end objectService()

	/**
	 * Mask the cast time, the only value that differs per run.
	 *
	 * @param array<string, mixed> $value A recorded value
	 *
	 * @return array<string, mixed> The value with every castAt masked.
	 */
	private function masked(array $value): array {
		array_walk_recursive(
			$value,
			static function (mixed &$item, int|string $key): void {
				if ($key === 'castAt' && is_string($item) === true) {
					$item = (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $item) === 1) ? '<atom>' : $item;
				}
			}
		);
		return $value;
	}//end masked()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}//end entity()
}//end class
