<?php

/**
 * Unit tests for MotionAmendmentService.
 *
 * Runs the service over an in-memory double of OpenRegister's
 * ObjectServiceInterface, with the real MotionLinkResolver and
 * AmendmentTextMerger behind it. Only the storage is faked.
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\MotionAmendmentService;
use OCA\Decidiq\Service\MotionLinkResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\MotionAmendmentService
 * @uses   \OCA\Decidiq\Service\MotionLinkResolver
 * @uses   \OCA\Decidiq\Service\AmendmentTextMerger
 * @spec   openspec/specs/motion-amendment/spec.md
 */
class MotionAmendmentServiceTest extends TestCase {

	/**
	 * Captured saveObject() calls, in order.
	 *
	 * @var \ArrayObject<int, array{uuid: string|null, object: array<string, mixed>}>
	 */
	private \ArrayObject $saves;

	/**
	 * Captured findAll() configs, in order.
	 *
	 * @var \ArrayObject<int, array<string, mixed>>
	 */
	private \ArrayObject $queries;

	/**
	 * Logger mock, for assertions on the info line.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the service over a store.
	 *
	 * findAll() answers the flat `amends` query from $byProperty and the
	 * `_relations.amends` query from $byRelation, so the two lookup shapes can
	 * be fed independently.
	 *
	 * @param array<string, array<string, mixed>> $store      Objects keyed by id (find())
	 * @param array<int, mixed>                   $byProperty Hits for the flat `amends` query
	 * @param array<int, mixed>                   $byRelation Hits for the `_relations.amends` query
	 *
	 * @return MotionAmendmentService
	 */
	private function buildService(array $store = [], array $byProperty = [], array $byRelation = []): MotionAmendmentService {
		$this->saves = new \ArrayObject();
		$this->queries = new \ArrayObject();
		$saves = $this->saves;
		$queries = $this->queries;

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($store[(string)$id]) === true ? $this->entity($store[(string)$id]) : null
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($queries, $byProperty, $byRelation): array {
				$queries->append($config);
				if (array_key_exists('_relations.amends', $config['filters'] ?? []) === true) {
					return $byRelation;
				}

				return $byProperty;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use ($saves): ObjectEntity {
				$saves->append(['uuid' => $uuid, 'object' => $object, 'register' => $register, 'schema' => $schema]);
				return $this->entity($object);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$container): object {
				if ($id === MotionLinkResolver::class) {
					return new MotionLinkResolver(container: $container);
				}

				throw new RuntimeException('not wired in test: ' . $id);
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new MotionAmendmentService(
			container: $container,
			logger: $this->logger,
			objectService: $objectService,
		);

	}//end buildService()

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
	 * An amendment on motion `m1`.
	 *
	 * @param string               $id     Amendment id
	 * @param array<string, mixed> $fields Overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function amendment(string $id, array $fields = []): array {
		return array_merge(
			['id' => $id, 'decisionType' => 'amendment', 'amends' => 'm1', 'lifecycle' => 'proposed', 'text' => ''],
			$fields
		);

	}//end amendment()

	public function testGetAmendmentsQueriesBothShapesWithTheAmendmentDiscriminator(): void {
		$service = $this->buildService();
		$service->getAmendmentsForMotion(motionId: 'm1');

		$this->assertCount(2, $this->queries);
		$this->assertSame(['amends' => 'm1', 'decisionType' => 'amendment'], $this->queries[0]['filters']);
		$this->assertSame(['_relations.amends' => 'm1', 'decisionType' => 'amendment'], $this->queries[1]['filters']);

	}//end testGetAmendmentsQueriesBothShapesWithTheAmendmentDiscriminator()

	public function testGetAmendmentsDedupsAcrossShapesAndDropsForeignHits(): void {
		$a1 = self::amendment('a1');
		$a2 = self::amendment('a2', ['amends' => null, 'relations' => [['id' => 'm1']]]);
		$foreign = self::amendment('a3', ['amends' => 'other-motion']);

		$service = $this->buildService(
			byProperty: [$this->entity($a1), $this->entity($foreign)],
			byRelation: [$this->entity($a1), $this->entity($a2), 'not-an-entity'],
		);

		$result = $service->getAmendmentsForMotion(motionId: 'm1');

		$this->assertSame(['a1', 'a2'], array_column($result, 'id'));

	}//end testGetAmendmentsDedupsAcrossShapesAndDropsForeignHits()

	public function testSetVotingOrderRejectsAnEmptyActor(): void {
		$service = $this->buildService();
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('actorId must be a non-empty Nextcloud user UID');
		$service->setAmendmentVotingOrder(motionId: 'm1', orderedAmendmentIds: ['a1'], actorId: '');

	}//end testSetVotingOrderRejectsAnEmptyActor()

	public function testSetVotingOrderRejectsAnEmptyList(): void {
		$service = $this->buildService();
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('orderedAmendmentIds must not be empty');
		$service->setAmendmentVotingOrder(motionId: 'm1', orderedAmendmentIds: [], actorId: 'chair');

	}//end testSetVotingOrderRejectsAnEmptyList()

	public function testSetVotingOrderRejectsDuplicates(): void {
		$service = $this->buildService();
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('orderedAmendmentIds must not contain duplicates');
		$service->setAmendmentVotingOrder(motionId: 'm1', orderedAmendmentIds: ['a1', 'a1'], actorId: 'chair');

	}//end testSetVotingOrderRejectsDuplicates()

	public function testSetVotingOrderFailsWhenTheMotionHasNoAmendments(): void {
		$service = $this->buildService();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motion m1 has no amendments to order');
		$service->setAmendmentVotingOrder(motionId: 'm1', orderedAmendmentIds: ['a1'], actorId: 'chair');

	}//end testSetVotingOrderFailsWhenTheMotionHasNoAmendments()

	public function testSetVotingOrderRejectsAnAmendmentOfAnotherMotionWithoutSaving(): void {
		$service = $this->buildService(byProperty: [self::amendment('a1')]);

		try {
			$service->setAmendmentVotingOrder(motionId: 'm1', orderedAmendmentIds: ['a1', 'zz'], actorId: 'chair');
			$this->fail('expected InvalidArgumentException');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Amendment zz does not belong to motion m1', $e->getMessage());
		}

		$this->assertCount(0, $this->saves);

	}//end testSetVotingOrderRejectsAnAmendmentOfAnotherMotionWithoutSaving()

	public function testSetVotingOrderStampsPositionsInTheGivenOrder(): void {
		$service = $this->buildService(
			byProperty: [self::amendment('a1'), self::amendment('a2', ['decisionType' => 'amendment'])],
		);

		$this->logger->expects($this->once())->method('info')->with(
			'Decidiq: amendment voting order set on motion m1 by chair',
			['order' => ['a2', 'a1']]
		);

		$updated = $service->setAmendmentVotingOrder(motionId: 'm1', orderedAmendmentIds: ['a2', 'a1'], actorId: 'chair');

		$this->assertSame(['a2', 'a1'], array_column($updated, 'id'));
		$this->assertSame([1, 2], array_column($updated, 'votingOrder'));
		$this->assertCount(2, $this->saves);
		$this->assertSame('a2', $this->saves[0]['uuid']);
		$this->assertSame(1, $this->saves[0]['object']['votingOrder']);
		$this->assertSame('amendment', $this->saves[0]['object']['decisionType']);
		$this->assertSame('decidiq', $this->saves[0]['register']);
		$this->assertSame('decision', $this->saves[0]['schema']);
		$this->assertSame('a1', $this->saves[1]['uuid']);
		$this->assertSame(2, $this->saves[1]['object']['votingOrder']);

	}//end testSetVotingOrderStampsPositionsInTheGivenOrder()

	public function testDetectConflictsIgnoresAnIdThatIsNotAnAmendment(): void {
		$service = $this->buildService(store: ['x' => ['id' => 'x', 'decisionType' => 'motion', 'text' => 'whatever']]);
		$service->detectConflicts(motionId: 'm1', newAmendmentId: 'x');
		$service->detectConflicts(motionId: 'm1', newAmendmentId: 'missing');

		$this->assertCount(0, $this->queries);
		$this->assertCount(0, $this->saves);

	}//end testDetectConflictsIgnoresAnIdThatIsNotAnAmendment()

	public function testDetectConflictsNotesAnOverlapWithALiveSibling(): void {
		$text = 'Gemeente verhoogt subsidie voor sportverenigingen binnen twee maanden';
		$new = self::amendment('new', ['text' => $text, 'notes' => [['title' => 'Bestaand', 'body' => 'x']]]);
		$sibling = self::amendment('old', ['lifecycle' => 'deliberating', 'text' => strtoupper($text)]);

		$service = $this->buildService(store: ['new' => $new], byProperty: [$new, $sibling]);
		$this->logger->expects($this->once())->method('info')
			->with('Decidiq: Amendment conflict detected for amendment new on motion m1');

		$service->detectConflicts(motionId: 'm1', newAmendmentId: 'new');

		$this->assertCount(1, $this->saves);
		$this->assertSame('new', $this->saves[0]['uuid']);
		$notes = $this->saves[0]['object']['notes'];
		$this->assertCount(2, $notes);
		$this->assertSame('Conflict:', $notes[1]['title']);
		$this->assertSame('amendment', $this->saves[0]['object']['decisionType']);

	}//end testDetectConflictsNotesAnOverlapWithALiveSibling()

	public function testDetectConflictsSkipsVotedSiblingsAndSmallOverlaps(): void {
		$text = 'Gemeente verhoogt subsidie voor sportverenigingen binnen twee maanden';
		$new = self::amendment('new', ['text' => $text]);
		$voted = self::amendment('voted', ['lifecycle' => 'adopted', 'text' => $text]);
		// Shares exactly three significant words: not enough.
		$small = self::amendment('small', ['text' => 'gemeente verhoogt subsidie zonder verdere wijziging']);

		$service = $this->buildService(store: ['new' => $new], byProperty: [$new, $voted, $small]);
		$this->logger->expects($this->never())->method('info');

		$service->detectConflicts(motionId: 'm1', newAmendmentId: 'new');

		$this->assertCount(0, $this->saves);

	}//end testDetectConflictsSkipsVotedSiblingsAndSmallOverlaps()

	public function testDetectConflictsTreatsDiacriticsAsWordCharacters(): void {
		// Split on ASCII only, "financiële" would break into "financi" + "le".
		$new = self::amendment('new', ['text' => 'financiële reële coöperatie ideeën']);
		$sibling = self::amendment('sib', ['text' => 'financiële reële coöperatie ideeën']);

		$service = $this->buildService(store: ['new' => $new], byProperty: [$sibling]);
		$service->detectConflicts(motionId: 'm1', newAmendmentId: 'new');

		// Only four significant words, all shared: 4 > 3 is a conflict.
		$this->assertCount(1, $this->saves);

	}//end testDetectConflictsTreatsDiacriticsAsWordCharacters()

	public function testApplyAmendmentRefusesANonAmendment(): void {
		$service = $this->buildService(store: ['a1' => ['id' => 'a1', 'decisionType' => 'motion']]);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Amendment a1 not found');
		$service->applyAmendment(motionId: 'm1', amendmentId: 'a1');

	}//end testApplyAmendmentRefusesANonAmendment()

	public function testApplyAmendmentRefusesANonMotionParent(): void {
		$service = $this->buildService(
			store: [
				'a1' => self::amendment('a1'),
				'm1' => ['id' => 'm1', 'decisionType' => 'amendment'],
			]
		);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motion m1 not found');
		$service->applyAmendment(motionId: 'm1', amendmentId: 'a1');

	}//end testApplyAmendmentRefusesANonMotionParent()

	public function testApplyAmendmentSavesTheMergedMotionOnce(): void {
		$service = $this->buildService(
			store: [
				'a1' => self::amendment('a1', ['proposedText' => 'Nieuwe tekst.']),
				'm1' => ['id' => 'm1', 'decisionType' => 'motion', 'title' => 'Motie', 'text' => 'Oude tekst.'],
			]
		);

		$service->applyAmendment(motionId: 'm1', amendmentId: 'a1');

		$this->assertCount(1, $this->saves);
		$saved = $this->saves[0];
		$this->assertSame('m1', $saved['uuid']);
		$this->assertSame('motion', $saved['object']['decisionType']);
		$this->assertSame('Motie', $saved['object']['title']);
		$this->assertSame('Nieuwe tekst.', $saved['object']['text']);
		$this->assertSame('Oude tekst.', $saved['object']['originalText']);

	}//end testApplyAmendmentSavesTheMergedMotionOnce()

	public function testApplyAmendmentIsANoOpWhenAlreadyApplied(): void {
		$service = $this->buildService(
			store: [
				'a1' => self::amendment('a1', ['proposedText' => 'Nieuwe tekst.']),
				'm1' => [
					'id' => 'm1',
					'decisionType' => 'motion',
					'text' => 'Nieuwe tekst.',
					'amendmentHistory' => [['amendment' => 'a1']],
				],
			]
		);

		$service->applyAmendment(motionId: 'm1', amendmentId: 'a1');

		$this->assertCount(0, $this->saves);

	}//end testApplyAmendmentIsANoOpWhenAlreadyApplied()
}//end class
