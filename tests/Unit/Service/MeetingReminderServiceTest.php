<?php

/**
 * Unit tests for MeetingReminderService and MeetingReminderJob.
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
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\BackgroundJob\MeetingReminderJob;
use OCA\Decidiq\Service\MeetingReminderService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\OpenRegisterNotificationPreferenceSync;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;

/**
 * Meeting notices follow each member's switches: Pieter left Meeting
 * reminder on, Anna switched it off.
 *
 * The real NotificationPreferenceService runs: it reads each member's
 * preference row, drops a switched-off event and sends in-app through
 * Nextcloud's notification manager, whose notify() calls are recorded here.
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
 */
class MeetingReminderServiceTest extends TestCase {

	private const MEETING = 'a1b2c3d4-0000-4000-8000-0000000000aa';

	/**
	 * Bell notices sent: [user, subject, parameters].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $sent = [];

	/**
	 * Meetings saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The meeting as stored, updated by every save.
	 *
	 * @var array<string, mixed>
	 */
	private array $meeting = [];

	/**
	 * Build the service over one meeting and two members.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 *
	 * @return MeetingReminderService
	 */
	private function service(array $meeting): MeetingReminderService {
		$this->meeting = array_merge(['title' => 'Raadsvergadering', 'lifecycle' => 'scheduled'], $meeting);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = []): array => (($config['filters']['lifecycle'] ?? '') === $this->meeting['lifecycle'] ? [$this->entity($this->meeting)] : [])
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$this->meeting = $object;
				return $this->entity($object);
			}
		);

		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveMeetingParticipants')->willReturn([
			['nextcloudUserId' => 'pieter', 'leftAt' => null],
			['nextcloudUserId' => 'anna', 'leftAt' => null],
			['nextcloudUserId' => 'oud-lid', 'leftAt' => '2026-01-01T00:00:00Z'],
		]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $params = []): string => vsprintf($text, (array)$params)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new MeetingReminderService(
			objectService: $objectService,
			participantResolver: $resolver,
			preferences: $this->preferences(rows: [
				'pieter' => ['person' => 'pieter'],
				'anna' => ['person' => 'anna', 'meetingReminder' => false, 'meetingCreated' => false],
			]),
			l10nFactory: $factory,
			logger: new NullLogger(),
		);

	}//end service()

	/**
	 * The real preference service over preference rows and a recording
	 * notification manager.
	 *
	 * @param array<string, array<string, mixed>> $rows Preference rows by person
	 *
	 * @return NotificationPreferenceService
	 */
	private function preferences(array $rows): NotificationPreferenceService {
		$store = new class($rows) {

			/**
			 * @param array<string, array<string, mixed>> $rows Rows by person
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string $register Register
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * @param string $schema Schema
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config Find-all config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$person = (string)($config['filters']['person'] ?? '');
				return isset($this->rows[$person]) === true ? [$this->rows[$person]] : [];
			}
		};

		$test = $this;
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () use ($test): INotification {
				$state = new \ArrayObject();
				$notification = $test->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setDateTime'] as $setter) {
					$notification->method($setter)->willReturnCallback(
						function (...$args) use ($notification, $state, $setter): INotification {
							$state[$setter] = $args;
							return $notification;
						}
					);
				}

				$notification->method('getUser')->willReturnCallback(fn () => ($state['setUser'][0] ?? ''));
				$notification->method('getSubject')->willReturnCallback(fn () => ($state['setSubject'][0] ?? ''));
				$notification->method('getSubjectParameters')->willReturnCallback(fn () => ($state['setSubject'][1] ?? []));
				$notification->method('getObjectId')->willReturnCallback(fn () => ($state['setObject'][1] ?? ''));
				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($test): void {
				$test->sent[] = [$notification->getUser(), $notification->getSubject(), $notification->getSubjectParameters(), $notification->getObjectId()];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store, $manager) {
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $store,
					INotificationManager::class => $manager,
					default => throw new class('not found: ' . $id) extends \Exception implements NotFoundExceptionInterface {
					},
				};
			}
		);

		return new NotificationPreferenceService(
			container: $container,
			logger: new NullLogger(),
			openRegisterSync: new OpenRegisterNotificationPreferenceSync(container: $container, logger: new NullLogger())
		);

	}//end preferences()

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
		$entity->method('jsonSerialize')->willReturn(array_merge($row, ['id' => self::MEETING]));
		$entity->method('getUuid')->willReturn(self::MEETING);
		return $entity;

	}//end entity()

	/**
	 * An ISO time a number of seconds from the given moment.
	 *
	 * @param int $now     The moment
	 * @param int $seconds Seconds after it
	 *
	 * @return string
	 */
	private function at(int $now, int $seconds): string {
		return (new DateTimeImmutable('@' . ($now + $seconds)))->format(DateTimeInterface::ATOM);

	}//end at()

	/**
	 * The subjects each user received.
	 *
	 * @return array<int, string> "user:subject"
	 */
	private function received(): array {
		return array_map(static fn (array $row): string => $row[0] . ':' . $row[1], $this->sent);

	}//end received()

	/**
	 * Scenario: Pieter is reminded 24 hours before; Anna, who switched it
	 * off, is not; the next run sends nothing again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	public function testPieterIsReminded(): void {
		$now = 1_790_000_000;
		$service = $this->service(['scheduledDate' => $this->at($now, 86400), 'scheduledNoticeSentAt' => $this->at($now, -600000)]);

		$service->run(now: $now);

		self::assertSame(['pieter:meeting_reminder'], $this->received());
		self::assertSame(self::MEETING, $this->sent[0][3]);
		$this->assertValidMeeting($this->saved[0]);

		$service->run(now: $now + 600);
		self::assertCount(1, $this->sent, 'Once per meeting and member');

	}//end testPieterIsReminded()

	/**
	 * Pieter's second reminder time (1 hour, a default) sends once more.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	public function testTheOneHourReminderFollows(): void {
		$now = 1_790_000_000;
		$service = $this->service(['scheduledDate' => $this->at($now, 86400), 'scheduledNoticeSentAt' => $this->at($now, -600000)]);

		$service->run(now: $now);
		$service->run(now: $now + 86400 - 3000);
		$service->run(now: $now + 86400 - 2400);

		self::assertSame(['pieter:meeting_reminder', 'pieter:meeting_reminder'], $this->received());

	}//end testTheOneHourReminderFollows()

	/**
	 * Scenario: the submission deadline is 40 hours away; members get one
	 * reminder naming the deadline and the meeting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-002-a-reminder-before-the-submission-deadline
	 */
	public function testTheDeadlineReminder(): void {
		$now = 1_790_000_000;
		$service = $this->service([
			'scheduledDate' => $this->at($now, 7 * 86400),
			'submissionDeadline' => '2026-09-25T12:00:00+02:00',
			'scheduledNoticeSentAt' => $this->at($now, -600000),
		]);
		$deadline = (new DateTimeImmutable('2026-09-25T12:00:00+02:00'))->getTimestamp();

		$service->run(now: $deadline - (40 * 3600));
		$service->run(now: $deadline - (39 * 3600));

		self::assertSame(['pieter:submission_deadline'], $this->received());
		self::assertSame('Raadsvergadering', $this->sent[0][2]['meetingTitle']);
		self::assertSame('2026-09-25 12:00', $this->sent[0][2]['deadline']);
		$this->assertValidMeeting($this->saved[0]);

	}//end testTheDeadlineReminder()

	/**
	 * A newly scheduled meeting is announced once to members with Meeting
	 * scheduled on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	public function testAScheduledMeetingIsAnnounced(): void {
		$now = 1_790_000_000;
		$service = $this->service(['scheduledDate' => $this->at($now, 10 * 86400)]);

		$service->run(now: $now);
		$service->run(now: $now + 3600);

		self::assertSame(['pieter:meeting_scheduled'], $this->received());
		$this->assertValidMeeting($this->saved[0]);

	}//end testAScheduledMeetingIsAnnounced()

	/**
	 * A draft meeting, or one that already started, gets nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	public function testDraftsAndPastMeetingsGetNothing(): void {
		$now = 1_790_000_000;
		$this->service(['lifecycle' => 'draft', 'scheduledDate' => $this->at($now, 3600)])->run(now: $now);
		$this->service(['scheduledDate' => $this->at($now, -3600)])->run(now: $now);

		self::assertSame([], $this->received());

	}//end testDraftsAndPastMeetingsGetNothing()

	/**
	 * The job is registered and runs the service hourly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	public function testTheJobIsRegisteredAndRunsTheService(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		self::assertStringContainsString('<job>OCA\Decidiq\BackgroundJob\MeetingReminderJob</job>', $info);

		$service = $this->createMock(MeetingReminderService::class);
		$service->expects($this->once())->method('run')->with(1_790_000_000)->willReturn(1);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);

		$job = new MeetingReminderJob(time: $time, reminders: $service, logger: new NullLogger());
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);

	}//end testTheJobIsRegisteredAndRunsTheService()

	/**
	 * The declarative OpenRegister notices that ignored the member switches
	 * are off, so a member is not told twice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	public function testTheSwitchlessRegisterNoticesAreOff(): void {
		$notices = SettingsService::shippedRegisterDescriptor()['components']['schemas']['Meeting']['x-openregister-notifications'];

		self::assertFalse($notices['meetingScheduled']['enabled']);
		self::assertFalse($notices['meetingReminder']['enabled']);

	}//end testTheSwitchlessRegisterNoticesAreOff()

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
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'additionalProperties' => false]))
		);
		$this->assertTrue($result->isValid(), 'The saved meeting must validate: ' . json_encode($result->error()?->args()));

	}//end assertValidMeeting()
}//end class
