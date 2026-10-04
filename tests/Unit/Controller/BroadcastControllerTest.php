<?php

/**
 * Decidiq BroadcastControllerTest
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
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

namespace OCA\Decidiq\Tests\Unit\Controller;

use DateTime;
use OCA\Decidiq\Controller\BroadcastController;
use OCA\Decidiq\Service\MeetingBroadcastService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Service\StreamingClient;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\Decidiq\Tests\Unit\Support\CaseSystemWorld;
use OCA\Decidiq\Tests\Unit\Support\StreamingCallServiceFake;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The broadcast routes: only the meeting's chair or secretary acts, and a
 * refused caller sends nothing to the streaming service.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
 *
 * @uses \OCA\Decidiq\Service\MeetingBroadcastService
 * @uses \OCA\Decidiq\Service\StreamingClient
 * @uses \OCA\Decidiq\Service\SigningAnswer
 * @uses \OCA\Decidiq\Service\TranscriptionStaffGuard
 * @uses \OCA\Decidiq\Exception\BroadcastRefusedException
 * @uses \OCA\Decidiq\Support\FleetAppId
 */
final class BroadcastControllerTest extends TestCase {
	/**
	 * The in-memory register.
	 *
	 * @var CaseSystemWorld
	 */
	private CaseSystemWorld $world;

	/**
	 * The public meeting the griffier is secretary of.
	 *
	 * @var string
	 */
	private string $meeting;

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Set up a meeting with a linked streaming service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world   = new CaseSystemWorld();
		$this->meeting = $this->world->put(schema: 'meeting', data: ['title' => 'Raadsvergadering 19 maart', 'openedAt' => '2026-03-19T19:30:00+01:00', 'isPublic' => true]);
		$source        = $this->world->put(schema: 'source', data: ['slug' => 'streaming'], register: 'integriq');
		$this->world->put(schema: 'app_connection', data: ['app' => 'decidiq', 'key' => 'streaming', 'source' => $source], register: 'integriq');
		$this->world->answers['start-test']    = ['previewUrl' => 'https://stream.example.org/preview'];
		$this->world->answers['start']         = ['playerUrl' => 'https://stream.example.org/live'];
		$this->world->answers['live-captions'] = ['accepted' => true];
	}//end setUp()

	/**
	 * The controller as a given account sees it.
	 *
	 * @param string|null $uid The signed-in account, null for none.
	 *
	 * @return BroadcastController
	 */
	private function controller(?string $uid): BroadcastController {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => (isset($this->world->objects[(string)$id]) === true ? $this->entity(id: (string)$id) : null));
		$objects->method('findAll')->willReturnCallback(fn (array $config=[]): array => array_map(fn (string $id): ObjectEntity => $this->entity(id: $id), $this->world->match(config: $config)));
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity => $this->entity(id: $this->world->save(data: $object, register: (string)$register, schema: (string)$schema, id: $uuid))
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('hasRole')->willReturnCallback(
			fn (string $meetingId, string $nextcloudUid, array $roles): bool => $meetingId === $this->meeting && $nextcloudUid === 'griffier' && in_array('secretary', $roles, true) === true
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new StreamingCallServiceFake(world: $this->world));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-03-19T19:30:00+01:00'));
		$time->method('getDateTime')->willReturn(new DateTime('2026-03-19T19:30:00+01:00'));

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default));

		return new BroadcastController(
			request: $request,
			broadcasts: new MeetingBroadcastService(
				objectService: $objects,
				streaming: new StreamingClient(objectService: $objects, container: $container, answers: new SigningAnswer()),
				time: $time,
				userSession: $session,
			),
			guard: new TranscriptionStaffGuard(objectService: $objects, participantResolver: $participants, userSession: $session, groupManager: $groups),
		);
	}//end controller()

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
	 * Every route, called by one controller.
	 *
	 * @param BroadcastController $controller The controller.
	 * @param string              $broadcast  A broadcast of the meeting.
	 *
	 * @return array<string, JSONResponse>
	 */
	private function everyRoute(BroadcastController $controller, string $broadcast): array {
		return [
			'status' => $controller->status(meetingId: $this->meeting),
			'test' => $controller->test(meetingId: $this->meeting),
			'testResult' => $controller->testResult(id: $broadcast),
			'start' => $controller->start(id: $broadcast),
			'pause' => $controller->pause(id: $broadcast),
			'resume' => $controller->resume(id: $broadcast),
			'stop' => $controller->stop(id: $broadcast),
		];
	}//end everyRoute()

	/**
	 * A member who is neither chair nor secretary gets 403 on every route,
	 * and nothing is sent to the streaming service or written.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return void
	 */
	public function testAMemberWhoIsNotStaffIsRefusedEverywhere(): void {
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'live', 'publicWindows' => [['start' => 0, 'recordingStart' => 0]]]);
		$before    = $this->world->objects;

		foreach ($this->everyRoute(controller: $this->controller(uid: 'raadslid'), broadcast: $broadcast) as $route => $response) {
			$this->assertSame(403, $response->getStatus(), $route);
		}

		$this->assertSame([], $this->world->calls);
		$this->assertSame($before, $this->world->objects);
	}//end testAMemberWhoIsNotStaffIsRefusedEverywhere()

	/**
	 * Without a session every route answers 401; a broadcast id that resolves
	 * to no meeting is refused, not looked up further.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return void
	 */
	public function testWithoutASessionOrAMeetingTheRoutesFailClosed(): void {
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);
		foreach ($this->everyRoute(controller: $this->controller(uid: null), broadcast: $broadcast) as $route => $response) {
			$this->assertSame(401, $response->getStatus(), $route);
		}

		$this->assertSame(403, $this->controller(uid: 'griffier')->start(id: 'no-such-broadcast')->getStatus());
		$this->assertSame([], $this->world->calls);
	}//end testWithoutASessionOrAMeetingTheRoutesFailClosed()

	/**
	 * The secretary runs a test, records its result and goes live; the
	 * service's refusals come back with their status.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testTheSecretaryRunsTheBroadcast(): void {
		$controller = $this->controller(uid: 'griffier');

		$status = $controller->status(meetingId: $this->meeting);
		$this->assertSame(200, $status->getStatus());
		$this->assertSame(['connected' => true, 'broadcast' => null], $status->getData());

		$test = $controller->test(meetingId: $this->meeting);
		$this->assertSame(201, $test->getStatus());
		$this->assertSame('testing', $test->getData()['lifecycle']);
		$broadcast = $test->getData()['id'];

		$this->params = ['result' => 'nonsense'];
		$this->assertSame(422, $controller->testResult(id: $broadcast)->getStatus());
		$this->params = ['result' => 'ok', 'note' => 'Picture and sound fine'];
		$result       = $controller->testResult(id: $broadcast);
		$this->assertSame(200, $result->getStatus());
		$this->assertSame('Griffier', $result->getData()['testedBy']);

		$this->assertSame('live', $controller->start(id: $broadcast)->getData()['lifecycle']);
		$this->assertSame('paused', $controller->pause(id: $broadcast)->getData()['lifecycle']);
		$this->assertSame('live', $controller->resume(id: $broadcast)->getData()['lifecycle']);
		$this->assertSame('ended', $controller->stop(id: $broadcast)->getData()['lifecycle']);
		$this->assertSame(409, $controller->start(id: $broadcast)->getStatus());
	}//end testTheSecretaryRunsTheBroadcast()

	/**
	 * A meeting that is not public answers 422 to the chair's start.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 *
	 * @return void
	 */
	public function testAMeetingThatIsNotPublicAnswers422(): void {
		$this->world->objects[$this->meeting]['data']['isPublic'] = false;
		$broadcast = $this->world->put(schema: 'meeting-broadcast', data: ['meeting' => $this->meeting, 'lifecycle' => 'planned']);

		$response = $this->controller(uid: 'griffier')->start(id: $broadcast);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['message' => 'Only a public meeting can be broadcast'], $response->getData());
	}//end testAMeetingThatIsNotPublicAnswers422()

	/**
	 * Every broadcast route is registered, so none is unreachable.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
	 *
	 * @return void
	 */
	public function testEveryRouteIsRegistered(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$names  = array_column($routes['routes'], 'url', 'name');

		$this->assertSame('/api/meetings/{meetingId}/broadcast', $names['broadcast#status'] ?? null);
		$this->assertSame('/api/meetings/{meetingId}/broadcast/test', $names['broadcast#test'] ?? null);
		foreach (['testResult' => 'test-result', 'start' => 'start', 'pause' => 'pause', 'resume' => 'resume', 'stop' => 'stop'] as $method => $path) {
			$this->assertSame('/api/meeting-broadcasts/{id}/' . $path, $names['broadcast#' . $method] ?? null, $method);
		}
	}//end testEveryRouteIsRegistered()
}//end class
