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
	public const REJECTION_MESSAGE = SubmissionWindow::DEADLINE_PASSED_MESSAGE;

	/**
	 * The message for a meeting whose window opens after it closes.
	 *
	 * @var string
	 */
	public const INVERTED_WINDOW_MESSAGE = SubmissionWindow::INVERTED_WINDOW_MESSAGE;

	/**
	 * The window rules.
	 *
	 * @var SubmissionWindow
	 */
	private readonly SubmissionWindow $window;

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
		$this->window = new SubmissionWindow();
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
				if ($this->window->isInverted(meeting: $row) === true) {
					$event->setErrors(['message' => self::INVERTED_WINDOW_MESSAGE]);
					$event->stopPropagation();
				}

				return;
			}

			// Motions and amendments are only checked when they are created:
			// a motion submitted inside the window may be edited afterwards.
			if ($slug === 'decision' && $event instanceof ObjectCreatingEvent) {
				$this->checkSubmission(event: $event, row: $row);
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
	 * Refuse a motion or amendment created outside its meeting's window.
	 *
	 * @param ObjectCreatingEvent  $event The creating event
	 * @param array<string, mixed> $row   The decision payload being created
	 *
	 * @spec openspec/changes/motions-submission-window/specs/motion-amendment/spec.md#requirement-req-subw-002-a-motion-or-amendment-submitted-before-the-window-opens-is-refused
	 *
	 * @return void
	 */
	private function checkSubmission(ObjectCreatingEvent $event, array $row): void {
		// ADR-005: motions and amendments are `decision` objects; the
		// discriminator, not the schema slug, says which. Every other
		// decisionType (resolution, contract, policy, ...) carries no
		// submission window and is left alone.
		$decisionType = (string)($row['decisionType'] ?? '');
		if (in_array($decisionType, ['motion', 'amendment'], true) === false) {
			return;
		}

		$meetingId = $this->resolveMeetingId(decisionType: $decisionType, row: $row);
		if ($meetingId === null) {
			return;
		}

		$refusal = $this->window->refusal(window: $this->resolveSubmissionWindow(meetingId: $meetingId), now: time());
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();
		$this->logger->info(
			'Decidiq: rejected submission outside the window',
			['decisionType' => $decisionType, 'meetingId' => $meetingId]
		);
	}//end checkSubmission()

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
		$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($meetingEntity === null) {
			return ['opensAt' => null, 'deadline' => null];
		}

		return $this->window->fromMeeting(meeting: (array)$meetingEntity->jsonSerialize());
	}//end resolveSubmissionWindow()
}//end class
