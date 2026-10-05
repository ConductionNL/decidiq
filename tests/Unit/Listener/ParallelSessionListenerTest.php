<?php

/**
 * Decidiq Parallel Session Listener Test
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\ParallelSessionListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An evening holds parallel sessions; each session is a meeting that points at
 * its evening. Drives the listener through OpenRegister's pre-save events, and
 * validates the seeded evening against the merged Meeting fields with Opis.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 */
class ParallelSessionListenerTest extends TestCase {

	private const EVENING = [
		'title'          => 'Commissieavond 3 november',
		'scheduledDate'  => '2026-11-03T18:00:00+01:00',
		'endDate'        => '2026-11-03T23:00:00+01:00',
		'governanceBody' => 'body-raad',
		'meetingMode'    => 'hybrid',
		'isPublic'       => true,
	];

	/**
	 * Build the listener over an in-memory store of meetings.
	 *
	 * @param array<string, array<string, mixed>> $store    Meetings by id
	 * @param array<int, array<string, mixed>>    $children What a parentMeeting search returns
	 *
	 * @return ParallelSessionListener
	 */
	private function listener(array $store, array $children=[]): ParallelSessionListener {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($store): ?ObjectEntity {
				$row = ($store[(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				return $this->entity(row: $row, uuid: (string)$id);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($children): array {
				// Only the sessions of evening-1 exist: a search for any other
				// meeting's sessions finds none.
				if (($config['filters']['parentMeeting'] ?? null) !== 'evening-1') {
					return [];
				}

				return array_map(fn (array $row): ObjectEntity => $this->entity(row: $row, uuid: 'child'), $children);
			}
		);

		return new ParallelSessionListener(logger: new NullLogger(), objectService: $objectService);

	}//end listener()

	/**
	 * An OR entity double that serialises to the given row.
	 *
	 * @param array<string, mixed> $row  Payload
	 * @param string|null          $uuid Its uuid
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row, ?string $uuid=null): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;

	}//end entity()

	/**
	 * A session for the example evening.
	 *
	 * @param array<string, mixed> $extra Overrides
	 *
	 * @return array<string, mixed>
	 */
	private function session(array $extra=[]): array {
		return array_merge(
			[
				'_schemaSlug'   => 'meeting',
				'title'         => 'Commissie Bestuur',
				'parentMeeting' => 'evening-1',
				'room'          => 'Commissiekamer 1',
				'scheduledDate' => '2026-11-03T19:30:00+01:00',
				'endDate'       => '2026-11-03T22:00:00+01:00',
			],
			$extra
		);

	}//end session()

	/**
	 * A session inside its evening saves, and a meeting without an evening is
	 * not looked at.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testSessionInsideItsEveningSaves(): void {
		$event = new ObjectCreatingEvent($this->entity(row: $this->session(['governanceBody' => 'body-x', 'meetingMode' => 'digital', 'isPublic' => false])));
		$this->listener(['evening-1' => self::EVENING])->handle($event);
		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData(), 'entered values are never overwritten');

		$plain = new ObjectCreatingEvent($this->entity(row: ['_schemaSlug' => 'meeting', 'title' => 'Raad']));
		$this->listener([])->handle($plain);
		self::assertFalse($plain->isPropagationStopped());

	}//end testSessionInsideItsEveningSaves()

	/**
	 * A session of a session is refused.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testSessionOfASessionIsRefused(): void {
		$store = [
			'evening-1' => (self::EVENING + ['parentMeeting' => 'evening-0']),
			'evening-0' => self::EVENING,
		];
		$event = new ObjectCreatingEvent($this->entity(row: $this->session()));
		$this->listener($store)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(ParallelSessionListener::NESTED_MESSAGE, $event->getErrors()['message']);

	}//end testSessionOfASessionIsRefused()

	/**
	 * An evening that has sessions cannot itself become a session.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testEveningWithSessionsCannotBecomeASession(): void {
		$store = ['evening-2' => (self::EVENING + ['title' => 'Andere avond'])];
		$evening = $this->entity(row: (self::EVENING + ['_schemaSlug' => 'meeting', 'parentMeeting' => 'evening-2']), uuid: 'evening-1');
		$event = new ObjectUpdatingEvent($evening, $this->entity(row: self::EVENING, uuid: 'evening-1'));
		$this->listener($store, [$this->session()])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(ParallelSessionListener::NESTED_MESSAGE, $event->getErrors()['message']);

	}//end testEveningWithSessionsCannotBecomeASession()

	/**
	 * A meeting that points at itself is refused.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testMeetingThatIsItsOwnParentIsRefused(): void {
		$self = $this->entity(row: $this->session(['parentMeeting' => 'evening-1']), uuid: 'evening-1');
		$event = new ObjectUpdatingEvent($self, $self);
		$this->listener(['evening-1' => self::EVENING])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(ParallelSessionListener::OWN_PARENT_MESSAGE, $event->getErrors()['message']);

	}//end testMeetingThatIsItsOwnParentIsRefused()

	/**
	 * A session at 23:30 of an evening that ends at 23:00 is refused with a
	 * message naming the evening's times, on create and on update.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testSessionOutsideItsEveningIsRefused(): void {
		$late = $this->session(['scheduledDate' => '2026-11-03T23:30:00+01:00', 'endDate' => null]);
		$create = new ObjectCreatingEvent($this->entity(row: $late));
		$this->listener(['evening-1' => self::EVENING])->handle($create);
		self::assertTrue($create->isPropagationStopped());
		self::assertSame(
			'A session must take place within its evening, 3 November 2026 from 18:00 to 23:00.',
			$create->getErrors()['message']
		);

		$early = $this->entity(row: $this->session(['scheduledDate' => '2026-11-03T17:00:00+01:00']), uuid: 'session-1');
		$update = new ObjectUpdatingEvent($early, $early);
		$this->listener(['evening-1' => self::EVENING])->handle($update);
		self::assertTrue($update->isPropagationStopped());

		$overrun = new ObjectCreatingEvent($this->entity(row: $this->session(['endDate' => '2026-11-03T23:15:00+01:00'])));
		$this->listener(['evening-1' => self::EVENING])->handle($overrun);
		self::assertTrue($overrun->isPropagationStopped(), 'a session may not run past the end of its evening');

	}//end testSessionOutsideItsEveningIsRefused()

	/**
	 * A session that points at an evening that does not exist is refused.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testSessionOfAMissingEveningIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(row: $this->session()));
		$this->listener([])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(ParallelSessionListener::MISSING_PARENT_MESSAGE, $event->getErrors()['message']);

	}//end testSessionOfAMissingEveningIsRefused()

	/**
	 * Only pre-save events and only meetings are looked at: any other event,
	 * and an object of another schema that happens to carry parentMeeting,
	 * pass untouched without a lookup.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testOtherEventsAndOtherSchemasAreNotLookedAt(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::never())->method('find');
		$listener = new ParallelSessionListener(logger: new NullLogger(), objectService: $objectService);

		$other = new Event();
		$listener->handle($other);
		self::assertFalse($other->isPropagationStopped());

		$item = new ObjectCreatingEvent($this->entity(row: $this->session(['_schemaSlug' => 'agendaItem'])));
		$listener->handle($item);
		self::assertFalse($item->isPropagationStopped());
		self::assertSame([], $item->getModifiedData());

	}//end testOtherEventsAndOtherSchemasAreNotLookedAt()

	/**
	 * A parentMeeting stored as an expanded object is read by its id, so the
	 * window check still applies.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testExpandedEveningReferenceIsReadByItsId(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(row: $this->session(['parentMeeting' => ['id' => 'evening-1'], 'scheduledDate' => '2026-11-03T23:30:00+01:00', 'endDate' => null]))
		);
		$this->listener(['evening-1' => self::EVENING])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringStartsWith('A session must take place within its evening', $event->getErrors()['message']);

	}//end testExpandedEveningReferenceIsReadByItsId()

	/**
	 * An evening without a start, or a session with an unreadable start, has no
	 * window to check: the session saves.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testUndatedEveningOrUnreadableStartHasNoWindowToCheck(): void {
		$undated = self::EVENING;
		unset($undated['scheduledDate']);
		$event = new ObjectCreatingEvent($this->entity(row: $this->session(['scheduledDate' => '2026-11-04T09:00:00+01:00'])));
		$this->listener(['evening-1' => $undated])->handle($event);
		self::assertFalse($event->isPropagationStopped());

		$garbled = new ObjectCreatingEvent($this->entity(row: $this->session(['scheduledDate' => 'not a date'])));
		$this->listener(['evening-1' => self::EVENING])->handle($garbled);
		self::assertFalse($garbled->isPropagationStopped());

	}//end testUndatedEveningOrUnreadableStartHasNoWindowToCheck()

	/**
	 * When the evening cannot be loaded (the store throws), the check is
	 * skipped with a warning instead of breaking the save.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testStoreFailureSkipsTheCheckWithAWarning(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willThrowException(new RuntimeException('database gone'));
		$logger = new class extends AbstractLogger {
			/**
			 * Recorded messages.
			 *
			 * @var array<int, array{0: mixed, 1: string, 2: array<string, mixed>}>
			 */
			public array $records = [];

			/**
			 * Record one log line.
			 *
			 * @param mixed                $level   Level
			 * @param string|\Stringable   $message Message
			 * @param array<string, mixed> $context Context
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->records[] = [$level, (string)$message, $context];
			}//end log()
		};

		$event = new ObjectCreatingEvent($this->entity(row: $this->session()));
		(new ParallelSessionListener(logger: $logger, objectService: $objectService))->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
		self::assertCount(1, $logger->records);
		self::assertSame('warning', $logger->records[0][0]);
		self::assertSame('database gone', $logger->records[0][2]['exception']);

	}//end testStoreFailureSkipsTheCheckWithAWarning()

	/**
	 * A new session without a body takes the evening's body, publicity and
	 * mode; an update does not.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testNewSessionTakesTheEveningsBodyPublicityAndMode(): void {
		$event = new ObjectCreatingEvent($this->entity(row: $this->session()));
		$this->listener(['evening-1' => self::EVENING])->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(
			['governanceBody' => 'body-raad', 'meetingMode' => 'hybrid', 'isPublic' => true],
			$event->getModifiedData()
		);

		$existing = $this->entity(row: $this->session(), uuid: 'session-1');
		$update = new ObjectUpdatingEvent($existing, $existing);
		$this->listener(['evening-1' => self::EVENING])->handle($update);
		self::assertSame([], $update->getModifiedData());

	}//end testNewSessionTakesTheEveningsBodyPublicityAndMode()

	/**
	 * The listener is subscribed to meeting creates and updates: a guard with
	 * tests and no subscription never runs.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testRegistrarSubscribesTheListenerToMeetingSaves(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatingEvent::class, ParallelSessionListener::class], $subscribed);
		self::assertContains([ObjectUpdatingEvent::class, ParallelSessionListener::class], $subscribed);

	}//end testRegistrarSubscribesTheListenerToMeetingSaves()

	/**
	 * The merged register declares parentMeeting and room on Meeting, the
	 * municipality example carries the evening with three sessions of two
	 * agenda items each, and the seeded sessions validate with Opis.
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 *
	 * @return void
	 */
	public function testRegisterAndExampleCarryTheEveningAndItsSessions(): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$meeting = [];
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$fragment = ($doc['components']['schemas']['Meeting'] ?? null);
			if (is_array($fragment) === true) {
				$meeting = array_replace_recursive($meeting, $fragment);
			}
		}

		$parent = ($meeting['properties']['parentMeeting'] ?? null);
		self::assertIsArray($parent, 'Meeting.parentMeeting must be declared');
		self::assertSame('uuid', $parent['format']);
		self::assertSame('meeting', $parent['$ref']);
		self::assertTrue($parent['facetable']);
		self::assertIsArray(($meeting['properties']['room'] ?? null), 'Meeting.room must be declared');

		$profile = json_decode((string)file_get_contents($settings . 'profiles/municipality.json'), true);
		$objects = $profile['x-openregister']['seedData']['objects'];
		$evening = array_values(array_filter($objects['meeting'], fn (array $m): bool => ($m['slug'] ?? '') === 'commissieavond-2026-11-03'));
		self::assertCount(1, $evening, 'the example evening is seeded');
		$sessions = array_values(array_filter($objects['meeting'], fn (array $m): bool => ($m['parentMeeting'] ?? '') === 'commissieavond-2026-11-03'));
		self::assertSame(
			['Commissie Bestuur', 'Commissie Ruimte', 'Commissie Samenleving'],
			(function (array $titles): array {
				sort($titles);
				return $titles;
			})(array_column($sessions, 'title'))
		);

		$schema = json_decode(
			(string)json_encode(
				[
					'type'       => 'object',
					'properties' => [
						'parentMeeting' => ['type' => 'string'],
						'room'          => $meeting['properties']['room'],
						'scheduledDate' => $meeting['properties']['scheduledDate'],
						'endDate'       => $meeting['properties']['endDate'],
						'meetingMode'   => $meeting['properties']['meetingMode'],
					],
				]
			)
		);
		$validator = new Validator();
		$validator->parser()->setOption('allowFormats', true);
		$listener = $this->listener(['commissieavond-2026-11-03' => $evening[0]]);
		foreach ($sessions as $session) {
			$payload = array_intersect_key($session, ['parentMeeting' => 1, 'room' => 1, 'scheduledDate' => 1, 'endDate' => 1, 'meetingMode' => 1]);
			self::assertTrue($validator->validate(json_decode((string)json_encode($payload)), $schema)->isValid(), $session['title']);
			self::assertNotEmpty(($session['room'] ?? ''), $session['title'] . ' has a room');

			$items = array_filter($objects['agenda-item'], fn (array $i): bool => ($i['meeting'] ?? '') === $session['slug']);
			self::assertCount(2, $items, $session['title'] . ' has two agenda items');

			$event = new ObjectCreatingEvent($this->entity(row: (['_schemaSlug' => 'meeting'] + $session)));
			$listener->handle($event);
			self::assertFalse($event->isPropagationStopped(), $session['title'] . ' fits inside the seeded evening');
		}

		self::assertFalse($validator->validate(json_decode('{"meetingMode":"by-pigeon"}'), $schema)->isValid(), 'the validator must reject a bad value, or it proves nothing');

	}//end testRegisterAndExampleCarryTheEveningAndItsSessions()
}//end class
