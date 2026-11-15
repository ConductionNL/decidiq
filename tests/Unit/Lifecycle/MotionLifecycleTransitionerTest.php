<?php

/**
 * Unit tests for MotionLifecycleTransitioner.
 *
 * The transitions themselves are exercised through MotionService elsewhere.
 * These pin the parts that only this class owns: the actor guard, the
 * unknown-type and not-found refusals, and the ADR-005 outcome axis, where a
 * vote result may only arrive when a terminal state is entered, must be in the
 * closed vocabulary, and comes with a `decisionDate` that is stamped once and
 * never restamped.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Lifecycle
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

namespace OCA\Decidiq\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Lifecycle\MotionLifecycleTransitioner;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MotionLifecycleTransitioner.
 *
 * @covers \OCA\Decidiq\Lifecycle\MotionLifecycleTransitioner
 * @uses \OCA\Decidiq\Lifecycle\DecisionTransitionGuard
 */
class MotionLifecycleTransitionerTest extends TestCase {

	/**
	 * The mocked object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The mocked container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The payloads handed to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The transitioner under test.
	 *
	 * @var MotionLifecycleTransitioner
	 */
	private MotionLifecycleTransitioner $transitioner;

	/**
	 * Set up the transitioner with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$this->transitioner = new MotionLifecycleTransitioner(
			container: $this->container,
			logger: $this->logger,
			guard: new DecisionTransitionGuard(),
			objectService: $this->objectService,
		);
	}

	/**
	 * Make find() answer one stored decision.
	 *
	 * @param array<string, mixed>|null $object The stored decision, or null for none.
	 *
	 * @return void
	 */
	private function stored(?array $object): void {
		$entity = null;
		if ($object !== null) {
			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('getObject')->willReturn($object);
		}

		$this->objectService->method('find')->willReturn($entity);
	}

	/**
	 * A transition without an actor is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnEmptyActorIsRefused(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('actorId must be a non-empty Nextcloud user UID or the sentinel "system"');

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'proposed', actorId: '');
	}

	/**
	 * An object type outside motion/amendment is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownObjectTypeIsRefused(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Unknown objectType 'resolution'; expected one of: motion, amendment");

		$this->transitioner->transition(objectId: 'r-1', objectType: 'resolution', newState: 'proposed', actorId: 'ruben');
	}

	/**
	 * A missing object, or one of another decision type, is "not found".
	 *
	 * @return void
	 */
	public function testAnObjectOfAnotherTypeIsNotFound(): void {
		$this->stored(['decisionType' => 'amendment', 'lifecycle' => 'draft']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object motion/m-1 not found');

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'proposed', actorId: 'ruben');
	}

	/**
	 * A null find() is "not found" too.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsNotFound(): void {
		$this->stored(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object amendment/a-1 not found');

		$this->transitioner->transition(objectId: 'a-1', objectType: 'amendment', newState: 'proposed', actorId: 'ruben');
	}

	/**
	 * An outcome supplied while the decision is still in flight is refused.
	 *
	 * @return void
	 */
	public function testAnOutcomeOnANonTerminalStateIsRefused(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'deliberating']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			"An outcome may only be recorded when entering a terminal state (decided, enacted, archived); 'voting' is still in flight"
		);

		$this->transitioner->transition(
			objectId: 'm-1',
			objectType: 'motion',
			newState: 'voting',
			actorId: 'ruben',
			outcome: 'adopted',
		);
	}

	/**
	 * An outcome outside the closed vocabulary is refused.
	 *
	 * @return void
	 */
	public function testAnOutOfVocabularyOutcomeIsRefused(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'voting']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Outcome 'pending' is not a recorded result; expected one of: adopted, rejected");

		$this->transitioner->transition(
			objectId: 'm-1',
			objectType: 'motion',
			newState: 'decided',
			actorId: 'ruben',
			outcome: 'pending',
		);
	}

	/**
	 * Entering `decided` without any result is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testDecidedWithoutAResultIsRefused(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'voting']);

		try {
			$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'decided', actorId: 'ruben');
			$this->fail('A decided motion without an outcome was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				'A decided decision cannot be recorded without a result. Missing or invalid: outcome.',
				$e->getMessage()
			);
		}

		$this->assertSame([], $this->saved);
	}

	/**
	 * A decided motion records its outcome and is stamped with today's
	 * decision date, then saved with both lifecycle and status.
	 *
	 * @return void
	 */
	public function testDecidedRecordsTheOutcomeAndStampsTheDate(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'voting', 'decisionDate' => '  ', 'title' => 'Motie']);
		$this->logger->expects($this->once())
			->method('info')
			->with('Decidiq: motion m-1 transitioned from voting to decided by ruben');

		$before = new DateTimeImmutable('-1 second');
		$this->transitioner->transition(
			objectId: 'm-1',
			objectType: 'motion',
			newState: 'decided',
			actorId: 'ruben',
			outcome: 'rejected',
		);

		$this->assertCount(1, $this->saved);
		$object = $this->saved[0]['object'];
		$this->assertSame('rejected', $object['outcome']);
		$this->assertSame('decided', $object['lifecycle']);
		$this->assertSame('decided', $object['status']);
		$this->assertSame('Motie', $object['title']);
		$stamped = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $object['decisionDate']);
		$this->assertNotFalse($stamped);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $stamped->getTimestamp());
		$this->assertSame('decidiq', $this->saved[0]['register']);
		$this->assertSame('decision', $this->saved[0]['schema']);
		$this->assertSame('m-1', $this->saved[0]['uuid']);
	}

	/**
	 * Re-entering a terminal state keeps the recorded outcome and date.
	 *
	 * @return void
	 */
	public function testEnactingKeepsTheRecordedOutcomeAndDate(): void {
		$this->stored([
			'decisionType' => 'motion',
			'lifecycle' => 'decided',
			'outcome' => 'adopted',
			'decisionDate' => '2026-03-01T10:00:00+00:00',
		]);

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'enacted', actorId: 'system');

		$object = $this->saved[0]['object'];
		$this->assertSame('adopted', $object['outcome']);
		$this->assertSame('2026-03-01T10:00:00+00:00', $object['decisionDate']);
		$this->assertSame('enacted', $object['lifecycle']);
	}

	/**
	 * An amendment can never be withdrawn, unlike a motion.
	 *
	 * @return void
	 */
	public function testAnAmendmentCannotBeWithdrawn(): void {
		$this->stored(['decisionType' => 'amendment', 'lifecycle' => 'proposed']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Transition from 'proposed' to 'withdrawn' is not allowed for amendment");

		$this->transitioner->transition(objectId: 'a-1', objectType: 'amendment', newState: 'withdrawn', actorId: 'ruben');
	}

	/**
	 * A never-transitioned object counts as draft, and the co-signer
	 * threshold is not consulted outside proposed -> deliberating.
	 *
	 * @return void
	 */
	public function testANeverTransitionedMotionIsInDraft(): void {
		$this->stored(['decisionType' => 'motion']);
		$this->container->expects($this->never())->method('get');

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'proposed', actorId: 'ruben');

		$object = $this->saved[0]['object'];
		$this->assertSame('proposed', $object['lifecycle']);
		$this->assertArrayNotHasKey('outcome', $object);
		$this->assertArrayNotHasKey('decisionDate', $object);
	}
}
