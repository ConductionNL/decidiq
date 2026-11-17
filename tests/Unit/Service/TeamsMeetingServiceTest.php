<?php

/**
 * Decidiq TeamsMeetingServiceTest
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
use OCA\Decidiq\Exception\TeamsMeetingRefusedException;
use OCA\Decidiq\Service\SigningAnswer;
use OCA\Decidiq\Service\TeamsMeetingService;
use OCA\Decidiq\Tests\Unit\Support\CaseSystemWorld;
use OCA\Decidiq\Tests\Unit\Support\StreamingCallServiceFake;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * The digital Commissie Ruimte of 16 April gets a Teams meeting through the
 * organisation's Microsoft 365 connection in integriq.
 *
 * @covers \OCA\Decidiq\Service\TeamsMeetingService
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 *
 * @uses \OCA\Decidiq\Service\SigningAnswer
 * @uses \OCA\Decidiq\Service\IntegriqConnection
 * @uses \OCA\Decidiq\Exception\TeamsMeetingRefusedException
 * @uses \OCA\Decidiq\Support\FleetAppId
 */
final class TeamsMeetingServiceTest extends TestCase {
	/**
	 * When the griffier creates the Teams meeting.
	 */
	private const NOW = '2027-03-30T10:00:00+00:00';

	/**
	 * The join address Microsoft 365 answers with.
	 */
	private const JOIN = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_ruimte';

	/**
	 * The in-memory register.
	 *
	 * @var CaseSystemWorld
	 */
	private CaseSystemWorld $world;

	/**
	 * The digital meeting.
	 *
	 * @var string
	 */
	private string $meeting;

	/**
	 * Set up a digital meeting and a linked Microsoft 365 source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world   = new CaseSystemWorld();
		$this->meeting = $this->world->put(
			schema: 'meeting',
			data: [
				'title' => 'Commissie Ruimte (digitaal)',
				'meetingType' => 'committee',
				'meetingMode' => 'digital',
				'scheduledDate' => '2027-04-16T19:30:00+02:00',
				'endDate' => '2027-04-16T22:00:00+02:00',
			]
		);
		$source = $this->world->put(schema: 'source', data: ['slug' => 'microsoft-365'], register: 'integriq');
		$this->world->put(schema: 'app_connection', data: ['app' => 'decidiq', 'key' => 'microsoft-365', 'source' => $source], register: 'integriq');
		$this->world->answers['/online-meetings'] = ['id' => 'MSO-ruimte-0416', 'joinWebUrl' => self::JOIN];
	}//end setUp()

	/**
	 * Create stores the join address, the external id, the author and the time.
	 *
	 * @return void
	 */
	public function testCreateStoresTheTeamsMeetingOnTheMeeting(): void {
		$teams = $this->service()->create(meetingId: $this->meeting);

		$expected = [
			'joinUrl' => self::JOIN,
			'externalId' => 'MSO-ruimte-0416',
			'createdBy' => 'Griffier De Vries',
			'createdAt' => '2027-03-30T10:00:00+00:00',
		];
		$this->assertSame($expected, $teams);
		$this->assertSame($expected, $this->world->objects[$this->meeting]['data']['teamsMeeting']);
		$this->assertSame([], $this->world->invalid, 'The meeting was saved in a shape its schema refuses.');

		$calls = $this->world->callsOf(operation: '/online-meetings');
		$this->assertCount(1, $calls);
		$this->assertSame(
			['subject' => 'Commissie Ruimte (digitaal)', 'startDateTime' => '2027-04-16T19:30:00+02:00', 'endDateTime' => '2027-04-16T22:00:00+02:00'],
			$calls[0]
		);
	}//end testCreateStoresTheTeamsMeetingOnTheMeeting()

	/**
	 * Without an end date the Teams meeting lasts two hours.
	 *
	 * @return void
	 */
	public function testAMeetingWithoutAnEndGetsTwoHours(): void {
		unset($this->world->objects[$this->meeting]['data']['endDate']);

		$this->service()->create(meetingId: $this->meeting);

		$this->assertSame('2027-04-16T21:30:00+02:00', $this->world->callsOf(operation: '/online-meetings')[0]['endDateTime']);
	}//end testAMeetingWithoutAnEndGetsTwoHours()

	/**
	 * An in-person meeting is refused before Microsoft 365 is called.
	 *
	 * @return void
	 */
	public function testAnInPersonMeetingIsRefused(): void {
		$this->world->objects[$this->meeting]['data']['meetingMode'] = 'in-person';

		$this->assertRefused(fn () => $this->service()->create(meetingId: $this->meeting), 409, 'Only a digital or hybrid meeting gets a Teams meeting');
		$this->assertSame([], $this->world->callsOf(operation: '/online-meetings'));
	}//end testAnInPersonMeetingIsRefused()

	/**
	 * A meeting that already has a Teams meeting is not given a second one.
	 *
	 * @return void
	 */
	public function testASecondTeamsMeetingIsRefused(): void {
		$this->service()->create(meetingId: $this->meeting);

		$this->assertRefused(fn () => $this->service()->create(meetingId: $this->meeting), 409, 'This meeting already has a Teams meeting');
		$this->assertCount(1, $this->world->callsOf(operation: '/online-meetings'));
	}//end testASecondTeamsMeetingIsRefused()

	/**
	 * An answer without a Teams address is a 502 and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutATeamsAddressStoresNothing(): void {
		$this->world->answers['/online-meetings'] = ['id' => 'x', 'joinWebUrl' => 'https://example.com/meet'];

		$this->assertRefused(fn () => $this->service()->create(meetingId: $this->meeting), 502, 'Microsoft 365 answered without a Teams join address');
		$this->assertArrayNotHasKey('teamsMeeting', $this->world->objects[$this->meeting]['data']);
	}//end testAnAnswerWithoutATeamsAddressStoresNothing()

	/**
	 * Without a linked connection the create is refused and status says so.
	 *
	 * @return void
	 */
	public function testWithoutAConnectionCreateIsRefused(): void {
		foreach ($this->world->objects as $id => $object) {
			if (($object['schema'] ?? '') === 'app_connection') {
				unset($this->world->objects[$id]);
			}
		}

		$this->assertSame(['connected' => false, 'teamsMeeting' => null], $this->service()->status(meetingId: $this->meeting));
		$this->assertRefused(fn () => $this->service()->create(meetingId: $this->meeting), 409, TeamsMeetingService::NOT_CONNECTED);
	}//end testWithoutAConnectionCreateIsRefused()

	/**
	 * An unknown meeting is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownMeetingIsNotFound(): void {
		$this->assertRefused(fn () => $this->service()->status(meetingId: 'no-such-meeting'), 404, 'Meeting not found');
	}//end testAnUnknownMeetingIsNotFound()

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
	 * The service under test, over the world.
	 *
	 * @return TeamsMeetingService
	 */
	private function service(): TeamsMeetingService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => (isset($this->world->objects[(string)$id]) === true ? $this->entity(id: (string)$id) : null));
		$objects->method('findAll')->willReturnCallback(fn (array $config=[]): array => array_map(fn (string $id): ObjectEntity => $this->entity(id: $id), $this->world->match(config: $config)));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$id = $this->world->save(data: $object, register: (string)$options->target->register, schema: (string)$options->target->schema, id: $options->uuid);
				return $this->entity(id: $id);
			}
		);

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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier');
		$user->method('getDisplayName')->willReturn('Griffier De Vries');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime(self::NOW));

		return new TeamsMeetingService(objectService: $objects, container: $container, answers: new SigningAnswer(), userSession: $session, time: $time);
	}//end service()

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
		} catch (TeamsMeetingRefusedException $e) {
			$this->assertSame($status, $e->getStatus());
			$this->assertSame($message, $e->getMessage());
		}
	}//end assertRefused()
}//end class
