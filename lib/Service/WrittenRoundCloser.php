<?php

/**
 * Settles and closes a voting round held in writing.
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
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A written round closes at the first of: every member answered, the outcome
 * can no longer change, an objection, the deadline. On close the round keeps
 * its result and reason, the decision moves to `decided` with the outcome
 * (on `adopted` dated at the last answer), and an active vote stage the round
 * resolves is decided with the same outcome.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
 */
class WrittenRoundCloser {

	/**
	 * Constructor.
	 *
	 * @param WrittenRoundStore      $store      Reads answers, writes round, decision and stage.
	 * @param VotingResultCalculator $calculator Counts against the whole electorate.
	 * @param LoggerInterface        $logger     Logger.
	 */
	public function __construct(
		private readonly WrittenRoundStore $store,
		private readonly VotingResultCalculator $calculator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The answers of a round, by membership: value and time.
	 *
	 * @param string $roundId The round.
	 *
	 * @return array<string, array{value: string, castAt: string}>
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
	 */
	public function answersOf(string $roundId): array {
		$answers = [];
		foreach ($this->store->findAll(schema: 'vote', filters: ['votingRound' => $roundId]) as $vote) {
			$membership = WrittenRoundStore::idOf(value: ($vote['membership'] ?? null));
			if ($membership === null) {
				continue;
			}

			$castAt = (string)($vote['castAt'] ?? '');
			if (isset($answers[$membership]) === true && strcmp($answers[$membership]['castAt'], $castAt) > 0) {
				continue;
			}

			$answers[$membership] = ['value' => (string)($vote['value'] ?? ''), 'castAt' => $castAt];
		}

		return $answers;
	}//end answersOf()

	/**
	 * Close the round when its answers already settle it; the round as it is now.
	 *
	 * @param array<string, mixed> $round The open round.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
	 */
	public function settle(array $round): array {
		if (self::isOpen(round: $round) === false) {
			return $round;
		}

		$answers  = $this->answersOf(roundId: (string)$round['id']);
		$computed = $this->calculator->computeWritten(round: $round, answers: self::valuesOf(answers: $answers));
		if ($computed['closeReason'] === null) {
			return $round;
		}

		return $this->close(round: $round, computed: $computed, reason: $computed['closeReason'], answers: $answers);
	}//end settle()

	/**
	 * Close the round at its deadline: silence counts as not agreeing.
	 * A closed round, an in-meeting round or one before its deadline is left alone.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param DateTimeImmutable    $now   Now.
	 *
	 * @return bool Whether it was closed now.
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
	 */
	public function closeAtDeadline(array $round, DateTimeImmutable $now): bool {
		if (self::isOpen(round: $round) === false) {
			return false;
		}

		$deadline = self::dateTimeOf(value: ($round['votingDeadline'] ?? null));
		if ($deadline === null || $deadline > $now) {
			return false;
		}

		$answers  = $this->answersOf(roundId: (string)$round['id']);
		$computed = $this->calculator->computeWritten(round: $round, answers: self::valuesOf(answers: $answers));
		$reason   = 'deadline';
		if ($computed['closeReason'] !== null) {
			$reason = $computed['closeReason'];
		}

		$this->close(round: $round, computed: $computed, reason: $reason, answers: $answers);

		return true;
	}//end closeAtDeadline()

	/**
	 * Whether a round is an open written round.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
	 */
	public static function isOpen(array $round): bool {
		return ($round['procedure'] ?? 'in-meeting') === 'written'
			&& in_array(($round['closedAt'] ?? null), [null, ''], true) === true
			&& WrittenRoundStore::idOf(value: ($round['id'] ?? null)) !== null;
	}//end isOpen()

	/**
	 * Store the close on the round, the decision and the stage.
	 *
	 * @param array<string, mixed>                              $round    The round.
	 * @param array<string, mixed>                              $computed The written count.
	 * @param string                                            $reason   Why it closes.
	 * @param array<string, array{value: string, castAt: string}> $answers  The answers.
	 *
	 * @return array<string, mixed> The closed round.
	 */
	private function close(array $round, array $computed, string $reason, array $answers): array {
		$counts = array_count_values(self::valuesOf(answers: $answers));
		$closed = array_merge(
			$round,
			[
				'closedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'result' => (string)$computed['result'],
				'closedReason' => $reason,
				'votesFor' => (int)($counts['for'] ?? 0),
				'votesAgainst' => (int)($counts['against'] ?? 0),
				'votesAbstain' => (int)($counts['abstain'] ?? 0),
			]
		);
		$closed = $this->store->save(schema: 'voting-round', object: $closed);

		$this->decide(round: $round, outcome: ['result' => (string)$computed['result'], 'lastAnswerAt' => self::lastAnswerAt(answers: $answers)]);
		$this->resolveStage(round: $round, result: (string)$computed['result']);

		return $closed;
	}//end close()

	/**
	 * Move the decision to `decided` with the round's outcome. Fails soft: the
	 * round's own result stays recorded when the decision cannot be written.
	 *
	 * @param array<string, mixed>                           $round   The round.
	 * @param array{result: string, lastAnswerAt: string|null} $outcome adopted|rejected, and the time of the last answer.
	 *
	 * @return void
	 */
	private function decide(array $round, array $outcome): void {
		$decisionId = WrittenRoundStore::idOf(value: ($round['decision'] ?? null));
		if ($decisionId === null) {
			return;
		}

		try {
			$decision = $this->store->find(id: $decisionId, schema: 'decision');
			if ($decision === null) {
				return;
			}

			$this->store->save(schema: 'decision', object: self::decided(decision: $decision, outcome: $outcome));
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: written round closed but the decision could not be marked decided',
				['votingRoundId' => ($round['id'] ?? null), 'decisionId' => $decisionId, 'error' => $e->getMessage()]
			);
		}
	}//end decide()

	/**
	 * The decision marked decided with the round's outcome; an adopted
	 * decision is dated by the last answer.
	 *
	 * @param array<string, mixed>                           $decision The decision.
	 * @param array{result: string, lastAnswerAt: string|null} $outcome  The outcome.
	 *
	 * @return array<string, mixed>
	 */
	private static function decided(array $decision, array $outcome): array {
		$decision['lifecycle'] = 'decided';
		$decision['outcome']   = $outcome['result'];
		if ($outcome['result'] === 'adopted' && $outcome['lastAnswerAt'] !== null) {
			$decision['decisionDate'] = $outcome['lastAnswerAt'];
		}

		return $decision;
	}//end decided()

	/**
	 * Decide the active vote stage the round resolves with the same outcome.
	 *
	 * @param array<string, mixed> $round  The round.
	 * @param string               $result adopted|rejected.
	 *
	 * @return void
	 */
	private function resolveStage(array $round, string $result): void {
		$stageId = WrittenRoundStore::idOf(value: ($round['decisionStage'] ?? null));
		if ($stageId === null) {
			return;
		}

		try {
			$stage = $this->store->find(id: $stageId, schema: 'decision-stage');
			if ($stage === null || (string)($stage['status'] ?? '') !== 'active') {
				return;
			}

			$stage['status']    = 'decided';
			$stage['outcome']   = $result;
			$stage['decidedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
			$this->store->save(schema: 'decision-stage', object: $stage);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: written round closed but the decision stage could not be decided',
				['votingRoundId' => ($round['id'] ?? null), 'stageId' => $stageId, 'error' => $e->getMessage()]
			);
		}
	}//end resolveStage()

	/**
	 * The answer values by membership.
	 *
	 * @param array<string, array{value: string, castAt: string}> $answers The answers.
	 *
	 * @return array<string, string>
	 */
	private static function valuesOf(array $answers): array {
		return array_map(static fn (array $answer): string => $answer['value'], $answers);
	}//end valuesOf()

	/**
	 * The latest answer time, or null when nobody answered.
	 *
	 * @param array<string, array{value: string, castAt: string}> $answers The answers.
	 *
	 * @return string|null
	 */
	private static function lastAnswerAt(array $answers): ?string {
		$times = array_filter(array_column($answers, 'castAt'), static fn (string $time): bool => $time !== '');
		if ($times === []) {
			return null;
		}

		usort($times, static fn (string $a, string $b): int => (self::dateTimeOf(value: $a) <=> self::dateTimeOf(value: $b)));

		return end($times);
	}//end lastAnswerAt()

	/**
	 * A stored date-time, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function dateTimeOf(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}//end dateTimeOf()
}//end class
