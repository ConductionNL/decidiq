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
 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Kernel\Contracts\AgendaServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing agenda lifecycle operations.
 *
 * Provides domain-specific business logic for:
 * - Publishing agendas and notifying participants
 * - Advancing BOB (Beeldvorming/Oordeelsvorming/Besluitvorming) phases
 * - Processing consent agenda items (hamerstukken)
 * - Atomically reordering agenda items
 *
 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1
 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
 */
class AgendaService implements AgendaServiceInterface {

	/**
	 * Tag identifying consent agenda items.
	 *
	 * @var string
	 */
	private const HAMERSTUK_TAG = 'hamerstuk';

	/**
	 * The outcome a formality records when the chair adopts the formalities.
	 *
	 * @var string
	 */
	private const FORMALITY_ADOPTED = 'adopted-without-debate';

	/**
	 * True while this service writes several agenda items as one change.
	 *
	 * @var boolean
	 */
	private bool $suppressItemNotices = false;

	/**
	 * Reads agenda objects and applies the phase, publication and version rules.
	 *
	 * @var AgendaRules
	 */
	private readonly AgendaRules $rules;

	/**
	 * Constructor for AgendaService.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param AgendaNotices $notices Sends the agenda notices to the members
	 * @param LoggerInterface $logger PSR-3 logger
	 * @param ParticipantResolver $participantResolver Canonical participant resolver
	 * @param PublicationEventRecorder $eventRecorder Records the publication for subscribers
	 * @param ShortenedNoticeService|null $shortenedNotice Checks an emergency convocation's shortened notice
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AgendaNotices $notices,
		private readonly LoggerInterface $logger,
		private readonly ParticipantResolver $participantResolver,
		private readonly PublicationEventRecorder $eventRecorder,
		private readonly ?ShortenedNoticeService $shortenedNotice=null,
	) {
		$this->rules   = new AgendaRules();
	}//end __construct()

	/**
	 * The agenda items of one meeting.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array The agenda items, as the object service returns them.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	private function meetingItems(string $meetingId): array {
		return $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'decidiq',
					'schema' => 'agenda-item',
					'_relations.meeting' => $meetingId,
				],
			]
		);
	}//end meetingItems()

	/**
	 * Publish an agenda for a meeting.
	 *
	 * Validates that at least one AgendaItem exists for the meeting, records
	 * the publication (agendaPublishedAt, agendaVersion and a snapshot in
	 * agendaVersions) and notifies all active participants. The Meeting
	 * lifecycle is left alone (#1396).
	 *
	 * @param string      $meetingId             UUID of the Meeting to publish
	 * @param string|null $shortenedNoticeReason Null for a regular convocation; a string (empty for the
	 *                                           urgency reason) for an emergency convocation at shortened notice
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no agenda items exist for the meeting, or the shortened notice is refused
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function publishAgenda(string $meetingId, ?string $shortenedNoticeReason=null): void {
		$items = $this->publishableItems(meetingId: $meetingId);

		// #315: Read the full meeting object before saving so that a partial payload cannot
		// silently wipe required fields that are not included in the update.
		$meeting   = $this->readMeeting(meetingId: $meetingId);
		$deviation = [];
		if ($shortenedNoticeReason !== null) {
			// Checked before anything is saved or sent: a refused deviation records nothing.
			if ($this->shortenedNotice === null) {
				throw new InvalidArgumentException('A shortened notice is not available.');
			}

			$deviation = $this->shortenedNotice->deviationFor(meetingId: $meetingId, meeting: $meeting, reason: $shortenedNoticeReason);
		}

		$publication = ['meetingId' => $meetingId, 'meeting' => $meeting, 'items' => $items, 'deviation' => $deviation];
		$this->savePublication(publication: $publication);
		$this->announcePublication(publication: $publication);
		$this->logger->info('Agenda published for meeting {meetingId}', ['meetingId' => $meetingId]);

	}//end publishAgenda()

	/**
	 * The agenda items of a meeting about to be published; at least one must exist.
	 *
	 * @param string $meetingId UUID of the Meeting
	 *
	 * @return array The agenda items, as the object service returns them.
	 *
	 * @throws InvalidArgumentException When no agenda items exist for the meeting
	 */
	private function publishableItems(string $meetingId): array {
		$items = $this->meetingItems(meetingId: $meetingId);
		if (empty($items) === true) {
			throw new InvalidArgumentException('Cannot publish agenda: no agenda items exist for this meeting.');
		}

		return $items;
	}//end publishableItems()

	/**
	 * Save the meeting with its next agenda version and the publication fields.
	 *
	 * @param array<string, mixed> $publication The meetingId, the full meeting and its agenda items
	 *
	 * @return void
	 */
	private function savePublication(array $publication): void {
		$meetingData = $publication['meeting'];
		$this->saveMeeting(
			meetingId: $publication['meetingId'],
			meeting: array_merge(
				$this->rules->withNewAgendaVersion(meetingData: $meetingData, items: $publication['items']),
				$this->rules->publicationChanges(meetingData: $meetingData),
				($publication['deviation'] ?? [])
			)
		);
	}//end savePublication()

	/**
	 * Notify the active participants of a publication and record it for subscribers.
	 *
	 * @param array<string, mixed> $publication The meetingId, the full meeting and its agenda items
	 *
	 * @return void
	 */
	private function announcePublication(array $publication): void {
		$meetingId = $publication['meetingId'];
		$subject   = $this->rules->publicationSubject(meetingData: $publication['meeting']);
		$this->notices->send(
			notice: ['meetingId' => $meetingId, 'meeting' => $publication['meeting'], 'subject' => $subject, 'items' => $publication['items']],
			recipients: fn (): array => $this->activeRecipients(meetingId: $meetingId)
		);
		$this->eventRecorder->agendaPublished(meetingId: $meetingId, meeting: $publication['meeting'], revised: ($subject === 'agenda_revised'));
	}//end announcePublication()

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
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function advanceBobPhase(string $agendaItemId): void {
		$item = $this->objectService->find($agendaItemId);
		if ($item === null) {
			throw new NotFoundException(message: "AgendaItem {$agendaItemId} not found.");
		}

		[$currentStatus, $nextStatus] = $this->rules->bobTransition(itemData: $this->rules->toArray(item: $item));

		// Uses patchObject, not saveObject: a uuid-bearing save is a FULL REPLACE
		// that OpenRegister validates whole, so a status-only payload 400s on
		// the required title, itemType and orderNumber it omits.
		$this->patchItem(itemId: $agendaItemId, data: ['status' => $nextStatus]);

		$this->logger->info(
			'BOB phase advanced for agenda item {id}: {from} to {to}',
			['id' => $agendaItemId, 'from' => $currentStatus, 'to' => $nextStatus]
		);

	}//end advanceBobPhase()

	/**
	 * Patch fields of one agenda item.
	 *
	 * @param string               $itemId UUID of the agenda item
	 * @param array<string, mixed> $data   The fields to set
	 *
	 * @return void
	 */
	private function patchItem(string $itemId, array $data): void {
		$this->objectService->patchObject(
			objectId: $itemId,
			data: $data,
			register: 'decidiq',
			schema: 'agenda-item',
		);
	}//end patchItem()

	/**
	 * Adopt the formalities (hamerstukken) of a meeting together.
	 *
	 * Every agenda item marked `isFormality` (or still carrying the older
	 * `hamerstuk` tag) that is not adopted yet records `formalityOutcome:
	 * adopted-without-debate` and `adoptedAt`, the fields register fragment 98
	 * declares. It used to write `status: completed`, which AgendaItem does not
	 * declare.
	 *
	 * @param string $meetingId UUID of the Meeting
	 *
	 * @return integer The number of formalities adopted.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 */
	public function processHamerstukken(string $meetingId): int {
		$processedCount = $this->adoptFormalities(items: $this->meetingItems(meetingId: $meetingId));

		$this->logger->info(
			'Adopted {count} formalities for meeting {meetingId}',
			['count' => $processedCount, 'meetingId' => $meetingId]
		);

		return $processedCount;
	}//end processHamerstukken()

	/**
	 * Adopt every pending formality among the items.
	 *
	 * @param iterable<mixed> $items The meeting's agenda items
	 *
	 * @return integer The number of formalities adopted.
	 */
	private function adoptFormalities(iterable $items): int {
		$adoptedAt = gmdate('Y-m-d\TH:i:s\Z');
		$processedCount = 0;
		foreach ($items as $item) {
			$itemData = $this->rules->toArray(item: $item);
			$itemId = $this->rules->itemIdOf(itemData: $itemData);
			if ($itemId === null || $this->isPendingFormality(item: $itemData) === false) {
				continue;
			}

			// Uses patchObject, not saveObject: see advanceBobPhase() — a partial
			// payload under save's full-replace validation 400s per item.
			$this->patchItem(itemId: (string)$itemId, data: ['formalityOutcome' => self::FORMALITY_ADOPTED, 'adoptedAt' => $adoptedAt]);
			$processedCount++;
		}

		return $processedCount;
	}//end adoptFormalities()

	/**
	 * Mark an agenda item of a meeting as a formality, or take the mark off.
	 *
	 * @param string $meetingId UUID of the Meeting the item must belong to
	 * @param string $itemId UUID of the agenda item
	 * @param bool $isFormality Whether it is a formality
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the item is not on this meeting, or was already adopted as a formality.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 */
	public function setFormality(string $meetingId, string $itemId, bool $isFormality): void {
		$entity = $this->objectService->find(id: $itemId, register: 'decidiq', schema: 'agenda-item');
		$item = [];
		if ($entity !== null) {
			$item = $this->rules->toArray(item: $entity);
		}

		if ((string)($item['meeting'] ?? '') !== $meetingId) {
			throw new InvalidArgumentException('This agenda item is not on this meeting.');
		}

		if (($item['formalityOutcome'] ?? null) === self::FORMALITY_ADOPTED) {
			throw new InvalidArgumentException('This formality was already adopted.');
		}

		$this->patchItem(itemId: $itemId, data: ['isFormality' => $isFormality]);
	}//end setFormality()

	/**
	 * Whether an item is a formality that has not been adopted yet.
	 *
	 * @param array<string, mixed> $item The agenda item
	 *
	 * @return bool
	 */
	private function isPendingFormality(array $item): bool {
		if (($item['formalityOutcome'] ?? null) === self::FORMALITY_ADOPTED) {
			return false;
		}

		if (($item['isFormality'] ?? false) === true) {
			return true;
		}

		$tags = ($item['tags'] ?? ($item['@self']['tags'] ?? []));
		return in_array(needle: self::HAMERSTUK_TAG, haystack: (array)$tags, strict: true);
	}//end isPendingFormality()

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
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function reviseAgenda(string $meetingId): void {
		// #315: Read the full meeting object before saving to avoid wiping required fields.
		$meetingData = $this->readMeeting(meetingId: $meetingId);

		// The lifecycle is NOT touched: a meeting that is running stays
		// running while its agenda is revised (#1396).
		$this->saveMeeting(meetingId: $meetingId, meeting: array_merge($meetingData, ['agendaUnderRevision' => true]));

		if (($meetingData['agendaPublishedAt'] ?? null) !== null) {
			$this->notices->send(
				notice: ['meetingId' => $meetingId, 'meeting' => $meetingData, 'subject' => 'agenda_revision_started'],
				recipients: fn (): array => $this->activeRecipients(meetingId: $meetingId)
			);
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
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function reorderItems(string $meetingId, array $orderedIds): void {
		$this->writeOrder(meetingId: $meetingId, orderedIds: $orderedIds);
		$this->notifyAgendaChanged(meetingId: $meetingId);

		$this->logger->info(
			'Reordered {count} agenda items for meeting {meetingId}',
			['count' => count($orderedIds), 'meetingId' => $meetingId]
		);

	}//end reorderItems()

	/**
	 * Give the meeting's items in the order asked orderNumber 1..n; ids that
	 * are not on this meeting are skipped.
	 *
	 * @param string   $meetingId  UUID of the Meeting
	 * @param string[] $orderedIds Ordered array of AgendaItem UUIDs
	 *
	 * @return void
	 */
	private function writeOrder(string $meetingId, array $orderedIds): void {
		$validIds = $this->itemIdSet(items: $this->meetingItems(meetingId: $meetingId));
		$orderNumber = 1;
		// One notice for the whole reorder, not one per patched item: the
		// item listener checks isSuppressingItemNotices() (#1396).
		$this->suppressItemNotices = true;
		foreach ($orderedIds as $itemId) {
			if (isset($validIds[(string)$itemId]) === false) {
				$this->logger->warning('reorderItems: UUID {id} does not belong to meeting {meetingId} — skipped', ['id' => $itemId, 'meetingId' => $meetingId]);
				continue;
			}

			$this->patchItem(itemId: (string)$itemId, data: ['orderNumber' => $orderNumber]);
			$orderNumber++;
		}

		$this->suppressItemNotices = false;
	}//end writeOrder()

	/**
	 * The ids of the items, as a set.
	 *
	 * @param iterable<mixed> $items The meeting's agenda items
	 *
	 * @return array<string, true>
	 */
	private function itemIdSet(iterable $items): array {
		$validIds = [];
		foreach ($items as $item) {
			$itemId = $this->rules->itemIdOf(itemData: $this->rules->toArray(item: $item));
			if ($itemId !== null) {
				$validIds[(string)$itemId] = true;
			}
		}

		return $validIds;
	}//end itemIdSet()

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
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function notifyAgendaChanged(string $meetingId): void {
		$meetingData = $this->liveAgendaMeeting(meetingId: $meetingId);
		if ($meetingData === null) {
			return;
		}

		$items = $this->meetingItems(meetingId: $meetingId);

		// REQ-ACN-004: every change is a version, but a member hears about a
		// burst of edits once. Who is due is decided before the save, so the
		// new notice times go out with the version in one write.
		[$due, $sentAt] = $this->dueRecipients(meetingId: $meetingId, sentAt: (array)($meetingData['agendaNoticeSentAt'] ?? []));

		$versioned = $this->rules->withNewAgendaVersion(meetingData: $meetingData, items: $items);
		$this->saveMeeting(meetingId: $meetingId, meeting: array_merge($versioned, ['agendaNoticeSentAt' => $sentAt]));
		$this->notices->send(
			notice: ['meetingId' => $meetingId, 'meeting' => $meetingData, 'subject' => 'agenda_changed'],
			recipients: fn (): array => $due
		);

		$this->eventRecorder->agendaChanged(meetingId: $meetingId, meeting: $meetingData, versions: $versioned['agendaVersions']);

	}//end notifyAgendaChanged()

	/**
	 * The meeting, when its agenda is published and not under revision.
	 *
	 * @param string $meetingId UUID of the Meeting
	 *
	 * @return array<string, mixed>|null The meeting, or null when it is missing or its agenda is not live
	 */
	private function liveAgendaMeeting(string $meetingId): ?array {
		$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($meetingEntity === null) {
			return null;
		}

		$meetingData = $this->rules->toArray(item: $meetingEntity);
		if (($meetingData['agendaPublishedAt'] ?? null) === null || ($meetingData['agendaUnderRevision'] ?? false) === true) {
			return null;
		}

		return $meetingData;
	}//end liveAgendaMeeting()

	/**
	 * The active recipients not noticed within the notice window, and the
	 * notice times with theirs set to now.
	 *
	 * @param string                $meetingId UUID of the Meeting
	 * @param array<string, string> $sentAt    When each user was last noticed
	 *
	 * @return array{0: list<string>, 1: array<string, string>} [due, sentAt]
	 */
	private function dueRecipients(string $meetingId, array $sentAt): array {
		$due = [];
		foreach ($this->activeRecipients(meetingId: $meetingId) as $uid) {
			if ($this->rules->noticedRecently(sentAt: ($sentAt[$uid] ?? '')) === true) {
				continue;
			}

			$due[] = $uid;
			$sentAt[$uid] = date(DATE_ATOM);
		}

		return [$due, $sentAt];
	}//end dueRecipients()

	/**
	 * Whether this service is writing several agenda items as one change.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
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

		return $this->rules->toArray(item: $meetingEntity);
	}//end readMeeting()

	/**
	 * Save a meeting as a full-object merge (#315).
	 *
	 * @param string               $meetingId UUID of the Meeting
	 * @param array<string, mixed> $meeting   The full meeting with its changes set over it
	 *
	 * @return void
	 */
	private function saveMeeting(string $meetingId, array $meeting): void {
		$this->objectService->saveObject(
			object: $meeting,
			register: 'decidiq',
			schema: 'meeting',
			uuid: $meetingId,
		);
	}//end saveMeeting()

	/**
	 * The Nextcloud users of the meeting's active participants: the linked
	 * `nextcloudUserId`, or `owner` for records made before that field, as
	 * ParticipantResolver::hasRole() reads them. Participants who left are skipped.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<int, string> Unique user ids
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 */
	private function activeRecipients(string $meetingId): array {
		// Participants come from the canonical path (participant -> governance-body
		// -> meeting; participants carry no direct meeting relation).
		$uids = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $participant) {
			$participantData = $this->rules->toArray(item: $participant);
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
}//end class
