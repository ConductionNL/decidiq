<?php

/**
 * Unit tests for the voting-rules-v1 VotingService behaviours that touch
 * OpenRegister: castAs stamping on castVote(), the chair-casting-vote guard on
 * closeVotingRound(), and the rule-validation + revote-once guard on
 * openVotingRound().
 *
 * Uses an in-memory double of OpenRegister's published ObjectServiceInterface
 * (ADR-084) — the concrete OpenRegister ObjectService class is never mocked, so
 * the stub-vs-real signature mismatch of issue #90 cannot occur.
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
 * Tests for castAs stamping, chair casting-vote guard, and revote-once guard.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingServiceCastAsTest extends TestCase {

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
	 * An open round without motion relation (skips the membership branch).
	 *
	 * @return array{schema: string, object: array<string, mixed>}
	 */
	private static function openRound(): array {
		return [
			'schema' => 'voting-round',
			'object' => [
				'id' => 'round-1',
				'openedAt' => '2026-06-12T10:00:00+00:00',
				'closedAt' => null,
				'isSecret' => false,
				'relations' => [],
				'notes' => [],
			],
		];

	}//end openRound()

	/**
	 * Find the captured vote save.
	 *
	 * @return array<string, mixed>|null
	 */
	private function savedVote(): ?array {
		foreach ($this->saves as $save) {
			if ($save['schema'] === 'vote') {
				return $save['object'];
			}
		}

		return null;
	}//end savedVote()

	/**
	 * castVote stamps castAs from the participant's participantType.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testCastVoteStampsRemoteAttendanceMode(): void {
		$service = $this->buildService(
			[
				'round-1' => self::openRound(),
				'part-1' => [
					'schema' => 'participant',
					'object' => [
						'id' => 'part-1',
						'participantType' => 'remote',
					],
				],
			]
		);

		$service->castVote(votingRoundId: 'round-1', participantId: 'part-1', value: 'for', isProxy: false, delegatorId: null);

		$vote = $this->savedVote();
		self::assertNotNull($vote, 'A vote must be persisted');
		self::assertSame('remote', $vote['castAs'], 'Remote attendance mode is recorded alongside the vote');
		self::assertSame('for', $vote['value']);

	}//end testCastVoteStampsRemoteAttendanceMode()

	/**
	 * In-person participants are stamped in-person.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testCastVoteStampsInPersonAttendanceMode(): void {
		$service = $this->buildService(
			[
				'round-1' => self::openRound(),
				'part-1' => [
					'schema' => 'participant',
					'object' => [
						'id' => 'part-1',
						'participantType' => 'in-person',
					],
				],
			]
		);

		$service->castVote(votingRoundId: 'round-1', participantId: 'part-1', value: 'against', isProxy: false, delegatorId: null);

		self::assertSame('in-person', $this->savedVote()['castAs']);

	}//end testCastVoteStampsInPersonAttendanceMode()

	/**
	 * Unknown / unresolvable attendance mode is honestly recorded as 'unknown' —
	 * both when the field is unset and when the participant cannot be found.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testCastVoteStampsUnknownWhenUnresolvable(): void {
		// Participant exists but has no participantType.
		$service = $this->buildService(
			[
				'round-1' => self::openRound(),
				'part-1' => [
					'schema' => 'participant',
					'object' => ['id' => 'part-1'],
				],
			]
		);
		$service->castVote(votingRoundId: 'round-1', participantId: 'part-1', value: 'abstain', isProxy: false, delegatorId: null);
		self::assertSame('unknown', $this->savedVote()['castAs'], 'Unset participantType is recorded as unknown');

		// Participant cannot be resolved at all.
		$service = $this->buildService(['round-1' => self::openRound()]);
		$service->castVote(votingRoundId: 'round-1', participantId: 'ghost', value: 'for', isProxy: false, delegatorId: null);
		self::assertSame('unknown', $this->savedVote()['castAs'], 'Unresolvable participant is recorded as unknown');

	}//end testCastVoteStampsUnknownWhenUnresolvable()

	/**
	 * A casting vote on a round whose tie-break rule is not chair-decides is
	 * refused (fail closed).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testChairCastingRefusedOnWrongTieBreakRule(): void {
		$round = self::openRound();
		$round['object']['tieBreakRule'] = 'rejected';
		$service = $this->buildService(['round-1' => $round]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("tie-break rule is not 'chair-decides'");

		$service->closeVotingRound(votingRoundId: 'round-1', chairCasting: 'for');

	}//end testChairCastingRefusedOnWrongTieBreakRule()

	/**
	 * A casting vote value other than for/against is refused (fail closed).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testChairCastingRefusedOnInvalidValue(): void {
		$round = self::openRound();
		$round['object']['tieBreakRule'] = 'chair-decides';
		$service = $this->buildService(['round-1' => $round]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("value must be 'for' or 'against'");

		$service->closeVotingRound(votingRoundId: 'round-1', chairCasting: 'abstain');

	}//end testChairCastingRefusedOnInvalidValue()

	/**
	 * A valid casting vote on a chair-decides round is persisted and resolves
	 * the tally (the round carries tied 1-1 votes).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testChairCastingResolvesTiedRound(): void {
		$round = self::openRound();
		$round['object']['tieBreakRule'] = 'chair-decides';
		$round['object']['voteThreshold'] = 'simple-majority';

		$service = $this->buildService(
			[
				'round-1' => $round,
				'vote-1' => [
					'schema' => 'vote',
					'object' => [
						'id' => 'vote-1',
						'value' => 'for',
						'weight' => 1,
						'relations' => [['schema' => 'voting-round', 'id' => 'round-1']],
					],
				],
				'vote-2' => [
					'schema' => 'vote',
					'object' => [
						'id' => 'vote-2',
						'value' => 'against',
						'weight' => 1,
						'relations' => [['schema' => 'voting-round', 'id' => 'round-1']],
					],
				],
			]
		);

		$closed = $service->closeVotingRound(votingRoundId: 'round-1', chairCasting: 'for');

		self::assertSame('adopted', $closed['result'], 'The casting vote resolves the 1-1 tie to adopted');
		self::assertSame('for', $closed['chairCastingVote'], 'The casting vote is recorded for the audit trail');

	}//end testChairCastingResolvesTiedRound()

	/**
	 * openVotingRound rejects unknown rule values (fail closed) before any
	 * round is written.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testOpenVotingRoundRejectsUnknownRuleValues(): void {
		$service = $this->buildService([]);

		try {
			$service->openVotingRound(
				motionId: 'motion-1',
				meetingId: 'meeting-1',
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: null,
				roundRules: new VotingRoundRules(voteThreshold: 'plurality')
			);
			self::fail('Unknown voteThreshold must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('voteThreshold', $e->getMessage());
		}

		try {
			$service->openVotingRound(
				motionId: 'motion-1',
				meetingId: 'meeting-1',
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: null,
				roundRules: new VotingRoundRules(abstentionHandling: 'half')
			);
			self::fail('Unknown abstentionHandling must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('abstentionHandling', $e->getMessage());
		}

		try {
			$service->openVotingRound(
				motionId: 'motion-1',
				meetingId: 'meeting-1',
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: null,
				roundRules: new VotingRoundRules(tieBreakRule: 'coin-flip')
			);
			self::fail('Unknown tieBreakRule must be rejected');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('tieBreakRule', $e->getMessage());
		}

		self::assertCount(0, $this->saves, 'No round may be written on rule rejection');

	}//end testOpenVotingRoundRejectsUnknownRuleValues()

	/**
	 * Seed store for the revote-guard tests: a quorum-free meeting and a
	 * closed round in the given state.
	 *
	 * @param array<string, mixed> $roundOverrides Overrides for the tied round
	 *
	 * @return array<string, array{schema: string, object: array<string, mixed>}>
	 */
	private static function revoteStore(array $roundOverrides = []): array {
		return [
			'meeting-1' => [
				'schema' => 'meeting',
				'object' => [
					'id' => 'meeting-1',
					'quorumRequired' => 0,
				],
			],
			'round-1' => [
				'schema' => 'voting-round',
				'object' => array_merge(
					[
						'id' => 'round-1',
						'openedAt' => '2026-06-12T10:00:00+00:00',
						'closedAt' => '2026-06-12T11:00:00+00:00',
						'result' => 'tied',
						'tieBreakRule' => 'revote',
						'relations' => [],
						'notes' => [],
					],
					$roundOverrides
				),
			],
		];

	}//end revoteStore()

	/**
	 * A valid revote creates a linked round and skips the motion transition.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testRevoteCreatesLinkedRoundWithoutMotionTransition(): void {
		$service = $this->buildService(self::revoteStore());

		$this->motionService->expects(self::never())->method('transitionLifecycle');

		$created = $service->openVotingRound(
			motionId: 'motion-1',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			revoteOfRoundId: 'round-1',
			roundRules: new VotingRoundRules(
				voteThreshold: 'simple-majority',
				abstentionHandling: 'exclude',
				tieBreakRule: 'revote'
			)
		);

		self::assertSame('round-1', $created['revoteOfRound'], 'The revote round links the tied round');

	}//end testRevoteCreatesLinkedRoundWithoutMotionTransition()

	/**
	 * Revote guard refusals: missing round, not tied, wrong rule, already revoted.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testRevoteGuardFailsClosed(): void {
		$open = function (VotingService $service): void {
			$service->openVotingRound(
				motionId: 'motion-1',
				meetingId: 'meeting-1',
				votingMethod: 'for-against-abstain',
				isSecret: false,
				closedAt: null,
				revoteOfRoundId: 'round-1'
			);
		};

		// Round not found.
		$store = self::revoteStore();
		unset($store['round-1']);
		try {
			$open($this->buildService($store));
			self::fail('Revote of a missing round must be refused');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('not found', $e->getMessage());
		}

		// Not tied.
		try {
			$open($this->buildService(self::revoteStore(['result' => 'rejected'])));
			self::fail('Revote of a non-tied round must be refused');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('not tied', $e->getMessage());
		}

		// Wrong tie-break rule.
		try {
			$open($this->buildService(self::revoteStore(['tieBreakRule' => 'chair-decides'])));
			self::fail('Revote under a non-revote rule must be refused');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString("tie-break rule is not 'revote'", $e->getMessage());
		}

		// Already revoted once.
		$store = self::revoteStore();
		$store['round-2'] = [
			'schema' => 'voting-round',
			'object' => [
				'id' => 'round-2',
				'revoteOfRound' => 'round-1',
				'relations' => [],
			],
		];
		try {
			$open($this->buildService($store));
			self::fail('A second revote of the same round must be refused');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('already been revoted once', $e->getMessage());
		}

	}//end testRevoteGuardFailsClosed()
}//end class
