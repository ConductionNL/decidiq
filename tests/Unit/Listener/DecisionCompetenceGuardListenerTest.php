<?php

/**
 * The decision write path calls the competence guard before a decision is saved.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-004-competence-is-enforced-at-the-write-path-not-merely-declared
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\DecisionCompetenceGuardListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Service\DecisionCompetenceGuard;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Through the listener and the real guard: an incompetent body's decision is
 * stopped before it is saved, and the registrar subscribes the listener, so
 * deleting the call site turns a test red, not only deleting the guard.
 *
 * @covers \OCA\Decidiq\Listener\DecisionCompetenceGuardListener
 * @covers \OCA\Decidiq\Service\DecisionCompetenceGuard
 * @covers \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 */
class DecisionCompetenceGuardListenerTest extends TestCase {

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * The listener over a budget type only the Management team may take.
	 *
	 * @param bool $broken Whether reading the type fails.
	 *
	 * @return DecisionCompetenceGuardListener
	 */
	private function listener(bool $broken = false): DecisionCompetenceGuardListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		if ($broken === true) {
			$objects->method('find')->willThrowException(new \RuntimeException('database gone'));
		} else {
			$objects->method('find')->willReturn($this->entity(['id' => 'type-budget', 'competentBody' => 'body-mt']));
		}

		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveGovernanceBodyId')->willReturnCallback(
			static fn (string $meetingId): ?string => ['meet-mt' => 'body-mt', 'meet-dev' => 'body-dev'][$meetingId] ?? null
		);

		return new DecisionCompetenceGuardListener(new DecisionCompetenceGuard($objects, $resolver), new NullLogger());
	}//end listener()

	public function testAnIncompetentBodysDecisionIsStoppedBeforeItIsSaved(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'decision', 'title' => 'Budget 2027', 'type' => 'type-budget', 'meeting' => 'meet-dev']));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => 'The decision was not saved: only the body its type names as competent may take a decision of this type.'],
			$event->getErrors()
		);
	}//end testAnIncompetentBodysDecisionIsStoppedBeforeItIsSaved()

	public function testMovingADecisionToAnIncompetentBodyOnUpdateIsStopped(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['type' => 'type-budget', 'meeting' => 'meet-dev']),
			$this->entity(['type' => 'type-budget', 'meeting' => 'meet-mt'])
		);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testMovingADecisionToAnIncompetentBodyOnUpdateIsStopped()

	public function testTheCompetentBodysDecisionIsSaved(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'decision', 'type' => 'type-budget', 'meeting' => 'meet-mt']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testTheCompetentBodysDecisionIsSaved()

	public function testAnUnreadableTypeStopsTheSave(): void {
		$event = new ObjectCreatingEvent($this->entity(['type' => 'type-budget', 'meeting' => 'meet-mt']));

		$this->listener(broken: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('The decision was not saved: its decision type could not be read to check who may take it.', $event->getErrors()['message']);
	}//end testAnUnreadableTypeStopsTheSave()

	public function testAnotherSchemaWithATypeIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'meeting', 'type' => 'meeting-type-1']));

		$this->listener(broken: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaWithATypeIsLeftAlone()

	public function testTheRegistrarSubscribesTheGuardToDecisionSaves(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, DecisionCompetenceGuardListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, DecisionCompetenceGuardListener::class], $subscribed);
	}//end testTheRegistrarSubscribesTheGuardToDecisionSaves()
}//end class
