<?php

/**
 * Decidiq Guest Invitation Service
 *
 * The organiser of an ad hoc meeting (a meeting without a governing body)
 * invites a guest from outside by email (meeting-ad-hoc-with-guests, pla-20,
 * board DcAdhocOverleg). The guest becomes a Participant with the email and
 * no Nextcloud account, is listed on the meeting through a meeting-attendance
 * record, and receives one email with the date, the place, the agenda and a
 * read-only link to the meeting's papers folder. Guests see nothing else.
 *
 * Only the meeting's organiser (its OpenRegister owner) or a decidiq
 * administrator may invite. A meeting of a governing body is refused: its
 * folder can hold confidential papers that a public link must never reach.
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
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\Exception\GuestInvitationRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Invites a guest from outside to an ad hoc meeting.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
class GuestInvitationService {

	/**
	 * Groups that may invite to a meeting they did not organise.
	 *
	 * @var array<int, string>
	 */
	private const ADMIN_GROUPS = ['decidiq-administrators', 'decidesk-administrators'];

	/**
	 * Share type of a public link, and read-only permission.
	 */
	private const PUBLIC_LINK = 3;
	private const READ_ONLY = 1;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the meeting, writes the guest
	 * @param FileService            $fileService   Shares the meeting's papers folder
	 * @param IMailer                $mailer        Sends the invitation
	 * @param IGroupManager          $groupManager  Admin checks
	 * @param IL10N                  $l10n          Invitation text
	 * @param LoggerInterface        $logger        Logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly FileService $fileService,
		private readonly IMailer $mailer,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse anyone but the meeting's organiser or a decidiq administrator.
	 *
	 * The meeting is read with the caller's own rights, so a meeting the
	 * caller cannot see answers 404 before any role is checked.
	 *
	 * @param string $meetingId The meeting
	 * @param string $userId    The caller
	 *
	 * @return array<string, mixed> The meeting
	 *
	 * @throws GuestInvitationRefusedException 404 when absent, 403 when not the organiser
	 *
	 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
	 */
	public function requireOrganiserOf(string $meetingId, string $userId): array {
		$meeting = $this->loadMeeting(meetingId: $meetingId);
		if ($userId === '' || ($this->isOwner(meeting: $meeting, userId: $userId) === false && $this->isAdministrator(userId: $userId) === false)) {
			throw new GuestInvitationRefusedException(message: $this->l10n->t('Only the organiser of this meeting can invite guests.'), status: 403);
		}

		return $meeting;
	}//end requireOrganiserOf()

	/**
	 * Invite a guest: add them to the meeting and send one invitation mail.
	 *
	 * @param string $meetingId The meeting
	 * @param string $name      The guest's name (the email when empty)
	 * @param string $email     The guest's email
	 * @param string $userId    The caller
	 *
	 * @return array{participant: string, attendance: string, mailed: bool}
	 *
	 * @throws GuestInvitationRefusedException When the invitation cannot be sent as asked
	 *
	 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
	 */
	public function invite(string $meetingId, string $name, string $email, string $userId): array {
		$meeting = $this->requireOrganiserOf(meetingId: $meetingId, userId: $userId);

		if (trim((string)($meeting['governanceBody'] ?? '')) !== '') {
			throw new GuestInvitationRefusedException(
				message: $this->l10n->t('Guests can only be invited to a meeting without a governing body.'),
				status: 422
			);
		}

		$email = trim($email);
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new GuestInvitationRefusedException(message: $this->l10n->t('Enter a valid email address.'), status: 422);
		}

		$name = trim($name);
		if ($name === '') {
			$name = $email;
		}

		$link = $this->papersLink(meetingId: $meetingId);

		$participant = $this->objectService->saveObject(
			object: ['displayName' => $name, 'role' => 'guest', 'email' => $email],
			register: 'decidiq',
			schema: 'participant'
		);
		$participantId = $this->idOf(entity: $participant);

		$attendance = $this->objectService->saveObject(
			object: ['meeting' => $meetingId, 'participant' => $participantId],
			register: 'decidiq',
			schema: 'meeting-attendance'
		);

		$mailed = $this->sendInvitation(meeting: $meeting, meetingId: $meetingId, email: $email, name: $name, link: $link);

		return [
			'participant' => $participantId,
			'attendance' => $this->idOf(entity: $attendance),
			'mailed' => $mailed,
		];
	}//end invite()

	/**
	 * The invitation text: the meeting, when and where, the agenda and the link.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param array<int, string>   $agenda  The agenda item titles, in order
	 * @param string               $name    The guest's name
	 * @param string               $link    The link to the papers
	 *
	 * @return string The plain-text body
	 *
	 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
	 */
	public function invitationBody(array $meeting, array $agenda, string $name, string $link): string {
		$lines = [
			$this->l10n->t('Dear %s,', [$name]),
			'',
			$this->l10n->t('You are invited to the meeting %s.', [(string)($meeting['title'] ?? '')]),
		];

		$when = $this->formatDate(value: (string)($meeting['scheduledDate'] ?? ''));
		if ($when !== '') {
			$lines[] = $this->l10n->t('When: %s', [$when]);
		}

		$where = trim((string)($meeting['location'] ?? ''));
		if ($where !== '') {
			$lines[] = $this->l10n->t('Where: %s', [$where]);
		}

		if ($agenda !== []) {
			$lines[] = '';
			$lines[] = $this->l10n->t('Agenda:');
			foreach ($agenda as $index => $title) {
				$lines[] = ($index + 1) . '. ' . $title;
			}
		}

		$lines[] = '';
		$lines[] = $this->l10n->t('The agenda and the papers of this meeting: %s', [$link]);
		$lines[] = $this->l10n->t('This link shows the papers of this meeting only.');

		return implode("\n", $lines);
	}//end invitationBody()

	/**
	 * Read the meeting with the caller's rights.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array<string, mixed>
	 *
	 * @throws GuestInvitationRefusedException 404 when the caller cannot see it
	 */
	private function loadMeeting(string $meetingId): array {
		try {
			$entity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		} catch (\Throwable $e) {
			$entity = null;
		}

		if ($entity === null) {
			throw new GuestInvitationRefusedException(message: $this->l10n->t('Meeting not found.'), status: 404);
		}

		return (array)$entity->jsonSerialize();
	}//end loadMeeting()

	/**
	 * Whether the caller created (owns) the meeting.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param string               $userId  The caller
	 *
	 * @return bool
	 */
	private function isOwner(array $meeting, string $userId): bool {
		$self = $meeting['@self'] ?? [];
		$owner = '';
		if (is_array($self) === true && isset($self['owner']) === true) {
			$owner = (string)$self['owner'];
		}

		return ($owner !== '' && $owner === $userId);
	}//end isOwner()

	/**
	 * Whether the caller is a Nextcloud or decidiq administrator.
	 *
	 * @param string $userId The caller
	 *
	 * @return bool
	 */
	private function isAdministrator(string $userId): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::ADMIN_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isAdministrator()

	/**
	 * A read-only public link to the meeting's papers folder.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return string The link
	 *
	 * @throws GuestInvitationRefusedException 422 when the folder cannot be shared
	 */
	private function papersLink(string $meetingId): string {
		$link = '';
		try {
			$folder = $this->fileService->getObjectFolder($meetingId);
			if ($folder !== null) {
				$link = $this->fileService->createShareLink($folder->getPath(), self::PUBLIC_LINK, self::READ_ONLY);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: sharing the meeting papers failed', ['meetingId' => $meetingId, 'error' => $e->getMessage()]);
			$link = '';
		}

		// FileService answers some failures with a message instead of a link.
		if (preg_match('#^https?://#', $link) !== 1) {
			throw new GuestInvitationRefusedException(
				message: $this->l10n->t('The papers of this meeting could not be shared. Add a paper to the meeting first.'),
				status: 422
			);
		}

		return $link;
	}//end papersLink()

	/**
	 * Send the invitation (fail-soft: the guest stays listed when mail fails).
	 *
	 * @param array<string, mixed> $meeting   The meeting
	 * @param string               $meetingId The meeting id
	 * @param string               $email     The guest's email
	 * @param string               $name      The guest's name
	 * @param string               $link      The link to the papers
	 *
	 * @return bool Whether the mail was sent
	 */
	private function sendInvitation(array $meeting, string $meetingId, string $email, string $name, string $link): bool {
		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email => $name]);
			$message->setSubject($this->l10n->t('Invitation: %s', [(string)($meeting['title'] ?? '')]));
			$message->setPlainBody(
				$this->invitationBody(meeting: $meeting, agenda: $this->agendaTitles(meetingId: $meetingId), name: $name, link: $link)
			);
			$this->mailer->send($message);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: guest invitation mail failed', ['meetingId' => $meetingId, 'error' => $e->getMessage()]);
			return false;
		}
	}//end sendInvitation()

	/**
	 * The meeting's agenda item titles in agenda order.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array<int, string>
	 */
	private function agendaTitles(string $meetingId): array {
		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => 'decidiq', 'schema' => 'agenda-item', 'meeting' => $meetingId], 'limit' => 200]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$items = [];
		foreach (($rows['results'] ?? $rows) as $row) {
			$item = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$item = (array)$row->jsonSerialize();
			}

			if (is_array($item) === true && trim((string)($item['title'] ?? '')) !== '' && ($item['parentItem'] ?? null) === null) {
				$items[] = $item;
			}
		}

		usort($items, fn (array $a, array $b): int => ((int)($a['orderNumber'] ?? 0) <=> (int)($b['orderNumber'] ?? 0)));
		return array_map(fn (array $item): string => (string)$item['title'], $items);
	}//end agendaTitles()

	/**
	 * A stored date-time in the reader's words, or '' when absent.
	 *
	 * @param string $value An ISO date-time
	 *
	 * @return string
	 */
	private function formatDate(string $value): string {
		if (trim($value) === '') {
			return '';
		}

		try {
			return (string)$this->l10n->l('datetime', new DateTimeImmutable($value));
		} catch (\Throwable $e) {
			return $value;
		}
	}//end formatDate()

	/**
	 * The id of a saved object.
	 *
	 * @param mixed $entity The saved object
	 *
	 * @return string
	 */
	private function idOf(mixed $entity): string {
		if (is_object($entity) === true && method_exists($entity, 'getUuid') === true) {
			return (string)$entity->getUuid();
		}

		$data = $entity;
		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$data = (array)$entity->jsonSerialize();
		}

		return (string)($data['id'] ?? ($data['@self']['id'] ?? ''));
	}//end idOf()
}//end class
