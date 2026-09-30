<?php

/**
 * Tests for PublicationDigestService: who hears of what, when (REQ-PSD-003, REQ-PSD-004).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\PublicationDigestComposer;
use OCA\Decidiq\Service\PublicationDigestSchedule;
use OCA\Decidiq\Service\PublicationDigestService;
use OCA\Decidiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A fixed clock throughout: Wednesday 14 October 2026 in Amsterdam.
 *
 * @covers \OCA\Decidiq\Service\PublicationDigestService
 * @covers \OCA\Decidiq\Service\PublicationDigestComposer
 * @covers \OCA\Decidiq\Service\PublicationDigestSchedule
 * @uses   \OCA\Decidiq\Service\SettingsService
 */
final class PublicationDigestServiceTest extends TestCase {

	private const BODY = '00000000-0000-4000-8000-0000000000aa';

	private const OTHER_BODY = '00000000-0000-4000-8000-0000000000bb';

	private const MEETING_A = '00000000-0000-4000-8000-00000000000a';

	private const MEETING_B = '00000000-0000-4000-8000-00000000000b';

	private const SECRET_ITEM = '00000000-0000-4000-8000-0000000000ff';

	/**
	 * Bell and mail messages sent through the preferences.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $dispatched = [];

	/**
	 * Objects written: [schema, object, uuid].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: ?string}>
	 */
	private array $saved = [];

	/**
	 * Uuids removed.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * The user the session holds right now.
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * A timestamp in Amsterdam local time.
	 *
	 * @param string $local Such as '2026-10-14 07:05'
	 *
	 * @return integer
	 */
	private static function at(string $local): int {
		return (new DateTimeImmutable($local, new DateTimeZone('Europe/Amsterdam')))->getTimestamp();
	}//end at()

	/**
	 * An ISO date-time for an Amsterdam local time.
	 *
	 * @param string $local Such as '2026-10-13 10:00'
	 *
	 * @return string
	 */
	private static function iso(string $local): string {
		return (new DateTimeImmutable($local, new DateTimeZone('Europe/Amsterdam')))->format(DATE_ATOM);
	}//end iso()

	/**
	 * One event.
	 *
	 * @param string              $kind  agenda, paper, decision or minutes
	 * @param string              $local When, Amsterdam time
	 * @param array<string,mixed> $extra Overrides
	 *
	 * @return array<string,mixed>
	 */
	private static function event(string $kind, string $local, array $extra=[]): array {
		return array_merge(
			[
				'id'             => 'ev-' . md5($kind . $local . json_encode($extra)),
				'kind'           => $kind,
				'governanceBody' => self::BODY,
				'meeting'        => self::MEETING_A,
				'objectType'     => 'meeting',
				'objectId'       => self::MEETING_A,
				'title'          => 'Raadsvergadering 14 oktober',
				'summary'        => 'Agenda published',
				'isPublished'    => false,
				'occurredAt'     => self::iso($local),
			],
			$extra
		);
	}//end event()

	/**
	 * The service over in-memory subscriptions and events.
	 *
	 * @param array<int,array<string,mixed>> $subscriptions Subscriptions
	 * @param array<int,array<string,mixed>> $events        Events
	 *
	 * @return PublicationDigestService
	 */
	private function service(array $subscriptions, array $events): PublicationDigestService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($subscriptions, $events): array {
				return match ($config['filters']['schema'] ?? '') {
					'publication-subscription' => $subscriptions,
					'publication-event' => $events,
					default => [],
				};
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null, bool $_rbac=true): ?ObjectEntity {
				if ($schema === 'governance-body') {
					return $this->entity(data: ['name' => ($id === self::BODY ? 'Gemeenteraad' : 'Commissie Ruimte')]);
				}

				// The member may read everything but the confidential paper.
				if ($id === self::SECRET_ITEM && $_rbac === true) {
					return null;
				}

				return $this->entity(data: ['id' => $id]);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null, bool $_rbac=true): ObjectEntity {
				$this->saved[] = [(string)$schema, $object, $uuid];
				return $this->entity(data: $object);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$this->deleted[] = $uuid;
				return true;
			}
		);

		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->method('dispatch')->willReturnCallback(
			function (string $personId, string $eventType, string $title, string $message): int {
				$this->dispatched[] = ['personId' => $personId, 'eventType' => $eventType, 'title' => $title, 'message' => $message];
				return 1;
			}
		);

		$jan = $this->createMock(IUser::class);
		$jan->method('getUID')->willReturn('jan');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn(string $uid): ?IUser => ($uid === 'jan' ? $jan : null));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn(): ?IUser => $this->sessionUser);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUser = $user;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('Europe/Amsterdam');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://raad.example.nl/apps/decidiq/');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn(string $text, array $parameters=[]): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new PublicationDigestService(
			objectService: $objects,
			preferences: $preferences,
			userManager: $users,
			userSession: $session,
			config: $config,
			composer: new PublicationDigestComposer(objectService: $objects, urlGenerator: $urls, l10nFactory: $factory),
			schedule: new PublicationDigestSchedule(),
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * An entity mock holding the given data.
	 *
	 * @param array<string,mixed> $data The object data
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A member subscription.
	 *
	 * @param string              $frequency immediate, daily or weekly
	 * @param array<string,mixed> $extra     Overrides
	 *
	 * @return array<string,mixed>
	 */
	private static function memberSubscription(string $frequency, array $extra=[]): array {
		return array_merge(
			[
				'id'               => 'sub-jan-' . $frequency,
				'subscriberUserId' => 'jan',
				'governanceBodies' => [self::BODY],
				'kinds'            => ['agenda', 'paper', 'decision', 'minutes'],
				'frequency'        => $frequency,
				'active'           => true,
				'lastSentAt'       => self::iso('2026-10-13 07:00'),
				'@self'            => ['owner' => 'jan'],
			],
			$extra
		);
	}//end memberSubscription()

	/**
	 * Five events since yesterday arrive at 07:00 as one message, grouped per meeting.
	 *
	 * @return void
	 */
	public function testADailySubscriberGetsOneMessageWithFiveEventsGroupedPerMeeting(): void {
		$events = [
			self::event('agenda', '2026-10-13 09:00'),
			self::event('paper', '2026-10-13 10:00', ['summary' => 'Paper added: Raadsvoorstel.pdf', 'objectType' => 'agenda-item', 'objectId' => 'item-1']),
			self::event('agenda', '2026-10-13 11:00', ['summary' => 'Agenda changed: item 4 added']),
			self::event('decision', '2026-10-13 15:00', ['meeting' => self::MEETING_B, 'title' => 'Commissie 8 oktober', 'summary' => 'Decision published', 'objectType' => 'decision', 'objectId' => 'dec-1']),
			self::event('minutes', '2026-10-13 16:00', ['meeting' => self::MEETING_B, 'title' => 'Commissie 8 oktober', 'summary' => 'Minutes published', 'objectType' => 'minutes', 'objectId' => 'min-1']),
			self::event('agenda', '2026-10-13 12:00', ['governanceBody' => self::OTHER_BODY, 'summary' => 'Another body']),
		];

		$service = $this->service(subscriptions: [self::memberSubscription(frequency: 'daily')], events: $events);

		$this->assertSame(0, $service->run(now: self::at('2026-10-14 06:50')), 'Nothing before 07:00');
		$this->assertSame([], $this->dispatched);

		$this->assertSame(1, $service->run(now: self::at('2026-10-14 07:05')));
		$this->assertCount(1, $this->dispatched);
		$this->assertSame('jan', $this->dispatched[0]['personId']);
		$this->assertSame(PublicationDigestService::EVENT_TYPE, $this->dispatched[0]['eventType']);
		$this->assertSame('5 updates from Gemeenteraad', $this->dispatched[0]['title']);

		$message = $this->dispatched[0]['message'];
		$this->assertStringNotContainsString('Another body', $message, 'A body he does not follow is left out');
		$this->assertSame(1, substr_count($message, 'Raadsvergadering 14 oktober'), 'Grouped: the meeting heads its events once');
		$this->assertSame(1, substr_count($message, 'Commissie 8 oktober'));
		$this->assertStringContainsString('https://raad.example.nl/apps/decidiq/meetings/' . self::MEETING_A, $message);
		$this->assertSame(5, substr_count($message, '  - '));

		$stamps = array_values(array_filter($this->saved, static fn(array $row): bool => $row[0] === 'publication-subscription'));
		$this->assertCount(1, $stamps);
		$this->assertSame('sub-jan-daily', $stamps[0][2]);
		$this->assertSame(self::iso('2026-10-14 07:00'), $stamps[0][1]['lastSentAt']);
	}//end testADailySubscriberGetsOneMessageWithFiveEventsGroupedPerMeeting()

	/**
	 * A daily digest already sent today is not sent again.
	 *
	 * @return void
	 */
	public function testADailyDigestIsSentOncePerDay(): void {
		$subscription = self::memberSubscription(frequency: 'daily', extra: ['lastSentAt' => self::iso('2026-10-14 07:00')]);
		$service      = $this->service(subscriptions: [$subscription], events: [self::event('agenda', '2026-10-14 08:00')]);

		$this->assertSame(0, $service->run(now: self::at('2026-10-14 09:00')));
		$this->assertSame([], $this->dispatched);
	}//end testADailyDigestIsSentOncePerDay()

	/**
	 * Three edits within ten minutes arrive as one message once the 15 minute wait is over.
	 *
	 * @return void
	 */
	public function testAnImmediateSubscriberGetsOneMessageAfterTheWait(): void {
		$subscription = self::memberSubscription(frequency: 'immediate', extra: ['lastSentAt' => self::iso('2026-10-14 09:00')]);
		$events       = [
			self::event('agenda', '2026-10-14 10:00', ['summary' => 'Agenda changed: item 4 added']),
			self::event('agenda', '2026-10-14 10:04', ['summary' => 'Agenda changed: item 5 added']),
			self::event('agenda', '2026-10-14 10:09', ['summary' => 'Agenda changed: items edited or moved']),
		];

		$this->assertSame(0, $this->service(subscriptions: [$subscription], events: $events)->run(now: self::at('2026-10-14 10:12')), 'Still inside the wait');
		$this->assertSame([], $this->dispatched);

		$this->assertSame(1, $this->service(subscriptions: [$subscription], events: $events)->run(now: self::at('2026-10-14 10:25')));
		$this->assertCount(1, $this->dispatched);
		$this->assertSame('3 updates from Gemeenteraad', $this->dispatched[0]['title']);
	}//end testAnImmediateSubscriberGetsOneMessageAfterTheWait()

	/**
	 * A weekly digest goes out on Monday after 07:00.
	 *
	 * @return void
	 */
	public function testTheWeeklyMomentIsMondaySeven(): void {
		$now = new DateTimeImmutable('2026-10-14 09:00', new DateTimeZone('Europe/Amsterdam'));
		$this->assertSame('2026-10-12 07:00', (new PublicationDigestSchedule())->lastDueMoment(frequency: 'weekly', now: $now)->format('Y-m-d H:i'));
		$this->assertSame('2026-10-14 07:00', (new PublicationDigestSchedule())->lastDueMoment(frequency: 'daily', now: $now)->format('Y-m-d H:i'));

		$early = new DateTimeImmutable('2026-10-12 06:00', new DateTimeZone('Europe/Amsterdam'));
		$this->assertSame('2026-10-05 07:00', (new PublicationDigestSchedule())->lastDueMoment(frequency: 'weekly', now: $early)->format('Y-m-d H:i'));
	}//end testTheWeeklyMomentIsMondaySeven()

	/**
	 * An event about a paper the member may not read is left out, read under his own account.
	 *
	 * @return void
	 */
	public function testAnEventTheMemberMayNotReadIsLeftOut(): void {
		$events = [
			self::event('agenda', '2026-10-13 09:00'),
			self::event('paper', '2026-10-13 10:00', ['summary' => 'Paper added: Vertrouwelijk.pdf', 'objectType' => 'agenda-item', 'objectId' => self::SECRET_ITEM]),
		];

		$this->assertSame(1, $this->service(subscriptions: [self::memberSubscription(frequency: 'daily')], events: $events)->run(now: self::at('2026-10-14 07:05')));
		$this->assertSame('1 update from Gemeenteraad', $this->dispatched[0]['title']);
		$this->assertStringNotContainsString('Vertrouwelijk', $this->dispatched[0]['message']);
		$this->assertNull($this->sessionUser, 'The session is restored after the read check');
	}//end testAnEventTheMemberMayNotReadIsLeftOut()

	/**
	 * A resident hears only of published events, in one inbox notification that validates.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-004-residents-subscribe-on-the-portal-and-receive-published-news-only
	 */
	public function testAResidentGetsOneInboxNotificationWithThePublishedEventsOnly(): void {
		$subscription = [
			'id'               => 'sub-resident',
			'subscriberRef'    => 'example-resident',
			'governanceBodies' => [],
			'kinds'            => ['agenda', 'decision'],
			'frequency'        => 'daily',
			'active'           => true,
		];
		$events       = [
			self::event('agenda', '2026-10-13 09:00', ['summary' => 'Members only']),
			self::event('agenda', '2026-10-13 10:00', ['summary' => 'Agenda published', 'isPublished' => true]),
			self::event('decision', '2026-10-13 11:00', ['summary' => 'Decision published', 'isPublished' => true, 'objectType' => 'decision', 'objectId' => 'dec-1']),
		];

		$this->assertSame(1, $this->service(subscriptions: [$subscription], events: $events)->run(now: self::at('2026-10-14 07:05')));
		$this->assertSame([], $this->dispatched, 'A resident has no Nextcloud account to notify');

		$notices = array_values(array_filter($this->saved, static fn(array $row): bool => $row[0] === 'notification'));
		$this->assertCount(1, $notices);
		$notice = $notices[0][1];
		$this->assertSame('example-resident', $notice['recipientId']);
		$this->assertSame('publication-digest', $notice['type']);
		$this->assertSame('2 updates from Gemeenteraad', $notice['subject']);
		$this->assertStringNotContainsString('Members only', $notice['content']);
		$this->assertStringNotContainsString('https://', $notice['content'], 'No member links for a resident');
		$this->assertValidAgainst(slug: 'notification', object: $notice);
	}//end testAResidentGetsOneInboxNotificationWithThePublishedEventsOnly()

	/**
	 * Events older than 90 days are removed; an inactive subscription is skipped.
	 *
	 * @return void
	 */
	public function testOldEventsArePurgedAndInactiveSubscriptionsSkipped(): void {
		$events = [
			self::event('agenda', '2026-06-01 09:00', ['id' => 'ev-old']),
			self::event('agenda', '2026-10-13 09:00', ['id' => 'ev-new']),
		];

		$service = $this->service(subscriptions: [self::memberSubscription(frequency: 'daily', extra: ['active' => false])], events: $events);

		$this->assertSame(0, $service->run(now: self::at('2026-10-14 07:05')));
		$this->assertSame(['ev-old'], $this->deleted);
		$this->assertSame([], $this->dispatched);
	}//end testOldEventsArePurgedAndInactiveSubscriptionsSkipped()

	/**
	 * A subscription naming another member's account sends nothing.
	 *
	 * @return void
	 */
	public function testASubscriptionMadeForSomeoneElseSendsNothing(): void {
		$subscription = self::memberSubscription(frequency: 'daily', extra: ['@self' => ['owner' => 'mallory']]);

		$this->assertSame(0, $this->service(subscriptions: [$subscription], events: [self::event('agenda', '2026-10-13 09:00')])->run(now: self::at('2026-10-14 07:05')));
		$this->assertSame([], $this->dispatched);
	}//end testASubscriptionMadeForSomeoneElseSendsNothing()

	/**
	 * Validate an object against the merged register's schema, as OpenRegister would.
	 *
	 * @param string              $slug   The schema slug
	 * @param array<string,mixed> $object The payload
	 *
	 * @return void
	 */
	private function assertValidAgainst(string $slug, array $object): void {
		foreach (SettingsService::shippedRegisterDescriptor()['components']['schemas'] as $schema) {
			if (($schema['slug'] ?? '') !== $slug) {
				continue;
			}

			$properties = array_map(
				static function (array $property): array {
					unset($property['$ref']);
					return $property;
				},
				$schema['properties']
			);
			$result     = (new Validator())->validate(
				json_decode((string)json_encode($object)),
				json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'required' => ($schema['required'] ?? []), 'additionalProperties' => false]))
			);
			$this->assertTrue($result->isValid(), 'The ' . $slug . ' must validate: ' . json_encode($result->error()?->args()));
			return;
		}

		$this->fail('The register has no ' . $slug . ' schema.');
	}//end assertValidAgainst()
}//end class
