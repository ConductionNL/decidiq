<?php

/**
 * The co-signer threshold a motion must meet before it moves from proposed to deliberating.
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

use InvalidArgumentException;
use OCA\Decidiq\Lifecycle\DecisionTransitionGuard;
use OCA\Decidiq\Lifecycle\MotionLifecycleTransitioner;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the co-signer threshold and the transition table of MotionLifecycleTransitioner.
 *
 * @covers \OCA\Decidiq\Lifecycle\MotionLifecycleTransitioner
 * @uses \OCA\Decidiq\Lifecycle\DecisionTransitionGuard
 */
class MotionCoSignerThresholdTest extends TestCase {

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
	 * The mocked app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

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
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntityInterface {
				$this->saved[] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$this->transitioner = new MotionLifecycleTransitioner(
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class),
			guard: new DecisionTransitionGuard(),
			objectService: $this->objectService,
		);
	}//end setUp()

	/**
	 * Make find() answer one stored decision.
	 *
	 * @param array<string, mixed> $object The stored decision.
	 *
	 * @return void
	 */
	private function stored(array $object): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($object);
		$this->objectService->method('find')->willReturn($entity);
	}//end stored()

	/**
	 * Make the app config answer a minimum number of co-signers.
	 *
	 * @param string $minimum The configured minimum.
	 *
	 * @return void
	 */
	private function minimumCoSigners(string $minimum): void {
		$this->container->expects($this->once())
			->method('get')
			->with(IAppConfig::class)
			->willReturn($this->appConfig);
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('decidiq', 'motion_min_cosigners', '0')
			->willReturn($minimum);
	}//end minimumCoSigners()

	/**
	 * A motion short of co-signers is refused with the shortfall, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAMotionShortOfCoSignersCannotProceedToDebate(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'proposed', 'coSigners' => ['anna']]);
		$this->minimumCoSigners('3');

		try {
			$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'deliberating', actorId: 'ruben');
			self::fail('A motion with too few co-signers must be refused.');
		} catch (InvalidArgumentException $e) {
			self::assertSame(
				'Motion requires at least 3 co-signers before it can proceed to debate; it currently has 1 (2 more needed)',
				$e->getMessage()
			);
		}

		self::assertSame([], $this->saved);
	}//end testAMotionShortOfCoSignersCannotProceedToDebate()

	/**
	 * A motion without any co-signer list counts zero co-signers.
	 *
	 * @return void
	 */
	public function testAMotionWithoutCoSignersCountsZero(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'proposed']);
		$this->minimumCoSigners('1');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('it currently has 0 (1 more needed)');

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'deliberating', actorId: 'ruben');
	}//end testAMotionWithoutCoSignersCountsZero()

	/**
	 * A motion that meets the threshold exactly proceeds and is saved as deliberating.
	 *
	 * @return void
	 */
	public function testAMotionMeetingTheThresholdProceeds(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'proposed', 'coSigners' => ['anna', 'bram']]);
		$this->minimumCoSigners('2');

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'deliberating', actorId: 'ruben');

		self::assertCount(1, $this->saved);
		self::assertSame('deliberating', $this->saved[0]['lifecycle']);
		self::assertSame('deliberating', $this->saved[0]['status']);
		self::assertSame(['anna', 'bram'], $this->saved[0]['coSigners']);
		self::assertArrayNotHasKey('outcome', $this->saved[0]);
	}//end testAMotionMeetingTheThresholdProceeds()

	/**
	 * With no minimum configured, a motion without co-signers proceeds.
	 *
	 * @return void
	 */
	public function testNoConfiguredMinimumLetsAnyMotionProceed(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'proposed']);
		$this->minimumCoSigners('0');

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'deliberating', actorId: 'ruben');

		self::assertSame('deliberating', $this->saved[0]['lifecycle']);
	}//end testNoConfiguredMinimumLetsAnyMotionProceed()

	/**
	 * An amendment entering debate never consults the co-signer threshold.
	 *
	 * @return void
	 */
	public function testAnAmendmentSkipsTheThreshold(): void {
		$this->stored(['decisionType' => 'amendment', 'lifecycle' => 'proposed']);
		$this->container->expects($this->never())->method('get');

		$this->transitioner->transition(objectId: 'a-1', objectType: 'amendment', newState: 'deliberating', actorId: 'ruben');

		self::assertSame('deliberating', $this->saved[0]['lifecycle']);
	}//end testAnAmendmentSkipsTheThreshold()

	/**
	 * A state the table does not allow from the current one is refused before any config is read.
	 *
	 * @return void
	 */
	public function testASkippedStateIsRefused(): void {
		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'proposed']);
		$this->container->expects($this->never())->method('get');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Transition from 'proposed' to 'voting' is not allowed for motion");

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'voting', actorId: 'ruben');
	}//end testASkippedStateIsRefused()

	/**
	 * A terminal state allows no further transition, and a withdrawn motion stays withdrawn.
	 *
	 * @return void
	 */
	public function testTerminalStatesAllowNoTransition(): void {
		self::assertSame([], MotionLifecycleTransitioner::MOTION_TRANSITIONS['archived']);
		self::assertSame([], MotionLifecycleTransitioner::MOTION_TRANSITIONS['withdrawn']);
		self::assertSame([], MotionLifecycleTransitioner::AMENDMENT_TRANSITIONS['decided']);

		$this->stored(['decisionType' => 'motion', 'lifecycle' => 'withdrawn']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Transition from 'withdrawn' to 'proposed' is not allowed for motion");

		$this->transitioner->transition(objectId: 'm-1', objectType: 'motion', newState: 'proposed', actorId: 'ruben');
	}//end testTerminalStatesAllowNoTransition()
}//end class
