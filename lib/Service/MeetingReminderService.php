<?php

/**
 * Decidiq Meeting Reminder Service
 *
 * Tells members a meeting was scheduled, reminds them before it starts and
 * before its submission deadline (change meeting-reminders-before-deadlines).
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
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scans the scheduled meetings (hourly, from MeetingReminderJob) and sends,
 * each through the member's own switches and delivery choice:
 *
 * - `meetingCreated`, subject meeting_scheduled: once, for a scheduled
 *   meeting that has not started;
 * - `meetingReminder`, subject meeting_reminder: at each of the member's
 *   reminder times (default 24 hours and 1 hour) before the start, once per
 *   time; a run that finds several times due sends one reminder;
 * - `meetingReminder`, subject submission_deadline: once, 48 hours before
 *   the meeting's submission deadline.
 *
 * What was sent is stamped on the meeting (scheduledNoticeSentAt,
 * reminderSentTo, deadlineReminderSentAt), so every notice goes out once.
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
 */
class MeetingReminderService {

	/**
	 * How long before the submission deadline its reminder goes out.
	 *
	 * @var int
	 */
	public const DEADLINE_WINDOW = 172800;

	/**
	 * Reminder time tokens a member can pick, in seconds before the start.
	 *
	 * @var array<string, int>
	 */
	private const REMINDER_OFFSETS = [
		'1w' => 604800,
		'48h' => 172800,
		'24h' => 86400,
		'4h' => 14400,
		'1h' => 3600,
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface        $objectService       Reads and stamps meetings
	 * @param ParticipantResolver           $participantResolver The meeting's members
	 * @param NotificationPreferenceService $preferences         Switches, reminder times and delivery
	 * @param IFactory                      $l10nFactory         Translations for the email text
	 * @param LoggerInterface               $logger              Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolver $participantResolver,
		private readonly NotificationPreferenceService $preferences,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send every notice that is due, and stamp it.
	 *
	 * @param int $now Unix time of the run
	 *
	 * @return int Deliveries made
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	public function run(int $now): int {
		try {
			$this->objectService->setRegister('decidiq');
			$this->objectService->setSchema('meeting');
			$meetings = $this->objectService->findAll(['filters' => ['lifecycle' => 'scheduled'], 'limit' => 500]);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: meeting reminder scan failed', ['exception' => $e->getMessage()]);
			return 0;
		}

		$sent = 0;
		foreach ($meetings as $entity) {
			try {
				$sent += $this->remindMeeting(entity: $entity, now: $now);
			} catch (Throwable $e) {
				$this->logger->warning('Decidiq: meeting reminder skipped', ['exception' => $e->getMessage()]);
			}
		}

		return $sent;

	}//end run()

	/**
	 * Send what is due for one meeting and stamp it.
	 *
	 * @param mixed $entity The meeting entity
	 * @param int   $now    Unix time of the run
	 *
	 * @return int Deliveries made
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	private function remindMeeting(mixed $entity, int $now): int {
		$meeting = (array)$entity->getObject();
		$meetingId = (string)($entity->getUuid() ?? ($meeting['id'] ?? ''));
		$start = $this->timestamp(value: ($meeting['scheduledDate'] ?? null));
		if ($meetingId === '' || $this->isUpcoming(meeting: $meeting, start: $start, now: $now) === false) {
			return 0;
		}

		$members = $this->members(meetingId: $meetingId);
		$patch = [];
		$sent = 0;

		if (empty($meeting['scheduledNoticeSentAt']) === true) {
			$sent += $this->tell(members: $members, meetingId: $meetingId, meeting: $meeting, kind: 'meeting_scheduled');
			$patch['scheduledNoticeSentAt'] = $this->iso(time: $now);
		}

		if ($this->deadlineReminderDue(meeting: $meeting, now: $now) === true) {
			$sent += $this->tell(members: $members, meetingId: $meetingId, meeting: $meeting, kind: 'submission_deadline');
			$patch['deadlineReminderSentAt'] = $this->iso(time: $now);
		}

		[$reminded, $sentTo] = $this->remindBeforeStart(members: $members, meetingId: $meetingId, meeting: $meeting, secondsLeft: ((int)$start - $now));
		$sent += $reminded;
		if ($sentTo !== ($meeting['reminderSentTo'] ?? [])) {
			$patch['reminderSentTo'] = $sentTo;
		}

		if ($patch !== []) {
			$this->objectService->saveObject(
				object: array_merge($meeting, $patch),
				register: 'decidiq',
				schema: 'meeting',
				uuid: $meetingId,
			);
		}

		return $sent;

	}//end remindMeeting()

	/**
	 * Whether the meeting is scheduled and has not started.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param int|null             $start   Its start as a Unix time
	 * @param int                  $now     Unix time of the run
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	private function isUpcoming(array $meeting, ?int $start, int $now): bool {
		return ($meeting['lifecycle'] ?? '') === 'scheduled' && $start !== null && $start > $now;

	}//end isUpcoming()

	/**
	 * Whether the submission deadline is within 48 hours and not yet reminded.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param int                  $now     Unix time of the run
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-002-a-reminder-before-the-submission-deadline
	 */
	private function deadlineReminderDue(array $meeting, int $now): bool {
		$deadline = $this->timestamp(value: ($meeting['submissionDeadline'] ?? null));
		if ($deadline === null || empty($meeting['deadlineReminderSentAt']) === false) {
			return false;
		}

		return $deadline > $now && ($deadline - $now) <= self::DEADLINE_WINDOW;

	}//end deadlineReminderDue()

	/**
	 * Remind each member whose reminder time has come, once per time.
	 *
	 * @param array<int, string>   $members     Member user ids
	 * @param string               $meetingId   The meeting
	 * @param array<string, mixed> $meeting     The meeting
	 * @param int                  $secondsLeft Seconds until the start
	 *
	 * @return array{0: int, 1: array<string, array<int, string>>} Deliveries and the updated stamp
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	private function remindBeforeStart(array $members, string $meetingId, array $meeting, int $secondsLeft): array {
		$sentTo = $meeting['reminderSentTo'] ?? [];
		if (is_array($sentTo) === false) {
			$sentTo = [];
		}

		$sent = 0;
		foreach ($members as $uid) {
			$done = (array)($sentTo[$uid] ?? []);
			$due = [];
			foreach ((array)($this->preferences->getPreferenceWithDefaults(personId: $uid)['reminderTimes'] ?? []) as $token) {
				$offset = (self::REMINDER_OFFSETS[$token] ?? null);
				if ($offset !== null && $secondsLeft <= $offset && in_array($token, $done, true) === false) {
					$due[] = $token;
				}
			}

			if ($due === []) {
				continue;
			}

			$sent += $this->tell(members: [$uid], meetingId: $meetingId, meeting: $meeting, kind: 'meeting_reminder');
			$sentTo[$uid] = array_values(array_unique(array_merge($done, $due)));
		}

		return [$sent, $sentTo];

	}//end remindBeforeStart()

	/**
	 * Send one kind of notice to the members, each by their own switch.
	 *
	 * @param array<int, string>   $members   Member user ids
	 * @param string               $meetingId The meeting
	 * @param array<string, mixed> $meeting   The meeting
	 * @param string               $kind      meeting_scheduled, meeting_reminder or submission_deadline
	 *
	 * @return int Deliveries made
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-002-a-reminder-before-the-submission-deadline
	 */
	private function tell(array $members, string $meetingId, array $meeting, string $kind): int {
		$l10n = $this->l10nFactory->get('decidiq');
		$title = (string)($meeting['title'] ?? '');
		$startsAt = $this->local(value: ($meeting['scheduledDate'] ?? null));
		$deadline = $this->local(value: ($meeting['submissionDeadline'] ?? null));

		$texts = [
			'meeting_scheduled' => [
				'meetingCreated',
				$l10n->t('The meeting %s was scheduled', [$title]),
				$l10n->t('It starts on %s.', [$startsAt]),
			],
			'meeting_reminder' => [
				'meetingReminder',
				$l10n->t('The meeting %s is coming up', [$title]),
				$l10n->t('It starts on %s.', [$startsAt]),
			],
			'submission_deadline' => [
				'meetingReminder',
				$l10n->t('The submission deadline of %s is coming up', [$title]),
				$l10n->t('Motions and amendments can be submitted until %s.', [$deadline]),
			],
		];
		[$eventType, $subject, $message] = $texts[$kind];

		$sent = 0;
		foreach ($members as $uid) {
			$sent += $this->preferences->dispatch(
				personId: $uid,
				eventType: $eventType,
				title: $subject,
				message: $message,
				deepLink: '/meetings/' . $meetingId,
				inApp: [
					'subject' => $kind,
					'parameters' => ['meetingId' => $meetingId, 'meetingTitle' => $title, 'startsAt' => $startsAt, 'deadline' => $deadline],
					'objectType' => 'meeting',
					'objectId' => $meetingId,
				]
			);
		}

		return $sent;

	}//end tell()

	/**
	 * The meeting's active members, by Nextcloud user id.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 */
	private function members(string $meetingId): array {
		$uids = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $participant) {
			if (($participant['leftAt'] ?? null) !== null) {
				continue;
			}

			$uid = (string)($participant['nextcloudUserId'] ?? ($participant['owner'] ?? ''));
			if ($uid !== '') {
				$uids[$uid] = true;
			}
		}

		return array_keys($uids);

	}//end members()

	/**
	 * A stored date-time as a Unix time, or null.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return int|null
	 */
	private function timestamp(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Throwable) {
			return null;
		}

	}//end timestamp()

	/**
	 * A stored date-time as "YYYY-MM-DD HH:MM" in its own offset, or ''.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return string
	 */
	private function local(mixed $value): string {
		if ($this->timestamp(value: $value) === null) {
			return '';
		}

		return (new DateTimeImmutable((string)$value))->format('Y-m-d H:i');

	}//end local()

	/**
	 * A Unix time as ISO 8601 in UTC.
	 *
	 * @param int $time The time
	 *
	 * @return string
	 */
	private function iso(int $time): string {
		return (new DateTimeImmutable('@' . $time))->format(DateTimeInterface::ATOM);

	}//end iso()
}//end class
