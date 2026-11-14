<?php

/**
 * A decision carries its type's remedy clause from the moment it is taken.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/tasks.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\DecisionRemedyStampListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Service\LegalRemedyResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Through the listener and the real resolver: a taken decision of a type
 * declaring a remedy gets the clause in its save; an untaken, untyped,
 * already-stamped or undeclared one does not; nothing is ever refused.
 *
 * @covers \OCA\Decidiq\Listener\DecisionRemedyStampListener
 * @uses   \OCA\Decidiq\Service\LegalRemedyResolver
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 */
class DecisionRemedyStampListenerTest extends TestCase {

	private const TYPE = [
		'id' => 'type-1',
		'legalRemedies' => [['kind' => 'bezwaar', 'termDays' => 42, 'body' => 'het college van burgemeester en wethouders']],
	];

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
	 * The listener over an object service that finds the given type.
	 *
	 * @param array<string, mixed>|null $type   The type it finds, null for none.
	 * @param bool                      $broken Whether reading the type fails.
	 * @param LoggerInterface|null      $logger The logger.
	 *
	 * @return DecisionRemedyStampListener
	 */
	private function listener(?array $type = self::TYPE, bool $broken = false, ?LoggerInterface $logger = null): DecisionRemedyStampListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		if ($broken === true) {
			$objects->method('find')->willThrowException(new \RuntimeException('database gone'));
		} else {
			$objects->method('find')->willReturnCallback(
				function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, ?string $schema = null) use ($type) {
					if ($type === null || $id !== 'type-1' || $register !== 'decidiq' || $schema !== 'decision-template') {
						return null;
					}

					return $this->entity($type);
				}
			);
		}

		return new DecisionRemedyStampListener(new LegalRemedyResolver(), $objects, $logger ?? new NullLogger());
	}//end listener()

	public function testADecisionTakenOnUpdateGetsItsTypesClause(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['_schemaSlug' => 'decision', 'type' => 'type-1', 'lifecycle' => 'decided', 'outcome' => 'adopted']),
			$this->entity(['_schemaSlug' => 'decision', 'type' => 'type-1', 'lifecycle' => 'voting'])
		);

		$this->listener()->handle($event);

		$clause = $event->getModifiedData()['legalRemedyClause'];
		self::assertSame('bezwaar', $clause['kind']);
		self::assertSame(42, $clause['termDays']);
		self::assertSame('het college van burgemeester en wethouders', $clause['body']);
		self::assertStringContainsString('binnen zes weken', $clause['text']);
		self::assertFalse($event->isPropagationStopped());
	}//end testADecisionTakenOnUpdateGetsItsTypesClause()

	public function testADecisionCreatedAsTakenGetsItToo(): void {
		$event = new ObjectCreatingEvent($this->entity(['type' => ['id' => 'type-1'], 'lifecycle' => 'enacted']));

		$this->listener()->handle($event);

		self::assertSame('bezwaar', $event->getModifiedData()['legalRemedyClause']['kind']);
	}//end testADecisionCreatedAsTakenGetsItToo()

	public function testAnOutcomeSetByTheSameSaveCountsAsTaken(): void {
		$event = new ObjectUpdatingEvent($this->entity(['type' => 'type-1', 'lifecycle' => 'voting']), $this->entity(['type' => 'type-1']));
		$event->setModifiedData(['outcome' => 'rejected']);

		$this->listener()->handle($event);

		self::assertSame('rejected', $event->getModifiedData()['outcome']);
		self::assertSame('bezwaar', $event->getModifiedData()['legalRemedyClause']['kind']);
	}//end testAnOutcomeSetByTheSameSaveCountsAsTaken()

	public function testADecisionNotYetTakenIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['type' => 'type-1', 'lifecycle' => 'deliberating']));

		$this->listener()->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testADecisionNotYetTakenIsLeftAlone()

	public function testAnAlreadyStampedClauseIsNeverRewritten(): void {
		$old = ['kind' => 'beroep', 'termDays' => 28, 'body' => 'de rechtbank', 'text' => 'as it was'];
		$event = new ObjectUpdatingEvent(
			$this->entity(['type' => 'type-1', 'lifecycle' => 'archived', 'legalRemedyClause' => $old]),
			$this->entity(['type' => 'type-1', 'lifecycle' => 'enacted', 'legalRemedyClause' => $old])
		);

		$this->listener()->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnAlreadyStampedClauseIsNeverRewritten()

	public function testAnUntypedDecisionAndATypeWithoutADeclarationAreLeftAlone(): void {
		$untyped = new ObjectCreatingEvent($this->entity(['lifecycle' => 'decided']));
		$this->listener()->handle($untyped);

		$undeclared = new ObjectCreatingEvent($this->entity(['type' => 'type-1', 'lifecycle' => 'decided']));
		$this->listener(type: ['id' => 'type-1'])->handle($undeclared);

		self::assertSame([], $untyped->getModifiedData());
		self::assertSame([], $undeclared->getModifiedData());
		self::assertFalse($undeclared->isPropagationStopped());
	}//end testAnUntypedDecisionAndATypeWithoutADeclarationAreLeftAlone()

	public function testAnUnreadableTypeIsLoggedAndTheSaveGoesOn(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: the decision type could not be read, so no remedy clause was stamped',
			['type' => 'type-1', 'reason' => 'database gone']
		);
		$event = new ObjectCreatingEvent($this->entity(['type' => 'type-1', 'lifecycle' => 'decided']));

		$this->listener(broken: true, logger: $logger)->handle($event);

		self::assertSame([], $event->getModifiedData());
		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnreadableTypeIsLoggedAndTheSaveGoesOn()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'meeting', 'type' => 'type-1', 'lifecycle' => 'decided']));

		$this->listener()->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	public function testTheRegistrarSubscribesItToDecisionSavesAfterTheCompetenceGuard(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, DecisionRemedyStampListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, DecisionRemedyStampListener::class], $subscribed);
		self::assertGreaterThan(
			array_search([ObjectUpdatingEvent::class, 'OCA\Decidiq\Listener\DecisionCompetenceGuardListener'], $subscribed, true),
			array_search([ObjectUpdatingEvent::class, DecisionRemedyStampListener::class], $subscribed, true)
		);
	}//end testTheRegistrarSubscribesItToDecisionSavesAfterTheCompetenceGuard()
}//end class
