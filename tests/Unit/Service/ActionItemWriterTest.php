<?php

/**
 * Unit tests for ActionItemWriter, the VTODO write path of action items.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ActionItemWriter;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\TaskService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Create, update and delete go through TaskService with the action-item
 * register and schema; core fields map onto the VTODO, the rest ride in the
 * field blob; every failure answers null/false and is logged.
 */
#[CoversClass(ActionItemWriter::class)]
class ActionItemWriterTest extends TestCase {

	/**
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registers;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators: register 7, schema 11.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tasks = $this->createMock(TaskService::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(11);
		$this->registers->method('find')->willReturnCallback(static function (string|int $id) use ($register): Register {
			self::assertSame('decidiq', $id);
			return $register;
		});
		$this->schemas->method('find')->willReturnCallback(static function (string|int $id) use ($schema): Schema {
			self::assertSame('action-item', $id);
			return $schema;
		});
	}//end setUp()

	/**
	 * The writer under test.
	 *
	 * @return ActionItemWriter
	 */
	private function writer(): ActionItemWriter {
		return new ActionItemWriter($this->logger, $this->tasks, $this->registers, $this->schemas);
	}//end writer()

	/**
	 * A create maps the core fields onto the VTODO and the rest into the field blob.
	 *
	 * @return void
	 */
	public function testCreateMapsTheItemOntoATask(): void {
		$this->tasks->expects($this->once())->method('createTask')->willReturnCallback(
			function (int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data): array {
				$this->assertSame([7, 11, 'Send the minutes'], [$registerId, $schemaId, $objectTitle]);
				$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $objectUuid);
				$this->assertSame(
					[
						'summary' => 'Send the minutes',
						'status' => 'IN-PROCESS',
						'description' => 'To all members',
						'due' => '2026-12-01',
						'fields' => ['assignee' => 'bob', 'taskStatus' => 'in-progress', 'meeting' => 'm-1'],
					],
					$data
				);
				return ['uid' => 'u-1', 'summary' => 'Send the minutes'];
			}
		);

		$created = $this->writer()->create(
			[
				'title' => 'Send the minutes',
				'assignee' => 'bob',
				'taskStatus' => 'in-progress',
				'description' => 'To all members',
				'dueDate' => '2026-12-01',
				'meeting' => 'm-1',
			]
		);

		$this->assertSame(['uid' => 'u-1', 'summary' => 'Send the minutes'], $created);
	}//end testCreateMapsTheItemOntoATask()

	/**
	 * A bare item gets the default title and status and no description, due date or field blob.
	 *
	 * @return void
	 */
	public function testABareItemGetsTheDefaults(): void {
		$this->tasks->expects($this->once())->method('createTask')
			->with(7, 11, $this->anything(), 'Action item', ['summary' => 'Action item', 'status' => 'NEEDS-ACTION'])
			->willReturn(['uid' => 'u-2']);

		$this->assertSame(['uid' => 'u-2'], $this->writer()->create([]));
	}//end testABareItemGetsTheDefaults()

	/**
	 * Each action-item status maps onto its VTODO status, case-insensitively; an unknown one is NEEDS-ACTION.
	 *
	 * @return void
	 */
	public function testStatusesMapOntoVtodoStatuses(): void {
		$seen = [];
		$this->tasks->method('createTask')->willReturnCallback(
			static function (int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data) use (&$seen): array {
				$seen[] = $data['status'];
				return [];
			}
		);

		$writer = $this->writer();
		foreach (['open', 'in_progress', 'COMPLETED', 'done', 'cancelled', 'parked'] as $status) {
			$writer->create(['title' => 'x', 'taskStatus' => $status]);
		}

		$this->assertSame(['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'COMPLETED', 'CANCELLED', 'NEEDS-ACTION'], $seen);
	}//end testStatusesMapOntoVtodoStatuses()

	/**
	 * The register and schema are resolved once and reused.
	 *
	 * @return void
	 */
	public function testTheIdsAreResolvedOnce(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$register = new Register();
		$register->setId(3);
		$registers->expects($this->once())->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(4);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->expects($this->once())->method('find')->willReturn($schema);
		$this->tasks->expects($this->exactly(2))->method('createTask')->with(3, 4)->willReturn([]);

		$writer = new ActionItemWriter($this->logger, $this->tasks, $registers, $schemas);
		$writer->create(['title' => 'a']);
		$writer->create(['title' => 'b']);
	}//end testTheIdsAreResolvedOnce()

	/**
	 * An unresolvable register is logged and creates nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterCreatesNothing(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willThrowException(new RuntimeException('no register'));
		$this->tasks->expects($this->never())->method('createTask');
		$this->logger->expects($this->once())->method('error')->with('ActionItemWriter: could not resolve register/schema ids: no register');

		$this->assertNull((new ActionItemWriter($this->logger, $this->tasks, $registers, $this->schemas))->create(['title' => 'a']));
	}//end testAnUnresolvableRegisterCreatesNothing()

	/**
	 * A failing task service is logged and answers null.
	 *
	 * @return void
	 */
	public function testAFailingCreateIsLoggedAndNull(): void {
		$this->tasks->method('createTask')->willThrowException(new RuntimeException('caldav down'));
		$this->logger->expects($this->once())->method('warning')->with('ActionItemWriter::create failed: caldav down');

		$this->assertNull($this->writer()->create(['title' => 'a']));
	}//end testAFailingCreateIsLoggedAndNull()

	/**
	 * An update keeps the round-tripped fields it does not change and applies the changes.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsUnchangedFields(): void {
		$this->tasks->method('getAllUserTasks')->with(null, 1000)->willReturn(
			[
				'results' => [
					['uid' => 'other', 'id' => 'other.ics', 'calendarId' => '1'],
					[
						'uid' => 'u-1',
						'id' => 'u-1.ics',
						'calendarId' => '5',
						'summary' => 'Send the minutes',
						'title' => 'Send the minutes',
						'fields' => ['assignee' => 'bob', 'taskStatus' => 'open', 'meeting' => 'm-1'],
					],
				],
			]
		);
		$this->tasks->expects($this->once())->method('updateTask')->with(
			'5',
			'u-1.ics',
			[
				'summary' => 'Send the minutes',
				'status' => 'COMPLETED',
				'fields' => ['assignee' => 'bob', 'taskStatus' => 'done', 'meeting' => 'm-1'],
			]
		)->willReturn(['uid' => 'u-1', 'status' => 'COMPLETED']);

		$this->assertSame(['uid' => 'u-1', 'status' => 'COMPLETED'], $this->writer()->update('u-1', ['taskStatus' => 'done']));
	}//end testAnUpdateKeepsUnchangedFields()

	/**
	 * A task is also found by its uri, and an update without a title falls back to the summary.
	 *
	 * @return void
	 */
	public function testATaskIsFoundByItsUriAndTitledByItsSummary(): void {
		$this->tasks->method('getAllUserTasks')->willReturn(
			['results' => [['uid' => 'u-9', 'id' => 'task-9.ics', 'calendarId' => '2', 'summary' => 'Book the room', 'fields' => 'corrupt']]]
		);
		$this->tasks->expects($this->once())->method('updateTask')
			->with('2', 'task-9.ics', ['summary' => 'Book the room', 'status' => 'NEEDS-ACTION'])
			->willReturn(['uid' => 'u-9']);

		$this->assertSame(['uid' => 'u-9'], $this->writer()->update('task-9.ics', []));
	}//end testATaskIsFoundByItsUriAndTitledByItsSummary()

	/**
	 * Updating or deleting an unknown task touches nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownTaskIsNotTouched(): void {
		$this->tasks->method('getAllUserTasks')->willReturn(['results' => []]);
		$this->tasks->expects($this->never())->method('updateTask');
		$this->tasks->expects($this->never())->method('deleteTask');

		$this->assertNull($this->writer()->update('nope', ['title' => 'x']));
		$this->assertFalse($this->writer()->delete('nope'));
	}//end testAnUnknownTaskIsNotTouched()

	/**
	 * A delete removes the located task from its calendar.
	 *
	 * @return void
	 */
	public function testADeleteRemovesTheTask(): void {
		$this->tasks->method('getAllUserTasks')->willReturn(['results' => [['uid' => 'u-1', 'id' => 'u-1.ics', 'calendarId' => '5']]]);
		$this->tasks->expects($this->once())->method('deleteTask')->with('5', 'u-1.ics');

		$this->assertTrue($this->writer()->delete('u-1'));
	}//end testADeleteRemovesTheTask()

	/**
	 * Failing updates and deletes are logged and answer null and false.
	 *
	 * @return void
	 */
	public function testFailingUpdatesAndDeletesAreLogged(): void {
		$this->tasks->method('getAllUserTasks')->willReturn(['results' => [['uid' => 'u-1', 'id' => 'u-1.ics', 'calendarId' => '5']]]);
		$this->tasks->method('updateTask')->willThrowException(new RuntimeException('locked'));
		$this->tasks->method('deleteTask')->willThrowException(new RuntimeException('gone'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		$this->assertNull($this->writer()->update('u-1', ['title' => 'x']));
		$this->assertFalse($this->writer()->delete('u-1'));
		$this->assertSame(['ActionItemWriter::update failed: locked', 'ActionItemWriter::delete failed: gone'], $warnings);
	}//end testFailingUpdatesAndDeletesAreLogged()
}//end class
