<?php

/**
 * Golden tests for the meeting-scoped reads of MeetingAttendanceReader and
 * SubstitutionResolver.
 *
 * Pins the query each sends, what each logs and answers when the read fails,
 * which rows each keeps (entity or array, bare or expanded references, a
 * foreign meeting, a row that is not an object), the id a substitution record
 * gets, their order, the resolver's per-meeting cache, and the quorum seats.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingAttendanceReader;
use OCA\Decidiq\Service\SubstitutionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins both meeting-scoped readers.
 */
final class MeetingRecordReadsGoldenTest extends TestCase {
	/**
	 * Every query sent, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Every warning logged, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * An object service answering the given rows, or failing.
	 *
	 * @param list<mixed> $rows The rows.
	 * @param bool        $fail Whether the read fails.
	 *
	 * @return ObjectServiceInterface The service.
	 */
	private function objects(array $rows, bool $fail = false): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($rows, $fail): array {
				$this->queries[] = $config;
				if ($fail === true) {
					throw new RuntimeException('database gone');
				}

				return $rows;
			}
		);
		return $objects;
	}//end objects()

	/**
	 * A logger recording warnings.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		return $logger;
	}//end logger()

	/**
	 * A row as an entity.
	 *
	 * @param array<string, mixed> $data The serialised row.
	 *
	 * @return Entity The entity.
	 */
	private function entity(array $data): Entity {
		$entity = $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->addMethods(['jsonSerialize'])->getMock();
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Attendance: the query, the kept rows and the failure.
	 *
	 * @return void
	 */
	public function testAttendanceStatuses(): void {
		$rows = [
			['meeting' => 'm-1', 'participant' => 'p-1', 'status' => 'present'],
			$this->entity(['meeting' => ['id' => 'm-1'], 'participant' => ['uuid' => 'p-2'], 'status' => 'absent']),
			['meeting' => ['uuid' => 'm-1'], 'participant' => 'p-3'],
			['meeting' => 'm-2', 'participant' => 'p-4', 'status' => 'present'],
			['meeting' => 'm-1', 'participant' => '', 'status' => 'present'],
			['meeting' => 'm-1', 'participant' => 42, 'status' => 'present'],
			'not a row',
			['meeting' => 'm-1', 'participant' => 'p-1', 'status' => 'late'],
		];
		$reader = new MeetingAttendanceReader(objectService: $this->objects(rows: $rows), logger: $this->logger());

		$this->assertSame([], $reader->statusesFor(meetingId: ''));
		$this->assertSame(['p-1' => 'late', 'p-2' => 'absent', 'p-3' => ''], $reader->statusesFor(meetingId: 'm-1'));
		$this->assertSame(
			[['filters' => ['register' => 'decidiq', 'schema' => 'meeting-attendance', 'meeting' => 'm-1'], 'limit' => 1000]],
			$this->queries
		);

		$failing = new MeetingAttendanceReader(objectService: $this->objects(rows: [], fail: true), logger: $this->logger());
		$this->assertSame([], $failing->statusesFor(meetingId: 'm-9'));
		$this->assertSame(
			[['Decidiq: attendance of a meeting could not be read, using the participant value', ['meetingId' => 'm-9', 'exception' => 'database gone']]],
			$this->warnings
		);
		$this->assertSame([], (new MeetingAttendanceReader(objectService: $this->objects(rows: [], fail: true)))->statusesFor(meetingId: 'm-9'));
	}//end testAttendanceStatuses()

	/**
	 * Attendance: the overlay and the id of a row.
	 *
	 * @return void
	 */
	public function testAttendanceOverlayAndIds(): void {
		$reader = new MeetingAttendanceReader(objectService: $this->objects(rows: []));
		$participants = [['id' => 'p-1'], ['@self' => ['id' => 'p-2']], ['uuid' => 'p-3', 'attendanceStatus' => 'present'], ['@self' => 'junk']];

		$this->assertSame($participants, $reader->overlay(participants: $participants, statuses: []));
		$this->assertSame(
			[['id' => 'p-1', 'attendanceStatus' => 'present'], ['@self' => ['id' => 'p-2'], 'attendanceStatus' => ''], ['uuid' => 'p-3', 'attendanceStatus' => 'absent'], ['@self' => 'junk', 'attendanceStatus' => '']],
			$reader->overlay(participants: $participants, statuses: ['p-1' => 'present', 'p-3' => 'absent'])
		);
		$this->assertSame(['p-1', 'p-2', 'p-3', ''], array_map(fn (array $row): string => $reader->idOf(row: $row), $participants));
	}//end testAttendanceOverlayAndIds()

	/**
	 * Substitutions: the query, kept rows with their ids, order, cache and failure.
	 *
	 * @return void
	 */
	public function testSubstitutionRecords(): void {
		$rows = [
			['@self' => ['id' => 's-2'], 'meeting' => 'm-1', 'startedAt' => '2026-01-02', 'outgoingParticipant' => 'a', 'incomingParticipant' => ['id' => 'b']],
			$this->entity(['id' => 's-1', 'meeting' => ['id' => 'm-1'], 'startedAt' => '2026-01-01', 'endedAt' => '2026-01-01T12', 'outgoingParticipant' => 'c', 'incomingParticipant' => 'd']),
			['uuid' => 's-3', 'meeting' => 'm-1', 'outgoingParticipant' => ['uuid' => 'e'], 'incomingParticipant' => 'f'],
			['id' => 's-4', 'meeting' => 'm-2', 'startedAt' => '2025-01-01'],
			'not a row',
		];
		$resolver = new SubstitutionResolver(objectService: $this->objects(rows: $rows), logger: $this->logger());

		$this->assertSame([], $resolver->allFor(meetingId: ''));
		$all = $resolver->allFor(meetingId: 'm-1');
		$this->assertSame(['s-3', 's-1', 's-2'], array_column($all, 'id'));
		$this->assertSame($all, $resolver->allFor(meetingId: 'm-1'));
		$this->assertCount(1, $this->queries);
		$this->assertSame(['filters' => ['register' => 'decidiq', 'schema' => 'mandate-substitution', 'meeting' => 'm-1'], 'limit' => 1000], $this->queries[0]);

		$this->assertSame(['s-3', 's-2'], array_column($resolver->activeFor(meetingId: 'm-1'), 'id'));
		$this->assertSame(['f', 'b'], $resolver->activeSubstitutes(meetingId: 'm-1'));
		$this->assertSame('b', $resolver->seatHolderFor(meetingId: 'm-1', participantId: 'a'));
		$this->assertSame('c', $resolver->seatHolderFor(meetingId: 'm-1', participantId: 'c'));
		$this->assertTrue($resolver->isSubstitutedOut(meetingId: 'm-1', participantId: 'e'));
		$this->assertNull($resolver->substitutionOf(meetingId: 'm-1', participantId: 'z'));

		$failing = new SubstitutionResolver(objectService: $this->objects(rows: [], fail: true), logger: $this->logger());
		$this->assertSame([], $failing->allFor(meetingId: 'm-9'));
		$this->assertSame([], $failing->allFor(meetingId: 'm-9'));
		$this->assertSame(
			[['Decidiq: substitutions of a meeting could not be read; every member keeps their seat', ['meetingId' => 'm-9', 'exception' => 'database gone']]],
			$this->warnings
		);
		$this->assertCount(2, $this->queries);
	}//end testSubstitutionRecords()

	/**
	 * Substitutions: the seats the quorum counts.
	 *
	 * @return void
	 */
	public function testQuorumSeats(): void {
		$rows = [['id' => 's-1', 'meeting' => 'm-1', 'outgoingParticipant' => 'a', 'incomingParticipant' => 'b']];
		$resolver = new SubstitutionResolver(objectService: $this->objects(rows: $rows));
		$none = new SubstitutionResolver(objectService: $this->objects(rows: []));
		$untaken = [['id' => 'a'], ['id' => 'b'], ['@self' => ['id' => 'c']]];
		$taken = [['id' => 'a', 'attendanceStatus' => 'absent'], ['id' => 'b', 'attendanceStatus' => 'present'], ['id' => 'c', 'attendanceStatus' => '']];

		$this->assertSame($untaken, $none->seatsForQuorum(meetingId: 'm-1', participants: $untaken));
		$this->assertSame([['id' => 'a'], ['@self' => ['id' => 'c']]], $resolver->seatsForQuorum(meetingId: 'm-1', participants: $untaken));
		$this->assertSame(
			[['id' => 'a', 'attendanceStatus' => 'present'], ['id' => 'c', 'attendanceStatus' => '']],
			$resolver->seatsForQuorum(meetingId: 'm-1', participants: $taken)
		);
	}//end testQuorumSeats()
}//end class
