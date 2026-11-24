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
 * The request fields are immutable. The result slots (meetingId, created,
 * handled) are written by Decidiq's listener and read by the producer right
 * after the synchronous dispatch, the pattern GovernanceBodyRequestedEvent uses.
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 */
class MeetingRequestedEvent extends Event {

	/**
	 * The id of the Meeting Decidiq created or matched (result slot).
	 *
	 * @var string
	 */
	private string $meetingId = '';

	/**
	 * Whether the meeting was newly created (false when an existing one matched).
	 *
	 * @var boolean
	 */
	private bool $created = false;

	/**
	 * Whether Decidiq's listener handled this command (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the command event.
	 *
	 * @param string               $subjectRegister Register of the producer's object
	 * @param string               $subjectSchema   Schema of the producer's object
	 * @param string               $subjectId       Uuid of the producer's object; with the schema the key a repeat resolves on
	 * @param string               $title           Meeting title
	 * @param string               $scheduledDate   ISO-8601 start
	 * @param array<string, mixed> $attributes      Further meeting fields (endDate, location, meetingType, meetingMode, governanceBody, virtualLocation, isPublic)
	 * @param string               $actorId         Nextcloud UID on whose behalf the command runs
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A PUBLISHED CROSS-APP
	 * CONTRACT built positionally through a class-string, so the consumer stays
	 * installable without Decidiq (see GovernanceBodyRequestedEvent).
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function __construct(
		private readonly string $subjectRegister,
		private readonly string $subjectSchema,
		private readonly string $subjectId,
		private readonly string $title,
		private readonly string $scheduledDate,
		private readonly array $attributes = [],
		private readonly string $actorId = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the register of the producer's object.
	 *
	 * @return string The register
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getSubjectRegister(): string {
		return $this->subjectRegister;

	}//end getSubjectRegister()

	/**
	 * Get the schema of the producer's object.
	 *
	 * @return string The schema
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;

	}//end getSubjectSchema()

	/**
	 * Get the uuid of the producer's object.
	 *
	 * @return string The uuid
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getSubjectId(): string {
		return $this->subjectId;

	}//end getSubjectId()

	/**
	 * Get the meeting title.
	 *
	 * @return string The title
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getTitle(): string {
		return $this->title;

	}//end getTitle()

	/**
	 * Get the start.
	 *
	 * @return string ISO-8601 start
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getScheduledDate(): string {
		return $this->scheduledDate;

	}//end getScheduledDate()

	/**
	 * Get the further meeting fields.
	 *
	 * @return array<string, mixed> The attribute map
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getAttributes(): array {
		return $this->attributes;

	}//end getAttributes()

	/**
	 * Get the acting Nextcloud UID.
	 *
	 * @return string The actor id
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getActorId(): string {
		return $this->actorId;

	}//end getActorId()

	/**
	 * Get the resolved Meeting id.
	 *
	 * @return string The id, or an empty string when unhandled
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function getMeetingId(): string {
		return $this->meetingId;

	}//end getMeetingId()

	/**
	 * Record the resolved Meeting id (result slot).
	 *
	 * @param string $meetingId The id Decidiq created or matched
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function setMeetingId(string $meetingId): void {
		$this->meetingId = $meetingId;

	}//end setMeetingId()

	/**
	 * Whether the meeting was newly created rather than matched.
	 *
	 * @return boolean The created flag
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function isCreated(): bool {
		return $this->created;

	}//end isCreated()

	/**
	 * Record whether the meeting was newly created (result slot).
	 *
	 * @param boolean $created True when this command minted the meeting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function setCreated(bool $created): void {
		$this->created = $created;

	}//end setCreated()

	/**
	 * Whether Decidiq handled the command.
	 *
	 * @return boolean The handled flag
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function isHandled(): bool {
		return $this->handled;

	}//end isHandled()

	/**
	 * Record that Decidiq handled the command (result slot).
	 *
	 * @param boolean $handled True when the command was applied
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;

	}//end setHandled()

}//end class
