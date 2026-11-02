<?php

/**
 * Decidiq Agenda Notices
 *
 * Tells a meeting's members about its agenda through each member's own
 * notification preferences.
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
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Closure;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the agenda notices of a meeting (event type `agendaChanged`): in the
 * bell, by email, both, or not at all, as each member chose.
 *
 * A notice is described as an array with `meetingId`, `meeting` (the meeting as
 * read before the save), `subject` (agenda_published, agenda_revised,
 * agenda_revision_started or agenda_changed) and optionally `items`, the
 * published agenda items that turn the notice into an invitation.
 *
 * @psalm-type AgendaNotice = array{meetingId: string, meeting: array<string, mixed>, subject: string, items?: iterable<mixed>|null}
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
 */
final class AgendaNotices {

	/**
	 * Notice titles per agenda subject; `%s` is the meeting title. The same
	 * sentences the notifier shows in the bell.
	 *
	 * @var array<string, string>
	 */
	private const AGENDA_SENTENCES = [
		'agenda_published'        => 'The agenda of %s was published',
		'agenda_revised'          => 'A revised agenda of %s was published',
		'agenda_revision_started' => 'The agenda of %s is being revised',
		'agenda_changed'          => 'The agenda of %s changed',
	];

	/**
	 * Constructor for AgendaNotices.
	 *
	 * @param NotificationPreferenceService $preferences Each member's delivery choice (bell, email, both, off)
	 * @param IFactory                      $l10nFactory Translations for the notice title and body
	 * @param LoggerInterface               $logger      PSR-3 logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly NotificationPreferenceService $preferences,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one agenda notice to each recipient; a failed delivery is logged
	 * and the next recipient is still told.
	 *
	 * @param array   $notice     The notice
	 * @param Closure $recipients Returns the user ids to tell
	 *
	 * @psalm-param AgendaNotice $notice
	 * @psalm-param Closure(): iterable<string> $recipients
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 */
	public function send(array $notice, Closure $recipients): void {
		$content = $this->content(notice: $notice);

		foreach ($recipients() as $uid) {
			try {
				$this->preferences->dispatch(
					personId: $uid,
					eventType: 'agendaChanged',
					title: $content['title'],
					message: $content['message'],
					deepLink: '/meetings/' . $notice['meetingId'],
					inApp: $content['inApp'],
					attachments: $content['attachments']
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Failed to send agenda notification to user {userId}: {error}',
					['userId' => $uid, 'error' => $e->getMessage()]
				);
			}
		}
	}//end send()

	/**
	 * The title, message, attachments and in-app payload of a notice.
	 *
	 * @param array $notice The notice
	 *
	 * @psalm-param AgendaNotice $notice
	 *
	 * @return array{title: string, message: string, attachments: array<int, array<string, string>>, inApp: array<string, mixed>}
	 */
	private function content(array $notice): array {
		$l10n = $this->l10nFactory->get('decidiq');
		$meetingTitle = (string)($notice['meeting']['title'] ?? '');
		$named = $meetingTitle;
		if ($named === '') {
			$named = $l10n->t('this meeting');
		}

		$content = [
			'title' => $l10n->t(self::AGENDA_SENTENCES[$notice['subject']] ?? self::AGENDA_SENTENCES['agenda_changed'], [$named]),
			'message' => $l10n->t('Open the meeting in Decidiq to see the agenda.'),
			'attachments' => [],
		];
		if (($notice['items'] ?? null) !== null) {
			$content = array_merge($content, $this->invitation(notice: $notice, l10n: $l10n));
		}

		$content['inApp'] = [
			'subject'    => $notice['subject'],
			'parameters' => ['meetingId' => $notice['meetingId'], 'meetingTitle' => $meetingTitle],
			'objectType' => 'meeting',
			'objectId'   => $notice['meetingId'],
		];

		return $content;
	}//end content()

	/**
	 * A published agenda is the invitation: when, where, the items, and the
	 * meeting as a calendar file (agenda-publish-and-invite-members).
	 *
	 * @param array $notice The notice, with its items
	 * @param IL10N $l10n   The translations
	 *
	 * @psalm-param AgendaNotice $notice
	 *
	 * @return array{message: string, attachments: array<int, array<string, string>>}
	 */
	private function invitation(array $notice, IL10N $l10n): array {
		$invitation = new AgendaInvitation();
		$titles = $invitation->orderedTitles(items: $notice['items']);
		return [
			'message' => $invitation->message(l10n: $l10n, meeting: $notice['meeting'], itemTitles: $titles),
			'attachments' => [
				[
					'data' => $invitation->calendarFile(meetingId: $notice['meetingId'], meeting: $notice['meeting'], itemTitles: $titles),
					'filename' => 'meeting.ics',
					'contentType' => 'text/calendar',
				],
			],
		];
	}//end invitation()
}//end class
