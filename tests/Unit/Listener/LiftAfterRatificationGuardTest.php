<?php

/**
 * A confidentiality restriction whose ground requires ratification is not
 * lifted straight from imposed, through the real OpenRegister update event.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\LiftAfterRatificationGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for LiftAfterRatificationGuard.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
class LiftAfterRatificationGuardTest extends TestCase {

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Build the object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The guard over the object service.
	 *
	 * @return LiftAfterRatificationGuard
	 */
	private function guard(): LiftAfterRatificationGuard {
		return new LiftAfterRatificationGuard(objectService: $this->objectService, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end guard()

	/**
	 * A restriction entity.
	 *
	 * @param array<string, mixed> $row The restriction.
	 *
	 * @return ObjectEntity
	 */
	private function restriction(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('restriction-1');
		$entity->setObject($row);
		return $entity;
	}//end restriction()

	/**
	 * The ground the object service finds, read without RBAC.
	 *
	 * @param array<string, mixed> $ground The ground.
	 *
	 * @return void
	 */
	private function groundIs(array $ground): void {
		$entity = new ObjectEntity();
		$entity->setObject($ground);
		$this->objectService->method('find')
			->with('ground-1', new ObjectFindOptions(register: 'decidiq', schema: 'confidentiality-ground'), false, false)
			->willReturn($entity);
	}//end groundIs()

	/**
	 * Lift the restriction from one state to another.
	 *
	 * @param string $from The stored lifecycle.
	 * @param string $to   The lifecycle saved.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function lift(string $from, string $to): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(
			$this->restriction(row: ['ground' => 'ground-1', 'scope' => 'item', 'lifecycle' => $to]),
			$this->restriction(row: ['ground' => 'ground-1', 'scope' => 'item', 'lifecycle' => $from])
		);
		$this->guard()->handle($event);
		return $event;
	}//end lift()

	/**
	 * A ground that requires ratification refuses the lift straight from imposed.
	 *
	 * @return void
	 */
	public function testAGroundNeedingRatificationRefusesTheDirectLift(): void {
		$this->groundIs(ground: ['citation' => 'Gemeentewet art. 87-89', 'requiresRatification' => true]);

		$event = $this->lift(from: 'imposed', to: 'dissolved');

		self::assertTrue(condition: $event->isPropagationStopped());
		self::assertSame(expected: ['message' => LiftAfterRatificationGuard::NEEDS_RATIFICATION], actual: $event->getErrors());
	}//end testAGroundNeedingRatificationRefusesTheDirectLift()

	/**
	 * A ground that needs no ratification lets the direct lift through.
	 *
	 * @return void
	 */
	public function testAGroundWithoutRatificationAllowsTheDirectLift(): void {
		$this->groundIs(ground: ['citation' => 'Woo art. 5.1 lid 2', 'requiresRatification' => false]);

		self::assertFalse(condition: $this->lift(from: 'imposed', to: 'dissolved')->isPropagationStopped());
	}//end testAGroundWithoutRatificationAllowsTheDirectLift()

	/**
	 * Lifting a ratified restriction, ratifying, or lapsing is not this guard's
	 * business: the ground is not even read.
	 *
	 * @return void
	 */
	public function testOtherTransitionsAreNotRead(): void {
		$this->objectService->expects(self::never())->method('find');

		self::assertFalse(condition: $this->lift(from: 'ratified', to: 'dissolved')->isPropagationStopped());
		self::assertFalse(condition: $this->lift(from: 'imposed', to: 'ratified')->isPropagationStopped());
		self::assertFalse(condition: $this->lift(from: 'imposed', to: 'lapsed')->isPropagationStopped());
		self::assertFalse(condition: $this->lift(from: 'imposed', to: 'imposed')->isPropagationStopped());

		$create = new ObjectCreatingEvent($this->restriction(row: ['ground' => 'ground-1', 'lifecycle' => 'dissolved']));
		$this->guard()->handle($create);
		self::assertFalse(condition: $create->isPropagationStopped());
	}//end testOtherTransitionsAreNotRead()

	/**
	 * A ground that cannot be read refuses the lift: confidentiality is
	 * never lost to a failed lookup.
	 *
	 * @return void
	 */
	public function testAnUnreadableGroundRefusesTheLift(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('database gone'));
		$event = $this->lift(from: 'imposed', to: 'dissolved');
		self::assertSame(expected: ['message' => LiftAfterRatificationGuard::GROUND_UNREADABLE], actual: $event->getErrors());

		$missing = $this->guard()->refusal(groundId: null);
		self::assertSame(expected: LiftAfterRatificationGuard::GROUND_UNREADABLE, actual: $missing);
	}//end testAnUnreadableGroundRefusesTheLift()

	/**
	 * A ground the object service does not find refuses the lift too.
	 *
	 * @return void
	 */
	public function testAMissingGroundRefusesTheLift(): void {
		$this->objectService->method('find')->willReturn(null);

		self::assertTrue(condition: $this->lift(from: 'imposed', to: 'dissolved')->isPropagationStopped());
	}//end testAMissingGroundRefusesTheLift()
}//end class
