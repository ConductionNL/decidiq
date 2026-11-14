<?php

/**
 * Unit tests for MotionAmendmentService: finding a motion's amendments in both
 * link shapes, the chair-set voting order, text-conflict detection and applying
 * an amendment to the motion text.
 *
 * The OpenRegister object service is the one double, an in-memory store of
 * `decision` objects; the link resolver and the text merger are the real ones.
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\MotionAmendmentService;
use OCA\Decidiq\Service\MotionLinkResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Amendments of a motion: found once each, ordered, checked for overlap, applied.
 *
 * @covers \OCA\Decidiq\Service\MotionAmendmentService
 * @uses \OCA\Decidiq\Service\MotionLinkResolver
 * @uses \OCA\Decidiq\Service\AmendmentTextMerger
 */
class MotionAmendmentServiceTest extends TestCase {

	private const MOTION = 'motion-1';

	/**
	 * The decision objects by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * What each findAll filter returns, keyed by its filter field.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $listed = [];

	/**
	 * The saveObject calls: [uuid, object, register, schema].
	 *
	 * @var list<array{0: ?string, 1: array, 2: mixed, 3: mixed}>
	 */
	private array $saved = [];

	/**
	 * Info lines logged.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * A motion and its two amendments, one linked by `amends`, one by a relation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->logged = [];
		$this->objects = [
			self::MOTION => ['id' => self::MOTION, 'decisionType' => 'motion', 'text' => 'De raad besluit het parkeerbeleid te herzien.'],
			'am-1' => ['id' => 'am-1', 'decisionType' => 'amendment', 'amends' => self::MOTION, 'lifecycle' => 'proposed', 'text' => 'Parkeervergunningen worden gratis voor bewoners binnen centrumgebied'],
			'am-2' => ['id' => 'am-2', 'decisionType' => 'amendment', 'relations' => [['id' => self::MOTION, 'schema' => 'decision']], 'lifecycle' => 'deliberating', 'text' => 'Fietsenstallingen komen erbij'],
		];
		$this->listed = [
			'amends' => [$this->objects['am-1']],
			'_relations.amends' => [$this->objects['am-1'], $this->objects['am-2']],
		];
	}//end setUp()

	/**
	 * The service over the in-memory store.
	 *
	 * @return MotionAmendmentService
	 */
	private function service(): MotionAmendmentService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'] ?? [];
				TestCase::assertSame('amendment', $filters['decisionType'] ?? null, 'only amendments are listed');
				$field = array_key_exists('amends', $filters) === true ? 'amends' : '_relations.amends';
				TestCase::assertSame(self::MOTION, $filters[$field]);
				return $this->listed[$field];
			}
		);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntityInterface => isset($this->objects[(string) $id]) ? $this->entity($this->objects[(string) $id]) : null
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saved[] = [$uuid, $object, $register, $schema];
				return $this->entity($object);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === MotionLinkResolver::class ? new MotionLinkResolver(container: $container) : new \stdClass())
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = $message;
			}
		);

		return new MotionAmendmentService(container: $container, logger: $logger, objectService: $objects);
	}//end service()

	/**
	 * An entity double holding an object.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $object): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}//end entity()

	/**
	 * Both link shapes are found, each amendment once, and a relation hit that
	 * does not point at the motion is dropped.
	 *
	 * @return void
	 */
	public function testAmendmentsAreFoundInBothShapesOnce(): void {
		$this->listed['_relations.amends'][] = ['id' => 'am-9', 'decisionType' => 'amendment', 'relations' => [['id' => 'other-motion']]];
		$this->listed['_relations.amends'][] = 'not an entity';

		$found = $this->service()->getAmendmentsForMotion(motionId: self::MOTION);

		$this->assertSame(['am-1', 'am-2'], array_column($found, 'id'));
	}//end testAmendmentsAreFoundInBothShapesOnce()

	/**
	 * A property hit that does not reference the motion is not counted.
	 *
	 * @return void
	 */
	public function testAPropertyHitForAnotherMotionIsDropped(): void {
		$this->listed['amends'] = [['id' => 'am-3', 'decisionType' => 'amendment', 'amends' => 'other-motion']];
		$this->listed['_relations.amends'] = [];

		$this->assertSame([], $this->service()->getAmendmentsForMotion(motionId: self::MOTION));
	}//end testAPropertyHitForAnotherMotionIsDropped()

	/**
	 * The chair's order is stored as votingOrder 1..N, keeping the amendment type.
	 *
	 * @return void
	 */
	public function testTheVotingOrderIsStored(): void {
		$updated = $this->service()->setAmendmentVotingOrder(motionId: self::MOTION, orderedAmendmentIds: ['am-2', 'am-1'], actorId: 'griffier');

		$this->assertSame([['am-2', 1], ['am-1', 2]], array_map(static fn (array $a): array => [$a['id'], $a['votingOrder']], $updated));
		$this->assertSame(['am-2', 'am-1'], array_column($this->saved, 0));
		foreach ($this->saved as [, $object, $register, $schema]) {
			$this->assertSame('amendment', $object['decisionType']);
			$this->assertSame('decidiq', $register);
			$this->assertSame('decision', $schema);
		}

		$this->assertSame(['Decidiq: amendment voting order set on motion motion-1 by griffier'], $this->logged);
	}//end testTheVotingOrderIsStored()

	/**
	 * The voting order refuses a missing actor, an empty list, duplicates and a foreign amendment.
	 *
	 * @return void
	 */
	public function testTheVotingOrderRefusesBadInput(): void {
		$service = $this->service();
		$cases = [
			['', ['am-1'], 'actorId must be a non-empty Nextcloud user UID'],
			['griffier', [], 'orderedAmendmentIds must not be empty'],
			['griffier', ['am-1', 'am-1'], 'orderedAmendmentIds must not contain duplicates'],
			['griffier', ['am-1', 'am-7'], 'Amendment am-7 does not belong to motion motion-1'],
		];
		foreach ($cases as [$actor, $ids, $message]) {
			try {
				$service->setAmendmentVotingOrder(motionId: self::MOTION, orderedAmendmentIds: $ids, actorId: $actor);
				$this->fail('refused: '.$message);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);
	}//end testTheVotingOrderRefusesBadInput()

	/**
	 * A motion without amendments has nothing to order.
	 *
	 * @return void
	 */
	public function testAMotionWithoutAmendmentsHasNothingToOrder(): void {
		$this->listed = ['amends' => [], '_relations.amends' => []];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Motion motion-1 has no amendments to order');

		$this->service()->setAmendmentVotingOrder(motionId: self::MOTION, orderedAmendmentIds: ['am-1'], actorId: 'griffier');
	}//end testAMotionWithoutAmendmentsHasNothingToOrder()

	/**
	 * More than three shared long words with an open sibling marks a conflict
	 * note on the new amendment.
	 *
	 * @return void
	 */
	public function testAnOverlappingAmendmentGetsAConflictNote(): void {
		$this->objects['am-new'] = ['id' => 'am-new', 'decisionType' => 'amendment', 'text' => 'Parkeervergunningen blijven gratis voor bewoners binnen het centrumgebied', 'notes' => [['title' => 'Eerder', 'body' => 'x']]];

		$this->service()->detectConflicts(motionId: self::MOTION, newAmendmentId: 'am-new');

		$this->assertCount(1, $this->saved);
		[$uuid, $object] = $this->saved[0];
		$this->assertSame('am-new', $uuid);
		$this->assertSame('amendment', $object['decisionType']);
		$this->assertCount(2, $object['notes']);
		$this->assertSame('Conflict:', $object['notes'][1]['title']);
		$this->assertSame(['Decidiq: Amendment conflict detected for amendment am-new on motion motion-1'], $this->logged);
	}//end testAnOverlappingAmendmentGetsAConflictNote()

	/**
	 * No note when the overlap is with a closed amendment, is too small, or the
	 * id is not an amendment at all.
	 *
	 * @return void
	 */
	public function testNoConflictWithoutAnOpenOverlap(): void {
		$text = 'Parkeervergunningen blijven gratis voor bewoners binnen het centrumgebied';
		$this->objects['am-new'] = ['id' => 'am-new', 'decisionType' => 'amendment', 'text' => $text];

		$this->objects['am-1']['lifecycle'] = 'adopted';
		$this->listed['amends'] = [$this->objects['am-1']];
		$this->listed['_relations.amends'] = [$this->objects['am-1'], $this->objects['am-2']];
		$this->service()->detectConflicts(motionId: self::MOTION, newAmendmentId: 'am-new');

		$this->objects['am-new']['text'] = 'Parkeervergunningen worden duurder';
		$this->objects['am-1']['lifecycle'] = 'proposed';
		$this->listed['amends'] = [$this->objects['am-1']];
		$this->service()->detectConflicts(motionId: self::MOTION, newAmendmentId: 'am-new');

		$this->objects['not-amendment'] = ['id' => 'not-amendment', 'decisionType' => 'motion', 'text' => $text];
		$this->service()->detectConflicts(motionId: self::MOTION, newAmendmentId: 'not-amendment');
		$this->service()->detectConflicts(motionId: self::MOTION, newAmendmentId: 'missing');

		$this->assertSame([], $this->saved);
	}//end testNoConflictWithoutAnOpenOverlap()

	/**
	 * An amendment compared with itself is no conflict.
	 *
	 * @return void
	 */
	public function testAnAmendmentDoesNotConflictWithItself(): void {
		$this->service()->detectConflicts(motionId: self::MOTION, newAmendmentId: 'am-1');

		$this->assertSame([], $this->saved);
	}//end testAnAmendmentDoesNotConflictWithItself()

	/**
	 * Applying an amendment rewrites the motion text and keeps the original and the history.
	 *
	 * @return void
	 */
	public function testApplyingAnAmendmentRewritesTheMotion(): void {
		$this->objects['am-1']['targetPassage'] = 'te herzien';
		$this->objects['am-1']['proposedText'] = 'binnen een jaar te herzien';

		$this->service()->applyAmendment(motionId: self::MOTION, amendmentId: 'am-1');

		$this->assertCount(1, $this->saved);
		[$uuid, $object, $register, $schema] = $this->saved[0];
		$this->assertSame([self::MOTION, 'decidiq', 'decision'], [$uuid, $register, $schema]);
		$this->assertSame('De raad besluit het parkeerbeleid binnen een jaar te herzien.', $object['text']);
		$this->assertSame('De raad besluit het parkeerbeleid te herzien.', $object['originalText']);
		$this->assertSame('am-1', $object['amendmentHistory'][0]['amendment']);
		$this->assertSame('motion', $object['decisionType']);
	}//end testApplyingAnAmendmentRewritesTheMotion()

	/**
	 * An amendment that is already in the history is not applied twice.
	 *
	 * @return void
	 */
	public function testAnAppliedAmendmentIsNotAppliedAgain(): void {
		$this->objects[self::MOTION]['amendmentHistory'] = [['amendment' => 'am-1']];

		$this->service()->applyAmendment(motionId: self::MOTION, amendmentId: 'am-1');

		$this->assertSame([], $this->saved);
	}//end testAnAppliedAmendmentIsNotAppliedAgain()

	/**
	 * A missing or mistyped amendment or motion is refused.
	 *
	 * @return void
	 */
	public function testApplyingRefusesWhatIsNotAnAmendmentOrMotion(): void {
		$service = $this->service();
		$cases = [
			['missing', 'Amendment missing not found', self::MOTION],
			[self::MOTION, 'Amendment motion-1 not found', self::MOTION],
			['am-1', 'Motion am-2 not found', 'am-2'],
			['am-1', 'Motion nowhere not found', 'nowhere'],
		];
		foreach ($cases as [$amendment, $message, $motion]) {
			try {
				$service->applyAmendment(motionId: $motion, amendmentId: $amendment);
				$this->fail('refused: '.$message);
			} catch (RuntimeException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);
	}//end testApplyingRefusesWhatIsNotAnAmendmentOrMotion()
}//end class
