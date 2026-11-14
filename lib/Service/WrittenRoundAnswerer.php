<?php

/**
 * Stores a member's answer to a voting round held in writing.
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
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Only a user in the round's electorate snapshot answers; nobody else does,
 * and no meeting check is ever consulted. The answer is a vote related to the
 * member's membership, one per member: a second answer replaces the first
 * until the round closes. After each answer the round is settled.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
 */
class WrittenRoundAnswerer {

	/**
	 * The answers a written round accepts; `objection` is "discuss this in a meeting".
	 *
	 * @var list<string>
	 */
	public const ANSWERS = ['for', 'against', 'abstain', 'objection'];

	/**
	 * Constructor.
	 *
	 * @param WrittenRoundStore  $store  Reads the round and the member's vote, saves the vote.
	 * @param WrittenRoundCloser $closer Settles the round after the answer.
	 * @param ITimeFactory       $time   The clock.
	 */
	public function __construct(
		private readonly WrittenRoundStore $store,
		private readonly WrittenRoundCloser $closer,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The round when it is held in writing, else null.
	 *
	 * @param string $roundId The round.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
	 */
	public function writtenRound(string $roundId): ?array {
		try {
			$round = $this->store->find(id: $roundId, schema: 'voting-round');
		} catch (Throwable) {
			// Unreadable here: the meeting cast path reads it again and refuses a written round itself.
			return null;
		}

		if ($round === null || ($round['procedure'] ?? 'in-meeting') !== 'written') {
			return null;
		}

		$round['id'] = (WrittenRoundStore::idOf(value: ($round['id'] ?? null)) ?? $roundId);

		return $round;
	}//end writtenRound()

	/**
	 * Store the caller's answer and settle the round.
	 *
	 * @param string $roundId The round.
	 * @param string $userId  The Nextcloud user answering.
	 * @param string $value   for|against|abstain|objection.
	 *
	 * @return array{vote: array<string, mixed>, round: array<string, mixed>}
	 *
	 * @throws NotFoundException When the round is not a written round.
	 * @throws AccessDeniedException When the user is not in its electorate.
	 * @throws InvalidArgumentException When the answer is unknown or the round is closed.
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
	 */
	public function answer(string $roundId, string $userId, string $value): array {
		$round = $this->writtenRound(roundId: $roundId);
		if ($round === null) {
			throw new NotFoundException(message: 'Written voting round not found');
		}

		$member = self::memberOf(round: $round, userId: $userId);
		if ($member === null) {
			throw new AccessDeniedException(message: 'You are not one of the members this decision was put to.');
		}

		if (in_array($value, self::ANSWERS, true) === false) {
			throw new InvalidArgumentException('value must be one of: ' . implode(', ', self::ANSWERS));
		}

		if (WrittenRoundCloser::isOpen(round: $round) === false) {
			throw new InvalidArgumentException('This written round is closed.');
		}

		$vote = $this->store->save(schema: 'vote', object: $this->voteFor(round: $round, member: $member, value: $value));

		return ['vote' => $vote, 'round' => $this->closer->settle(round: $round)];
	}//end answer()

	/**
	 * The electorate entry of a user, or null when they are not in it.
	 *
	 * @param array<string, mixed> $round  The round.
	 * @param string               $userId The user.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
	 */
	public static function memberOf(array $round, string $userId): ?array {
		if ($userId === '' || in_array($userId, (array)($round['electorateUserIds'] ?? []), true) === false) {
			return null;
		}

		foreach ((array)($round['electorate'] ?? []) as $member) {
			if (is_array($member) === true && ($member['userId'] ?? null) === $userId && (string)($member['membership'] ?? '') !== '') {
				return $member;
			}
		}

		return null;
	}//end memberOf()

	/**
	 * The vote object of an answer, replacing the member's earlier answer.
	 *
	 * @param array<string, mixed> $round  The round.
	 * @param array<string, mixed> $member The electorate entry.
	 * @param string               $value  The answer.
	 *
	 * @return array<string, mixed>
	 */
	private function voteFor(array $round, array $member, string $value): array {
		$roundId    = (string)$round['id'];
		$membership = (string)$member['membership'];
		$vote       = [
			'@self' => ['slug' => 'vote-written-' . substr($roundId, 0, 8) . '-' . substr($membership, 0, 8)],
			'value' => $value,
			'weight' => (float)($member['votingWeight'] ?? 1),
			'isProxy' => false,
			'castAt' => (new DateTimeImmutable())->setTimestamp($this->time->getTime())->format(DateTimeInterface::ATOM),
			'votingRound' => $roundId,
			'membership' => $membership,
		];

		foreach ($this->store->findAll(schema: 'vote', filters: ['votingRound' => $roundId, 'membership' => $membership]) as $earlier) {
			$vote['id'] = ($earlier['id'] ?? null);
			if (isset($earlier['uuid']) === true) {
				$vote['uuid'] = $earlier['uuid'];
			}

			break;
		}

		return $vote;
	}//end voteFor()
}//end class
