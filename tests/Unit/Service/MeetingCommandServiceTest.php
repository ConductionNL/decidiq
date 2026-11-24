<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingCommandService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the cross-app meeting command engine.
 *
 * Idempotency is asserted by calling the seam twice and counting meetings: one
 * call cannot tell an update from a duplicate.
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 */
class MeetingCommandServiceTest extends TestCase {

	/**
	 * Stored meetings, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Whether findAll ignores its filters, as a store dropping an unknown key does.
	 *
	 * @var boolean
	 */
	private bool $dropFilters = false;

	/**
	 * The service under test, over an in-memory register.
	 *
	 * @return MeetingCommandService The service.
	 */
	private function service(): MeetingCommandService {
		$facade = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$facade->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntityInterface {
				$uuid = 'meeting-' . (count($this->rows) + 1);
				$this->rows[$uuid] = ($object + ['id' => $uuid]);

				return $this->entity(row: $this->rows[$uuid]);
			}
		);
		$facade->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch): ObjectEntityInterface {
				$uuid = $patch->objectId;
				$this->rows[$uuid] = array_merge($this->rows[$uuid], $patch->data);

				return $this->entity(row: $this->rows[$uuid]);
			}
		);
		$facade->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'];
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach ($this->rows as $row) {
					$match = true;
					foreach ($filters as $key => $value) {
						if ($this->dropFilters === false && ($row[$key] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$out[] = $row;
					}
				}

				return $out;
			}
		);

		return new MeetingCommandService(store: new RegisterObjectStore(objectService: $facade));

	}//end service()

	/**
	 * An entity mock serialising to a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntityInterface The entity.
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;

	}//end entity()

	/**
	 * A dossiq session subject.
	 *
	 * @param string $id The session uuid.
	 *
	 * @return array{register: string, schema: string, id: string} The subject.
	 */
	private function subject(string $id = 'session-1'): array {
		return ['register' => 'dossiq', 'schema' => 'hearingSession', 'id' => $id];

	}//end subject()

	/**
	 * A new meeting carries the subject, the defaults and the scheduled lifecycle.
	 *
	 * @return void
	 */
	public function testANewSubjectGetsAScheduledMeetingPointingBack(): void {
		$result = $this->service()->upsert(
			subject: $this->subject(),
			meeting: ['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-10T10:00:00+01:00', 'location' => 'Raadzaal'],
		);

		$this->assertTrue(condition: $result['created']);
		$row = $this->rows[$result['id']];
		$this->assertSame(expected: 'dossiq', actual: $row['subjectRegister']);
		$this->assertSame(expected: 'hearingSession', actual: $row['subjectSchema']);
		$this->assertSame(expected: 'session-1', actual: $row['subjectId']);
		$this->assertSame(expected: 'Hoorzitting', actual: $row['title']);
		$this->assertSame(expected: 'Raadzaal', actual: $row['location']);
		$this->assertSame(expected: 'committee', actual: $row['meetingType']);
		$this->assertSame(expected: 'in-person', actual: $row['meetingMode']);
		$this->assertSame(expected: 'scheduled', actual: $row['lifecycle']);

	}//end testANewSubjectGetsAScheduledMeetingPointingBack()

	/**
	 * A second command for the same subject moves the one meeting and keeps its lifecycle.
	 *
	 * @return void
	 */
	public function testARepeatMovesTheOneMeetingAndKeepsItsLifecycle(): void {
		$service = $this->service();
		$first = $service->upsert(subject: $this->subject(), meeting: ['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-10T10:00:00+01:00']);
		$this->rows[$first['id']]['lifecycle'] = 'opened';

		$second = $service->upsert(subject: $this->subject(), meeting: ['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-17T10:00:00+01:00']);

		$this->assertFalse(condition: $second['created']);
		$this->assertSame(expected: $first['id'], actual: $second['id']);
		$this->assertCount(expectedCount: 1, haystack: $this->rows);
		$this->assertSame(expected: '2026-12-17T10:00:00+01:00', actual: $this->rows[$first['id']]['scheduledDate']);
		$this->assertSame(expected: 'opened', actual: $this->rows[$first['id']]['lifecycle']);

	}//end testARepeatMovesTheOneMeetingAndKeepsItsLifecycle()

	/**
	 * A store that drops the filter does not make an unrelated meeting the match.
	 *
	 * @return void
	 */
	public function testADroppedFilterDoesNotMoveSomebodyElsesMeeting(): void {
		$this->rows['meeting-1'] = [
			'id' => 'meeting-1',
			'title' => 'Raadsvergadering',
			'scheduledDate' => '2026-12-01T19:30:00+01:00',
			'lifecycle' => 'scheduled',
		];
		$this->dropFilters = true;

		$result = $this->service()->upsert(subject: $this->subject(), meeting: ['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-10T10:00:00+01:00']);

		$this->assertTrue(condition: $result['created']);
		$this->assertSame(expected: 'Raadsvergadering', actual: $this->rows['meeting-1']['title']);
		$this->assertCount(expectedCount: 2, haystack: $this->rows);

	}//end testADroppedFilterDoesNotMoveSomebodyElsesMeeting()

	/**
	 * Lifecycle and subject fields cannot be commanded.
	 *
	 * @return void
	 */
	public function testLifecycleAndSubjectAreNotCommandable(): void {
		$result = $this->service()->upsert(
			subject: $this->subject(),
			meeting: ['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-10T10:00:00+01:00', 'lifecycle' => 'closed', 'subjectId' => 'other'],
		);

		$this->assertSame(expected: 'scheduled', actual: $this->rows[$result['id']]['lifecycle']);
		$this->assertSame(expected: 'session-1', actual: $this->rows[$result['id']]['subjectId']);

	}//end testLifecycleAndSubjectAreNotCommandable()

	/**
	 * Incomplete commands are refused and write nothing.
	 *
	 * @param array<string, string> $subject The subject.
	 * @param array<string, mixed>  $meeting The meeting fields.
	 *
	 * @return void
	 *
	 * @dataProvider refusedCommands
	 */
	public function testAnIncompleteCommandIsRefusedAndWritesNothing(array $subject, array $meeting): void {
		try {
			$this->service()->upsert(subject: $subject, meeting: $meeting);
			$this->fail(message: 'The command should have been refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: [], actual: $this->rows);
		}

	}//end testAnIncompleteCommandIsRefusedAndWritesNothing()

	/**
	 * Commands that must be refused.
	 *
	 * @return array<string, array{0: array<string, string>, 1: array<string, mixed>}>
	 */
	public static function refusedCommands(): array {
		$subject = ['register' => 'dossiq', 'schema' => 'hearingSession', 'id' => 'session-1'];
		$meeting = ['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-10T10:00:00+01:00'];

		return [
			'no subject id' => [['schema' => 'hearingSession', 'id' => ''], $meeting],
			'no subject schema' => [['id' => 'session-1'], $meeting],
			'no title' => [$subject, ['title' => ' '] + $meeting],
			'no start' => [$subject, ['scheduledDate' => ''] + $meeting],
			'start is no date' => [$subject, ['scheduledDate' => 'next week-ish'] + $meeting],
			'unknown meeting type' => [$subject, $meeting + ['meetingType' => 'hearing']],
			'unknown meeting mode' => [$subject, $meeting + ['meetingMode' => 'telepathic']],
		];

	}//end refusedCommands()

}//end class
