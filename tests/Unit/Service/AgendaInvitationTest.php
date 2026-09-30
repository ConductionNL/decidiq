<?php

/**
 * Unit tests: publishing the agenda invites the members and records the
 * convocation.
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
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaInvitation;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The secretary publishes the agenda; each member is invited with the date,
 * the place and the items, and the convocation is recorded so a public
 * meeting's agenda can go to the public catalogue.
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
 */
class AgendaInvitationTest extends TestCase {

	private const MEETING = 'a1b2c3d4-0000-4000-8000-00000000000a';

	/**
	 * The council meeting.
	 *
	 * @var array<string, mixed>
	 */
	private const COUNCIL = [
		'title' => 'Raadsvergadering',
		'meetingType' => 'regular',
		'meetingMode' => 'in-person',
		'scheduledDate' => '2026-10-08T19:30:00+02:00',
		'endDate' => '2026-10-08T22:30:00+02:00',
		'location' => 'Raadzaal, Gemeentehuis Leek',
		'lifecycle' => 'scheduled',
		'isPublic' => true,
	];

	/**
	 * Meetings saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Publication events recorded: [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $recorded = [];

	/**
	 * Notifications dispatched.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $dispatched = [];

	/**
	 * Build the service over a council with five items and three members.
	 *
	 * @param array<string, mixed> $meeting Overrides on the meeting
	 *
	 * @return AgendaService
	 */
	private function service(array $meeting = []): AgendaService {
		$meetingData = array_merge(self::COUNCIL, $meeting);
		$items = [];
		foreach (['Opening', 'Vaststelling agenda', 'Begroting 2027', 'Rondvraag', 'Sluiting'] as $i => $title) {
			$items[] = $this->entity(['id' => 'item-' . ($i + 1), 'title' => $title, 'orderNumber' => ($i + 1)]);
		}

		// Out of order on purpose: the invitation lists the agenda order.
		$items = array_reverse($items);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(fn (): ObjectEntity => $this->entity($meetingData));
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => (($config['filters']['schema'] ?? '') === 'agenda-item' ? $items : [])
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->entity($object);
			}
		);

		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveMeetingParticipants')->willReturn([
			['nextcloudUserId' => 'anna', 'leftAt' => null],
			['nextcloudUserId' => 'bram', 'leftAt' => null],
			['nextcloudUserId' => 'cees', 'leftAt' => null],
		]);

		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->method('dispatch')->willReturnCallback(
			function (string $personId, string $eventType, string $title, string $message, string $deepLink = '', ?array $inApp = null, array $attachments = []): int {
				$this->dispatched[] = compact('personId', 'eventType', 'title', 'message', 'deepLink', 'inApp', 'attachments');
				return 1;
			}
		);

		$recorder = $this->createMock(PublicationEventRecorder::class);
		foreach (['agendaPublished', 'agendaChanged'] as $method) {
			$recorder->method($method)->willReturnCallback(
				function (mixed ...$arguments) use ($method): void {
					$this->recorded[] = [$method, $arguments];
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $params = []): string => vsprintf($text, (array)$params)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new AgendaService(
			objectService: $objectService,
			calendarEventService: $this->createMock(CalendarEventService::class),
			preferences: $preferences,
			logger: new NullLogger(),
			participantResolver: $resolver,
			l10nFactory: $factory,
			eventRecorder: $recorder,
		);

	}//end service()

	/**
	 * An entity double serialising to the row.
	 *
	 * @param array<string, mixed> $row The row
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;

	}//end entity()

	/**
	 * Scenario: members are invited. Each member gets the invitation through
	 * their own delivery choice, listing the date, the place and the five
	 * items in agenda order, with a calendar file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
	 */
	public function testMembersAreInvited(): void {
		$this->service()->publishAgenda(self::MEETING);

		self::assertSame(['anna', 'bram', 'cees'], array_column($this->dispatched, 'personId'));
		foreach ($this->dispatched as $call) {
			self::assertSame('agendaChanged', $call['eventType']);
			self::assertSame('agenda_published', $call['inApp']['subject']);
			self::assertStringContainsString('2026-10-08 19:30', $call['message']);
			self::assertStringContainsString('Raadzaal, Gemeentehuis Leek', $call['message']);
			self::assertMatchesRegularExpression(
				'/1\. Opening\n2\. Vaststelling agenda\n3\. Begroting 2027\n4\. Rondvraag\n5\. Sluiting/',
				$call['message']
			);

			self::assertCount(1, $call['attachments']);
			self::assertSame('text/calendar', $call['attachments'][0]['contentType']);
			self::assertSame('meeting.ics', $call['attachments'][0]['filename']);
			self::assertStringContainsString("DTSTART:20261008T173000Z\r\n", $call['attachments'][0]['data']);
		}

	}//end testMembersAreInvited()

	/**
	 * Publishing records when the convocation was sent and leaves the stage;
	 * a republish keeps the first date. The saved meeting validates against
	 * the merged Meeting schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-002-a-published-agenda-of-a-public-meeting-can-go-public
	 */
	public function testPublishingRecordsTheConvocation(): void {
		$this->service()->publishAgenda(self::MEETING);

		self::assertSame('scheduled', $this->saved[0]['lifecycle']);
		self::assertNotEmpty($this->saved[0]['convocationSentAt']);
		$this->assertValidMeeting($this->saved[0]);

		$this->saved = [];
		$this->service(['convocationSentAt' => '2026-09-20T09:00:00+02:00', 'agendaVersion' => 1])->publishAgenda(self::MEETING);
		self::assertSame('2026-09-20T09:00:00+02:00', $this->saved[0]['convocationSentAt']);

	}//end testPublishingRecordsTheConvocation()

	/**
	 * Scenario: the public sees the agenda. The published meeting is
	 * eligible for public publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-002-a-published-agenda-of-a-public-meeting-can-go-public
	 */
	public function testAPublishedAgendaOfAPublicMeetingIsEligible(): void {
		$this->service()->publishAgenda(self::MEETING);
		$published = $this->saved[0];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->entity($published));
		$eligibility = new PublicationEligibilityService(logger: new NullLogger(), objectService: $objectService);

		self::assertSame($published, $eligibility->assertEligible(sourceType: 'agenda', sourceId: self::MEETING));

	}//end testAPublishedAgendaOfAPublicMeetingIsEligible()

	/**
	 * The calendar file is RFC 5545: CRLF lines of at most 75 octets, text
	 * escaped, one event with the meeting's times in UTC.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
	 */
	public function testTheCalendarFileIsWellFormed(): void {
		$ics = (new AgendaInvitation())->calendarFile(
			meetingId: self::MEETING,
			meeting: array_merge(self::COUNCIL, ['title' => 'Raad; vergadering, oktober']),
			itemTitles: array_fill(0, 12, 'Een lang agendapunt over de begroting en de voorjaarsnota')
		);

		self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
		self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
		self::assertStringContainsString("UID:" . self::MEETING . "@decidiq\r\n", $ics);
		self::assertStringContainsString("DTEND:20261008T203000Z\r\n", $ics);
		self::assertStringContainsString('SUMMARY:Raad\; vergadering\, oktober', $ics);
		foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
			self::assertLessThanOrEqual(75, strlen($line), $line);
		}

	}//end testTheCalendarFileIsWellFormed()

	/**
	 * The saved meeting validates against the merged Meeting schema.
	 *
	 * @param array<string, mixed> $data The saved object
	 *
	 * @return void
	 */
	private function assertValidMeeting(array $data): void {
		$schema = SettingsService::shippedRegisterDescriptor()['components']['schemas']['Meeting'];
		$properties = array_map(
			static function (array $property): array {
				unset($property['$ref']);
				return $property;
			},
			$schema['properties']
		);

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'required' => $schema['required'], 'additionalProperties' => false]))
		);
		$this->assertTrue($result->isValid(), 'The saved meeting must validate: ' . json_encode($result->error()?->args()));

	}//end assertValidMeeting()
	/**
	 * Publishing the agenda records one publication event for subscribers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function testPublishingTheAgendaRecordsOneEvent(): void {
		$this->service()->publishAgenda(self::MEETING);

		self::assertCount(1, $this->recorded);
		self::assertSame('agendaPublished', $this->recorded[0][0]);
		self::assertSame(self::MEETING, $this->recorded[0][1][0]);
		self::assertFalse($this->recorded[0][1][2], 'A first publication is not a revision');

	}//end testPublishingTheAgendaRecordsOneEvent()

	/**
	 * A change to a published agenda records one event with the item snapshots
	 * before and after, so the summary can say which item was added.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function testAChangedPublishedAgendaRecordsOneEventWithTheSnapshots(): void {
		$before = [['id' => 'item-1', 'title' => 'Opening', 'orderNumber' => 1]];
		$this->service(
			[
				'agendaPublishedAt' => '2026-10-01T09:00:00+02:00',
				'agendaVersion'     => 1,
				'agendaVersions'    => [['version' => 1, 'publishedAt' => '2026-10-01T09:00:00+02:00', 'items' => $before]],
			]
		)->notifyAgendaChanged(self::MEETING);

		self::assertCount(1, $this->recorded);
		self::assertSame('agendaChanged', $this->recorded[0][0]);
		self::assertSame($before, $this->recorded[0][1][2]);
		self::assertCount(5, $this->recorded[0][1][3], 'The new snapshot holds the five current items');
		self::assertSame(
			'Agenda changed: item 2 added, item 3 added, item 4 added, item 5 added',
			PublicationEventRecorder::changeSummary(before: $this->recorded[0][1][2], after: $this->recorded[0][1][3])
		);

	}//end testAChangedPublishedAgendaRecordsOneEventWithTheSnapshots()

}//end class
