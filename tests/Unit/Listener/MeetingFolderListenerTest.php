<?php

/**
 * Unit tests for MeetingFolderListener.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Listener;

use OCA\Decidesk\Listener\MeetingFolderListener;
use OCA\Decidesk\Service\ListenerSchemaResolver;
use OCA\Decidesk\Service\MeetingFolderService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the schema filter, event-type filter, and fail-soft contract.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class MeetingFolderListenerTest extends TestCase {

	/**
	 * Folder service mock.
	 *
	 * @var MeetingFolderService&MockObject
	 */
	private MeetingFolderService&MockObject $folderService;

	/**
	 * Listener under test.
	 *
	 * @var MeetingFolderListener
	 */
	private MeetingFolderListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->folderService = $this->createMock(MeetingFolderService::class);
		$this->listener = new MeetingFolderListener(
			folderService: $this->folderService,
			schemaResolver: $this->schemaResolver(['93' => 'meeting']),
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * A real ListenerSchemaResolver over a SchemaMapper double.
	 *
	 * The resolver is deliberately NOT mocked: the defect decidesk#471 records
	 * lives in the accessor probe, so a listener test that stubs the answer out
	 * cannot see it.
	 *
	 * @param array<string, string> $slugsById Slug per schema id the mapper knows
	 *
	 * @return ListenerSchemaResolver
	 */
	private function schemaResolver(array $slugsById): ListenerSchemaResolver {
		$mapper = new class($slugsById) {

			/**
			 * @param array<string, string> $slugsById Slug per schema id
			 */
			public function __construct(
				private array $slugsById,
			) {
			}

			/**
			 * Mirrors OCA\OpenRegister\Db\SchemaMapper::find()'s first parameter.
			 *
			 * @param string|integer $id The schema id, uuid or slug
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				if (array_key_exists((string)$id, $this->slugsById) === false) {
					throw new RuntimeException('Schema ' . $id . ' does not exist');
				}

				$schema = new Schema();
				$schema->setSlug($this->slugsById[(string)$id]);

				return $schema;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new ListenerSchemaResolver(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end schemaResolver()

	/**
	 * Build the event OpenRegister actually dispatches: a real ObjectEntity
	 * whose schema is the schema's numeric database id, and whose payload
	 * carries no schema key of any kind.
	 *
	 * Every pre-existing test in this class fed a `_schemaSlug` key that
	 * OpenRegister never emits — the fixture, not the stub, is why the suite
	 * was green over a listener that could not fire.
	 *
	 * @param string $schemaId The numeric schema id OR stamps on the entity
	 * @param array<string, mixed> $payload The stored object payload
	 *
	 * @return ObjectCreatedEvent&MockObject
	 */
	private function productionEvent(string $schemaId, array $payload): ObjectCreatedEvent&MockObject {
		$entity = new ObjectEntity();
		$entity->setUuid('meet-1');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($payload);

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($entity);

		return $event;
	}//end productionEvent()

	/**
	 * Build an ObjectCreatedEvent mock carrying the given object payload.
	 *
	 * @param array<string, mixed> $row Object payload (with _schemaSlug)
	 *
	 * @return ObjectCreatedEvent&MockObject
	 */
	private function createdEvent(array $row): ObjectCreatedEvent&MockObject {
		$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($entity);
		return $event;
	}//end createdEvent()

	/**
	 * decidesk#471: a meeting create as OpenRegister actually dispatches it —
	 * numeric schema id on the entity, no schema key on the payload — reaches
	 * the folder service.
	 *
	 * Before the fix this failed: `method_exists($entity, 'getSchema')` is
	 * false for a magic accessor, so the guard resolved '' and returned.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testProductionShapedMeetingCreationTriggersFolders(): void {
		$this->folderService->expects(self::once())
			->method('ensureMeetingFolders')
			->with(self::callback(static fn (array $m): bool => ($m['title'] ?? '') === 'Q3 Meeting'
				&& ($m['id'] ?? '') === 'meet-1'));

		$this->listener->handle($this->productionEvent('93', ['title' => 'Q3 Meeting']));

	}//end testProductionShapedMeetingCreationTriggersFolders()

	/**
	 * The same shape on another schema id is still ignored — the fix widens the
	 * guard, it does not remove it.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testProductionShapedOtherSchemaIgnored(): void {
		$this->folderService->expects(self::never())->method('ensureMeetingFolders');

		$this->listener->handle($this->productionEvent('94', ['title' => 'Budget']));

	}//end testProductionShapedOtherSchemaIgnored()

	/**
	 * With OpenRegister's SchemaMapper unreachable the guard fails closed: no
	 * folder tree is created for an object that might not be a meeting.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testUnresolvableSchemaFailsClosed(): void {
		$this->folderService->expects(self::never())->method('ensureMeetingFolders');

		$listener = new MeetingFolderListener(
			folderService: $this->folderService,
			schemaResolver: $this->schemaResolver([]),
			logger: $this->createMock(LoggerInterface::class),
		);

		$listener->handle($this->productionEvent('93', ['title' => 'Q3 Meeting']));

	}//end testUnresolvableSchemaFailsClosed()

	/**
	 * Meeting creations trigger the folder service.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testMeetingCreationTriggersFolders(): void {
		$this->folderService->expects(self::once())
			->method('ensureMeetingFolders')
			->with(self::callback(static fn (array $m): bool => ($m['title'] ?? '') === 'Q3 Meeting'));

		$this->listener->handle(
			$this->createdEvent(['_schemaSlug' => 'meeting', 'id' => 'meet-1', 'title' => 'Q3 Meeting'])
		);

	}//end testMeetingCreationTriggersFolders()

	/**
	 * Other schemas are ignored.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testOtherSchemasIgnored(): void {
		$this->folderService->expects(self::never())->method('ensureMeetingFolders');

		$this->listener->handle(
			$this->createdEvent(['_schemaSlug' => 'decision', 'id' => 'dec-1', 'title' => 'Budget'])
		);

	}//end testOtherSchemasIgnored()

	/**
	 * Non-created events are ignored.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testNonCreatedEventsIgnored(): void {
		$this->folderService->expects(self::never())->method('ensureMeetingFolders');

		$this->listener->handle($this->createMock(Event::class));

	}//end testNonCreatedEventsIgnored()

	/**
	 * Folder service failures never escape the listener (fail soft).
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testFolderFailureIsSwallowed(): void {
		$this->folderService->method('ensureMeetingFolders')
			->willThrowException(new \RuntimeException('Files down'));

		$this->listener->handle(
			$this->createdEvent(['_schemaSlug' => 'meeting', 'id' => 'meet-1', 'title' => 'Q3 Meeting'])
		);

		// Reaching this point without an exception is the assertion.
		self::assertTrue(condition: true);

	}//end testFolderFailureIsSwallowed()
}//end class
