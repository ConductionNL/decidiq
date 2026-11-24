<?php

/**
 * Decidiq MeetingRequestedEvent
 *
 * Public cross-app command event a consumer fleet app dispatches to ask Decidiq
 * to hold a Meeting for one of its own objects (a case session with parties, a
 * site visit). Per ADR-041 a cross-app COMMAND travels as a typed event, not as
 * REST. Dispatched via Nextcloud's IEventDispatcher and handled synchronously
 * by MeetingRequestedListener, so the producer reads the meeting id back off
 * the same instance right after dispatch.
 *
 * @category Event
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app command event: a consumer app asks Decidiq to hold a Meeting for its object.
 *
 * Three arguments: the subject, the meeting and the actor. The answer is
 * written by Decidiq's listener through answer() and read by the producer
 * right after the synchronous dispatch.
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 */
class MeetingRequestedEvent extends Event {

	/**
	 * Decidiq's answer: the meeting id and whether this command minted it.
	 *
	 * Null until the listener answers, so "not handled" needs no flag of its own.
	 *
	 * @var array{meetingId: string, created: bool}|null
	 */
	private ?array $answer = null;

	/**
	 * Construct the command event.
	 *
	 * @param array{register?: string, schema: string, id: string} $subject The producer's object the meeting
	 *                                                                      belongs to; schema and id are the key
	 *                                                                      a repeat resolves on.
	 * @param array<string, mixed>                                 $meeting The meeting: title and scheduledDate,
	 *                                                                      plus any of endDate, location,
	 *                                                                      meetingType, meetingMode,
	 *                                                                      governanceBody, virtualLocation, isPublic.
	 * @param string                                               $actorId Nextcloud UID the command runs for.
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function __construct(
		private readonly array $subject,
		private readonly array $meeting,
		private readonly string $actorId = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The producer's object, as given.
	 *
	 * @return array<string, mixed> Register, schema and id of the object
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getSubject(): array {
		return $this->subject;

	}//end getSubject()

	/**
	 * The commanded meeting fields, as given.
	 *
	 * @return array<string, mixed> Title, start and further fields
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getMeeting(): array {
		return $this->meeting;

	}//end getMeeting()

	/**
	 * The Nextcloud UID the command runs for.
	 *
	 * @return string The UID, or '' for the session user
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getActorId(): string {
		return $this->actorId;

	}//end getActorId()

	/**
	 * Answer the command: the meeting Decidiq holds for the subject.
	 *
	 * @param string $meetingId The meeting created or matched
	 * @param bool   $created   True when this command minted it
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function answer(string $meetingId, bool $created): void {
		$this->answer = ['meetingId' => $meetingId, 'created' => $created];

	}//end answer()

	/**
	 * Whether Decidiq answered the command.
	 *
	 * @return bool True once answered
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function isHandled(): bool {
		return $this->answer !== null;

	}//end isHandled()

	/**
	 * The meeting id Decidiq answered.
	 *
	 * @return string The id, or '' while unanswered
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getMeetingId(): string {
		return ($this->answer['meetingId'] ?? '');

	}//end getMeetingId()

	/**
	 * Whether the answered meeting was minted by this command.
	 *
	 * @return bool False when an existing meeting matched, or while unanswered
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function isCreated(): bool {
		return ($this->answer['created'] ?? false);

	}//end isCreated()

}//end class
