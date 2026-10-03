<?php

/**
 * Unit tests for AgendaItemChangeListener (#1396).
 *
 * The events are built the way OpenRegister dispatches them: a real
 * ObjectEntity whose schema is the numeric schema id, with no schema key in
 * the payload.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\AgendaItemChangeListener;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AgendaItemChangeListener.
 */
class AgendaItemChangeListenerTest extends TestCase {

	/**
	 * The agenda service double.
	 *
	 * @var AgendaService&MockObject
	 */
	private AgendaService&MockObject $agendaService;

	/**
	 * Listener under test.
	 *
	 * @var AgendaItemChangeListener
	 */
	private AgendaItemChangeListener $listener;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->agendaService = $this->createMock(AgendaService::class);

		$mapper = new class {
			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$slugs = ['41' => 'agenda-item', '93' => 'meeting'];
				if (isset($slugs[(string)$id]) === false) {
					throw new RuntimeException('Schema ' . $id . ' does not exist');
				}

				$schema = new Schema();
				$schema->setSlug($slugs[(string)$id]);
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$this->listener = new AgendaItemChangeListener(
			agendaService: $this->agendaService,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A production-shaped entity.
	 *
	 * @param string               $schemaId Numeric schema id.
	 * @param array<string, mixed> $payload  Stored payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schemaId, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('item-1');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * Creating or withdrawing an agenda item reports the meeting's agenda as changed.
	 *
	 * @return void
	 */
	public function testCreateAndDeleteReportTheMeeting(): void {
		$this->agendaService->expects(self::exactly(2))->method('notifyAgendaChanged')->with('meet-1');

		$item = $this->entity(schemaId: '41', payload: ['title' => 'Budget', 'meeting' => 'meet-1']);
		$this->listener->handle(new ObjectCreatedEvent($item));
		$this->listener->handle(new ObjectDeletedEvent($item));
	}//end testCreateAndDeleteReportTheMeeting()

	/**
	 * Moving or renaming an item is an agenda change.
	 *
	 * @return void
	 */
	public function testTitleOrOrderChangeReportsTheMeeting(): void {
		$this->agendaService->expects(self::once())->method('notifyAgendaChanged')->with('meet-1');

		$old = $this->entity(schemaId: '41', payload: ['title' => 'Budget', 'orderNumber' => 2, 'meeting' => 'meet-1']);
		$new = $this->entity(schemaId: '41', payload: ['title' => 'Budget 2027', 'orderNumber' => 2, 'meeting' => 'meet-1']);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end testTitleOrOrderChangeReportsTheMeeting()

	/**
	 * A status or duration change during the meeting is not an agenda change.
	 *
	 * @return void
	 */
	public function testProgressChangeIsNotAnAgendaChange(): void {
		$this->agendaService->expects(self::never())->method('notifyAgendaChanged');

		$old = $this->entity(schemaId: '41', payload: ['title' => 'Budget', 'meeting' => 'meet-1', 'actualDuration' => 0]);
		$new = $this->entity(schemaId: '41', payload: ['title' => 'Budget', 'meeting' => 'meet-1', 'actualDuration' => 12]);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end testProgressChangeIsNotAnAgendaChange()

	/**
	 * Other schemas, and writes the agenda service groups itself, are ignored.
	 *
	 * @return void
	 */
	public function testOtherSchemaAndSuppressedWritesAreIgnored(): void {
		$this->agendaService->method('isSuppressingItemNotices')->willReturn(true);
		$this->agendaService->expects(self::never())->method('notifyAgendaChanged');

		$this->listener->handle(new ObjectCreatedEvent($this->entity(schemaId: '93', payload: ['title' => 'Council', 'meeting' => 'meet-1'])));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(schemaId: '41', payload: ['title' => 'Budget', 'meeting' => 'meet-1'])));
	}//end testOtherSchemaAndSuppressedWritesAreIgnored()
}//end class
