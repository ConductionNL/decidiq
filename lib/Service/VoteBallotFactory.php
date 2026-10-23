<?php

/**
 * Decidiq Vote Ballot Factory
 *
 * Assembles the ballot payload a cast vote persists: the idempotency slug, the
 * relations, the anonymity tokens and the attendance stamp.
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the vote object VoteCastingService writes.
 *
 * Extracted from VotingService::castVote() together with the rest of the
 * casting path. Keeping payload assembly separate from orchestration is what
 * lets the idempotency slug — the mechanism that makes a concurrent duplicate
 * cast an UPDATE rather than a second INSERT — be read in one place.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoteBallotFactory {

	/**
	 * Derives the secret-ballot tokens. The factory owns it: the slug of a
	 * secret ballot and the dedup lookup before a cast must use the same one.
	 *
	 * @var VoterTokenSecret
	 */
	private readonly VoterTokenSecret $tokens;

	/**
	 * Constructor for the VoteBallotFactory.
	 *
	 * @param ContainerInterface $container The DI container (for ObjectService)
	 * @param LoggerInterface $logger The logger
	 * @param VoterTokenSecret|null $tokens Derives secret-ballot tokens; built from the container when null
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		?VoterTokenSecret $tokens = null,
	) {
		$this->tokens = ($tokens ?? new VoterTokenSecret(container: $container));
	}//end __construct()

	/**
	 * The token secret this factory signs secret ballots with, for the guards
	 * and lookups of the same cast.
	 *
	 * @return VoterTokenSecret The token secret.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function tokens(): VoterTokenSecret {
		return $this->tokens;
	}//end tokens()

	/**
	 * Assemble the ballot payload, including the idempotency slug.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The casting participant UUID
	 * @param string $value for | against | abstain
	 * @param bool $isProxy Whether the vote is cast by proxy
	 * @param string|null $delegatorId The delegator UUID for a proxy vote
	 * @param bool $isSecret Whether the round is a secret ballot
	 * @param array<string,mixed>|null $existingVote The ballot being overwritten, when any
	 * @param array<int, mixed>|null $ranking The checked ranking of a ranked ballot, or null
	 *
	 * @return array<string,mixed> The vote payload to persist.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function buildVote(
		string $votingRoundId,
		string $participantId,
		string $value,
		bool $isProxy,
		?string $delegatorId,
		bool $isSecret,
		?array $existingVote,
		?array $ranking = null,
	): array {
		$relations = $this->voteRelations(
			votingRoundId: $votingRoundId,
			participantId: $participantId,
			isProxy: $isProxy,
			delegatorId: $delegatorId,
			isSecret: $isSecret
		);

		$idempotencySlug = $this->idempotencySlug(
			votingRoundId: $votingRoundId,
			participantId: $participantId,
			isSecret: $isSecret,
			isProxy: $isProxy,
			delegatorId: $delegatorId
		);

		$vote = [
			'@self' => ['slug' => $idempotencySlug],
			'value' => $value,
			'weight' => 1,
			'isProxy' => $isProxy,
			'castAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'castAs' => $this->resolveCastAs(participantId: $participantId),
			'relations' => $relations,
		];

		// A ranked ballot carries the member's order in its own field (REQ-PRF-002).
		if ($ranking !== null) {
			$vote['ranking'] = array_values(array_map(static fn (mixed $key): string => (string)$key, $ranking));
		}

		// Store opaque dedup token for secret rounds (never contains participant identity).
		if ($isSecret === true) {
			$vote['voterToken'] = $idempotencySlug;
		}

		// Store delegatorToken on secret proxy votes for one-proxy-per-round enforcement
		// without storing the delegator's participant ID (anonymity preservation).
		if ($isSecret === true && $isProxy === true && $delegatorId !== null) {
			$vote['delegatorToken'] = $this->tokens->delegatorToken(
				delegatorId: $delegatorId,
				votingRoundId: $votingRoundId
			);
		}

		if ($existingVote !== null) {
			$vote['id'] = ($existingVote['id'] ?? null);
			$vote['uuid'] = ($existingVote['uuid'] ?? null);
		}

		return $vote;
	}//end buildVote()

	/**
	 * Build the ballot's relations.
	 *
	 * For non-secret rounds the vote is linked to the casting participant (and
	 * to the delegator on a proxy vote). For secret rounds the participant
	 * relation is omitted to preserve anonymity.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The casting participant UUID
	 * @param bool $isProxy Whether the vote is cast by proxy
	 * @param string|null $delegatorId The delegator UUID for a proxy vote
	 * @param bool $isSecret Whether the round is a secret ballot
	 *
	 * @return array<int, array<string,mixed>> The relations structure.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function voteRelations(
		string $votingRoundId,
		string $participantId,
		bool $isProxy,
		?string $delegatorId,
		bool $isSecret,
	): array {
		$relations = [
			['register' => 'decidiq', 'schema' => 'voting-round', 'id' => $votingRoundId],
		];

		if ($isSecret === true) {
			return $relations;
		}

		$relations[] = ['register' => 'decidiq', 'schema' => 'participant', 'id' => $participantId];

		if ($isProxy === true && $delegatorId !== null) {
			$relations[] = [
				'register' => 'decidiq',
				'schema' => 'participant',
				'id' => $delegatorId,
				'type' => 'delegator',
			];
		}

		return $relations;
	}//end voteRelations()

	/**
	 * Build the deterministic @self.slug that makes castVote idempotent.
	 *
	 * Concurrent castVote requests for the same (participant, round) must
	 * upsert rather than insert twice: OpenRegister's saveObject() performs an
	 * UPDATE when the slug matches an existing object, so the second request
	 * safely overwrites the first with the same value.
	 *
	 * - Secret rounds:     an HMAC over (participant, round), already opaque.
	 * - Non-secret rounds: "vote-{round}-{participant}", truncated because
	 *   slugs must be URL-safe and at most 255 characters.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The voting participant UUID
	 * @param bool $isSecret Whether the round is secret
	 * @param bool $isProxy Whether the vote is cast by proxy
	 * @param string|null $delegatorId The delegator UUID for a proxy vote
	 *
	 * @return string The idempotency slug.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function idempotencySlug(
		string $votingRoundId,
		string $participantId,
		bool $isSecret,
		bool $isProxy,
		?string $delegatorId,
	): string {
		if ($isSecret === true) {
			return $this->tokens->voterToken(participantId: $participantId, votingRoundId: $votingRoundId);
		}

		$slug = 'vote-' . substr($votingRoundId, 0, 8) . '-' . substr($participantId, 0, 8);
		if ($isProxy === true && $delegatorId !== null) {
			$slug .= '-proxy-' . substr($delegatorId, 0, 8);
		}

		return $slug;
	}//end idempotencySlug()

	/**
	 * Resolve the attendance mode to stamp on a vote (remote-vote annotation).
	 *
	 * Honest recording only — reads the casting participant's participantType
	 * ('in-person' | 'remote') and returns it; 'unknown' when the participant
	 * cannot be resolved or the field is unset. No session-verification theater.
	 * Carries no identity, so it is stamped on secret-ballot votes too.
	 *
	 * @param string $participantId The casting participant UUID
	 *
	 * @return string 'in-person' | 'remote' | 'unknown'
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function resolveCastAs(string $participantId): string {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$participantEntity = $objectService->find(
				id: $participantId,
				register: 'decidiq',
				schema: 'participant'
			);
			if ($participantEntity !== null) {
				$participant = $participantEntity->jsonSerialize();
				$type = ($participant['participantType'] ?? null);
				if (in_array($type, ['in-person', 'remote'], true) === true) {
					return $type;
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug('Decidiq: castAs participant lookup failed', ['error' => $e->getMessage()]);
		}

		return 'unknown';
	}//end resolveCastAs()
}//end class
