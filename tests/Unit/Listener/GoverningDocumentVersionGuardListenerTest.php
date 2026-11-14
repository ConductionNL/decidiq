<?php

/**
 * The version write path calls the governing-document version rules before a version is saved.
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
 * @spec openspec/changes/governing-documents-register
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\GoverningDocumentVersionGuardListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Service\GoverningDocumentConsolidationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Through the listener and the real rules; deleting the call site or the
 * subscription turns a test red, not only deleting the rule.
 *
 * @covers \OCA\Decidiq\Listener\GoverningDocumentVersionGuardListener
 * @covers \OCA\Decidiq\Service\GoverningDocumentConsolidationService
 * @covers \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 */
class GoverningDocumentVersionGuardListenerTest extends TestCase {

	/**
	 * An entity double carrying a record.
	 *
	 * @param array<string, mixed> $row  The record.
	 * @param string|null          $uuid Its uuid, when it has one.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row, ?string $uuid = null): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()

	/**
	 * The listener over a document whose version 1 is in effect from 2022-01-01.
	 *
	 * @param bool $broken Whether reading the versions fails.
	 *
	 * @return GoverningDocumentVersionGuardListener
	 */
	private function listener(bool $broken = false): GoverningDocumentVersionGuardListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		if ($broken === true) {
			$objects->method('findAll')->willThrowException(new \RuntimeException('database gone'));
		} else {
			$objects->method('findAll')->willReturn(
				['results' => [['id' => 'v1', 'document' => 'doc', 'versionNumber' => 1, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect']]]
			);
		}

		return new GoverningDocumentVersionGuardListener(new GoverningDocumentConsolidationService($objects), new NullLogger());
	}//end listener()

	public function testAnAmendmentWithoutItsDecisionIsStoppedBeforeItIsSaved(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'governing-document-versie', 'document' => 'doc', 'versionNumber' => 2, 'status' => 'draft']));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('must name the decision that enacted it', (string)$event->getErrors()['message']);
	}//end testAnAmendmentWithoutItsDecisionIsStoppedBeforeItIsSaved()

	public function testPuttingAVersionInEffectOutOfOrderIsStoppedOnUpdate(): void {
		$new = $this->entity(['document' => 'doc', 'versionNumber' => 2, 'effectiveDate' => '2021-06-01', 'status' => 'in-effect', 'determinedBy' => 'besluit'], 'v2');
		$event = new ObjectUpdatingEvent($new, $this->entity([], 'v2'));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('is not after 2022-01-01', (string)$event->getErrors()['message']);
	}//end testPuttingAVersionInEffectOutOfOrderIsStoppedOnUpdate()

	public function testATracedAmendmentIsSaved(): void {
		$event = new ObjectCreatingEvent($this->entity(['document' => 'doc', 'versionNumber' => 2, 'status' => 'draft', 'determinedBy' => 'besluit']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testATracedAmendmentIsSaved()

	public function testUnreadableVersionsStopTheSave(): void {
		$event = new ObjectCreatingEvent($this->entity(['document' => 'doc', 'versionNumber' => 2, 'determinedBy' => 'besluit']));

		$this->listener(broken: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testUnreadableVersionsStopTheSave()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'decision', 'document' => 'doc', 'versionNumber' => 1]));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()

	public function testEditingTheEffectiveDateOfASealedVersionIsStopped(): void {
		$sealed = ['document' => 'doc', 'versionNumber' => 1, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect', 'notary' => 'mr. A. de Wit'];
		$edited = array_merge($sealed, ['effectiveDate' => '2022-02-01']);
		$event = new ObjectUpdatingEvent($this->entity($edited, 'v1'), $this->entity($sealed, 'v1'));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('is in effect and sealed, so its effectiveDate cannot change', (string)$event->getErrors()['message']);
	}//end testEditingTheEffectiveDateOfASealedVersionIsStopped()

	public function testReplacingASealedVersionWithItsFieldsUntouchedIsSaved(): void {
		$sealed = ['document' => 'doc', 'versionNumber' => 1, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect'];
		$event = new ObjectUpdatingEvent(
			$this->entity(array_merge($sealed, ['status' => 'replaced', 'expiryDate' => '2024-01-01']), 'v1'),
			$this->entity($sealed, 'v1')
		);

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testReplacingASealedVersionWithItsFieldsUntouchedIsSaved()

	public function testTheRegistrarSubscribesTheGuardToVersionSaves(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, GoverningDocumentVersionGuardListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, GoverningDocumentVersionGuardListener::class], $subscribed);
	}//end testTheRegistrarSubscribesTheGuardToVersionSaves()
}//end class
