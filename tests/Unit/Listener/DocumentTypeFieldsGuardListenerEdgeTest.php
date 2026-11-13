<?php

/**
 * Edge cases of the document type fields guard.
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

use OCA\Decidiq\Listener\DocumentTypeFieldsGuardListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Other events and empty events pass; a record is recognised by its file id
 * when it names no schema; a record without a whole-number file id is not
 * checked for duplicates; a type that does not exist and a duplicate check
 * that cannot run are refused, the latter logged.
 *
 * @covers \OCA\Decidiq\Listener\DocumentTypeFieldsGuardListener
 *
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-004-a-required-field-is-enforced-on-save
 */
class DocumentTypeFieldsGuardListenerEdgeTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The guard's logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var DocumentTypeFieldsGuardListener
	 */
	private DocumentTypeFieldsGuardListener $guard;

	/**
	 * Set up the guard over the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new DocumentTypeFieldsGuardListener(objectService: $this->objectService, logger: $this->logger);
	}//end setUp()

	/**
	 * A creating event for an entity with the given payload.
	 *
	 * @param array<string, mixed> $row The payload
	 *
	 * @return ObjectCreatingEvent
	 */
	private function creating(array $row): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('doc-1');
		$entity->setObject($row);
		return new ObjectCreatingEvent($entity);
	}//end creating()

	/**
	 * Other events and events without an object are let through.
	 *
	 * @return void
	 */
	public function testOtherEventsAndEmptyEventsPass(): void {
		$this->objectService->expects(self::never())->method('findAll');
		$this->objectService->expects(self::never())->method('find');
		$empty = new ObjectCreatingEvent(null);

		$this->guard->handle(new Event());
		$this->guard->handle($empty);

		self::assertFalse($empty->isPropagationStopped());
	}//end testOtherEventsAndEmptyEventsPass()

	/**
	 * A record naming no schema is recognised by its file id.
	 *
	 * @return void
	 */
	public function testARecordWithoutASchemaIsRecognisedByItsFileId(): void {
		$other = $this->createMock(ObjectEntity::class);
		$other->method('jsonSerialize')->willReturn(['@self' => ['id' => 'doc-2']]);
		$this->objectService->expects(self::once())->method('findAll')
			->with(['filters' => ['register' => 'decidiq', 'schema' => 'digital-document', 'fileId' => 77]])
			->willReturn([$other]);
		$event = $this->creating(['fileId' => '77']);

		$this->guard->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'This file already has details. Open them to change them.'], $event->getErrors());
	}//end testARecordWithoutASchemaIsRecognisedByItsFileId()

	/**
	 * Without a file id or schema the object is no document record.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutFileIdOrSchemaIsNoDocument(): void {
		$this->objectService->expects(self::never())->method('find');
		$event = $this->creating(['type' => 'type-1']);

		$this->guard->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnObjectWithoutFileIdOrSchemaIsNoDocument()

	/**
	 * A file id that is not a whole number skips the duplicate check.
	 *
	 * @return void
	 */
	public function testANonNumericFileIdSkipsTheDuplicateCheck(): void {
		$this->objectService->expects(self::never())->method('findAll');

		self::assertNull($this->guard->refusal(['fileId' => '12a']));
		self::assertNull($this->guard->refusal(['fileId' => 12.5]));
	}//end testANonNumericFileIdSkipsTheDuplicateCheck()

	/**
	 * A type that does not exist is refused.
	 *
	 * @return void
	 */
	public function testATypeThatDoesNotExistIsRefused(): void {
		$this->objectService->method('find')->willReturn(null);

		self::assertSame(
			'The details were not saved: the document type does not exist.',
			$this->guard->refusal(['type' => ' type-gone '])
		);
	}//end testATypeThatDoesNotExistIsRefused()

	/**
	 * A duplicate check that cannot run is refused and logged.
	 *
	 * @return void
	 */
	public function testADuplicateCheckThatCannotRunIsRefusedAndLogged(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('index offline'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: document records could not be read',
			['fileId' => 5, 'error' => 'index offline']
		);

		self::assertSame(
			'The details were not saved: the existing details could not be checked. Try again in a moment.',
			$this->guard->refusal(['fileId' => 5])
		);
	}//end testADuplicateCheckThatCannotRunIsRefusedAndLogged()
}//end class
