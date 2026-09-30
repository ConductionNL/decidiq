<?php

/**
 * Tests: document details keep their type's required fields and one record per file.
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

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\DocumentTypeFieldsGuardListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Listener\DocumentTypeFieldsGuardListener
 * @covers \OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar
 *
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
 */
class DocumentTypeFieldsGuardListenerTest extends TestCase {

	/**
	 * The Raadsvoorstel type: zaaknummer required, status optional.
	 *
	 * @var array<string, mixed>
	 */
	private const RAADSVOORSTEL = [
		'id' => 'type-rv',
		'name' => 'Raadsvoorstel',
		'appliesTo' => ['agenda-item'],
		'fields' => [
			['key' => 'zaaknummer', 'label' => 'Zaaknummer', 'fieldType' => 'string', 'required' => true],
			['key' => 'status', 'label' => 'Status', 'fieldType' => 'enum', 'enumValues' => ['concept', 'definitief']],
		],
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
		$entity->method('getUuid')->willReturn($row['id'] ?? null);
		return $entity;
	}//end entity()

	/**
	 * The guard over the Raadsvoorstel type and the given existing records.
	 *
	 * @param list<array<string, mixed>> $existing Records already stored.
	 * @param bool                       $broken   Whether reading the type fails.
	 *
	 * @return DocumentTypeFieldsGuardListener
	 */
	private function guard(array $existing=[], bool $broken=false): DocumentTypeFieldsGuardListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		if ($broken === true) {
			$objects->method('find')->willThrowException(new \RuntimeException('database gone'));
		} else {
			$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => $id === 'type-rv' ? $this->entity(self::RAADSVOORSTEL) : null);
		}

		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($existing): array {
				$fileId = ($config['filters']['fileId'] ?? null);
				$found  = array_filter($existing, static fn (array $row): bool => ($row['fileId'] ?? null) === $fileId);
				return array_map(fn (array $row): ObjectEntity => $this->entity($row), array_values($found));
			}
		);

		return new DocumentTypeFieldsGuardListener($objects, $this->createMock(LoggerInterface::class));
	}//end guard()

	/**
	 * A record whose required field is empty is refused, naming the field.
	 *
	 * @return void
	 */
	public function testAnEmptyRequiredFieldIsRefusedNamingIt(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'digital-document', 'name' => 'Raadsvoorstel omgevingsvisie.pdf', 'documentType' => 'Raadsvoorstel', 'fileId' => 412, 'agendaItem' => 'item-1', 'type' => 'type-rv', 'typeFields' => ['status' => 'definitief']]));

		$this->guard()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('Zaaknummer', (string)$event->getErrors()['message']);
	}//end testAnEmptyRequiredFieldIsRefusedNamingIt()

	/**
	 * The same holds on an update that clears the field.
	 *
	 * @return void
	 */
	public function testClearingARequiredFieldOnUpdateIsRefused(): void {
		$old   = $this->entity(['id' => 'doc-1', 'fileId' => 412, 'type' => 'type-rv', 'typeFields' => ['zaaknummer' => 'Z-2026-00412']]);
		$event = new ObjectUpdatingEvent($this->entity(['id' => 'doc-1', '_schemaSlug' => 'digital-document', 'fileId' => 412, 'type' => 'type-rv', 'typeFields' => ['zaaknummer' => '  ']]), $old);

		$this->guard([['id' => 'doc-1', 'fileId' => 412]])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('Zaaknummer', (string)$event->getErrors()['message']);
	}//end testClearingARequiredFieldOnUpdateIsRefused()

	/**
	 * A filled-in record passes; so does a record without a type.
	 *
	 * @return void
	 */
	public function testAFilledInRecordAndAnUntypedRecordPass(): void {
		$filled  = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'digital-document', 'fileId' => 412, 'type' => 'type-rv', 'typeFields' => ['zaaknummer' => 'Z-2026-00412']]));
		$untyped = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'digital-document', 'fileId' => 413]));

		$this->guard()->handle($filled);
		$this->guard()->handle($untyped);

		self::assertFalse($filled->isPropagationStopped());
		self::assertFalse($untyped->isPropagationStopped());
	}//end testAFilledInRecordAndAnUntypedRecordPass()

	/**
	 * A second record for a file that already has one is refused.
	 *
	 * @return void
	 */
	public function testASecondRecordForOneFileIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'digital-document', 'fileId' => 412, 'type' => 'type-rv', 'typeFields' => ['zaaknummer' => 'Z-1']]));

		$this->guard([['id' => 'doc-1', 'fileId' => 412]])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('already has details', (string)$event->getErrors()['message']);
	}//end testASecondRecordForOneFileIsRefused()

	/**
	 * A type that cannot be read refuses the save instead of skipping the rule.
	 *
	 * @return void
	 */
	public function testAnUnreadableTypeRefusesTheSave(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'digital-document', 'fileId' => 412, 'type' => 'type-rv', 'typeFields' => []]));

		$this->guard(broken: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('could not be read', (string)$event->getErrors()['message']);
	}//end testAnUnreadableTypeRefusesTheSave()

	/**
	 * Another schema's record is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(['_schemaSlug' => 'agenda-item', 'type' => 'type-rv', 'typeFields' => []]));

		$this->guard()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The guard is subscribed to document creates and updates: a guard with no
	 * call site guards nothing.
	 *
	 * @return void
	 */
	public function testTheRegistrarSubscribesTheGuardToDocumentSaves(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, DocumentTypeFieldsGuardListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, DocumentTypeFieldsGuardListener::class], $subscribed);
	}//end testTheRegistrarSubscribesTheGuardToDocumentSaves()
}//end class
