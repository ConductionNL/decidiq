<?php

/**
 * Unit tests for MotionService.
 *
 * Runs MotionService over an in-memory double of OpenRegister's published
 * ObjectServiceInterface (ADR-084), with the REAL MotionLifecycleTransitioner
 * and MotionLinkResolver wired behind the container. Only the storage is
 * faked; the state machine, the co-signer bookkeeping and the amendment
 * conflict detection are the production code.
 *
 * This class was unconditionally markTestSkipped() for issue #90 ("real
 * OpenRegister ObjectService loads instead of the stub"). That cause is gone:
 * the service now takes the contract interface, which is the same class with
 * or without OpenRegister installed. What was left behind was the tests
 * themselves, which still spoke the pre-ADR-005 Motion vocabulary
 * (`submitted`, `debating`), returned bare stdClass doubles and null from
 * methods typed to return an ObjectEntityInterface, and matched the saved
 * payload against the wrong positional argument.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Lifecycle\MotionLifecycleTransitioner;
use OCA\Decidiq\Service\MotionLinkResolver;
use OCA\Decidiq\Service\MotionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for MotionService.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
 */
class MotionServiceTest extends TestCase {

	/**
	 * Captured saveObject() calls, in order.
	 *
	 * @var \ArrayObject<int, array{uuid: string|null, object: array<string, mixed>}>
	 */
	private \ArrayObject $saves;

	/**
	 * Build a MotionService over an in-memory object store.
	 *
	 * find() resolves by id; findAll() applies plain field-equality filters and
	 * treats `_relations.*` keys as presence-only, the way OpenRegister does;
	 * saveObject() records the call and upserts the store.
	 *
	 * @param array<string, array<string, mixed>> $store Seed objects keyed by id
	 *
	 * @return MotionService
	 */
	private function buildService(array $store): MotionService {
		$this->saves = new \ArrayObject();
		$saves = $this->saves;
		$storeRef = new \ArrayObject($store);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($storeRef[(string)$id]) === true
				? $this->entity($storeRef[(string)$id])
				: null
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($storeRef): array {
				$out = [];
				foreach ($storeRef as $object) {
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (str_starts_with((string)$key, '_relations.') === true) {
							continue;
						}

						if (($object[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$out[] = $this->entity($object);
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
			) use ($storeRef, $saves): ObjectEntity {
				$saves->append(['uuid' => $uuid, 'object' => $object]);
				$storeRef[(string)($uuid ?? $object['id'] ?? ('new-' . count($saves)))] = $object;
				return $this->entity($object);
			}
		);

		// The co-signer threshold is disabled (0) here; its own matrix lives in
		// MotionServiceCosignerThresholdTest.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $appConfig, &$container): object {
				return match ($id) {
					IAppConfig::class => $appConfig,
					MotionLinkResolver::class => new MotionLinkResolver(container: $container),
					MotionLifecycleTransitioner::class => new MotionLifecycleTransitioner(
						container: $container,
						logger: new NullLogger(),
						guard: new DecisionTransitionGuard(),
						objectService: $objectService,
					),
					default => throw new \RuntimeException('not wired in test: ' . $id),
				};
			}
		);

		return new MotionService(
			container: $container,
			logger: new NullLogger(),
			userManager: $this->createMock(IUserManager::class),
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
	 * A motion-typed decision (ADR-005: a motion is a `decision` with decisionType=motion).
	 *
	 * @param array<string, mixed> $fields Overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function motion(array $fields = []): array {
		return array_merge(
			[
				'id' => 'motion-uuid',
				'decisionType' => 'motion',
				'title' => 'Test Motie',
				'text' => 'Originele motietekst.',
				'lifecycle' => 'proposed',
			],
			$fields
		);

	}//end motion()

	/**
	 * An amendment-typed decision on motion-uuid.
	 *
	 * @param string $id Amendment id
	 * @param array<string, mixed> $fields Overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function amendment(string $id, array $fields = []): array {
		return array_merge(
			[
				'id' => $id,
				'decisionType' => 'amendment',
				'amends' => 'motion-uuid',
				'lifecycle' => 'proposed',
			],
			$fields
		);

	}//end amendment()

	/**
	 * Test that transitionLifecycle succeeds for an allowed transition.
	 *
	 * `proposed -> deliberating` is the Decision-vocabulary name of the retired
	 * Motion edge `submitted -> debating` (openspec/specs/decision-management/spec.md,
	 * lifecycle `draft, proposed, deliberating, voting, decided, enacted, archived`).
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionLifecycleAllowedTransition(): void {
		$service = $this->buildService(['motion-uuid' => self::motion()]);

		$service->transitionLifecycle('motion-uuid', 'motion', 'deliberating', 'user1');

		self::assertCount(1, $this->saves, 'An allowed transition writes the motion exactly once');
		self::assertSame('motion-uuid', $this->saves[0]['uuid']);
		self::assertSame('deliberating', $this->saves[0]['object']['lifecycle']);
		self::assertSame('Test Motie', $this->saves[0]['object']['title'], 'The full motion is written, not a partial payload');

	}//end testTransitionLifecycleAllowedTransition()

	/**
	 * Test that transitionLifecycle throws when transition is not allowed.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	public function testTransitionLifecycleBlocksInvalidTransition(): void {
		$service = $this->buildService(['motion-uuid' => self::motion()]);

		// proposed -> decided skips deliberation and the vote: refused.
		try {
			$service->transitionLifecycle('motion-uuid', 'motion', 'decided', 'user1', 'adopted');
			self::fail('proposed -> decided must be refused');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString("from 'proposed' to 'decided' is not allowed", $e->getMessage());
		}

		self::assertCount(0, $this->saves, 'A refused transition writes nothing');

	}//end testTransitionLifecycleBlocksInvalidTransition()

	/**
	 * Test that addCoSigner appends a new co-signer.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
	 *
	 * @return void
	 */
	public function testAddCoSignerIdempotent(): void {
		$service = $this->buildService(['motion-uuid' => self::motion(['coSigners' => ['A. Pietersen']])]);

		$service->addCoSigner('motion-uuid', 'M. de Vries');

		self::assertCount(1, $this->saves);
		self::assertSame('motion-uuid', $this->saves[0]['uuid']);
		self::assertSame(['A. Pietersen', 'M. de Vries'], $this->saves[0]['object']['coSigners']);

		// Adding the same name again is a no-op: the store now holds it.
		$service->addCoSigner('motion-uuid', 'M. de Vries');
		self::assertCount(1, $this->saves, 'A repeated co-signer is not written twice');

	}//end testAddCoSignerIdempotent()

	/**
	 * Test that addCoSigner does NOT duplicate an existing co-signer.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
	 *
	 * @return void
	 */
	public function testAddCoSignerDoesNotDuplicate(): void {
		$service = $this->buildService(['motion-uuid' => self::motion(['coSigners' => ['A. Pietersen']])]);

		$service->addCoSigner('motion-uuid', 'A. Pietersen');

		self::assertCount(0, $this->saves, 'saveObject must not be called when the name already exists');

	}//end testAddCoSignerDoesNotDuplicate()

	/**
	 * Test that detectConflicts returns without saving when no overlap exists.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testDetectConflictsNoOverlap(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(),
				'new-amendment-uuid' => self::amendment('new-amendment-uuid', ['text' => 'Completely different text about transport']),
				'other-amendment-uuid' => self::amendment('other-amendment-uuid', ['text' => 'Zonnepanelen beleid voor scholen']),
			]
		);

		// Both amendments are visible on the motion, so an empty result below
		// is the overlap check speaking, not a lookup that found nothing.
		self::assertCount(2, $service->getAmendmentsForMotion('motion-uuid'));

		$service->detectConflicts('motion-uuid', 'new-amendment-uuid');

		self::assertCount(0, $this->saves, 'No conflict note is saved when the texts do not overlap');

	}//end testDetectConflictsNoOverlap()

	/**
	 * Test that detectConflicts saves a conflict note when overlap is detected.
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.5
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testDetectConflictsWithOverlap(): void {
		$overlappingText = 'De raad besluit uitvoeringsplan duurzame energie begroting jeugdzorg aanpassen vastgesteld';

		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(),
				'new-amendment-uuid' => self::amendment('new-amendment-uuid', ['text' => $overlappingText]),
				'other-amendment-uuid' => self::amendment('other-amendment-uuid', ['text' => $overlappingText]),
			]
		);

		$service->detectConflicts('motion-uuid', 'new-amendment-uuid');

		self::assertCount(1, $this->saves, 'The conflict note is written once, on the new amendment');
		self::assertSame('new-amendment-uuid', $this->saves[0]['uuid']);
		self::assertSame('amendment', $this->saves[0]['object']['decisionType']);

		$conflictNotes = array_filter(
			($this->saves[0]['object']['notes'] ?? []),
			static fn (array $note): bool => str_starts_with(($note['title'] ?? ''), 'Conflict:')
		);
		self::assertCount(1, $conflictNotes);

	}//end testDetectConflictsWithOverlap()

	/**
	 * An adopted amendment with a target passage replaces that passage in the
	 * motion text, instead of being pasted under a `---` separator (#1394).
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testApplyAmendmentReplacesTheTargetPassage(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(['text' => 'De raad verhoogt het budget met 10% in 2027.']),
				'amendment-uuid' => self::amendment(
					'amendment-uuid',
					['title' => 'Amendement A', 'targetPassage' => '10%', 'proposedText' => '5%']
				),
			]
		);

		$service->applyAmendment('motion-uuid', 'amendment-uuid');

		self::assertCount(1, $this->saves);
		self::assertSame('motion-uuid', $this->saves[0]['uuid'], 'The MOTION is updated, not the amendment');

		$saved = $this->saves[0]['object'];
		self::assertSame('De raad verhoogt het budget met 5% in 2027.', $saved['text'], 'The passage is worked into the wording');
		self::assertStringNotContainsString('---', $saved['text']);
		self::assertStringNotContainsString('Amendement A', $saved['text']);
		self::assertSame('De raad verhoogt het budget met 10% in 2027.', $saved['originalText'], 'The original wording is kept in its own field');

		self::assertCount(1, $saved['amendmentHistory']);
		$entry = $saved['amendmentHistory'][0];
		self::assertSame('amendment-uuid', $entry['amendment']);
		self::assertSame('De raad verhoogt het budget met 10% in 2027.', $entry['before']);
		self::assertSame('De raad verhoogt het budget met 5% in 2027.', $entry['after']);
		self::assertSame('passage', $entry['mode']);
		self::assertNotEmpty($entry['appliedAt']);

	}//end testApplyAmendmentReplacesTheTargetPassage()

	/**
	 * Without a target passage the proposed text replaces the whole wording.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testApplyAmendmentWithoutPassageReplacesTheWholeText(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(),
				'amendment-uuid' => self::amendment(
					'amendment-uuid',
					['title' => 'Amendement A', 'proposedText' => 'Vervangende motietekst.']
				),
			]
		);

		$service->applyAmendment('motion-uuid', 'amendment-uuid');

		$saved = $this->saves[0]['object'];
		self::assertSame('Vervangende motietekst.', $saved['text']);
		self::assertSame('Originele motietekst.', $saved['originalText']);
		self::assertSame('full', $saved['amendmentHistory'][0]['mode']);

	}//end testApplyAmendmentWithoutPassageReplacesTheWholeText()

	/**
	 * A legacy amendment that only carries `text` is read as the full
	 * replacement, the same way the amendment diff view reads it.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testApplyLegacyAmendmentUsesItsTextAsTheReplacement(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(),
				'amendment-uuid' => self::amendment(
					'amendment-uuid',
					['title' => 'Amendement A', 'text' => 'Vervangende tekst voor artikel 2.']
				),
			]
		);

		$service->applyAmendment('motion-uuid', 'amendment-uuid');

		$saved = $this->saves[0]['object'];
		self::assertSame('Vervangende tekst voor artikel 2.', $saved['text']);
		self::assertSame('Originele motietekst.', $saved['originalText']);

	}//end testApplyLegacyAmendmentUsesItsTextAsTheReplacement()

	/**
	 * A second amendment builds on the consolidated text, never overwrites the
	 * original wording, and extends the history; re-applying one is a no-op.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testSecondAmendmentKeepsTheOriginalAndExtendsTheHistory(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(
					[
						'text' => 'De raad verhoogt het budget met 5% in 2027.',
						'originalText' => 'De raad verhoogt het budget met 10% in 2027.',
						'amendmentHistory' => [
							[
								'amendment' => 'amendment-a',
								'before' => 'De raad verhoogt het budget met 10% in 2027.',
								'after' => 'De raad verhoogt het budget met 5% in 2027.',
								'mode' => 'passage',
								'appliedAt' => '2026-09-01T10:00:00+00:00',
							],
						],
					]
				),
				'amendment-a' => self::amendment('amendment-a', ['targetPassage' => '10%', 'proposedText' => '5%']),
				'amendment-b' => self::amendment('amendment-b', ['targetPassage' => '2027', 'proposedText' => '2028']),
			]
		);

		$service->applyAmendment('motion-uuid', 'amendment-b');

		$saved = $this->saves[0]['object'];
		self::assertSame('De raad verhoogt het budget met 5% in 2028.', $saved['text']);
		self::assertSame('De raad verhoogt het budget met 10% in 2027.', $saved['originalText'], 'The original is set once and never overwritten');
		self::assertCount(2, $saved['amendmentHistory']);
		self::assertSame('amendment-b', $saved['amendmentHistory'][1]['amendment']);

		$service->applyAmendment('motion-uuid', 'amendment-b');
		self::assertCount(1, $this->saves, 'An amendment already in the history is not applied twice');

	}//end testSecondAmendmentKeepsTheOriginalAndExtendsTheHistory()

	/**
	 * A target passage that no longer occurs in the motion is refused, and the
	 * wording stays as it is rather than being guessed at.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function testApplyAmendmentRefusesAPassageThatIsNotInTheText(): void {
		$service = $this->buildService(
			[
				'motion-uuid' => self::motion(),
				'amendment-uuid' => self::amendment('amendment-uuid', ['targetPassage' => 'artikel 9', 'proposedText' => 'artikel 10']),
			]
		);

		try {
			$service->applyAmendment('motion-uuid', 'amendment-uuid');
			self::fail('Expected a RuntimeException for a passage that is not in the motion text');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('passage', $e->getMessage());
		}

		self::assertCount(0, $this->saves, 'Nothing is written when the passage is not found');

	}//end testApplyAmendmentRefusesAPassageThatIsNotInTheText()
}//end class
