<?php

/**
 * Opens rounds and casts votes through VotingService from loose test arguments.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VoteCast;
use OCA\Decidiq\Service\VotingRoundRequest;
use OCA\Decidiq\Service\VotingRoundRules;
use OCA\Decidiq\Service\VotingService;

/**
 * Builds the VotingRoundRequest and VoteCast the service takes.
 */
trait VotingFacadeCalls {

	/**
	 * Open a round.
	 *
	 * @param VotingService $service The service
	 * @param string $motionId The subject UUID
	 * @param string $meetingId The meeting UUID
	 * @param string $votingMethod The voting method
	 * @param bool $isSecret Whether the ballot is secret
	 * @param string|null $closedAt The close time
	 * @param array<string> $presetParticipantIds The preset participants
	 * @param string|null $revoteOfRoundId The tied round this revotes
	 * @param VotingRoundRules|null $roundRules The decision rules
	 *
	 * @return array<string,mixed> The opened round
	 */
	private function openRoundOn(
		VotingService $service,
		string $motionId,
		string $meetingId,
		string $votingMethod,
		bool $isSecret,
		?string $closedAt,
		array $presetParticipantIds = [],
		?string $revoteOfRoundId = null,
		?VotingRoundRules $roundRules = null,
	): array {
		$request = new VotingRoundRequest(motionId: $motionId, meetingId: $meetingId, votingMethod: $votingMethod, isSecret: $isSecret);

		return $service->openVotingRound(
			request: $request->opening(
				closedAt: $closedAt,
				presetParticipantIds: $presetParticipantIds,
				revoteOfRoundId: $revoteOfRoundId,
				rules: $roundRules
			)
		);
	}//end openRoundOn()

	/**
	 * Cast a vote.
	 *
	 * @param VotingService $service The service
	 * @param string $votingRoundId The round UUID
	 * @param string $participantId The participant UUID
	 * @param string $value The vote value
	 * @param bool $isProxy Whether this is a proxy vote
	 * @param string|null $delegatorId The delegating participant UUID
	 * @param string|null $callerUid The casting user
	 * @param array<int, mixed>|null $ranking The ranking
	 *
	 * @return array<string,mixed> The vote
	 */
	private function castVoteOn(
		VotingService $service,
		string $votingRoundId,
		string $participantId,
		string $value,
		bool $isProxy,
		?string $delegatorId,
		?string $callerUid = null,
		?array $ranking = null,
	): array {
		$cast = new VoteCast(votingRoundId: $votingRoundId, participantId: $participantId, value: $value, ranking: $ranking);
		if ($isProxy === true) {
			$cast = $cast->asProxy(delegatorId: $delegatorId);
		}

		return $service->castVote(cast: $cast, callerUid: $callerUid);
	}//end castVoteOn()
}//end trait
