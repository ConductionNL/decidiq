<?php

/**
 * Decidiq MeetingBroadcastServiceTest
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Exception\BroadcastRefusedException;
use OCA\Decidiq\Service\MeetingBroadcastService;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Service\StreamingClient;
use OCA\Decidiq\Tests\Unit\Support\CaseSystemWorld;
use OCA\Decidiq\Tests\Unit\Support\StreamingCallServiceFake;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * The council meeting of 19 March, opened at 19:30, broadcast through the
 * organisation's streaming service.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
 *
 * @uses \OCA\Decidiq\Service\StreamingClient
 * @uses \OCA\Decidiq\Service\SigningAnswer
 * @uses \OCA\Decidiq\Exception\BroadcastRefusedException
 * @uses \OCA\Decidiq\Support\FleetAppId
 */
final class MeetingBroadcastServiceTest extends TestCase {
	/**
	 * When the meeting opened.
	 */
	private const OPENED = '2026-03-19T19:30:00+01:00';

	/**
	 * The in-memory register, with schema validation on every save.
	 *
	 * @var CaseSystemWorld
	 */
	private CaseSystemWorld $world;

	/**
	 * The clock, in Unix seconds.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * The public council meeting.
	 *
	 * @var string
	 */
	private string $meeting;

	/**
	 * Every lifecycle the broadcasts passed through, in order, per broadcast.
	 *
	 * @var array<string, list<string>>
	 */
	private array $trail = [];

	/**
	 * Set up a public meeting, its body and a linked streaming service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world = new CaseSystemWorld();
		$this->now   = (int)strtotime(self::OPENED);
		$body        = $this->world->put(schema: 'governance-body', data: ['name' => 'Gemeenteraad']);
		$this->meeting = $this->world->put(schema: 'meeting', data: ['title' => 'Raadsvergadering 19 maart', 'scheduledDate' => self::OPENED, 'openedAt' => self::OPENED, 'isPublic' => true, 'governanceBody' => $body]);
		$this->connect();
		$this->world->answers['start-test']   = ['previewUrl' => 'https://stream.example.org/preview/19-maart'];
		$this->world->answers['start']        = ['playerUrl' => 'https://stream.example.org/live/19-maart'];
		$this->world->answers['live-captions'] = ['accepted' => true];
		$this->world->answers['stop']         = ['recordingUrl' => 'https://stream.example.org/recordings/19-maart'];
	}//end setUp()

	/**
	 * Every save was valid against the real schema, and every lifecycle move
	 * is one the schema declares.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->assertSame([], $this->world->invalid, 'A broadcast was saved that its own schema refuses.');

		$declared = [];
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/120-meeting-broadcast.json'), true);
		foreach ($fragment['components']['schemas']['MeetingBroadcast']['x-openregister-lifecycle']['transitions'] as $transition) {
			$declared[] = $transition['from'] . '>' . $transition['to'];
		}

		foreach ($this->trail as $states) {
			for ($i = 1; $i < count($states); $i++) {
				if ($states[$i] === $states[($i - 1)]) {
					continue;
				}

				$this->assertContains($states[($i - 1)] . '>' . $states[$i], $declared, 'An undeclared lifecycle move was written.');
			}
		}
	}//end tearDown()

	/**
	 * Link a source to decidiq's streaming connection.
	 *
	 * @return void
	 */
	private function connect(): void {
		$source = $this->world->put(schema: 'source', data: ['slug' => 'streaming'], register: 'integriq');
		$this->world->put(schema: 'app_connection', data: ['app' => 'decidiq', 'key' => 'streaming', 'source' => $source], register: 'integriq');
	}//end connect()

	/**
	 * An entity over a world object.
	 *
	 * @param string $id The id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id): ObjectEntity {
		$data   = (['id' => $id] + $this->world->objects[$id]['data']);
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($id);
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * OpenRegister's object service over the world.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => (isset($this->world->objects[(string)$id]) === true ? $this->entity(id: (string)$id) : null));
		$objects->method('findAll')->willReturnCallback(fn (array $config=[]): array => array_map(fn (string $id): ObjectEntity => $this->entity(id: $id), $this->world->match(config: $config)));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				$id = $this->world->save(data: $object, register: (string)$register, schema: (string)$schema, id: $uuid);
				if ((string)$schema === 'meeting-broadcast') {
					$this->trail[$id][] = (string)($object['lifecycle'] ?? '');
				}

				return $this->entity(id: $id);
			}
		);
		return $objects;
	}//end objects()

	/**
	 * The container with integriq's call service.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$services  = ['OCA\Integriq\Service\CallService' => new StreamingCallServiceFake(world: $this->world)];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	/**
	 * The service under test, with the real streaming client.
	 *
	 * @return MeetingBroadcastService
	 */
	private function service(): MeetingBroadcastService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime('@' . $this->now));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$user->method('getDisplayName')->willReturn('Griffier De Vries');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objects = $this->objects();
		return new MeetingBroadcastService(
			objectService: $objects,
			streaming: new StreamingClient(objectService: $objects, container: $this->container(), answers: new SigningAnswer()),
			time: $time,
			userSession: $session,
		);
	}//end service()

	/**
	 * The stored broadcast.
	 *
	 * @param string $id The broadcast.
	 *
	 * @return array<string, mixed>
	 */
	private function broadcast(string $id): array {
		return $this->world->objects[$id]['data'];
	}//end broadcast()

	/**
	 * Assert that an action is refused with a status and message.
	 *
	 * @param callable $action  The action.
	 * @param int      $status  The expected status.
	 * @param string   $message The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(callable $action, int $status, string $message): void {
		try {
			$action();
			$this->fail('Expected a refusal: ' . $message);
		} catch (BroadcastRefusedException $e) {
			$this->assertSame($status, $e->getStatus());
			$this->assertSame($message, $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * Without a linked source every broadcast action answers 409 and nothing
	 * reaches a streaming service.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testWithoutAStreamingServiceEveryActionAnswers409(): void {
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'live', 'publicWindows' => [['start' => 0, 'recordingStart' => 0]]]);
		foreach (array_keys($this->world->objects) as $id) {
			if ($this->world->objects[$id]['schema'] === 'app_connection') {
				unset($this->world->objects[$id]);
			}
		}

		$service = $this->service();
		$this->assertFalse($service->isConnected());
		$actions = [
			fn () => $service->startTest(meetingId: $this->meeting),
			fn () => $service->recordTestResult(broadcastId: $broadcast, result: 'ok', note: ''),
			fn () => $service->start(broadcastId: $broadcast),
			fn () => $service->pause(broadcastId: $broadcast),
			fn () => $service->resume(broadcastId: $broadcast),
			fn () => $service->stop(broadcastId: $broadcast),
		];
		foreach ($actions as $action) {
			$this->assertRefused(action: $action, status: 409, message: 'No streaming service is connected');
		}

		$this->assertSame([], $this->world->calls);
		$this->assertSame('live', $this->broadcast(id: $broadcast)['lifecycle']);
	}//end testWithoutAStreamingServiceEveryActionAnswers409()

	/**
	 * The streaming source is found through integriq's connection rows, never
	 * through integriq's retired source mapper.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testTheStreamingServiceIsNeverLookedUpThroughTheSourceMapper(): void {
		foreach (['Service/MeetingBroadcastService.php', 'Service/StreamingClient.php'] as $file) {
			$source = (string)file_get_contents(__DIR__ . '/../../../lib/' . $file);
			$this->assertNotSame('', $source, $file . ' is missing.');
			$this->assertStringNotContainsString('SourceMapper', $source, $file);
		}

		$this->assertTrue($this->service()->isConnected());
	}//end testTheStreamingServiceIsNeverLookedUpThroughTheSourceMapper()

	/**
	 * The clerk checks picture and sound before the meeting: the preview is
	 * staff-only, so the broadcast gets no player address and no publication date.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return void
	 */
	public function testATestBroadcastRunsOnTheStaffPreviewOnly(): void {
		$broadcast = $this->service()->startTest(meetingId: $this->meeting);

		$stored = $this->broadcast(id: $broadcast['id']);
		$this->assertSame('testing', $stored['lifecycle']);
		$this->assertSame('https://stream.example.org/preview/19-maart', $stored['previewUrl']);
		$this->assertArrayNotHasKey('playerUrl', $stored);
		$this->assertArrayNotHasKey('publicationDate', $stored);
		$this->assertSame($this->meeting, $stored['meeting']);
		$this->assertSame('Raadsvergadering 19 maart', $stored['title']);
		$this->assertSame('Gemeenteraad', $stored['bodyName']);
		$this->assertSame(self::OPENED, $stored['scheduledDate']);
		$this->assertSame([['meeting' => $this->meeting, 'title' => 'Raadsvergadering 19 maart']], $this->world->callsOf(operation: 'start-test'));

		// A second test reuses the one broadcast of the meeting.
		$this->service()->recordTestResult(broadcastId: $broadcast['id'], result: 'ok', note: '');
		$again = $this->service()->startTest(meetingId: $this->meeting);
		$this->assertSame($broadcast['id'], $again['id']);
		$this->assertCount(1, $this->world->all(schema: 'meeting-broadcast'));
	}//end testATestBroadcastRunsOnTheStaffPreviewOnly()

	/**
	 * The clerk records what the test showed, and the broadcast is back in planned.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return void
	 */
	public function testTheClerkRecordsWhatTheTestShowed(): void {
		$broadcast = $this->service()->startTest(meetingId: $this->meeting);
		$this->now += 600;

		$this->service()->recordTestResult(broadcastId: $broadcast['id'], result: 'problems', note: 'Microphone 4 had no sound, replaced');

		$stored = $this->broadcast(id: $broadcast['id']);
		$this->assertSame('planned', $stored['lifecycle']);
		$this->assertSame('problems', $stored['testResult']);
		$this->assertSame('Microphone 4 had no sound, replaced', $stored['testNote']);
		$this->assertSame('Griffier De Vries', $stored['testedBy']);
		$this->assertSame('2026-03-19T18:40:00+00:00', $stored['testedAt']);

		$this->assertRefused(
			action: fn () => $this->service()->recordTestResult(broadcastId: $broadcast['id'], result: 'fine', note: ''),
			status: 422,
			message: 'A test result is ok or problems'
		);
		$this->assertRefused(
			action: fn () => $this->service()->recordTestResult(broadcastId: $broadcast['id'], result: 'ok', note: ''),
			status: 409,
			message: 'No test is running for this broadcast'
		);
	}//end testTheClerkRecordsWhatTheTestShowed()

	/**
	 * A meeting that is not public cannot go live, and the service is not asked.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testAMeetingThatIsNotPublicCannotGoLive(): void {
		$closed    = $this->world->put(schema: 'meeting', data: ['title' => 'Besloten bestuursvergadering', 'openedAt' => self::OPENED, 'isPublic' => false]);
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $closed, 'lifecycle' => 'planned']);

		$this->assertRefused(action: fn () => $this->service()->start(broadcastId: $broadcast), status: 422, message: 'Only a public meeting can be broadcast');
		$this->assertSame([], $this->world->callsOf(operation: 'start'));
		$this->assertSame('planned', $this->broadcast(id: $broadcast)['lifecycle']);
	}//end testAMeetingThatIsNotPublicCannotGoLive()

	/**
	 * Going live makes the stream public: player address, publication date and
	 * one open window that starts at the second the meeting is at.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testGoingLiveMakesTheStreamPublic(): void {
		$broadcast = $this->service()->startTest(meetingId: $this->meeting);
		$this->now += 120;

		$this->service()->start(broadcastId: $broadcast['id']);

		$stored = $this->broadcast(id: $broadcast['id']);
		$this->assertSame('live', $stored['lifecycle']);
		$this->assertSame('https://stream.example.org/live/19-maart', $stored['playerUrl']);
		$this->assertSame('2026-03-19T18:32:00+00:00', $stored['publicationDate']);
		$this->assertSame([['start' => 120, 'recordingStart' => 0]], $stored['publicWindows']);
		$this->assertSame(['planned', 'testing', 'live'], $this->trail[$broadcast['id']]);
	}//end testGoingLiveMakesTheStreamPublic()

	/**
	 * The council goes into a closed session at 5400 seconds and comes back at
	 * 6300: one closed window and one open window. When the service does not
	 * say where the second part starts in the recording, it is the sum of the
	 * earlier windows (the service cut the paused time).
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return void
	 */
	public function testAClosedSessionPausesTheBroadcastAndClosesTheWindow(): void {
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);
		$this->service()->start(broadcastId: $broadcast);

		$this->now += 5400;
		$this->service()->pause(broadcastId: $broadcast);
		$this->assertSame('paused', $this->broadcast(id: $broadcast)['lifecycle']);
		$this->assertSame([['start' => 0, 'recordingStart' => 0, 'end' => 5400]], $this->broadcast(id: $broadcast)['publicWindows']);

		$this->now += 900;
		$this->service()->resume(broadcastId: $broadcast);

		$stored = $this->broadcast(id: $broadcast);
		$this->assertSame('live', $stored['lifecycle']);
		$this->assertSame([['start' => 0, 'recordingStart' => 0, 'end' => 5400], ['start' => 6300, 'recordingStart' => 5400]], $stored['publicWindows']);
		$this->assertCount(1, $this->world->callsOf(operation: 'pause'));
		$this->assertCount(1, $this->world->callsOf(operation: 'resume'));
	}//end testAClosedSessionPausesTheBroadcastAndClosesTheWindow()

	/**
	 * A service that keeps the paused time in its recording reports where the
	 * resumed part begins, and that second is kept.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return void
	 */
	public function testTheRecordingStartTheServiceReportsIsKept(): void {
		$this->world->answers['resume'] = ['recordingStart' => 6300];
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);
		$this->service()->start(broadcastId: $broadcast);
		$this->now += 5400;
		$this->service()->pause(broadcastId: $broadcast);
		$this->now += 900;
		$this->service()->resume(broadcastId: $broadcast);

		$this->assertSame(6300, $this->broadcast(id: $broadcast)['publicWindows'][1]['recordingStart']);
	}//end testTheRecordingStartTheServiceReportsIsKept()

	/**
	 * Stopping closes the last window, ends the broadcast and keeps the
	 * recording address the service reports. An ended broadcast stays ended.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return void
	 */
	public function testStoppingEndsTheBroadcast(): void {
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);
		$this->service()->start(broadcastId: $broadcast);
		$this->now += 7200;

		$this->service()->stop(broadcastId: $broadcast);

		$stored = $this->broadcast(id: $broadcast);
		$this->assertSame('ended', $stored['lifecycle']);
		$this->assertSame([['start' => 0, 'recordingStart' => 0, 'end' => 7200]], $stored['publicWindows']);
		$this->assertSame('https://stream.example.org/recordings/19-maart', $stored['recordingUrl']);

		$this->assertRefused(action: fn () => $this->service()->start(broadcastId: $broadcast), status: 409, message: 'The broadcast has ended');
		$this->assertRefused(action: fn () => $this->service()->startTest(meetingId: $this->meeting), status: 409, message: 'The broadcast has ended');
	}//end testStoppingEndsTheBroadcast()

	/**
	 * Stopping during a closed session keeps the closed window as it was, and a
	 * service that has no recording address yet leaves recordingUrl empty.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return void
	 */
	public function testStoppingDuringAClosedSessionKeepsTheWindowClosed(): void {
		$this->world->answers['stop'] = [];
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);
		$this->service()->start(broadcastId: $broadcast);
		$this->now += 5400;
		$this->service()->pause(broadcastId: $broadcast);
		$this->now += 900;

		$this->service()->stop(broadcastId: $broadcast);

		$stored = $this->broadcast(id: $broadcast);
		$this->assertSame('ended', $stored['lifecycle']);
		$this->assertSame([['start' => 0, 'recordingStart' => 0, 'end' => 5400]], $stored['publicWindows']);
		$this->assertArrayNotHasKey('recordingUrl', $stored);
	}//end testStoppingDuringAClosedSessionKeepsTheWindowClosed()

	/**
	 * A move the lifecycle does not declare is refused before the service is asked.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
	 *
	 * @return void
	 */
	public function testAMoveTheLifecycleDoesNotDeclareIsRefused(): void {
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);

		$this->assertRefused(action: fn () => $this->service()->pause(broadcastId: $broadcast), status: 409, message: 'The broadcast is planned, so it cannot be paused');
		$this->assertRefused(action: fn () => $this->service()->resume(broadcastId: $broadcast), status: 409, message: 'The broadcast is planned, so it cannot be resumed');
		$this->assertSame([], $this->world->calls);
		$this->assertRefused(action: fn () => $this->service()->start(broadcastId: 'no-such-broadcast'), status: 404, message: 'Broadcast not found');
	}//end testAMoveTheLifecycleDoesNotDeclareIsRefused()

	/**
	 * When the broadcast goes live decidiq asks the service for live captions
	 * and records that it asked.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-005-live-captions-come-from-the-streaming-service
	 *
	 * @return void
	 */
	public function testLiveCaptionsAreRequestedFromTheService(): void {
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);

		$this->service()->start(broadcastId: $broadcast);

		$this->assertSame('requested', $this->broadcast(id: $broadcast)['liveCaptions']);
		$this->assertCount(1, $this->world->callsOf(operation: 'live-captions'));
	}//end testLiveCaptionsAreRequestedFromTheService()

	/**
	 * A service without live captions: the broadcast still goes live, and
	 * liveCaptions reads unavailable. A service that answers with an error is
	 * the same as one that says no.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-005-live-captions-come-from-the-streaming-service
	 *
	 * @return void
	 */
	public function testAServiceWithoutLiveCaptions(): void {
		$this->world->answers['live-captions'] = ['accepted' => false];
		$first = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);
		$this->service()->start(broadcastId: $first);
		$this->assertSame('live', $this->broadcast(id: $first)['lifecycle']);
		$this->assertSame('unavailable', $this->broadcast(id: $first)['liveCaptions']);

		$this->world->answers['live-captions'] = ['_status' => 501, 'message' => 'Not supported'];
		$second = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);
		$this->service()->start(broadcastId: $second);
		$this->assertSame('live', $this->broadcast(id: $second)['lifecycle']);
		$this->assertSame('unavailable', $this->broadcast(id: $second)['liveCaptions']);
	}//end testAServiceWithoutLiveCaptions()

	/**
	 * A streaming service that refuses to start leaves the broadcast as it was,
	 * and the refusal names the service's own message.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testAServiceThatRefusesToStartChangesNothing(): void {
		$this->world->answers['start'] = ['_status' => 503, 'message' => 'Encoder offline'];
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);

		$this->assertRefused(action: fn () => $this->service()->start(broadcastId: $broadcast), status: 502, message: 'Encoder offline');
		$this->assertSame(['meeting' => $this->meeting, 'lifecycle' => 'planned'], $this->broadcast(id: $broadcast));

		$this->world->answers['start'] = [];
		$this->assertRefused(action: fn () => $this->service()->start(broadcastId: $broadcast), status: 502, message: 'The streaming service sent no player address');
		$this->assertSame('planned', $this->broadcast(id: $broadcast)['lifecycle']);
	}//end testAServiceThatRefusesToStartChangesNothing()

	/**
	 * The status the widget reads: whether a service is connected and the
	 * meeting's broadcast, if it has one.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testTheStatusNamesTheConnectionAndTheBroadcast(): void {
		$this->assertSame(['connected' => true, 'broadcast' => null], $this->service()->status(meetingId: $this->meeting));

		$broadcast = $this->service()->startTest(meetingId: $this->meeting);
		$status    = $this->service()->status(meetingId: $this->meeting);
		$this->assertSame($broadcast['id'], $status['broadcast']['id']);
		$this->assertSame($this->meeting, $this->service()->meetingOf(broadcastId: $broadcast['id']));
		$this->assertSame('', $this->service()->meetingOf(broadcastId: 'no-such-broadcast'));
	}//end testTheStatusNamesTheConnectionAndTheBroadcast()
}//end class
