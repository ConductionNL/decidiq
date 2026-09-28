<?php

/**
 * Decidiq Submission Deadline Listener
 *
 * Rejects motion/amendment creations outside the linked meeting's submission
 * window: after its deadline, or before it opens (motion-amendment spec,
 * Motion Submission requirement; change motions-submission-window).
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-amendment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Pre-save hook on OpenRegister's ObjectCreatingEvent: when a motion or
 * amendment is being created and its meeting carries a `submissionDeadline`
 * in the past, the creation is rejected (propagation stopped → the OR object
 * API returns HTTP 422 with the spec message). The same holds when the
 * meeting's `submissionOpensAt` lies in the future: the message then names the
 * opening time. On `meeting` creates and updates (ObjectCreatingEvent and
 * ObjectUpdatingEvent, read through getNewObject()) it refuses a window whose
 * opening is not before its deadline.
 *
 * Validation semantics (deliberate, documented in the change design):
 * the deadline gate is a submission RULE, not an auth guard — when no
 * meeting is linked or no deadline is configured, creation is allowed
 * (deadlines are opt-in). Infrastructure failures during lookups log a
 * warning and allow, so this listener can never break the OR write path
 * for unrelated objects. Chair/role authorization elsewhere stays
 * fail-closed.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class SubmissionDeadlineListener implements IEventListener {

	/**
	 * The spec rejection message returned to late submitters.
	 *
	 * @var string
	 */
	public const REJECTION_MESSAGE = 'The submission deadline for this meeting has passed; new motions and amendments can no longer be submitted.';

	/**
	 * The message for a submission before the window opens; `%s` is the opening time.
	 *
	 * @var string
	 */
	public const NOT_YET_OPEN_MESSAGE = 'Submission of motions and amendments for this meeting opens on %s.';

	/**
	 * The message for a meeting whose window opens after it closes.
	 *
	 * @var string
	 */
	public const INVERTED_WINDOW_MESSAGE = 'The submission window opens after it closes.';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle an OR object-creating event for motion/amendment schemas.
	 *
	 * @param Event $event The event to handle
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		try {
			$entity = $this->eventObject(event: $event);
			if (is_object($entity) === false) {
				return;
			}

			$row = $this->extractRow(entity: $entity);
			$slug = $this->resolveSchemaSlug(entity: $entity, row: $row);
			if ($slug === 'meeting') {
				$this->refuseInvertedWindow(event: $event, row: $row);
				return;
			}

			// Motions and amendments are only checked when they are created:
			// a motion submitted inside the window may be edited afterwards.
			if ($slug !== 'decision' || $event instanceof ObjectCreatingEvent === false) {
				return;
			}

			// ADR-005: motions and amendments are `decision` objects; the
			// discriminator — not the schema slug — says which. Every other
			// decisionType (resolution, contract, policy, …) carries no
			// submission deadline rule and is left alone.
			$decisionType = (string)($row['decisionType'] ?? '');
			if (in_array($decisionType, ['motion', 'amendment'], true) === false) {
				return;
			}

			$meetingId = $this->resolveMeetingId(decisionType: $decisionType, row: $row);
			if ($meetingId === null) {
				return;
			}

			$window = $this->resolveSubmissionWindow(meetingId: $meetingId);
			if ($window['opensAt'] !== null && $window['opensAt'] > time()) {
				$event->setErrors(
					[
						'message'           => sprintf(self::NOT_YET_OPEN_MESSAGE, date('j F Y H:i', $window['opensAt'])),
						'submissionOpensAt' => date(DATE_ATOM, $window['opensAt']),
					]
				);
				$event->stopPropagation();
				$this->logger->info(
					'Decidiq: rejected early submission',
					['schema' => $slug, 'decisionType' => $decisionType, 'meetingId' => $meetingId]
				);
				return;
			}

			$deadline = $window['deadline'];
			if ($deadline === null) {
				return;
			}

			if ($deadline < time()) {
				$event->setErrors(
					[
						'message' => self::REJECTION_MESSAGE,
						'submissionDeadline' => date(DATE_ATOM, $deadline),
					]
				);
				$event->stopPropagation();
				$this->logger->info(
					'Decidiq: rejected late submission',
					['schema' => $slug, 'decisionType' => $decisionType, 'meetingId' => $meetingId]
				);
			}
		} catch (\Throwable $e) {
			// Fail soft on infrastructure errors: the deadline rule must never
			// break the OR write path (deliberate — see class docblock).
			$this->logger->warning(
				'Decidiq: submission deadline listener failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * The object an event carries: getObject() on a creating event, the NEW
	 * object on an updating event (the real ObjectUpdatingEvent has no
	 * getObject()).
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused
	 *
	 * @return object|null The entity, or null
	 */
	private function eventObject(ObjectCreatingEvent|ObjectUpdatingEvent $event): ?object {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end eventObject()

	/**
	 * Refuse a meeting whose submission window opens at or after its deadline.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event
	 * @param array<string, mixed>                    $row   The meeting payload being saved
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-003-a-window-that-opens-after-it-closes-is-refused
	 *
	 * @return void
	 */
	private function refuseInvertedWindow(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $row): void {
		$opensAt = $this->parseTime(value: ($row['submissionOpensAt'] ?? null));
		$deadline = $this->parseTime(value: ($row['submissionDeadline'] ?? null));
		if ($opensAt === null || $deadline === null || $opensAt < $deadline) {
			return;
		}

		$event->setErrors(['message' => self::INVERTED_WINDOW_MESSAGE]);
		$event->stopPropagation();
	}//end refuseInvertedWindow()

	/**
	 * Parse a stored date-time into a unix timestamp.
	 *
	 * @param mixed $value The stored value
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-001-a-meeting-can-open-submission-at-a-set-time
	 *
	 * @return int|null The timestamp, or null when empty or unparseable
	 */
	private function parseTime(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return null;
		}

		return $timestamp;
	}//end parseTime()

	/**
	 * Extract the serialized payload from an OR object entity.
	 *
	 * Prefers `getObject()`; falls back to `jsonSerialize()` when the former
	 * is absent or yields nothing.
	 *
	 * @param object $entity OR object entity
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return array<string, mixed> Serialized payload, or [] when unavailable
	 */
	private function extractRow(object $entity): array {
		$row = [];
		if (method_exists($entity, 'getObject') === true) {
			$row = (array)$entity->getObject();
		}

		if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {
			$row = (array)$entity->jsonSerialize();
		}

		return $row;
	}//end extractRow()

	/**
	 * Resolve the schema slug from the canonical OR entity surface
	 * (same candidates as MeetingFolderListener).
	 *
	 * @param object $entity OR object entity
	 * @param array<string, mixed> $row Serialized payload
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return string Schema slug (lower-cased), or '' when unresolvable
	 */
	private function resolveSchemaSlug(object $entity, array $row): string {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			$candidate = ($row[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '') {
				return strtolower($candidate);
			}
		}

		// Same order as the row keys above; each getter is consulted only when
		// the entity actually exposes it.
		foreach (['getSchemaSlug', 'getSchema'] as $getter) {
			if (method_exists($entity, $getter) === false) {
				continue;
			}

			$value = $entity->{$getter}();
			if (is_string($value) === true && $value !== '') {
				return strtolower($value);
			}
		}

		return '';
	}//end resolveSchemaSlug()

	/**
	 * Resolve the meeting UUID governing this submission.
	 *
	 * Motions link to their meeting through the flat `meeting` property or a
	 * structured relations entry; amendments resolve through their parent
	 * motion (the ADR-005 `amends` relation that replaced `parentMotion`, or a
	 * relations entry against the unified decision schema).
	 *
	 * @param string $decisionType The ADR-005 discriminator ('motion' or 'amendment')
	 * @param array<string, mixed> $row Serialized payload of the object being created
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return string|null The meeting UUID, or null when unlinked
	 */
	private function resolveMeetingId(string $decisionType, array $row): ?string {
		if ($decisionType === 'motion') {
			return $this->extractReference(row: $row, property: 'meeting', relationSchema: 'meeting');
		}

		// Amendment: resolve parent motion first.
		$parentMotionId = $this->extractReference(
			row: $row,
			property: 'amends',
			relationSchema: 'decision'
		);
		if ($parentMotionId === null) {
			return null;
		}

		$motionEntity = $this->objectService->find(id: $parentMotionId, register: 'decidiq', schema: 'decision');
		if ($motionEntity === null) {
			return null;
		}

		$motion = (array)$motionEntity->jsonSerialize();
		return $this->extractReference(row: $motion, property: 'meeting', relationSchema: 'meeting');
	}//end resolveMeetingId()

	/**
	 * Extract a referenced object id from a flat property or relations entry.
	 *
	 * @param array<string, mixed> $row Serialized object payload
	 * @param string $property Flat property name (e.g. 'meeting', 'amends')
	 * @param string $relationSchema Relations schema slug to match
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return string|null The referenced id, or null
	 */
	private function extractReference(array $row, string $property, string $relationSchema): ?string {
		$ref = ($row[$property] ?? null);
		if (is_string($ref) === true && $ref !== '') {
			return $ref;
		}

		if (is_array($ref) === true) {
			$refId = ($ref['id'] ?? $ref['uuid'] ?? '');
			if ($refId !== '') {
				return (string)$refId;
			}
		}

		foreach (($row['relations'] ?? []) as $relation) {
			if (is_array($relation) === true && ($relation['schema'] ?? '') === $relationSchema) {
				$relId = ($relation['id'] ?? $relation['uuid'] ?? '');
				if ($relId !== '') {
					return (string)$relId;
				}
			}
		}

		return null;
	}//end extractReference()

	/**
	 * Resolve the meeting's submission window as unix timestamps, in one lookup.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @spec openspec/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused
	 *
	 * @return array{opensAt: int|null, deadline: int|null} Each null when unset, unparseable or the meeting is missing
	 */
	private function resolveSubmissionWindow(string $meetingId): array {
		$window = ['opensAt' => null, 'deadline' => null];
		$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($meetingEntity === null) {
			return $window;
		}

		$meeting = (array)$meetingEntity->jsonSerialize();
		foreach (['opensAt' => 'submissionOpensAt', 'deadline' => 'submissionDeadline'] as $key => $property) {
			$raw = ($meeting[$property] ?? null);
			$window[$key] = $this->parseTime(value: $raw);
			if ($window[$key] === null && is_string($raw) === true && $raw !== '') {
				$this->logger->warning(
					'Decidiq: unparseable submission window time on meeting',
					['meetingId' => $meetingId, $property => $raw]
				);
			}
		}

		return $window;
	}//end resolveSubmissionWindow()
}//end class
