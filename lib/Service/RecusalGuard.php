<?php

/**
 * Decidiq Recusal Guard
 *
 * Keeps a member who declared a conflict of interest out of the vote on the
 * matter (bod-10). A declaration names one subject: a motion (or amendment)
 * or the agenda item it is tabled under. A declaration whose action taken is
 * a recusal on the round's motion, on the amendment the round votes on, or on
 * the agenda item either of them sits under, refuses the ballot.
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
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Refuses a ballot from a member recused on the matter being voted on.
 *
 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
 */
class RecusalGuard {

	/**
	 * The actions taken on a declaration that keep the member out of the vote.
	 *
	 * @var string[]
	 */
	public const RECUSALS = ['recused-from-vote', 'recused-from-discussion'];

	/**
	 * Constructor for the RecusalGuard.
	 *
	 * @param ConflictOfInterestService             $conflicts           Reads the active declaration per member and subject
	 * @param ParticipantToPersonMembershipResolver $participantCrosswalk Resolves a Participant to its Membership
	 * @param ObjectServiceInterface                $objectService       The OpenRegister object service
	 * @param AmendmentOrderService                 $amendmentOrder      Resolves an amendment's parent motion
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConflictOfInterestService $conflicts,
		private readonly ParticipantToPersonMembershipResolver $participantCrosswalk,
		private readonly ObjectServiceInterface $objectService,
		private readonly AmendmentOrderService $amendmentOrder,
	) {
	}//end __construct()

	/**
	 * Refuse the ballot when the member it counts for is recused on the matter.
	 *
	 * @param array<string, mixed> $round         The open voting round
	 * @param string               $participantId The Participant the ballot counts for
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the member is recused.
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
	 */
	public function assertNotRecused(array $round, string $participantId): void {
		$subjects = $this->subjectsOf(round: $round);
		if ($subjects === []) {
			return;
		}

		foreach ($this->memberKeys(participantId: $participantId) as $memberKey) {
			foreach ($subjects as $subjectId) {
				$conflict = $this->readConflict(memberKey: $memberKey, subjectId: $subjectId);
				if ($conflict !== null && in_array(($conflict['actionTaken'] ?? ''), self::RECUSALS, true) === true) {
					throw new RuntimeException($this->refusal(conflict: $conflict));
				}
			}
		}

	}//end assertNotRecused()

	/**
	 * Read the member's active declaration on one subject. A read that fails
	 * refuses the ballot: without the declarations the guard cannot tell a
	 * recused member from one who may vote, so it fails closed.
	 *
	 * @param string $memberKey The Membership or Participant id
	 * @param string $subjectId The motion, amendment or agenda item id
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws RuntimeException When the declarations cannot be read.
	 */
	private function readConflict(string $memberKey, string $subjectId): ?array {
		try {
			return $this->conflicts->getActiveConflicts($memberKey, $subjectId);
		} catch (\Throwable $e) {
			throw new RuntimeException(
				'Your vote could not be checked against the declared conflicts of interest. Try again in a moment.',
				0,
				$e
			);
		}
	}//end readConflict()

	/**
	 * The message that names the declaration.
	 *
	 * @param array<string, mixed> $conflict The recusing declaration
	 *
	 * @return string
	 */
	private function refusal(array $conflict): string {
		$reason = trim((string)($conflict['description'] ?? ''));
		if ($reason === '') {
			return 'You declared a conflict of interest on this matter and are recused from the vote.';
		}

		return 'You declared a conflict of interest on this matter ("' . $reason . '") and are recused from the vote.';
	}//end refusal()

	/**
	 * The ids a declaration of this member can carry as `boardMember`: the
	 * Membership (the schema's reference) and the Participant itself (rows
	 * written before the schema moved from Participant to Membership).
	 *
	 * @param string $participantId The Participant UUID
	 *
	 * @return array<int, string>
	 */
	private function memberKeys(string $participantId): array {
		$keys = [$participantId];
		$resolution = $this->participantCrosswalk->resolve(participantId: $participantId);
		$membership = (string)($resolution['membership'] ?? '');
		if ($membership !== '' && $membership !== $participantId) {
			$keys[] = $membership;
		}

		return $keys;
	}//end memberKeys()

	/**
	 * The subjects a declaration on this round's matter can name: the voted
	 * motion or amendment, an amendment's parent motion, and the agenda item
	 * each of them is tabled under.
	 *
	 * @param array<string, mixed> $round The voting round
	 *
	 * @return array<int, string>
	 */
	private function subjectsOf(array $round): array {
		$subjects = [];
		foreach (($round['relations'] ?? []) as $relation) {
			if (is_array($relation) === false) {
				continue;
			}

			$schema = (string)($relation['schema'] ?? '');
			$id = (string)($relation['id'] ?? '');
			if ($id === '' || in_array($schema, ['motion', 'amendment'], true) === false) {
				continue;
			}

			$this->addDecision(decisionId: $id, subjects: $subjects, followParent: ($schema === 'amendment'));
		}

		return array_values(array_unique($subjects));
	}//end subjectsOf()

	/**
	 * Add a decision (motion or amendment), its agenda item and, for an
	 * amendment, its parent motion to the subject list.
	 *
	 * @param string             $decisionId   The decision UUID
	 * @param array<int, string> $subjects     The list to add to
	 * @param bool               $followParent Whether to add the parent motion too
	 *
	 * @return void
	 */
	private function addDecision(string $decisionId, array &$subjects, bool $followParent): void {
		$subjects[] = $decisionId;
		$decision = $this->loadDecision(decisionId: $decisionId);
		if ($decision === null) {
			return;
		}

		$agendaItem = $this->refId(ref: ($decision['agendaItem'] ?? null));
		if ($agendaItem !== '') {
			$subjects[] = $agendaItem;
		}

		if ($followParent === false) {
			return;
		}

		$parent = ($this->amendmentOrder->resolveParentMotionId(amendment: $decision) ?? '');
		if ($parent !== '' && in_array($parent, $subjects, true) === false) {
			$this->addDecision(decisionId: $parent, subjects: $subjects, followParent: false);
		}
	}//end addDecision()

	/**
	 * Load a decision as an array, or null when it does not exist.
	 *
	 * @param string $decisionId The decision UUID
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadDecision(string $decisionId): ?array {
		// No catch: a read that fails refuses the ballot (the caller answers
		// 400) rather than letting a recused member through unchecked.
		$entity = $this->objectService->find(id: $decisionId, register: 'decidiq', schema: 'decision');
		if ($entity === null) {
			return null;
		}

		return (array)$entity->jsonSerialize();
	}//end loadDecision()

	/**
	 * Read a reference that is a bare uuid or an expanded object.
	 *
	 * @param mixed $ref The reference
	 *
	 * @return string
	 */
	private function refId(mixed $ref): string {
		if (is_string($ref) === true) {
			return $ref;
		}

		if (is_array($ref) === true) {
			return (string)($ref['id'] ?? $ref['uuid'] ?? '');
		}

		return '';
	}//end refId()
}//end class
