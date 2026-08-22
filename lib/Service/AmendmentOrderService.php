<?php

/**
 * Decidiq Amendment Order Service
 *
 * The parliamentary amendment-before-motion ordering rules (fail closed) and
 * the relation walks those rules share: an amendment's parent motion and the
 * meeting a voting round ultimately belongs to.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Ordering rules and subject resolution for amendment voting.
 *
 * Amendments sort by votingOrder ascending (set by the chair via
 * MotionService::setAmendmentVotingOrder), with unordered amendments after
 * ordered ones, oldest submittedAt first, id as the final deterministic
 * tiebreaker. The first undecided amendment in that order is the only one a
 * round may be opened on.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class AmendmentOrderService {
	/**
	 * Lifecycle states that still block the main motion from being voted.
	 *
	 * ADR-005 vocabulary. Both of these lists were written in the retired
	 * Motion vocabulary, and both were therefore broken — in OPPOSITE
	 * directions, which is why neither showed up as an obvious failure:
	 *
	 * - UNDECIDED_STATES held `submitted | debating | voting`. Two of those
	 *   three are not values `Decision.lifecycle` can hold, so a real amendment
	 *   could only ever match `voting`. assertAmendmentsDecided() had quietly
	 *   become close to a no-op — it FAILED OPEN, letting a motion be voted
	 *   while its amendments were still in flight, which is the exact thing it
	 *   exists to prevent.
	 * - DECIDED_STATES held `adopted | rejected`, which under ADR-005 are
	 *   values of `outcome`, never of `lifecycle`. No real amendment could
	 *   match it, so the ordering check FAILED CLOSED and would refuse every
	 *   sibling regardless of how it had been decided.
	 *
	 * The two lists PARTITION the `Decision.lifecycle` enum: every state is in
	 * exactly one of them, and DecisionLifecycleVocabularyTest asserts that
	 * against the enum read out of the SHIPPED register, so a future state added
	 * to the schema cannot silently fall through either check.
	 *
	 * @var string[]
	 */
	private const UNDECIDED_STATES = ['draft', 'proposed', 'deliberating', 'voting'];

	/**
	 * Lifecycle states that count as decided for ordering purposes.
	 *
	 * `withdrawn` belongs here, not in UNDECIDED_STATES: a withdrawn amendment
	 * is off the table and must not block its parent motion, even though it was
	 * never voted on. See the note on UNDECIDED_STATES above.
	 *
	 * @var string[]
	 */
	private const DECIDED_STATES = ['decided', 'enacted', 'archived', 'withdrawn'];

	/**
	 * Constructor for AmendmentOrderService.
	 *
	 * @param MotionService $motionService The motion service (amendment lookup)
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function __construct(
		private readonly MotionService $motionService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Enforce the parliamentary amendment-before-motion ordering (fail closed).
	 *
	 * For a MOTION round: every amendment of the motion must already be decided
	 * (ADR-005: lifecycle `decided`/`enacted`/`archived`, or `withdrawn` — the
	 * result itself lives on the separate `outcome` axis) — amendments are voted
	 * before the main motion.
	 *
	 * For an AMENDMENT round: the requested amendment must be the next one in
	 * the configured order.
	 *
	 * @param string $subjectId The motion UUID, or the amendment UUID for amendment rounds
	 * @param string $subjectType 'motion' or 'amendment' (validated by the caller)
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the ordering rule is violated or the amendment cannot be resolved
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function assertOrdering(string $subjectId, string $subjectType): void {
		if ($subjectType === 'motion') {
			$this->assertAmendmentsDecided(motionId: $subjectId);
			return;
		}

		$this->assertAmendmentIsNext(amendmentId: $subjectId);

	}//end assertOrdering()

	/**
	 * Refuse a motion round while any of its amendments is still undecided.
	 *
	 * @param string $motionId The motion UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When one or more amendments are still undecided
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function assertAmendmentsDecided(string $motionId): void {
		$pending = [];
		foreach ($this->motionService->getAmendmentsForMotion(motionId: $motionId) as $amendment) {
			if (in_array(($amendment['lifecycle'] ?? ''), self::UNDECIDED_STATES, true) === true) {
				$pending[] = (string)($amendment['title'] ?? $amendment['id'] ?? 'amendment');
			}
		}

		if ($pending === []) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'Cannot open a voting round on the motion: %d amendment(s) must be decided first '
				. '(amendments are voted before the main motion): %s',
				count($pending),
				implode(', ', $pending)
			)
		);

	}//end assertAmendmentsDecided()

	/**
	 * Refuse an amendment round that is out of the chair-configured order.
	 *
	 * @param string $amendmentId The amendment UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the amendment is unknown, out of order, or already decided
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function assertAmendmentIsNext(string $amendmentId): void {
		// ADR-005: amendments are `decision` objects; `decisionType` carries the
		// identity the retired `amendment` schema used to carry.
		$amendmentEntity = $this->objectService()->find(id: $amendmentId, register: 'decidesk', schema: 'decision');
		$amendment = ($amendmentEntity?->jsonSerialize() ?? []);

		// A missing object and a decision of the wrong type are the same answer:
		// this id is not an amendment. An absent object has no discriminator, so
		// the single check covers both.
		if (($amendment['decisionType'] ?? null) !== 'amendment') {
			throw new RuntimeException("Amendment {$amendmentId} not found");
		}

		$parentMotionId = $this->resolveParentMotionId(amendment: $amendment);
		if ($parentMotionId === null) {
			// No parent motion — nothing to order against; a standalone amendment
			// (data-migration artifact) may be voted directly.
			return;
		}

		$siblings = $this->orderedSiblings(
			parentMotionId: $parentMotionId,
			amendment: $amendment,
			amendmentId: $amendmentId
		);

		foreach ($siblings as $sibling) {
			if (in_array(($sibling['lifecycle'] ?? ''), self::DECIDED_STATES, true) === true) {
				continue;
			}

			$nextId = (string)($sibling['id'] ?? $sibling['uuid'] ?? '');
			if ($nextId === $amendmentId) {
				return;
			}

			throw new RuntimeException(
				sprintf(
					"Amendment voting order violated: '%s' must be voted first (the chair-configured order is enforced, most far-reaching first)",
					(string)($sibling['title'] ?? $nextId)
				)
			);
		}

		// All siblings decided yet this one undecided was not encountered —
		// only possible when the requested amendment is itself already decided.
		throw new RuntimeException("Amendment {$amendmentId} has already been decided");
	}//end assertAmendmentIsNext()

	/**
	 * The amendment's siblings in the configured voting order, with the
	 * amendment itself guaranteed to be part of the comparison set even when
	 * its link shape is unusual.
	 *
	 * @param string $parentMotionId The parent motion UUID
	 * @param array<string,mixed> $amendment The amendment being opened
	 * @param string $amendmentId The amendment UUID
	 *
	 * @return array<int, array<string,mixed>> The ordered sibling amendments
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function orderedSiblings(string $parentMotionId, array $amendment, string $amendmentId): array {
		$siblings = $this->motionService->getAmendmentsForMotion(motionId: $parentMotionId);

		$present = false;
		foreach ($siblings as $sibling) {
			if ((string)($sibling['id'] ?? $sibling['uuid'] ?? '') === $amendmentId) {
				$present = true;
				break;
			}
		}

		if ($present === false) {
			$amendment['id'] = ($amendment['id'] ?? $amendment['uuid'] ?? $amendmentId);
			$siblings[] = $amendment;
		}

		usort($siblings, $this->compareOrder(...));

		return $siblings;
	}//end orderedSiblings()

	/**
	 * Comparator for the chair-configured amendment order.
	 *
	 * @param array<string,mixed> $a First amendment
	 * @param array<string,mixed> $b Second amendment
	 *
	 * @return int The comparison result
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function compareOrder(array $a, array $b): int {
		$rankA = $this->orderRank(amendment: $a);
		$rankB = $this->orderRank(amendment: $b);
		if ($rankA !== $rankB) {
			return ($rankA <=> $rankB);
		}

		$submittedA = (string)($a['submittedAt'] ?? '');
		$submittedB = (string)($b['submittedAt'] ?? '');
		if ($submittedA !== $submittedB) {
			return ($submittedA <=> $submittedB);
		}

		return ((string)($a['id'] ?? '') <=> (string)($b['id'] ?? ''));
	}//end compareOrder()

	/**
	 * The chair-set voting order of an amendment; unordered amendments sort last.
	 *
	 * @param array<string,mixed> $amendment The amendment
	 *
	 * @return int The sort rank
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function orderRank(array $amendment): int {
		$order = ($amendment['votingOrder'] ?? null);
		if (is_numeric($order) === true) {
			return (int)$order;
		}

		return PHP_INT_MAX;
	}//end orderRank()

	/**
	 * Resolve the parent motion UUID from a serialized amendment.
	 *
	 * Honours the flat `amends` property (string or {id} object) and the
	 * structured `relations` list with schema 'motion'.
	 *
	 * ADR-005 replaced the retired Amendment schema's `parentMotion` property
	 * with the `amends` relation declared on `Decision`.
	 *
	 * @param array<string,mixed> $amendment Serialized amendment object
	 *
	 * @return string|null The parent motion UUID, or null when unlinked
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function resolveParentMotionId(array $amendment): ?string {
		$direct = $this->refId(ref: ($amendment['amends'] ?? null));
		if ($direct !== '') {
			return $direct;
		}

		return $this->relationId(relations: ($amendment['relations'] ?? []), schema: 'motion');
	}//end resolveParentMotionId()

	/**
	 * Resolve the meeting UUID that owns a voting round.
	 *
	 * Walks round → motion → meeting for motion rounds, and
	 * round → amendment → parent motion → meeting for amendment rounds, so the
	 * membership and count-validation guards apply to both round types.
	 *
	 * @param array<string,mixed> $round Serialized voting round
	 *
	 * @return string|null The meeting UUID, or null when unresolvable
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function resolveMeetingIdForRound(array $round): ?string {
		$motionId = $this->motionIdForRound(round: $round);
		if ($motionId === null) {
			return null;
		}

		$motionEntity = $this->objectService()->find(id: $motionId, register: 'decidesk', schema: 'decision');
		if ($motionEntity === null) {
			return null;
		}

		return $this->meetingIdFromMotion(motion: $motionEntity->jsonSerialize());
	}//end resolveMeetingIdForRound()

	/**
	 * The motion a voting round votes on, following an amendment round up to
	 * its parent motion.
	 *
	 * @param array<string,mixed> $round Serialized voting round
	 *
	 * @return string|null The motion UUID, or null when unresolvable
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function motionIdForRound(array $round): ?string {
		foreach (($round['relations'] ?? []) as $roundRel) {
			$relSchema = ($roundRel['schema'] ?? '');
			if ($relSchema === 'motion') {
				return $this->stringOrNull(value: ($roundRel['id'] ?? null));
			}

			if ($relSchema === 'amendment') {
				return $this->parentMotionOf(amendmentId: ($roundRel['id'] ?? null));
			}
		}

		return null;
	}//end motionIdForRound()

	/**
	 * The parent motion of an amendment referenced by a voting round.
	 *
	 * @param mixed $amendmentId The amendment identifier from the relation
	 *
	 * @return string|null The parent motion UUID, or null when unresolvable
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function parentMotionOf(mixed $amendmentId): ?string {
		if ($amendmentId === null) {
			return null;
		}

		$amendmentEntity = $this->objectService()->find(id: $amendmentId, register: 'decidesk', schema: 'decision');
		if ($amendmentEntity === null) {
			return null;
		}

		return $this->resolveParentMotionId(amendment: $amendmentEntity->jsonSerialize());
	}//end parentMotionOf()

	/**
	 * Resolve the meeting a motion belongs to, honouring the flat `meeting`
	 * property (canonical UI shape) and the structured relation entry.
	 *
	 * @param array<string,mixed> $motion Serialized motion object
	 *
	 * @return string|null The meeting UUID, or null when unlinked
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function meetingIdFromMotion(array $motion): ?string {
		$direct = $this->refId(ref: ($motion['meeting'] ?? null));
		if ($direct !== '') {
			return $direct;
		}

		return $this->relationId(relations: ($motion['relations'] ?? []), schema: 'meeting');
	}//end meetingIdFromMotion()

	/**
	 * Normalise a flat relation reference (a UUID string or a {id}/{uuid}
	 * object) to a string.
	 *
	 * @param mixed $ref The reference value
	 *
	 * @return string The referenced UUID, or '' when absent
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function refId(mixed $ref): string {
		if (is_string($ref) === true) {
			return $ref;
		}

		if (is_array($ref) === false) {
			return '';
		}

		$id = ($ref['id'] ?? $ref['uuid'] ?? '');
		if ($id === '') {
			return '';
		}

		return (string)$id;
	}//end refId()

	/**
	 * The identifier of the first relation entry with the given schema.
	 *
	 * @param mixed $relations The relations collection
	 * @param string $schema The schema slug to look for
	 *
	 * @return string|null The related UUID, or null when there is no such relation
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function relationId(mixed $relations, string $schema): ?string {
		foreach ($relations as $relation) {
			if (is_array($relation) === false || ($relation['schema'] ?? '') !== $schema) {
				continue;
			}

			// The `??` chain ends in `''`, so `$id` is never null and the
			// `!== null` this replaces could never be reached as false.
			$id = ($relation['id'] ?? $relation['uuid'] ?? '');
			if ($id !== '') {
				return (string)$id;
			}
		}

		return null;
	}//end relationId()

	/**
	 * Cast a relation identifier to a string, or null when it is absent.
	 *
	 * @param mixed $value The identifier
	 *
	 * @return string|null The identifier as a string, or null
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end stringOrNull()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object The ObjectService instance
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
