<?php

/**
 * Decidiq TeamsMeetingController unit tests.
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use DateTime;
use OCA\Decidiq\Controller\TeamsMeetingController;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Service\TeamsMeetingService;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\Decidiq\Tests\Unit\Support\CaseSystemWorld;
use OCA\Decidiq\Tests\Unit\Support\StreamingCallServiceFake;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Creating the Teams meeting of a digital or hybrid meeting through integriq.
 */
final class TeamsMeetingControllerTest extends TestCase {
	private const JOIN = 'https://teams.microsoft.com/l/meetup-join/00000000-0000-0000-0000-000000000000';

	private CaseSystemWorld $world;

	private string $meeting;

	/**
	 * A digital committee meeting and a linked Microsoft 365 source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world   = new CaseSystemWorld();
		$this->meeting = $this->world->put(
			schema: 'meeting',
			data: ['title' => 'Commissie Ruimte', 'scheduledDate' => '2026-04-16T19:30:00+02:00', 'endDate' => '2026-04-16T22:00:00+02:00', 'meetingMode' => 'digital', 'location' => 'Online']
		);
		$this->link();
		$this->world->answers['/online-meetings'] = ['id' => 'graph-123', 'joinWebUrl' => self::JOIN];
	}//end setUp()

	/**
	 * Link a source to the microsoft-365 connection.
	 *
	 * @return void
	 */
	private function link(): void {
		$source = $this->world->put(schema: 'source', data: ['slug' => 'microsoft-365'], register: 'integriq');
		$this->world->put(schema: 'app_connection', data: ['app' => 'decidiq', 'key' => 'microsoft-365', 'source' => $source], register: 'integriq');
	}//end link()

	/**
	 * The controller over the world, signed in as a user or nobody.
	 *
	 * @param string|null           $uid       The signed-in user.
	 * @param array<string, string> $catalogue The reader's translations; an absent key reads as itself.
	 *
	 * @return TeamsMeetingController
	 */
	private function controller(?string $uid, array $catalogue=[]): TeamsMeetingController {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => (isset($this->world->objects[(string)$id]) === true ? $this->entity(id: (string)$id) : null));
		$objects->method('findAll')->willReturnCallback(fn (array $config=[]): array => array_map(fn (string $id): ObjectEntity => $this->entity(id: $id), $this->world->match(config: $config)));
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object, ObjectSaveOptions $options=new ObjectSaveOptions()): ObjectEntity => $this->entity(id: $this->world->save(data: $object, register: (string)$options->target->register, schema: (string)$options->target->schema, id: $options->uuid))
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
			fn (string $meetingId, string $nextcloudUid, array $roles): bool => $nextcloudUid === 'griffier' && in_array('secretary', $roles, true) === true
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new StreamingCallServiceFake(world: $this->world));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-04-10T10:00:00+02:00'));

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => ($catalogue[$text] ?? $text));

		return new TeamsMeetingController(
			request: $this->createMock(IRequest::class),
			teams: new TeamsMeetingService(objectService: $objects, container: $container, answers: new SigningAnswer(), userSession: $session, time: $time),
			guard: new TranscriptionStaffGuard(objectService: $objects, participantResolver: $participants, userSession: $session, groupManager: $groups),
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * A world object as an entity.
	 *
	 * @param string $id The object id.
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
	 * The secretary creates the Teams meeting; it is stored on the meeting with who and when.
	 *
	 * @return void
	 */
	public function testTheSecretaryCreatesTheTeamsMeeting(): void {
		$controller = $this->controller(uid: 'griffier');
		$this->assertSame(['connected' => true, 'teamsMeeting' => null], $controller->status(meetingId: $this->meeting)->getData());

		$response = $controller->create(meetingId: $this->meeting);

		$this->assertSame(201, $response->getStatus());
		$expected = ['joinUrl' => self::JOIN, 'externalId' => 'graph-123', 'createdBy' => 'Griffier', 'createdAt' => '2026-04-10T10:00:00+02:00'];
		$this->assertSame($expected, $response->getData());
		$this->assertSame($expected, $this->world->objects[$this->meeting]['data']['teamsMeeting']);
		$this->assertSame('Online', $this->world->objects[$this->meeting]['data']['location'], 'the location is left alone');
		$this->assertSame(
			[['subject' => 'Commissie Ruimte', 'startDateTime' => '2026-04-16T19:30:00+02:00', 'endDateTime' => '2026-04-16T22:00:00+02:00']],
			$this->world->callsOf(operation: '/online-meetings')
		);

		$this->assertSame(409, $controller->create(meetingId: $this->meeting)->getStatus(), 'a second one is refused');
		$this->assertCount(1, $this->world->calls);
	}//end testTheSecretaryCreatesTheTeamsMeeting()

	/**
	 * A meeting without an end asks for two hours.
	 *
	 * @return void
	 */
	public function testAMeetingWithoutAnEndAsksForTwoHours(): void {
		$this->world->objects[$this->meeting]['data']['endDate'] = null;

		$this->assertSame(201, $this->controller(uid: 'griffier')->create(meetingId: $this->meeting)->getStatus());
		$this->assertSame('2026-04-16T21:30:00+02:00', $this->world->callsOf(operation: '/online-meetings')[0]['endDateTime']);
	}//end testAMeetingWithoutAnEndAsksForTwoHours()

	/**
	 * A member who is neither chair nor secretary gets 403 and nothing reaches integriq.
	 *
	 * @return void
	 */
	public function testOnlyStaffCreateATeamsMeeting(): void {
		$before = $this->world->objects;

		$this->assertSame(403, $this->controller(uid: 'raadslid')->create(meetingId: $this->meeting)->getStatus());
		$this->assertSame(403, $this->controller(uid: 'raadslid')->status(meetingId: $this->meeting)->getStatus());
		$this->assertSame(401, $this->controller(uid: null)->create(meetingId: $this->meeting)->getStatus());

		$this->assertSame([], $this->world->calls);
		$this->assertSame($before, $this->world->objects);
	}//end testOnlyStaffCreateATeamsMeeting()

	/**
	 * An in-person meeting gets no Teams meeting.
	 *
	 * @return void
	 */
	public function testAnInPersonMeetingIsRefused(): void {
		$this->world->objects[$this->meeting]['data']['meetingMode'] = 'in-person';

		$response = $this->controller(uid: 'griffier')->create(meetingId: $this->meeting);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame([], $this->world->calls);
	}//end testAnInPersonMeetingIsRefused()

	/**
	 * Without a linked source the status says so and create is refused with 409.
	 *
	 * @return void
	 */
	public function testWithoutALinkedSource(): void {
		foreach ($this->world->objects as $id => $object) {
			if (($object['schema'] ?? null) === 'app_connection' || (($object['data']['key'] ?? null) === 'microsoft-365')) {
				unset($this->world->objects[$id]);
			}
		}

		$controller = $this->controller(uid: 'griffier');
		$this->assertFalse($controller->status(meetingId: $this->meeting)->getData()['connected']);

		$response = $controller->create(meetingId: $this->meeting);
		$this->assertSame(409, $response->getStatus());
		$this->assertStringStartsWith(TeamsMeetingService::NOT_CONNECTED, $response->getData()['message']);
		$this->assertSame([], $this->world->calls);
	}//end testWithoutALinkedSource()

	/**
	 * An answer without a Teams address is refused, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutATeamsAddressIsRefused(): void {
		$this->world->answers['/online-meetings'] = ['id' => 'x', 'joinWebUrl' => 'https://example.org/meet/123'];

		$this->assertSame(502, $this->controller(uid: 'griffier')->create(meetingId: $this->meeting)->getStatus());
		$this->assertArrayNotHasKey('teamsMeeting', $this->world->objects[$this->meeting]['data']);

		$this->world->answers['/online-meetings'] = ['_status' => 500, 'message' => 'Graph is down'];
		$response = $this->controller(uid: 'griffier')->create(meetingId: $this->meeting);
		$this->assertSame(502, $response->getStatus());
		$this->assertSame('Graph is down', $response->getData()['message']);
	}//end testAnAnswerWithoutATeamsAddressIsRefused()

	/**
	 * An unknown meeting is refused before anything else.
	 *
	 * @return void
	 */
	public function testAnUnknownMeeting(): void {
		$this->assertSame(404, $this->controller(uid: 'griffier')->create(meetingId: 'no-such-meeting')->getStatus());
		$this->assertSame([], $this->world->calls);
	}//end testAnUnknownMeeting()

	/**
	 * A fixed refusal reaches the reader in their language.
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/tasks.md#task-6-strings-and-docs
	 *
	 * @return void
	 */
	public function testAFixedRefusalIsInTheReadersLanguage(): void {
		$this->world->objects[$this->meeting]['data']['meetingMode'] = 'in-person';
		$dutch = ['Only a digital or hybrid meeting gets a Teams meeting' => 'Alleen een digitale of hybride vergadering krijgt een Teams-vergadering'];

		$response = $this->controller(uid: 'griffier', catalogue: $dutch)->create(meetingId: $this->meeting);

		$this->assertSame(expected: 409, actual: $response->getStatus());
		$this->assertSame(expected: 'Alleen een digitale of hybride vergadering krijgt een Teams-vergadering', actual: $response->getData()['message']);
	}//end testAFixedRefusalIsInTheReadersLanguage()

	/**
	 * Microsoft 365's own answer is passed on unchanged, not looked up as a key.
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/tasks.md#task-6-strings-and-docs
	 *
	 * @return void
	 */
	public function testARunTimeRefusalIsPassedOnUnchanged(): void {
		$this->world->answers['/online-meetings'] = ['_status' => 500, 'message' => 'Graph is down'];

		$response = $this->controller(uid: 'griffier', catalogue: ['Graph is down' => 'vertaald'])->create(meetingId: $this->meeting);

		$this->assertSame(expected: 502, actual: $response->getStatus());
		$this->assertSame(expected: 'Graph is down', actual: $response->getData()['message']);
	}//end testARunTimeRefusalIsPassedOnUnchanged()

	/**
	 * Every fixed refusal the controller translates has a Dutch and an English catalogue entry.
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/tasks.md#task-6-strings-and-docs
	 *
	 * @return void
	 */
	public function testEveryFixedRefusalIsInTheCatalogues(): void {
		$source = (string)file_get_contents(filename: __DIR__ . '/../../../lib/Controller/TeamsMeetingController.php');
		preg_match_all(pattern: "/l10n->t\\('([^']+)'\\)/", subject: $source, matches: $matches);
		$this->assertCount(expectedCount: 6, haystack: $matches[1]);

		foreach (['en', 'nl'] as $locale) {
			$catalogue = json_decode(json: (string)file_get_contents(filename: __DIR__ . '/../../../l10n/' . $locale . '.json'), associative: true)['translations'];
			foreach ($matches[1] as $key) {
				$this->assertNotSame(expected: '', actual: (string)($catalogue[$key] ?? ''), message: $locale . ' lacks "' . $key . '"');
			}
		}
	}//end testEveryFixedRefusalIsInTheCatalogues()
}//end class
