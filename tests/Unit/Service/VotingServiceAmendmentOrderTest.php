<?php

/**
 * Unit tests for the motion-amendment-v1 parliamentary ordering rules in
 * VotingService::openVotingRound() / closeVotingRound():
 *
 * - a MOTION round is rejected while any amendment is undecided,
 * - an AMENDMENT round is rejected out of the chair-configured order,
 * - amendment rounds relate to the `amendment` schema and transition the
 *   amendment lifecycle,
 * - an adopted amendment is incorporated into the parent motion text.
 *
 * Uses an in-memory double of OpenRegister's published ObjectServiceInterface
 * (ADR-084) — the concrete OpenRegister ObjectService class is never mocked, so
 * the stub-vs-real signature mismatch of issue #90 cannot occur.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AmendmentOrderService;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\ObjectRelationFilter;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ParticipantUuidLookup;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\Decidesk\Service\VoteCastingService;
use OCA\Decidesk\Service\VotingOpenedNotifier;
use OCA\Decidesk\Service\VotingRoundCloser;
use OCA\Decidesk\Service\VotingRoundOpener;
use OCA\Decidesk\Service\VotingRoundPreflight;
use OCA\Decidesk\Service\VotingRoundProjection;
use OCA\Decidesk\Service\VotingRoundResults;
use OCA\Decidesk\Service\VotingRoundRules;
use OCA\Decidesk\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Ordering-enforcement matrix for amendment-before-motion voting.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class VotingServiceAmendmentOrderTest extends TestCase {

	/**
	 * Captured saveObject() payloads keyed by schema slug.
	 *
	 * @var \ArrayObject<int, array{schema: string, object: array<string, mixed>}>
	 */
	private \ArrayObject $saves;

	/**
	 * Mock MotionService (amendment resolution + lifecycle assertions).
	 *
	 * @var MotionService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private MotionService $motionService;

	/**
	 * Build a VotingService over an in-memory object store double.
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
					templateService: $this->createMock(ProcessTemplateService::class),
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
	 * Base store: a meeting without quorum requirement plus a motion.
	 *
	 * @return array<string, array{schema: string, object: array<string, mixed>}>
	 */
	private static function baseStore(): array {
		return [
			'meeting-1' => [
				'schema' => 'meeting',
				'object' => [
					'id' => 'meeting-1',
					'quorumRequired' => 0,
				],
			],
			'motion-1' => [
				// ADR-005: a motion is a `decision` carrying decisionType=motion.
				'schema' => 'decision',
				'object' => [
					'id' => 'motion-1',
					'decisionType' => 'motion',
					'title' => 'Hoofdmotie',
					'lifecycle' => 'deliberating',
					'meeting' => 'meeting-1',
				],
			],
		];

	}//end baseStore()

	/**
	 * Amendment fixture helper.
	 *
	 * @param string $id Amendment id
	 * @param string $lifecycle Lifecycle state (Decision.lifecycle vocabulary)
	 * @param int|null $votingOrder Chair-set order or null
	 * @param string $submittedAt Submission timestamp
	 * @param string|null $outcome Vote result on the separate outcome axis ('adopted'|'rejected')
	 *
	 * @return array<string, mixed>
	 */
	private static function amendment(
		string $id,
		string $lifecycle,
		?int $votingOrder,
		string $submittedAt = '2026-06-01T10:00:00+00:00',
		?string $outcome = null,
	): array {
		// ADR-005: an amendment is a `decision` carrying decisionType=amendment,
		// and its parent link is the `amends` relation that replaced `parentMotion`.
		//
		// `lifecycle` and `outcome` are two axes, not one. A decided amendment
		// is `lifecycle: decided` PLUS `outcome: adopted|rejected` — fixtures
		// that put `adopted` in the lifecycle slot were describing an object
		// shape the schema cannot hold.
		$amendment = [
			'id' => $id,
			'decisionType' => 'amendment',
			'title' => 'Amendement ' . $id,
			'lifecycle' => $lifecycle,
			'amends' => 'motion-1',
			'submittedAt' => $submittedAt,
		];
		if ($outcome !== null) {
			$amendment['outcome'] = $outcome;
		}
		if ($votingOrder !== null) {
			$amendment['votingOrder'] = $votingOrder;
		}

		return $amendment;
	}//end amendment()

	/**
	 * Opening a round on a MOTION with an undecided amendment is rejected.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testMotionRoundRejectedWhileAmendmentUndecided(): void {
		$service = $this->buildService(self::baseStore());
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[self::amendment('amendment-1', 'proposed', null)]
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/amendment\(s\) must be decided first/');

		$service->openVotingRound(
			motionId: 'motion-1',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
		);

	}//end testMotionRoundRejectedWhileAmendmentUndecided()

	/**
	 * Each pending lifecycle blocks the motion round.
	 *
	 * ADR-005 vocabulary: this loop used to iterate `submitted/debating/voting`,
	 * two of which `Decision.lifecycle` cannot hold — so two thirds of this
	 * matrix asserted on states no stored amendment could be in, and would have
	 * gone on passing if the guard had stopped working for them. It now walks
	 * the real in-flight enum, `draft` included.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testMotionRoundRejectedForEveryPendingLifecycle(): void {
		foreach (['draft', 'proposed', 'deliberating', 'voting'] as $lifecycle) {
			$service = $this->buildService(self::baseStore());
			$this->motionService->method('getAmendmentsForMotion')->willReturn(
				[self::amendment('amendment-1', $lifecycle, 1)]
			);

			try {
				$service->openVotingRound(
					motionId: 'motion-1',
					meetingId: 'meeting-1',
					votingMethod: 'for-against-abstain',
					isSecret: false,
					closedAt: null,
				);
				self::fail("Pending lifecycle '{$lifecycle}' must block the motion round");
			} catch (\RuntimeException $e) {
				self::assertStringContainsString('must be decided first', $e->getMessage());
			}
		}

	}//end testMotionRoundRejectedForEveryPendingLifecycle()

	/**
	 * A motion round opens normally once every amendment is decided.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testMotionRoundOpensWhenAllAmendmentsDecided(): void {
		$service = $this->buildService(self::baseStore());
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[
				self::amendment('amendment-1', 'decided', 1, outcome: 'adopted'),
				self::amendment('amendment-2', 'decided', 2, outcome: 'rejected'),
			]
		);
		$this->motionService->expects(self::once())
			->method('transitionLifecycle')
			->with(
				self::anything(),
				self::callback(fn (string $type): bool => $type === 'motion'),
				self::anything(),
				self::anything(),
			);

		$round = $service->openVotingRound(
			motionId: 'motion-1',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
		);

		self::assertSame('motion', $round['relations'][0]['schema']);
		self::assertSame('motion-1', $round['relations'][0]['id']);

	}//end testMotionRoundOpensWhenAllAmendmentsDecided()

	/**
	 * Unknown subjectType is rejected (fail closed).
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testUnknownSubjectTypeRejected(): void {
		$service = $this->buildService(self::baseStore());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/subjectType/');

		$service->openVotingRound(
			motionId: 'motion-1',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules(subjectType: 'resolution'),
		);

	}//end testUnknownSubjectTypeRejected()

	/**
	 * Opening a round on the next amendment in the configured order succeeds,
	 * relates the round to the amendment schema, and transitions the AMENDMENT
	 * lifecycle.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testAmendmentRoundInOrderOpens(): void {
		$store = self::baseStore();
		$store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'deliberating', 1)];
		$store['amendment-2'] = ['schema' => 'decision', 'object' => self::amendment('amendment-2', 'proposed', 2)];

		$service = $this->buildService($store);
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[
				self::amendment('amendment-1', 'deliberating', 1),
				self::amendment('amendment-2', 'proposed', 2),
			]
		);
		$this->motionService->expects(self::once())
			->method('transitionLifecycle')
			->with(
				self::callback(fn (string $id): bool => $id === 'amendment-1'),
				self::callback(fn (string $type): bool => $type === 'amendment'),
				self::callback(fn (string $state): bool => $state === 'voting'),
				self::anything(),
			);

		$round = $service->openVotingRound(
			motionId: 'amendment-1',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules(subjectType: 'amendment'),
		);

		self::assertSame('amendment', $round['relations'][0]['schema']);
		self::assertSame('amendment-1', $round['relations'][0]['id']);

	}//end testAmendmentRoundInOrderOpens()

	/**
	 * Opening a round on an amendment OUT of the configured order is rejected.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testAmendmentRoundOutOfOrderRejected(): void {
		$store = self::baseStore();
		$store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'deliberating', 1)];
		$store['amendment-2'] = ['schema' => 'decision', 'object' => self::amendment('amendment-2', 'deliberating', 2)];

		$service = $this->buildService($store);
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[
				self::amendment('amendment-1', 'deliberating', 1),
				self::amendment('amendment-2', 'deliberating', 2),
			]
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/must be voted first/');

		$service->openVotingRound(
			motionId: 'amendment-2',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules(subjectType: 'amendment'),
		);

	}//end testAmendmentRoundOutOfOrderRejected()

	/**
	 * Once the earlier amendment is decided, the next one becomes votable.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testAmendmentRoundNextAfterDecisionOpens(): void {
		$store = self::baseStore();
		$store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'decided', 1, outcome: 'adopted')];
		$store['amendment-2'] = ['schema' => 'decision', 'object' => self::amendment('amendment-2', 'deliberating', 2)];

		$service = $this->buildService($store);
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[
				self::amendment('amendment-1', 'decided', 1, outcome: 'adopted'),
				self::amendment('amendment-2', 'deliberating', 2),
			]
		);

		$round = $service->openVotingRound(
			motionId: 'amendment-2',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules(subjectType: 'amendment'),
		);

		self::assertSame('amendment-2', $round['relations'][0]['id']);

	}//end testAmendmentRoundNextAfterDecisionOpens()

	/**
	 * Unordered amendments queue after ordered ones (votingOrder wins over
	 * submission age).
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testVotingOrderBeatsSubmissionAge(): void {
		// amendment-old was submitted first but carries no votingOrder;
		// amendment-new has votingOrder 1, so it must be voted first.
		$old = self::amendment('amendment-old', 'deliberating', null, '2026-05-01T10:00:00+00:00');
		$new = self::amendment('amendment-new', 'deliberating', 1, '2026-06-01T10:00:00+00:00');

		$store = self::baseStore();
		$store['amendment-old'] = ['schema' => 'decision', 'object' => $old];
		$store['amendment-new'] = ['schema' => 'decision', 'object' => $new];

		$service = $this->buildService($store);
		$this->motionService->method('getAmendmentsForMotion')->willReturn([$old, $new]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/must be voted first/');

		$service->openVotingRound(
			motionId: 'amendment-old',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules(subjectType: 'amendment'),
		);

	}//end testVotingOrderBeatsSubmissionAge()

	/**
	 * Opening a round on an amendment that is already decided is rejected.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testDecidedAmendmentCannotBeReopened(): void {
		$store = self::baseStore();
		$store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'decided', 1, outcome: 'adopted')];

		$service = $this->buildService($store);
		$this->motionService->method('getAmendmentsForMotion')->willReturn(
			[self::amendment('amendment-1', 'decided', 1, outcome: 'adopted')]
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/already been decided/');

		$service->openVotingRound(
			motionId: 'amendment-1',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			roundRules: new VotingRoundRules(subjectType: 'amendment'),
		);

	}//end testDecidedAmendmentCannotBeReopened()

	/**
	 * Closing an adopted amendment round transitions the AMENDMENT lifecycle
	 * and incorporates it into the parent motion via applyAmendment().
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testCloseAmendmentRoundAdoptsAndIncorporates(): void {
		$store = self::baseStore();
		$store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'voting', 1)];
		$store['round-1'] = [
			'schema' => 'voting-round',
			'object' => [
				'id' => 'round-1',
				'openedAt' => '2026-06-12T10:00:00+00:00',
				'closedAt' => null,
				'isSecret' => false,
				'relations' => [
					['register' => 'decidesk', 'schema' => 'amendment', 'id' => 'amendment-1'],
				],
			],
		];
		$store['vote-1'] = [
			'schema' => 'vote',
			'object' => [
				'id' => 'vote-1',
				'value' => 'for',
				'weight' => 1,
				'relations' => [
					['register' => 'decidesk', 'schema' => 'voting-round', 'id' => 'round-1'],
				],
			],
		];

		$service = $this->buildService($store);

		$this->motionService->expects(self::once())
			->method('transitionLifecycle')
			->with(
				self::callback(fn (string $id): bool => $id === 'amendment-1'),
				self::callback(fn (string $type): bool => $type === 'amendment'),
				// ADR-005: the STATE entered is `decided` for an adopted and a
				// rejected amendment alike; adoption is carried by the separate
				// `outcome` argument below. Asserting on the state alone can no
				// longer tell the two apart, so both are asserted.
				self::callback(fn (string $state): bool => $state === 'decided'),
				self::anything(),
				self::callback(fn (?string $outcome): bool => $outcome === 'adopted'),
			);
		$this->motionService->expects(self::once())
			->method('applyAmendment')
			->with(
				self::callback(fn (string $motionId): bool => $motionId === 'motion-1'),
				self::callback(fn (string $amendmentId): bool => $amendmentId === 'amendment-1'),
			);

		$service->closeVotingRound(votingRoundId: 'round-1');

	}//end testCloseAmendmentRoundAdoptsAndIncorporates()

	/**
	 * A revote round skips the ordering guard (the question was already in
	 * order when first voted).
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testRevoteRoundSkipsOrderingGuard(): void {
		$store = self::baseStore();
		$store['round-0'] = [
			'schema' => 'voting-round',
			'object' => [
				'id' => 'round-0',
				'result' => 'tied',
				'tieBreakRule' => 'revote',
				'relations' => [
					['register' => 'decidesk', 'schema' => 'motion', 'id' => 'motion-1'],
				],
			],
		];

		$service = $this->buildService($store);
		// An undecided amendment exists — but the revote guard must NOT consult it.
		$this->motionService->expects(self::never())->method('getAmendmentsForMotion');

		$round = $service->openVotingRound(
			motionId: 'motion-1',
			meetingId: 'meeting-1',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: null,
			revoteOfRoundId: 'round-0',
		);

		self::assertSame('motion-1', $round['relations'][0]['id']);

	}//end testRevoteRoundSkipsOrderingGuard()
}//end class
