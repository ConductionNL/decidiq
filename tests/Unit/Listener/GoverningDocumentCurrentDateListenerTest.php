<?php

/**
 * A governing-document version put in effect writes its date onto its document.
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

use OCA\Decidiq\Kernel\Contracts\GoverningDocumentConsolidationServiceInterface;
use OCA\Decidiq\Listener\GoverningDocumentCurrentDateListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Listener\GoverningDocumentCurrentDateListener
 * @covers \OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar
 */
class GoverningDocumentCurrentDateListenerTest extends TestCase {

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

	public function testAnUpdatedVersionIsHandedOnWithItsNewState(): void {
		$version = ['document' => 'doc', 'versionNumber' => 2, 'effectiveDate' => '2022-01-01', 'status' => 'in-effect'];
		$consolidation = $this->createMock(GoverningDocumentConsolidationServiceInterface::class);
		$consolidation->expects(self::once())->method('recordCurrentEffectiveDate')
			->with(self::callback(static fn (array $row): bool => ($row['status'] ?? null) === 'in-effect' && ($row['effectiveDate'] ?? null) === '2022-01-01'))
			->willReturn(true);

		(new GoverningDocumentCurrentDateListener($consolidation, new NullLogger()))
			->handle(new ObjectUpdatedEvent($this->entity($version), $this->entity(['status' => 'adopted'] + $version)));
	}//end testAnUpdatedVersionIsHandedOnWithItsNewState()

	public function testACreatedVersionIsHandedOn(): void {
		$consolidation = $this->createMock(GoverningDocumentConsolidationServiceInterface::class);
		$consolidation->expects(self::once())->method('recordCurrentEffectiveDate')->willReturn(false);

		(new GoverningDocumentCurrentDateListener($consolidation, new NullLogger()))
			->handle(new ObjectCreatedEvent($this->entity(['document' => 'doc', 'status' => 'draft'])));
	}//end testACreatedVersionIsHandedOn()

	public function testAnotherSchemaIsLeftAlone(): void {
		$consolidation = $this->createMock(GoverningDocumentConsolidationServiceInterface::class);
		$consolidation->expects(self::never())->method('recordCurrentEffectiveDate');

		(new GoverningDocumentCurrentDateListener($consolidation, new NullLogger()))
			->handle(new ObjectCreatedEvent($this->entity(['_schemaSlug' => 'decision', 'status' => 'in-effect'])));
	}//end testAnotherSchemaIsLeftAlone()

	public function testAFailureIsLoggedAndNotThrown(): void {
		$consolidation = $this->createMock(GoverningDocumentConsolidationServiceInterface::class);
		$consolidation->method('recordCurrentEffectiveDate')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('Decidiq: the current effective date of a governing document could not be updated', ['error' => 'database gone']);

		(new GoverningDocumentCurrentDateListener($consolidation, $logger))
			->handle(new ObjectCreatedEvent($this->entity(['document' => 'doc', 'status' => 'in-effect'])));
	}//end testAFailureIsLoggedAndNotThrown()

	public function testTheRegistrarSubscribesItToVersionSaves(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatedEvent::class, GoverningDocumentCurrentDateListener::class], $subscribed);
		self::assertContains([ObjectUpdatedEvent::class, GoverningDocumentCurrentDateListener::class], $subscribed);
	}//end testTheRegistrarSubscribesItToVersionSaves()
}//end class
