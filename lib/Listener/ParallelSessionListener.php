<?php

/**
 * Decidiq Parallel Session Listener
 *
 * Keeps an evening's parallel sessions in shape: a session is a meeting that
 * points at its evening through parentMeeting (change planning-parallel-sessions).
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pre-save hook on OpenRegister's ObjectCreatingEvent and ObjectUpdatingEvent
 * for meetings.
 *
 * A meeting with a parentMeeting is a session of that evening. The save is
 * refused (HTTP 422 with the message) when the meeting is its own parent, when
 * the evening is itself a session, when the meeting already has sessions of
 * its own, when the evening does not exist, or when the session starts before
 * the evening, starts after it, or ends after it. A new session takes the
 * evening's governanceBody, meetingMode and isPublic for each of them it was
 * created without; entered values are never overwritten. OpenRegister applies
 * the schema default (isPublic false) before this hook, so through the object
 * API isPublic arrives filled: the Add session action presets it from the
 * evening instead.
 *
 * Fails soft on infrastructure errors: a lookup that throws leaves the save alone.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
 *
 * @implements IEventListener<Event>
 */
class ParallelSessionListener implements IEventListener {

	public const NESTED_MESSAGE = 'A session cannot have sessions of its own.';

	public const OWN_PARENT_MESSAGE = 'A meeting cannot be a session of itself.';

	public const MISSING_PARENT_MESSAGE = 'The evening this session belongs to does not exist.';

	/**
	 * The fields a new session takes from its evening when left empty.
	 *
	 * @var string[]
	 */
	private const INHERITED = ['governanceBody', 'meetingMode', 'isPublic'];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface        $logger        Logger
	 * @param ObjectServiceInterface $objectService Reads the evening and its sessions
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Check the session against its evening, and fill a new session's defaults.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		try {
			$entity = $this->eventObject(event: $event);

			$meeting = (array)$entity?->getObject();
			$parentId = $this->referenceId(value: ($meeting['parentMeeting'] ?? null));
			if ($parentId === null || strtolower((string)($meeting['_schemaSlug'] ?? 'meeting')) !== 'meeting') {
				return;
			}

			$ownId = $this->referenceId(value: ($entity?->getUuid() ?? ($meeting['id'] ?? null)));
			$parent = $this->load(id: $parentId);
			$refusal = $this->refusal(meeting: $meeting, ownId: $ownId, parentId: $parentId, parent: $parent);
			if ($refusal !== null) {
				$event->setErrors(['message' => $refusal]);
				$event->stopPropagation();
				return;
			}

			if ($event instanceof ObjectCreatingEvent && $parent !== null) {
				$patch = $this->inherited(meeting: $meeting, filled: $event->getModifiedData(), parent: $parent);
				if ($patch !== []) {
					$event->setModifiedData(array_merge($event->getModifiedData(), $patch));
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: parallel session check skipped', ['exception' => $e->getMessage()]);
		}//end try

	}//end handle()

	/**
	 * The object an event carries: getObject() on a creating event, the NEW
	 * object on an updating event (the real ObjectUpdatingEvent has no getObject()).
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function eventObject(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?object {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();

	}//end eventObject()

	/**
	 * Why the session cannot be saved, or null when it can.
	 *
	 * @param array<string, mixed>      $meeting  The meeting as it will be saved
	 * @param string|null               $ownId    Its uuid (null while it is created)
	 * @param string                    $parentId The evening it points at
	 * @param array<string, mixed>|null $parent   The evening, or null when missing
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function refusal(array $meeting, ?string $ownId, string $parentId, ?array $parent): ?string {
		if ($ownId !== null && $ownId === $parentId) {
			return self::OWN_PARENT_MESSAGE;
		}

		if ($parent === null) {
			return self::MISSING_PARENT_MESSAGE;
		}

		if ($this->referenceId(value: ($parent['parentMeeting'] ?? null)) !== null || $this->hasSessions(meetingId: $ownId) === true) {
			return self::NESTED_MESSAGE;
		}

		return $this->outsideEvening(meeting: $meeting, parent: $parent);

	}//end refusal()

	/**
	 * The message for a session that falls outside its evening, or null.
	 *
	 * @param array<string, mixed> $meeting The session
	 * @param array<string, mixed> $parent  Its evening
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function outsideEvening(array $meeting, array $parent): ?string {
		$opens = $this->moment(value: ($parent['scheduledDate'] ?? null));
		$closes = $this->moment(value: ($parent['endDate'] ?? null));
		$start = $this->moment(value: ($meeting['scheduledDate'] ?? null));
		$end = $this->moment(value: ($meeting['endDate'] ?? null));
		if ($opens === null || $start === null) {
			return null;
		}

		$outside = $start < $opens
			|| ($closes !== null && ($start > $closes || ($end !== null && $end > $closes)));
		if ($outside === false) {
			return null;
		}

		$window = $opens->format('j F Y') . ' from ' . $opens->format('H:i');
		if ($closes !== null) {
			$window .= ' to ' . $closes->setTimezone($opens->getTimezone())->format('H:i');
		}

		return 'A session must take place within its evening, ' . $window . '.';

	}//end outsideEvening()

	/**
	 * The evening's values for the fields a new session was created without.
	 *
	 * @param array<string, mixed> $meeting The new session
	 * @param array<string, mixed> $filled  What earlier hooks already filled
	 * @param array<string, mixed> $parent  Its evening
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function inherited(array $meeting, array $filled, array $parent): array {
		$patch = [];
		foreach (self::INHERITED as $field) {
			$own = ($filled[$field] ?? ($meeting[$field] ?? null));
			$value = ($parent[$field] ?? null);
			if (($own === null || $own === '') && $value !== null && $value !== '') {
				$patch[$field] = $value;
			}
		}

		return $patch;

	}//end inherited()

	/**
	 * Whether a saved meeting already has sessions of its own.
	 *
	 * @param string|null $meetingId The meeting (null while it is created)
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function hasSessions(?string $meetingId): bool {
		if ($meetingId === null) {
			return false;
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => 'meeting', 'parentMeeting' => $meetingId], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);

		return $rows !== [];

	}//end hasSessions()

	/**
	 * Load the evening as an array, or null when it does not exist.
	 *
	 * @param string $id The evening's id
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function load(string $id): ?array {
		$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: 'meeting', _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		return (array)$entity->getObject();

	}//end load()

	/**
	 * A date-time value as a moment, or null when it is empty or unreadable.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}

	}//end moment()

	/**
	 * The id a reference holds: a uuid string or an expanded object.
	 *
	 * @param mixed $value The stored reference
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting
	 */
	private function referenceId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;

	}//end referenceId()
}//end class
