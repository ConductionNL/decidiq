<?php

/**
 * Contract of AgendaService
 *
 * The public surface of AgendaService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Decidiq\Exception\NotFoundException;

/**
 * Contract of AgendaService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgendaServiceInterface {

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
	public function advanceBobPhase(string $agendaItemId): void;

	/**
	 * Whether this service is writing several agenda items as one change.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 */
	public function isSuppressingItemNotices(): bool;

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
	public function notifyAgendaChanged(string $meetingId): void;

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
	public function processHamerstukken(string $meetingId): int;

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
	 *                                           urgency reason) to send an emergency convocation at shortened notice
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no agenda items exist for the meeting, or the shortened notice is refused
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-agenda-management/tasks.md#task-1.1
	 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
	 */
	public function publishAgenda(string $meetingId, ?string $shortenedNoticeReason=null): void;

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
	public function reorderItems(string $meetingId, array $orderedIds): void;

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
	public function reviseAgenda(string $meetingId): void;

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
	public function setFormality(string $meetingId, string $itemId, bool $isFormality): void;
}//end interface
