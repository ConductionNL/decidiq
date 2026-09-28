<?php

/**
 * Decidiq Agenda Service
 *
 * Service for managing agenda lifecycle operations including publication,
 * BOB phase advancement, consent item (hamerstukken) processing, and reordering.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing agenda lifecycle operations.
 *
 * Provides domain-specific business logic for:
 * - Publishing agendas and notifying participants
 * - Advancing BOB (Beeldvorming/Oordeelsvorming/Besluitvorming) phases
 * - Processing consent agenda items (hamerstukken)
 * - Atomically reordering agenda items
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
 */
class AgendaService {

	/**
	 * BOB phase transition map: current status → next status.
	 *
	 * @var array<string,string>
	 */
	private const BOB_PHASE_TRANSITIONS = [
		'voorstel' => 'beeldvorming',
		'beeldvorming' => 'oordeelsvorming',
		'oordeelsvorming' => 'besluitvorming',
		'besluitvorming' => 'completed',
	];

	/**
	 * Tag identifying consent agenda items.
	 *
	 * @var string
	 */
	private const HAMERSTUK_TAG = 'hamerstuk';

	/**
	 * True while this service writes several agenda items as one change.
	 *
	 * @var boolean
	 */
	private bool $suppressItemNotices = false;

	/**
	 * A member gets at most one agenda change notice per meeting in this many seconds.
	 *
	 * @var int
	 */
	private const AGENDA_NOTICE_WINDOW_SECONDS = 300;

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
	 * Constructor for AgendaService.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param CalendarEventService $calendarEventService OpenRegister calendar event service
	 * @param NotificationPreferenceService $preferences Each member's delivery choice (bell, email, both, off)
	 * @param LoggerInterface $logger PSR-3 logger
	 * @param ParticipantResolver $participantResolver Canonical participant resolver
	 * @param IFactory $l10nFactory Translations for the notice title and body
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly CalendarEventService $calendarEventService,
		private readonly NotificationPreferenceService $preferences,
		private readonly LoggerInterface $logger,
		private readonly ParticipantResolver $participantResolver,
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * Publish an agenda for a meeting.
	 *
	 * Validates that at least one AgendaItem exists for the meeting, records
	 * the publication (agendaPublishedAt, agendaVersion and a snapshot in
	 * agendaVersions) and notifies all active participants. The Meeting
	 * lifecycle is left alone (#1396).
	 *
	 * @param string $meetingId UUID of the Meeting to publish
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no agenda items exist for the meeting
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function publishAgenda(string $meetingId): void {
		// Validate at least one AgendaItem exists.
		$items = $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'agenda-item',
					'_relations.meeting' => $meetingId,
				],
			]
		);

		if (empty($items) === true) {
			throw new InvalidArgumentException('Cannot publish agenda: no agenda items exist for this meeting.');
		}

		// Update the meeting calendar entry to reflect the published agenda
		// (method guard for forward-compatibility until CalendarEventService
		// exposes updateMeetingEvent).
		if (method_exists($this->calendarEventService, 'updateMeetingEvent') === true) {
			$this->calendarEventService->updateMeetingEvent(meetingId: $meetingId);
		}

		// #315: Read the full meeting object before saving so that a partial payload cannot
		// silently wipe required fields that are not included in the update.
		$meetingData = $this->readMeeting(meetingId: $meetingId);

		// A first publication is "published"; publishing again after a
		// revision is "revised". Either way the meeting's lifecycle is left
		// alone: publishing an agenda days ahead is not a meeting in session,
		// and LiveDecisionService only records live decisions on an `opened`
		// meeting (#1396). Publication has its own fields instead.
		$subject = 'agenda_published';
		if ((int)($meetingData['agendaVersion'] ?? 0) > 0) {
			$subject = 'agenda_revised';
		}

		$this->saveMeeting(
			meetingId: $meetingId,
			meetingData: $this->withNewAgendaVersion(meetingData: $meetingData, items: $items),
			changes: ['agendaUnderRevision' => false]
		);

		$this->notifyParticipants(meetingData: $meetingData, meetingId: $meetingId, subject: $subject);

		$this->logger->info('Agenda published for meeting {meetingId}', ['meetingId' => $meetingId]);

	}//end publishAgenda()

	/**
	 * Normalise an OpenRegister object to a plain PHP array.
	 *
	 * Handles both raw arrays and ObjectEntity instances returned by ObjectService.
	 *
	 * @param mixed $item The raw item from ObjectService
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	private function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (method_exists($item, 'getObject') === true) {
			return $item->getObject();
		}

		return (array)$item;
	}//end toArray()

	/**
	 * Advance the BOB phase of a single agenda item.
	 *
	 * Maps the current status to the next BOB phase using the transition table.
	 * Informational items (itemType = 'informational') cannot be advanced.
	 *
	 * BOB phase order: voorstel → beeldvorming → oordeelsvorming → besluitvorming → afgerond
	 *
	 * @param string $agendaItemId UUID of the AgendaItem to advance
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the agenda item does not exist
	 * @throws InvalidArgumentException When item is informational or already at final phase
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function advanceBobPhase(string $agendaItemId): void {
		$item = $this->objectService->find($agendaItemId);
		if ($item === null) {
			throw new NotFoundException(message: "AgendaItem {$agendaItemId} not found.");
		}

		$itemData = $this->toArray(item: $item);

		// Guard: informational items have no BOB phase.
		$itemType = $itemData['itemType'] ?? null;
		if ($itemType === 'informational') {
			throw new InvalidArgumentException('Informational agenda items do not have a BOB phase.');
		}

		$currentStatus = $itemData['status'] ?? 'beeldvorming';
		$nextStatus = self::BOB_PHASE_TRANSITIONS[$currentStatus] ?? null;

		if ($nextStatus === null) {
			throw new InvalidArgumentException(
				"AgendaItem is already at final phase '{$currentStatus}' and cannot be advanced."
			);
		}

		// Uses patchObject, not saveObject: a uuid-bearing save is a FULL REPLACE
		// that OpenRegister validates whole, so a status-only payload 400s on
		// the required title, itemType and orderNumber it omits.
		$this->objectService->patchObject(
			objectId: $agendaItemId,
			data: ['status' => $nextStatus],
			register: 'decidiq',
			schema: 'agenda-item',
		);

		$this->logger->info(
			'BOB phase advanced for agenda item {id}: {from} to {to}',
			['id' => $agendaItemId, 'from' => $currentStatus, 'to' => $nextStatus]
		);

	}//end advanceBobPhase()

	/**
	 * Process all consent agenda items (hamerstukken) for a meeting.
	 *
	 * Fetches all AgendaItems for the meeting that have the 'hamerstuk' tag
	 * and bulk-updates their status to 'completed' via ObjectService.
	 *
	 * @param string $meetingId UUID of the Meeting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function processHamerstukken(string $meetingId): void {
		$items = $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'agenda-item',
					'_relations.meeting' => $meetingId,
				],
			]
		);

		$processedCount = 0;
		foreach ($items as $item) {
			$itemData = $this->toArray(item: $item);
			$tags = $itemData['tags'] ?? ($itemData['@self']['tags'] ?? []);

			if (in_array(needle: self::HAMERSTUK_TAG, haystack: (array)$tags, strict: true) === false) {
				continue;
			}

			$itemId = $itemData['id'] ?? ($itemData['@self']['id'] ?? ($itemData['uuid'] ?? null));
			if ($itemId === null) {
				continue;
			}

			// Uses patchObject, not saveObject: see advanceBobPhase() — a partial
			// payload under save's full-replace validation 400s per item.
			$this->objectService->patchObject(
				objectId: (string)$itemId,
				data: ['status' => 'completed'],
				register: 'decidiq',
				schema: 'agenda-item',
			);

			$processedCount++;
		}//end foreach

		$this->logger->info(
			'Processed {count} hamerstukken for meeting {meetingId}',
			['count' => $processedCount, 'meetingId' => $meetingId]
		);

	}//end processHamerstukken()

	/**
	 * Open a published agenda for revision.
	 *
	 * Marks the agenda as under revision and tells the participants, so the
	 * chair or secretary can edit it before publishing the next version. The
	 * Meeting lifecycle is left alone (#1396).
	 *
	 * @param string $meetingId UUID of the Meeting to revert
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function reviseAgenda(string $meetingId): void {
		// #315: Read the full meeting object before saving to avoid wiping required fields.
		$meetingData = $this->readMeeting(meetingId: $meetingId);

		// The lifecycle is NOT touched: a meeting that is running stays
		// running while its agenda is revised (#1396).
		$this->saveMeeting(meetingId: $meetingId, meetingData: $meetingData, changes: ['agendaUnderRevision' => true]);

		if (($meetingData['agendaPublishedAt'] ?? null) !== null) {
			$this->notifyParticipants(meetingData: $meetingData, meetingId: $meetingId, subject: 'agenda_revision_started');
		}

		$this->logger->info('Agenda opened for revision for meeting {meetingId}', ['meetingId' => $meetingId]);

	}//end reviseAgenda()

	/**
	 * Atomically reorder agenda items for a meeting.
	 *
	 * Accepts an ordered array of AgendaItem UUIDs and assigns sequential
	 * orderNumber values 1..n, preventing gaps and duplicates.
	 *
	 * @param string $meetingId UUID of the Meeting (used for validation)
	 * @param string[] $orderedIds Ordered array of AgendaItem UUIDs
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function reorderItems(string $meetingId, array $orderedIds): void {
		// Build a set of valid UUIDs that belong to this meeting.
		$meetingItems = $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'agenda-item',
					'_relations.meeting' => $meetingId,
				],
			]
		);

		$validIds = [];
		foreach ($meetingItems as $item) {
			$itemData = $this->toArray(item: $item);
			$itemId = $itemData['id'] ?? ($itemData['@self']['id'] ?? ($itemData['uuid'] ?? null));
			if ($itemId !== null) {
				$validIds[(string)$itemId] = true;
			}
		}

		$orderNumber = 1;
		// One notice for the whole reorder, not one per patched item: the
		// item listener checks isSuppressingItemNotices() (#1396).
		$this->suppressItemNotices = true;
		foreach ($orderedIds as $itemId) {
			if (isset($validIds[(string)$itemId]) === false) {
				$this->logger->warning(
					'reorderItems: UUID {id} does not belong to meeting {meetingId} — skipped',
					['id' => $itemId, 'meetingId' => $meetingId]
				);
				continue;
			}

			// Uses patchObject, not saveObject: see advanceBobPhase() — a partial
			// payload under save's full-replace validation 400s per item.
			$this->objectService->patchObject(
				objectId: (string)$itemId,
				data: ['orderNumber' => $orderNumber],
				register: 'decidiq',
				schema: 'agenda-item',
			);

			$orderNumber++;
		}//end foreach

		$this->suppressItemNotices = false;
		$this->notifyAgendaChanged(meetingId: $meetingId);

		$this->logger->info(
			'Reordered {count} agenda items for meeting {meetingId}',
			['count' => count($orderedIds), 'meetingId' => $meetingId]
		);

	}//end reorderItems()

	/**
	 * Tell members that the agenda of a published meeting changed.
	 *
	 * Called for every agenda item created, edited, moved or withdrawn on a
	 * meeting whose agenda was published (AgendaItemChangeListener), and
	 * once after a reorder. It records a new agenda version with a snapshot
	 * of the items, so the version members were sent stays visible, and
	 * notifies the active participants through the same channel as the
	 * publish notice. Silent before publication and while a revision is
	 * open: the republish at the end of a revision is the notice then.
	 *
	 * @param string $meetingId UUID of the Meeting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function notifyAgendaChanged(string $meetingId): void {
		$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($meetingEntity === null) {
			return;
		}

		$meetingData = $this->toArray(item: $meetingEntity);
		if (($meetingData['agendaPublishedAt'] ?? null) === null || ($meetingData['agendaUnderRevision'] ?? false) === true) {
			return;
		}

		$items = $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'agenda-item',
					'_relations.meeting' => $meetingId,
				],
			]
		);

		// REQ-ACN-004: every change is a version, but a member hears about a
		// burst of edits once. Who is due is decided before the save, so the
		// new notice times go out with the version in one write.
		$sentAt = (array)($meetingData['agendaNoticeSentAt'] ?? []);
		$due = [];
		foreach ($this->activeRecipients(meetingId: $meetingId) as $uid) {
			$last = strtotime((string)($sentAt[$uid] ?? ''));
			if ($last !== false && (time() - $last) < self::AGENDA_NOTICE_WINDOW_SECONDS) {
				continue;
			}

			$due[] = $uid;
			$sentAt[$uid] = date(DATE_ATOM);
		}

		$this->saveMeeting(
			meetingId: $meetingId,
			meetingData: $this->withNewAgendaVersion(meetingData: $meetingData, items: $items),
			changes: ['agendaNoticeSentAt' => $sentAt]
		);
		$this->notifyParticipants(meetingData: $meetingData, meetingId: $meetingId, subject: 'agenda_changed', recipients: $due);

	}//end notifyAgendaChanged()

	/**
	 * Whether this service is writing several agenda items as one change.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function isSuppressingItemNotices(): bool {
		return $this->suppressItemNotices;
	}//end isSuppressingItemNotices()

	/**
	 * Read a meeting as a plain array, or refuse when it does not exist.
	 *
	 * @param string $meetingId UUID of the Meeting
	 *
	 * @return array<string, mixed>
	 *
	 * @throws NotFoundException When the meeting does not exist
	 */
	private function readMeeting(string $meetingId): array {
		$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($meetingEntity === null) {
			throw new NotFoundException(message: "Meeting {$meetingId} not found");
		}

		return $this->toArray(item: $meetingEntity);
	}//end readMeeting()

	/**
	 * Save a meeting as a full-object merge (#315).
	 *
	 * @param string               $meetingId   UUID of the Meeting
	 * @param array<string, mixed> $meetingData The full meeting
	 * @param array<string, mixed> $changes     Fields to set over it
	 *
	 * @return void
	 */
	private function saveMeeting(string $meetingId, array $meetingData, array $changes): void {
		$this->objectService->saveObject(
			object: array_merge($meetingData, $changes),
			register: 'decidiq',
			schema: 'meeting',
			uuid: $meetingId,
		);
	}//end saveMeeting()

	/**
	 * The meeting with its next agenda version recorded.
	 *
	 * @param array<string, mixed> $meetingData The full meeting
	 * @param iterable<mixed>      $items       The meeting's agenda items
	 *
	 * @return array<string, mixed>
	 */
	private function withNewAgendaVersion(array $meetingData, iterable $items): array {
		$now = (new DateTime())->format(DATE_ATOM);
		$version = ((int)($meetingData['agendaVersion'] ?? 0) + 1);

		$snapshot = [];
		foreach ($items as $item) {
			$itemData = $this->toArray(item: $item);
			$snapshot[] = [
				'id' => (string)($itemData['id'] ?? ($itemData['@self']['id'] ?? '')),
				'title' => (string)($itemData['title'] ?? ''),
				'orderNumber' => ($itemData['orderNumber'] ?? null),
			];
		}

		$versions = $meetingData['agendaVersions'] ?? [];
		if (is_array($versions) === false) {
			$versions = [];
		}

		$versions[] = ['version' => $version, 'publishedAt' => $now, 'items' => $snapshot];

		return array_merge(
			$meetingData,
			[
				'agendaPublishedAt' => $now,
				'agendaVersion' => $version,
				'agendaVersions' => $versions,
			]
		);
	}//end withNewAgendaVersion()

	/**
	 * The Nextcloud users of the meeting's active participants: the linked
	 * `nextcloudUserId`, or `owner` for records made before that field, as
	 * ParticipantResolver::hasRole() reads them. Participants who left are skipped.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<int, string> Unique user ids
	 *
	 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 */
	private function activeRecipients(string $meetingId): array {
		// Participants come from the canonical path (participant -> governance-body
		// -> meeting; participants carry no direct meeting relation).
		$uids = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $participant) {
			$participantData = $this->toArray(item: $participant);
			if (($participantData['leftAt'] ?? null) !== null) {
				continue;
			}

			$uid = (string)($participantData['nextcloudUserId'] ?? $participantData['owner'] ?? '');
			if ($uid !== '') {
				$uids[$uid] = true;
			}
		}

		return array_keys($uids);
	}//end activeRecipients()

	/**
	 * Tell the meeting's members about its agenda, each through their own
	 * notification preferences (event type `agendaChanged`): in the bell, by
	 * email, both, or not at all.
	 *
	 * @param array<string, mixed>   $meetingData The meeting as read before the save
	 * @param string                 $meetingId   The meeting UUID
	 * @param string                 $subject     agenda_published, agenda_revised, agenda_revision_started or agenda_changed
	 * @param array<int, string>|null $recipients The users to tell, or null for every active participant
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 */
	private function notifyParticipants(array $meetingData, string $meetingId, string $subject, ?array $recipients=null): void {
		$l10n = $this->l10nFactory->get('decidiq');
		$meetingTitle = (string)($meetingData['title'] ?? '');
		$named = $meetingTitle;
		if ($named === '') {
			$named = $l10n->t('this meeting');
		}

		$title = $l10n->t(self::AGENDA_SENTENCES[$subject] ?? self::AGENDA_SENTENCES['agenda_changed'], [$named]);
		$message = $l10n->t('Open the meeting in Decidiq to see the agenda.');
		$inApp = [
			'subject'    => $subject,
			'parameters' => ['meetingId' => $meetingId, 'meetingTitle' => $meetingTitle],
			'objectType' => 'meeting',
			'objectId'   => $meetingId,
		];

		foreach (($recipients ?? $this->activeRecipients(meetingId: $meetingId)) as $uid) {
			try {
				$this->preferences->dispatch(
					personId: $uid,
					eventType: 'agendaChanged',
					title: $title,
					message: $message,
					deepLink: '/meetings/' . $meetingId,
					inApp: $inApp
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Failed to send agenda notification to user {userId}: {error}',
					['userId' => $uid, 'error' => $e->getMessage()]
				);
			}
		}
		}//end notifyParticipants()
}//end class
