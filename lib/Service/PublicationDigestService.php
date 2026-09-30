<?php

/**
 * Decidiq Publication Digest Service
 *
 * Sends each publication subscriber the events that match his bodies and
 * kinds since his last message: immediately after a 15 minute wait, daily at
 * 07:00, or weekly on Monday at 07:00 (change
 * publication-subscriptions-and-daily-digest). A member hears of it through
 * his own delivery choice and only of objects he may read at sending time; a
 * resident gets one message in his portal inbox, of published items only.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Decidiq\AppInfo\Application;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Groups publication events into one message per subscriber and sends it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The digest reads OpenRegister, the user session and the delivery channel.
 *
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */
class PublicationDigestService {

	/**
	 * The event type the member's delivery goes out under.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'publicationDigest';

	/**
	 * An immediate subscriber waits this long, so an editing session arrives as one message.
	 *
	 * @var int
	 */
	public const IMMEDIATE_WAIT_SECONDS = 900;

	/**
	 * Events older than this are removed.
	 *
	 * @var int
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * The hour daily and weekly digests go out.
	 *
	 * @var int
	 */
	private const DIGEST_HOUR = 7;

	/**
	 * The app-relative page per object type, for the links in a member's message.
	 *
	 * @var array<string, string>
	 */
	private const PAGES = [
		'meeting'  => 'meetings',
		'decision' => 'decisions',
	];

	/**
	 * Construct the digest service.
	 *
	 * @param ObjectServiceInterface        $objectService OpenRegister object service
	 * @param NotificationPreferenceService $preferences   Each member's delivery choice
	 * @param IUserManager                  $userManager   Looks up the member for the read check
	 * @param IUserSession                  $userSession   Runs the read check as the member
	 * @param IConfig                       $config        The instance time zone
	 * @param IURLGenerator                 $urlGenerator  Absolute links in a member's message
	 * @param IFactory                      $l10nFactory   Translations of the message
	 * @param LoggerInterface               $logger        PSR-3 logger
	 *
	 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly NotificationPreferenceService $preferences,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send every digest that is due, stamp the subscriptions and remove old events.
	 *
	 * @param int $now Unix time of the run
	 *
	 * @return int Messages sent
	 *
	 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function run(int $now): int {
		$events = $this->purgeAndList(now: $now);
		$sent   = 0;

		foreach ($this->activeSubscriptions() as $subscription) {
			try {
				$sent += $this->sendOne(subscription: $subscription, events: $events, now: $now);
			} catch (Throwable $e) {
				$this->logger->warning('Decidiq: publication digest failed for a subscription', ['subscription' => ($subscription['id'] ?? ''), 'error' => $e->getMessage()]);
			}
		}

		return $sent;
	}//end run()

	/**
	 * The last moment a subscription with this frequency was due, at or before now.
	 *
	 * Immediate is always due; daily is due from 07:00; weekly from Monday 07:00.
	 *
	 * @param string            $frequency immediate, daily or weekly
	 * @param DateTimeImmutable $now       The run, in the instance time zone
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public static function lastDueMoment(string $frequency, DateTimeImmutable $now): DateTimeImmutable {
		if ($frequency === 'immediate') {
			return $now;
		}

		$moment = $now;
		if ($frequency === 'weekly') {
			// modify() with a day name resets the time, so the hour is set after it.
			$moment = $moment->modify('monday this week');
		}

		$moment = $moment->setTime(self::DIGEST_HOUR, 0);

		if ($moment > $now) {
			$moment = $moment->modify('-1 ' . ($frequency === 'weekly' ? 'week' : 'day'));
		}

		return $moment;
	}//end lastDueMoment()

	/**
	 * The events of one subscription since its last message, up to a moment.
	 *
	 * @param array<string,mixed>            $subscription The subscription
	 * @param array<int,array<string,mixed>> $events       All retained events
	 * @param int                            $until        Unix time; later events wait for the next run
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public static function matching(array $subscription, array $events, int $until): array {
		$since  = self::time(value: ($subscription['lastSentAt'] ?? null));
		$kinds  = (array)($subscription['kinds'] ?? []);
		$bodies = (array)($subscription['governanceBodies'] ?? []);

		$matched = [];
		foreach ($events as $event) {
			$at = self::time(value: ($event['occurredAt'] ?? null));
			if ($at === null || $at > $until || ($since !== null && $at <= $since)) {
				continue;
			}

			if (in_array(($event['kind'] ?? ''), $kinds, true) === false) {
				continue;
			}

			if ($bodies !== [] && in_array(($event['governanceBody'] ?? ''), $bodies, true) === false) {
				continue;
			}

			$matched[] = $event;
		}

		usort($matched, static fn(array $a, array $b): int => strcmp((string)$a['occurredAt'], (string)$b['occurredAt']));

		return $matched;
	}//end matching()

	/**
	 * Send one subscription its digest, if it is due and has news.
	 *
	 * @param array<string,mixed>            $subscription The subscription
	 * @param array<int,array<string,mixed>> $events       All retained events
	 * @param int                            $now          Unix time of the run
	 *
	 * @return int 1 when a message went out
	 */
	private function sendOne(array $subscription, array $events, int $now): int {
		$frequency = (string)($subscription['frequency'] ?? 'daily');
		$local     = (new DateTimeImmutable('@' . $now))->setTimezone($this->timeZone());
		$due       = self::lastDueMoment(frequency: $frequency, now: $local)->getTimestamp();

		$last = self::time(value: ($subscription['lastSentAt'] ?? null));
		if ($frequency !== 'immediate' && $last !== null && $last >= $due) {
			return 0;
		}

		$until = $due;
		if ($frequency === 'immediate') {
			$until = ($now - self::IMMEDIATE_WAIT_SECONDS);
		}

		$matched = self::matching(subscription: $subscription, events: $events, until: $until);
		if ($matched === []) {
			return 0;
		}

		$uid      = (string)($subscription['subscriberUserId'] ?? '');
		$resident = (string)($subscription['subscriberRef'] ?? '');
		$sent     = 0;
		if ($uid !== '' && $this->ownedBySubscriber(subscription: $subscription, uid: $uid) === true) {
			$sent = $this->sendToMember(uid: $uid, events: $this->readableBy(uid: $uid, events: $matched));
		} else if ($uid === '' && $resident !== '') {
			$published = array_values(array_filter($matched, static fn(array $event): bool => ($event['isPublished'] ?? false) === true));
			$sent      = $this->sendToResident(subjectRef: $resident, events: $published, now: $now);
		}

		$this->stamp(subscription: $subscription, at: $until);

		return $sent;
	}//end sendOne()

	/**
	 * A member's subscription only sends to the account that made it.
	 *
	 * Create is open to every member on the object API, so a subscription
	 * naming someone else must send nothing. Rows without an owner (the
	 * example sets) count as the subscriber's own.
	 *
	 * @param array<string,mixed> $subscription The subscription
	 * @param string              $uid          subscriberUserId
	 *
	 * @return bool
	 */
	private function ownedBySubscriber(array $subscription, string $uid): bool {
		$owner = (string)($subscription['@self']['owner'] ?? ($subscription['_owner'] ?? ''));

		return $owner === '' || $owner === $uid;
	}//end ownedBySubscriber()

	/**
	 * The events whose object the member may read now, checked as that member.
	 *
	 * @param string                         $uid    The member
	 * @param array<int,array<string,mixed>> $events Matching events
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	private function readableBy(string $uid, array $events): array {
		$user = $this->userManager->get($uid);
		if ($user === null || $events === []) {
			return [];
		}

		$previous = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			$readable = [];
			foreach ($events as $event) {
				try {
					$found = $this->objectService->find(
						id: (string)$event['objectId'],
						register: 'decidiq',
						schema: (string)$event['objectType'],
						_rbac: true
					);
				} catch (Throwable) {
					$found = null;
				}

				if ($found !== null) {
					$readable[] = $event;
				}
			}
		} finally {
			$this->userSession->setUser($previous);
		}

		return $readable;
	}//end readableBy()

	/**
	 * One message to a member through his delivery choice.
	 *
	 * @param string                         $uid    The member
	 * @param array<int,array<string,mixed>> $events The events he may read
	 *
	 * @return int 1 when a message went out
	 */
	private function sendToMember(string $uid, array $events): int {
		if ($events === []) {
			return 0;
		}

		[$title, $message] = $this->compose(events: $events, withLinks: true);
		$this->preferences->dispatch(personId: $uid, eventType: self::EVENT_TYPE, title: $title, message: $message);

		return 1;
	}//end sendToMember()

	/**
	 * One message in a resident's portal inbox; portaliq mails it on.
	 *
	 * @param string                         $subjectRef The portal subject
	 * @param array<int,array<string,mixed>> $events     Published events only
	 * @param int                            $now        Unix time of the run
	 *
	 * @return int 1 when a message went out
	 */
	private function sendToResident(string $subjectRef, array $events, int $now): int {
		if ($events === []) {
			return 0;
		}

		[$title, $message] = $this->compose(events: $events, withLinks: false);
		$this->objectService->saveObject(
			object: [
				'recipientId' => $subjectRef,
				'type'        => 'publication-digest',
				'subject'     => $title,
				'content'     => $message,
				'channel'     => 'in-app',
				'status'      => 'sent',
				'sentAt'      => (new DateTimeImmutable('@' . $now))->format(DateTimeInterface::ATOM),
			],
			register: 'decidiq',
			schema: 'notification',
			_rbac: false,
			_multitenancy: false
		);

		return 1;
	}//end sendToResident()

	/**
	 * The title and the message, events grouped per body and meeting.
	 *
	 * @param array<int,array<string,mixed>> $events    The events, oldest first
	 * @param bool                           $withLinks Whether to add a link to each meeting or decision
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	private function compose(array $events, bool $withLinks): array {
		$l10n   = $this->l10nFactory->get('decidiq');
		$groups = [];
		foreach ($events as $event) {
			$body  = (string)($event['governanceBody'] ?? '');
			$group = (string)($event['meeting'] ?? '');
			if ($group === '') {
				$group = (string)$event['objectId'];
			}

			$groups[$body][$group]['title']   = (string)($event['title'] ?? '');
			$groups[$body][$group]['link']    = $this->link(event: $event);
			$groups[$body][$group]['lines'][] = (string)($event['summary'] ?? '');
		}

		$bodyNames = [];
		$lines     = [];
		foreach ($groups as $body => $meetings) {
			$bodyNames[$body] = $this->bodyName(id: (string)$body);
			$lines[]          = ($bodyNames[$body] !== '' ? $bodyNames[$body] : $l10n->t('Other'));
			foreach ($meetings as $meeting) {
				$lines[] = '  ' . $meeting['title'];
				foreach ($meeting['lines'] as $line) {
					$lines[] = '  - ' . $line;
				}

				if ($withLinks === true && $meeting['link'] !== '') {
					$lines[] = '  ' . $meeting['link'];
				}
			}

			$lines[] = '';
		}

		$count = count($events);
		$title = $l10n->n('%n update from the bodies you follow', '%n updates from the bodies you follow', $count);
		if (count($bodyNames) === 1 && reset($bodyNames) !== '') {
			$title = $l10n->n('%n update from %s', '%n updates from %s', $count, [reset($bodyNames)]);
		}

		return [$title, rtrim(implode("\n", $lines))];
	}//end compose()

	/**
	 * The absolute link to the meeting or decision of an event, or ''.
	 *
	 * @param array<string,mixed> $event The event
	 *
	 * @return string
	 */
	private function link(array $event): string {
		$meeting = (string)($event['meeting'] ?? '');
		$path    = '';
		if ($meeting !== '') {
			$path = self::PAGES['meeting'] . '/' . $meeting;
		} else if (isset(self::PAGES[(string)$event['objectType']]) === true) {
			$path = self::PAGES[(string)$event['objectType']] . '/' . (string)$event['objectId'];
		}

		if ($path === '') {
			return '';
		}

		$base = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page');
		return rtrim($base, '/') . '/' . $path;
	}//end link()

	/**
	 * A body's name, or '' when it cannot be read.
	 *
	 * @param string $id The body
	 *
	 * @return string
	 */
	private function bodyName(string $id): string {
		if ($id === '') {
			return '';
		}

		try {
			$found = $this->objectService->find(id: $id, register: 'decidiq', schema: 'governance-body', _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return '';
		}

		return (string)($found?->getObject()['name'] ?? '');
	}//end bodyName()

	/**
	 * Every active subscription, in system context.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function activeSubscriptions(): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => 'publication-subscription'], 'limit' => 10000],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(
			array_filter(
				array_map(static fn(mixed $row): array => self::plain(row: $row), $rows),
				static fn(array $row): bool => ($row['active'] ?? true) !== false
			)
		);
	}//end activeSubscriptions()

	/**
	 * Remove events past retention and return the rest.
	 *
	 * @param int $now Unix time of the run
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function purgeAndList(int $now): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => 'publication-event'], 'limit' => 10000],
			_rbac: false,
			_multitenancy: false
		);

		$cutoff = ($now - (self::RETENTION_DAYS * 86400));
		$kept   = [];
		foreach ($rows as $row) {
			$event = self::plain(row: $row);
			$at    = self::time(value: ($event['occurredAt'] ?? null));
			if ($at !== null && $at < $cutoff && ($event['id'] ?? '') !== '') {
				$this->objectService->deleteObject(uuid: (string)$event['id'], register: 'decidiq', schema: 'publication-event', _rbac: false, _multitenancy: false);
				continue;
			}

			$kept[] = $event;
		}

		return $kept;
	}//end purgeAndList()

	/**
	 * Record when the subscriber last got a message.
	 *
	 * @param array<string,mixed> $subscription The subscription
	 * @param int                 $at           Unix time the message covers up to
	 *
	 * @return void
	 */
	private function stamp(array $subscription, int $at): void {
		$id   = (string)($subscription['id'] ?? '');
		$data = $subscription;
		unset($data['@self'], $data['id'], $data['_owner']);
		$data['lastSentAt'] = (new DateTimeImmutable('@' . $at))->setTimezone($this->timeZone())->format(DateTimeInterface::ATOM);

		$this->objectService->saveObject(
			object: $data,
			register: 'decidiq',
			schema: 'publication-subscription',
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);
	}//end stamp()

	/**
	 * The instance time zone.
	 *
	 * @return DateTimeZone
	 */
	private function timeZone(): DateTimeZone {
		try {
			return new DateTimeZone($this->config->getSystemValueString('default_timezone', 'Europe/Amsterdam'));
		} catch (Throwable) {
			return new DateTimeZone('UTC');
		}
	}//end timeZone()

	/**
	 * An object as a plain array with its id.
	 *
	 * @param mixed $row An entity or array
	 *
	 * @return array<string,mixed>
	 */
	private static function plain(mixed $row): array {
		$data = $row;
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$data = $row->getObject();
			if (method_exists($row, 'getUuid') === true) {
				$data['id'] = ($data['id'] ?? $row->getUuid());
			}

			if (method_exists($row, 'getOwner') === true) {
				$data['_owner'] = (string)$row->getOwner();
			}
		}

		if (is_array($data) === false) {
			return [];
		}

		$data['id'] = (string)($data['id'] ?? ($data['@self']['id'] ?? ''));

		return $data;
	}//end plain()

	/**
	 * A stored date-time as Unix time, or null.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return int|null
	 */
	private static function time(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$time = strtotime($value);
		if ($time === false) {
			return null;
		}

		return $time;
	}//end time()
}//end class
