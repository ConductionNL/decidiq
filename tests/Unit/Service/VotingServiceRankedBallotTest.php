<?php

/**
 * Ranked preference rounds through the real voting services (issue #1419).
 *
 * Opens, casts on and closes a ranked-choice round through VotingService and
 * its real collaborators (opener, preflight, caster, ballot factory, results,
 * closer) over a generated mock of OpenRegister's ObjectServiceInterface,
 * the same in-memory fixture VotingServiceCastAsTest uses.
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
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ParticipantUuidLookup;
use OCA\Decidiq\Service\VoteCastingService;
use OCA\Decidiq\Service\VotingOpenedNotifier;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\Decidiq\Service\VotingRoundOpener;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\Decidiq\Service\VotingRoundProjection;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingRoundRules;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Open, cast and close a ranked preference round.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md
 */
class VotingServiceRankedBallotTest extends TestCase {

	/**
	 * Captured saveObject() payloads keyed by schema slug.
	 *
	 * @var \ArrayObject<int, array{schema: string, object: array<string, mixed>}>
	 */
	private \ArrayObject $saves;

	/**
	 * Mock MotionService (shared so tests can assert transition calls).
	 *
	 * @var MotionService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private MotionService $motionService;

	/**
	 * Build a VotingService over an in-memory object store double.
	 *
	 * The store maps object id => ['schema' => ..., 'object' => [...]]. find()
	 * resolves by id; findAll() filters the requested schema on plain field
	 * equality; saveObject() records the payload and upserts the store.
	 *
	 * @param array<string, array{schema: string, object: array<string, mixed>}> $store Seed objects by id
	 *
	 * @return VotingService
	 */
	private function buildService(array $store): VotingService {
		$this->saves = new \ArrayObject();
		$saves = $this->saves;
		$storeRef = new \ArrayObject($store);

		$objectService = $this->makeObjectService(store: $storeRef, saves: $saves);

		$this->motionService = $this->createMock(MotionService::class);

		// VoteBallotFactory (behind VoteCastingService) still resolves OpenRegister
		// through the container, so the same double is served both ways.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				// Notification/activity lookups are fail-soft in the service.
				throw new \RuntimeException('not wired in test: ' . $id);
			}
		);

		$participantResolver = $this->createMock(ParticipantResolver::class);
		$participantResolver->method('resolveMeetingParticipants')->willReturn([]);

		$templateService = $this->createMock(\OCA\Decidiq\Service\ProcessTemplateService::class);
		$templateService->method('resolveVotingRuleForBody')->willReturn(null);

		// VotingService is a thin facade: every operation is delegated to a
		// single-purpose collaborator, so the graph is built explicitly here
		// where production relies on Nextcloud's constructor auto-wiring.
		$logger = new NullLogger();
		$amendmentOrder = new AmendmentOrderService(
			motionService: $this->motionService,
			objectService: $objectService,
		);
		$relationFilter = new ObjectRelationFilter();

		return new VotingService(
			opener: new VotingRoundOpener(
				motionService: $this->motionService,
				participantResolver: $participantResolver,
				preflight: new VotingRoundPreflight(
					logger: $logger,
					motionService: $this->motionService,
					participantResolver: $participantResolver,
					templateService: $templateService,
					objectService: $objectService,
				),
				notifier: new VotingOpenedNotifier(
					logger: $logger,
					participantResolver: $participantResolver,
					container: $container,
				),
				objectService: $objectService,
			),
			caster: new VoteCastingService(
				logger: $logger,
				participantResolver: $participantResolver,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				objectService: $objectService,
				container: $container,
				recusal: $this->createMock(\OCA\Decidiq\Service\RecusalGuard::class),
			),
			closer: new VotingRoundCloser(
				logger: $logger,
				oriService: $this->createMock(OriPublicationService::class),
				motionService: $this->motionService,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				objectService: $objectService,
				fileService: $this->createMock(FileService::class),
			),
			results: new VotingRoundResults(
				motionService: $this->motionService,
				participantResolver: $participantResolver,
				objectService: $objectService,
			),
			projection: new VotingRoundProjection(
				objectService: $objectService,
			),
			participants: new ParticipantUuidLookup(
				objectService: $objectService,
			),
		);

	}//end buildService()

	/**
	 * Build an in-memory ObjectServiceInterface double over the seeded store.
	 *
	 * ADR-084 injects OpenRegister's published contract directly, so the double
	 * is a mock of that interface rather than the anonymous class this suite
	 * previously served through the DI container.
	 *
	 * @param \ArrayObject $store In-memory object store keyed by id
	 * @param \ArrayObject $saves Captured saveObject() payloads
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function makeObjectService(\ArrayObject $store, \ArrayObject $saves): ObjectServiceInterface {
		$schema = '';
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema, $objectService): ObjectServiceInterface {
				$schema = (string)$slug;
				return $objectService;
			}
		);

		$objectService->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use ($store): ?ObjectEntity {
				$row = ($store[(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				if ($schema !== null && $row['schema'] !== $schema) {
					return null;
				}

				return $this->entity($row['object']);
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($store, &$schema): array {
				$out = [];
				foreach ($store as $row) {
					if ($row['schema'] !== $schema) {
						continue;
					}

					$matches = true;
					foreach (($config['filters'] ?? []) as $key => $value) {
						// Relation filters (_relations.*) are presence-only, mirroring OR.
						if (str_starts_with((string)$key, '_relations.') === true) {
							continue;
						}

						if (($row['object'][$key] ?? null) !== $value) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $this->entity($row['object']);
					}
				}

				return $out;
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($store, $saves): ObjectEntity {
				$saves->append(['schema' => (string)$schema, 'object' => $object]);
				$id = (string)($uuid ?? $object['id'] ?? $object['uuid'] ?? ('new-' . count($saves)));
				$store[$id] = ['schema' => (string)$schema, 'object' => $object];
				return $this->entity($object);
			}
		);

		return $objectService;
	}//end makeObjectService()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it verbatim.
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

	/**
	 * A quorum-free meeting, the three clubhouse options and, optionally,
	 * seeded rows.
	 *
	 * @param array<string, array{schema: string, object: array<string, mixed>}> $extra More rows.
	 *
	 * @return array<string, array{schema: string, object: array<string, mixed>}>
	 */
	private static function store(array $extra = []): array {
		return array_merge(
			[
				'meeting-1' => ['schema' => 'meeting', 'object' => ['id' => 'meeting-1', 'quorumRequired' => 0]],
			],
			$extra
		);
	}//end store()

	/**
	 * The three clubhouse options.
	 *
	 * @return array<int, array{key: string, label: string}>
	 */
	private static function options(): array {
		return [
			['key' => 'renoveren', 'label' => 'Renovate the clubhouse'],
			['key' => 'nieuwbouw', 'label' => 'Build a new clubhouse'],
			['key' => 'huren', 'label' => 'Rent a hall'],
		];
	}//end options()

	/**
	 * An open ranked round on motion-1.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array{schema: string, object: array<string, mixed>}
	 */
	private static function rankedRound(array $overrides = []): array {
		return [
			'schema' => 'voting-round',
			'object' => array_merge(
				[
					'id' => 'round-1',
					'votingMethod' => 'ranked-choice',
					'options' => self::options(),
					'openedAt' => '2026-09-28T10:00:00+00:00',
					'closedAt' => null,
					'isSecret' => false,
					'tieBreakRule' => 'rejected',
					'relations' => [['register' => 'decidiq', 'schema' => 'motion', 'id' => 'motion-1']],
				],
				$overrides
			),
		];
	}//end rankedRound()

	/**
	 * A ranked ballot in round-1.
	 *
	 * @param string $id The vote id.
	 * @param array<int, string> $ranking The ranking.
	 *
	 * @return array{schema: string, object: array<string, mixed>}
	 */
	private static function ballot(string $id, array $ranking): array {
		return [
			'schema' => 'vote',
			'object' => [
				'id' => $id,
				'value' => 'ranked',
				'ranking' => $ranking,
				'weight' => 1,
				'relations' => [['schema' => 'voting-round', 'id' => 'round-1']],
			],
		];
	}//end ballot()

	/**
	 * The last object saved to a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lastSaved(string $schema): ?array {
		$last = null;
		foreach ($this->saves as $save) {
			if ($save['schema'] === $schema) {
				$last = $save['object'];
			}
		}

		return $last;
	}//end lastSaved()

	/**
	 * The chair opens a ranked-choice round with three options, and the round
	 * stores them (REQ-PRF-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	public function testARankedRoundOpensWithItsOptions(): void {
		$service = $this->buildService(self::store());

		$service->openVotingRound(
			motionId: 'motion-1',
			meetingId: 'meeting-1',
			votingMethod: 'ranked-choice',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules(tieBreakRule: 'rejected', options: self::options())
		);

		$round = $this->lastSaved(schema: 'voting-round');
		self::assertNotNull($round);
		self::assertSame('ranked-choice', $round['votingMethod']);
		self::assertSame(self::options(), $round['options'] ?? null, 'A ranked round must carry the options members rank');
	}//end testARankedRoundOpensWithItsOptions()

	/**
	 * One option, twenty-one, duplicate keys, options on another method and
	 * chair-decides on a ranked round are refused, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	public function testARankedRoundWithBadOptionsIsRefused(): void {
		$many = [];
		for ($i = 1; $i <= 21; $i++) {
			$many[] = ['key' => 'o' . $i, 'label' => 'Option ' . $i];
		}

		$cases = [
			'at least two options' => ['ranked-choice', [self::options()[0]], 'rejected'],
			'at most twenty options' => ['ranked-choice', $many, 'rejected'],
			'its own key' => ['ranked-choice', [self::options()[0], self::options()[0]], 'rejected'],
			'only be given for a ranked-choice round' => ['for-against-abstain', self::options(), 'rejected'],
			'cannot use the tie-break rule chair-decides' => ['ranked-choice', self::options(), 'chair-decides'],
		];

		foreach ($cases as $reason => [$method, $options, $tieBreak]) {
			$service = $this->buildService(self::store());
			try {
				$service->openVotingRound(
					motionId: 'motion-1',
					meetingId: 'meeting-1',
					votingMethod: $method,
					isSecret: false,
					closedAt: null,
					roundRules: new VotingRoundRules(tieBreakRule: $tieBreak, options: $options)
				);
				self::fail('Expected a refusal: ' . $reason);
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString($reason, $e->getMessage());
			}

			self::assertNull($this->lastSaved(schema: 'voting-round'), 'A refused round is not written: ' . $reason);
		}
	}//end testARankedRoundWithBadOptionsIsRefused()

	/**
	 * A member's full ranking is stored with value `ranked` (REQ-PRF-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function testAFullRankingIsCast(): void {
		$service = $this->buildService(self::store(['round-1' => self::rankedRound(['relations' => []])]));

		$service->castVote(
			votingRoundId: 'round-1',
			participantId: 'part-1',
			value: 'ranked',
			isProxy: false,
			delegatorId: null,
			ranking: ['nieuwbouw', 'renoveren', 'huren']
		);

		$vote = $this->lastSaved(schema: 'vote');
		self::assertNotNull($vote);
		self::assertSame('ranked', $vote['value']);
		self::assertSame(['nieuwbouw', 'renoveren', 'huren'], $vote['ranking']);
	}//end testAFullRankingIsCast()

	/**
	 * A partial or duplicated ranking, a for vote on a ranked round and a
	 * ranking on an ordinary round are refused, and no vote is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function testABallotThatDoesNotFitTheRoundIsRefused(): void {
		$ordinary = self::rankedRound(['relations' => [], 'votingMethod' => 'for-against-abstain']);
		unset($ordinary['object']['options']);

		$cases = [
			'partial' => [self::rankedRound(['relations' => []]), 'ranked', ['renoveren', 'nieuwbouw']],
			'duplicated' => [self::rankedRound(['relations' => []]), 'ranked', ['renoveren', 'renoveren', 'huren']],
			'for on ranked' => [self::rankedRound(['relations' => []]), 'for', null],
			'ranking on ordinary' => [$ordinary, 'ranked', ['renoveren', 'nieuwbouw', 'huren']],
		];

		foreach ($cases as $case => [$round, $value, $ranking]) {
			$service = $this->buildService(self::store(['round-1' => $round]));
			try {
				$service->castVote(
					votingRoundId: 'round-1',
					participantId: 'part-1',
					value: $value,
					isProxy: false,
					delegatorId: null,
					ranking: $ranking
				);
				self::fail('Expected a refusal: ' . $case);
			} catch (\InvalidArgumentException $e) {
				self::assertNotSame('', $e->getMessage());
			}

			self::assertNull($this->lastSaved(schema: 'vote'), 'No vote is stored: ' . $case);
		}
	}//end testABallotThatDoesNotFitTheRoundIsRefused()

	/**
	 * Closing counts the ballots with a Borda count: 5, 3 and 1 points,
	 * renoveren wins, the round is adopted and the motion is decided
	 * (REQ-PRF-003 "Borda count tallying on close").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
	 */
	public function testClosingARankedRoundCountsTheBallots(): void {
		$service = $this->buildService(
			self::store(
				[
					'round-1' => self::rankedRound(),
					'vote-1' => self::ballot(id: 'vote-1', ranking: ['renoveren', 'nieuwbouw', 'huren']),
					'vote-2' => self::ballot(id: 'vote-2', ranking: ['nieuwbouw', 'renoveren', 'huren']),
					'vote-3' => self::ballot(id: 'vote-3', ranking: ['renoveren', 'huren', 'nieuwbouw']),
				]
			)
		);

		$this->motionService->expects(self::once())
			->method('transitionLifecycle')
			->with(
				objectId: 'motion-1',
				objectType: 'motion',
				newState: 'decided',
				actorId: 'system',
				outcome: 'adopted'
			);

		$service->closeVotingRound(votingRoundId: 'round-1');

		$round = $this->lastSaved(schema: 'voting-round');
		self::assertSame('adopted', $round['result']);
		self::assertSame('renoveren', $round['winningOption'] ?? null);
		self::assertSame([5, 3, 1], array_column($round['rankingResult'] ?? [], 'points'));
		self::assertSame(['renoveren', 'nieuwbouw', 'huren'], array_column($round['rankingResult'] ?? [], 'key'));
	}//end testClosingARankedRoundCountsTheBallots()

	/**
	 * A tie at the top stores `tied`, names no winner and leaves the motion
	 * in voting (REQ-PRF-003 "Tie in Borda count is recorded as tied").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-003-borda-count-tallying-determines-the-winner
	 */
	public function testATiedRankedRoundLeavesTheMotionInVoting(): void {
		$service = $this->buildService(
			self::store(
				[
					'round-1' => self::rankedRound(),
					'vote-1' => self::ballot(id: 'vote-1', ranking: ['renoveren', 'nieuwbouw', 'huren']),
					'vote-2' => self::ballot(id: 'vote-2', ranking: ['nieuwbouw', 'renoveren', 'huren']),
				]
			)
		);

		$this->motionService->expects(self::never())->method('transitionLifecycle');

		$service->closeVotingRound(votingRoundId: 'round-1');

		$round = $this->lastSaved(schema: 'voting-round');
		self::assertSame('tied', $round['result']);
		self::assertSame('', $round['winningOption'] ?? null);
	}//end testATiedRankedRoundLeavesTheMotionInVoting()

	/**
	 * A revote of a tied ranked round offers the tied options only, whatever
	 * the request sent (REQ-RPB-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-rpb-001-a-tie-in-a-ranked-round-follows-the-rounds-tie-break-rule
	 */
	public function testARevoteOffersTheTiedOptionsOnly(): void {
		$tied = self::rankedRound(
			[
				'closedAt' => '2026-09-28T11:00:00+00:00',
				'result' => 'tied',
				'tieBreakRule' => 'revote',
				'rankingResult' => [
					['key' => 'renoveren', 'label' => 'Renovate the clubhouse', 'points' => 3, 'rank' => 1],
					['key' => 'nieuwbouw', 'label' => 'Build a new clubhouse', 'points' => 3, 'rank' => 1],
					['key' => 'huren', 'label' => 'Rent a hall', 'points' => 0, 'rank' => 3],
				],
			]
		);
		$service = $this->buildService(self::store(['round-1' => $tied]));

		$service->openVotingRound(
			motionId: 'motion-1',
			meetingId: 'meeting-1',
			votingMethod: 'ranked-choice',
			isSecret: false,
			closedAt: null,
			revoteOfRoundId: 'round-1',
			roundRules: new VotingRoundRules(tieBreakRule: 'revote', options: self::options())
		);

		$round = $this->lastSaved(schema: 'voting-round');
		self::assertSame('round-1', $round['revoteOfRound']);
		self::assertSame(['renoveren', 'nieuwbouw'], array_column($round['options'] ?? [], 'key'));
	}//end testARevoteOffersTheTiedOptionsOnly()
	/**
	 * A ranked-choice round opened before rounds had options holds for,
	 * against and abstain votes, and is still cast and counted that way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function testARankedChoiceRoundWithoutOptionsKeepsItsForAndAgainstVotes(): void {
		$legacy = self::rankedRound(['relations' => []]);
		unset($legacy['object']['options']);
		$service = $this->buildService(self::store(['round-1' => $legacy]));

		$service->castVote(votingRoundId: 'round-1', participantId: 'part-1', value: 'for', isProxy: false, delegatorId: null);
		self::assertSame('for', $this->lastSaved(schema: 'vote')['value'] ?? null);

		$service->closeVotingRound(votingRoundId: 'round-1');
		self::assertSame('adopted', $this->lastSaved(schema: 'voting-round')['result'] ?? null, 'One for vote adopts under the ordinary count');
	}//end testARankedChoiceRoundWithoutOptionsKeepsItsForAndAgainstVotes()
}//end class
