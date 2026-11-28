<?php

/**
 * Decidiq Decision Competence Guard Listener Edges Test
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\DecisionCompetenceGuardListener;
use OCA\Decidiq\Service\DecisionCompetenceGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The decision competence guard on OpenRegister's pre-save events: a decision
 * the guard refuses, or whose competence cannot be checked, is not saved;
 * anything else passes untouched.
 *
 * @covers \OCA\Decidiq\Listener\DecisionCompetenceGuardListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 */
class DecisionCompetenceGuardListenerEdgesTest extends TestCase {

	/**
	 * What the guard was asked to check.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $checked = [];

	/**
	 * An entity whose payload is the row.
	 *
	 * @param array<string, mixed> $row The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * The listener over a guard that records each check and then throws the given failure.
	 *
	 * @param \Throwable|null      $failure What the guard throws, null to allow.
	 * @param LoggerInterface|null $logger  The logger, a quiet one when null.
	 *
	 * @return DecisionCompetenceGuardListener
	 */
	private function listener(?\Throwable $failure=null, ?LoggerInterface $logger=null): DecisionCompetenceGuardListener {
		$guard = $this->createMock(originalClassName: DecisionCompetenceGuard::class);
		$guard->method('assertCompetent')->willReturnCallback(
			function (array $decision) use ($failure): void {
				$this->checked[] = $decision;
				if ($failure !== null) {
					throw $failure;
				}
			}
		);

		return new DecisionCompetenceGuardListener(guard: $guard, logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)));
	}//end listener()

	/**
	 * An update is checked on the new object, not the old one.
	 *
	 * @return void
	 */
	public function testAnUpdateIsCheckedOnTheNewObject(): void {
		$event = new ObjectUpdatingEvent($this->entity(row: ['takenBy' => 'burgemeester']), $this->entity(row: ['takenBy' => 'college']));

		$this->listener()->handle(event: $event);

		$this->assertSame(expected: [['takenBy' => 'burgemeester']], actual: $this->checked);
	}//end testAnUpdateIsCheckedOnTheNewObject()

	/**
	 * Competence that cannot be checked is logged and the save refused.
	 *
	 * @return void
	 */
	public function testCompetenceThatCannotBeCheckedIsLoggedAndRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: decision competence could not be checked',
			['error' => 'mandate register offline'],
		);
		$event = new ObjectCreatingEvent($this->entity(row: ['_schema' => '42']));

		$this->listener(failure: new RuntimeException(message: 'mandate register offline'), logger: $logger)->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: ['message' => 'The decision was not saved: who may take it could not be checked. Try again in a moment.'],
			actual: $event->getErrors()
		);
	}//end testCompetenceThatCannotBeCheckedIsLoggedAndRefused()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnyOtherEventIsIgnored(): void {
		$this->listener()->handle(event: new Event());

		$this->assertSame(expected: [], actual: $this->checked);
	}//end testAnyOtherEventIsIgnored()
}//end class
