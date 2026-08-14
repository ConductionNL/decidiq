<?php

/**
 * Decidesk Submission Deadline Listener
 *
 * Rejects motion/amendment creations after the linked meeting's submission
 * deadline (motion-amendment spec, Motion Submission requirement).
 *
 * @category Listener
 * @package  OCA\Decidesk\Listener
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

namespace OCA\Decidesk\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Pre-save hook on OpenRegister's ObjectCreatingEvent: when a motion or
 * amendment is being created and its meeting carries a `submissionDeadline`
 * in the past, the creation is rejected (propagation stopped → the OR object
 * API returns HTTP 422 with the spec message).
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
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy-loads ObjectService)
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectService $objectService,
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
		if ($event instanceof ObjectCreatingEvent === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			if (is_object($entity) === false) {
				return;
			}

			$row = $this->extractRow(entity: $entity);
			$slug = $this->resolveSchemaSlug(entity: $entity, row: $row);
			if ($slug !== 'decision') {
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

			$deadline = $this->resolveSubmissionDeadline(meetingId: $meetingId);
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
					'Decidesk: rejected late submission',
					['schema' => $slug, 'decisionType' => $decisionType, 'meetingId' => $meetingId]
				);
			}
		} catch (\Throwable $e) {
			// Fail soft on infrastructure errors: the deadline rule must never
			// break the OR write path (deliberate — see class docblock).
			$this->logger->warning(
				'Decidesk: submission deadline listener failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

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

		$motionEntity = $this->objectService->find(id: $parentMotionId, register: 'decidesk', schema: 'decision');
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
	 * Resolve the meeting's submissionDeadline as a unix timestamp.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return int|null Deadline timestamp, or null when unset/unparseable/meeting missing
	 */
	private function resolveSubmissionDeadline(string $meetingId): ?int {
		$meetingEntity = $this->objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
		if ($meetingEntity === null) {
			return null;
		}

		$meeting = (array)$meetingEntity->jsonSerialize();
		$deadline = ($meeting['submissionDeadline'] ?? null);
		if (is_string($deadline) === false || $deadline === '') {
			return null;
		}

		$timestamp = strtotime($deadline);
		if ($timestamp === false) {
			$this->logger->warning(
				'Decidesk: unparseable submissionDeadline on meeting',
				['meetingId' => $meetingId, 'submissionDeadline' => $deadline]
			);
			return null;
		}

		return $timestamp;
	}//end resolveSubmissionDeadline()
}//end class
