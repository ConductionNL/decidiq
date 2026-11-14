<?php

/**
 * Decidiq Voting Round Resolution
 *
 * What the opener settled before a round is written: the effective rules,
 * whether quorum was met, and the eligible preset participants.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The settled facts a voting round is written with, and the payload they
 * write for a request.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
final class VotingRoundResolution {

	/**
	 * Constructor for a VotingRoundResolution.
	 *
	 * @param array<string>             $rules          The effective voting rules
	 * @param bool                      $quorumWith     Whether quorum was met when the round opened
	 * @param array<int|string, string> $participantIds Eligible preset participant UUIDs
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		public readonly array $rules,
		public readonly bool $quorumWith,
		public readonly array $participantIds,
	) {
	}//end __construct()

	/**
	 * Assemble the VotingRound payload that gets persisted.
	 *
	 * The round carries its meeting, so rules that hold per meeting (the
	 * per-holder proxy cap) can find every round of that meeting.
	 *
	 * @param VotingRoundRequest $request The request: subject, method, secrecy, deadline and revote
	 *
	 * @return array<string,mixed> The voting-round payload
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function roundPayload(VotingRoundRequest $request): array {
		$rules = $this->rules;
		$relations = [['register' => 'decidiq', 'schema' => $request->rules()->subjectType, 'id' => $request->motionId]];
		foreach ($this->participantIds as $uuid) {
			$relations[] = ['register' => 'decidiq', 'schema' => 'participant', 'id' => $uuid];
		}

		$round = [
			'votingMethod' => $request->votingMethod,
			'isSecret' => $request->isSecret,
			'openedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'closedAt' => $request->closedAt(),
			'quorumWith' => $this->quorumWith,
			'result' => null,
			'votesFor' => 0,
			'votesAgainst' => 0,
			'votesAbstain' => 0,
			'voteThreshold' => $rules['voteThreshold'],
			'abstentionHandling' => $rules['abstentionHandling'],
			'tieBreakRule' => $rules['tieBreakRule'],
			'relations' => $relations,
		];

		if ($request->meetingId !== '') {
			$round['meeting'] = $request->meetingId;
		}

		if ($request->isFreshRound() === false) {
			$round['revoteOfRound'] = $request->revoteOfRoundId();
		}

		return $round;
	}//end roundPayload()
}//end class
