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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
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
	 * The voting method whose ballots count with the member's voting weight (vot-09).
	 *
	 * @var string
	 */
	public const WEIGHTED_METHOD = 'weighted';

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
	 * @param VoteCast                  $cast         The settled ballot (see VoteCast::forRound())
	 * @param array<string,mixed>|null $existingVote The ballot being overwritten, when any
	 * @param string $votingMethod The round's votingMethod; `weighted` weighs the ballot (vot-09)
	 *
	 * @return array<string,mixed> The vote payload to persist.
	 *
	 * @spec openspec/changes/archive/2026-11-24-voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function buildVote(VoteCast $cast, ?array $existingVote, string $votingMethod = 'for-against-abstain'): array {
		$idempotencySlug = $this->idempotencySlug(cast: $cast);

		// A proxy ballot counts for — and weighs as — the delegator (VoteCast::ballotOwner()).
		$vote = [
			'@self' => ['slug' => $idempotencySlug],
			'value' => $cast->value(),
			'weight' => $this->resolveWeight(votingMethod: $votingMethod, ballotOwnerId: $cast->ballotOwner()),
			'isProxy' => $cast->isProxy(),
			'castAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'castAs' => $this->resolveCastAs(participantId: $cast->participantId),
			'relations' => $this->voteRelations(cast: $cast),
		];

		// A ranked ballot carries the member's order in its own field (REQ-PRF-002).
		if ($cast->ranking() !== null) {
			$vote['ranking'] = array_values(array_map(static fn (mixed $key): string => (string)$key, $cast->ranking()));
		}

		// Store opaque dedup token for secret rounds (never contains participant identity).
		if ($cast->isSecret() === true) {
			$vote['voterToken'] = $idempotencySlug;
		}

		// Store delegatorToken on secret proxy votes for one-proxy-per-round enforcement
		// without storing the delegator's participant ID (anonymity preservation).
		if ($cast->isSecret() === true && $cast->isDelegated() === true) {
			$vote['delegatorToken'] = $this->tokens->delegatorToken(
				delegatorId: (string)$cast->delegatorId(),
				votingRoundId: $cast->votingRoundId
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
	 * @param VoteCast $cast The settled ballot
	 *
	 * @return array<int, array<string,mixed>> The relations structure.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function voteRelations(VoteCast $cast): array {
		$relations = [
			['register' => 'decidiq', 'schema' => 'voting-round', 'id' => $cast->votingRoundId],
		];

		if ($cast->isSecret() === true) {
			return $relations;
		}

		$relations[] = ['register' => 'decidiq', 'schema' => 'participant', 'id' => $cast->participantId];

		if ($cast->isDelegated() === true) {
			$relations[] = [
				'register' => 'decidiq',
				'schema' => 'participant',
				'id' => $cast->delegatorId(),
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
	 * - Secret rounds:     an HMAC over (participant, round), already opaque;
	 *   on a proxy ballot the delegatorToken over (delegator, round).
	 * - Non-secret rounds: "vote-{round}-{participant}", truncated because
	 *   slugs must be URL-safe and at most 255 characters; a proxy ballot
	 *   appends "-proxy-{delegator}".
	 *
	 * @param VoteCast $cast The settled ballot
	 *
	 * @return string The idempotency slug.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function idempotencySlug(VoteCast $cast): string {
		if ($cast->isSecret() === true) {
			// A proxy ballot is keyed on its delegator, not on the holder: keyed
			// on the holder it would share the holder's own voterToken and the
			// two ballots would overwrite each other.
			if ($cast->isDelegated() === true) {
				return $this->tokens->delegatorToken(delegatorId: (string)$cast->delegatorId(), votingRoundId: $cast->votingRoundId);
			}

			return $this->tokens->voterToken(participantId: $cast->participantId, votingRoundId: $cast->votingRoundId);
		}

		$slug = 'vote-' . substr($cast->votingRoundId, 0, 8) . '-' . substr($cast->participantId, 0, 8);
		if ($cast->isDelegated() === true) {
			$slug .= '-proxy-' . substr((string)$cast->delegatorId(), 0, 8);
		}

		return $slug;
	}//end idempotencySlug()

	/**
	 * The weight a ballot counts with (vot-09).
	 *
	 * Every method except `weighted` is one member, one vote. On a weighted
	 * round the ballot counts with the voting weight of the member it counts
	 * for (the delegator on a proxy vote): the Membership's `votingWeight`
	 * (edited in the member-role dialog), else the deprecated Participant's
	 * own `votingWeight`, else 1. Weights are whole non-negative numbers
	 * because the round's tallies are integers (VvE breukdelen are numerators).
	 * The weight carries no identity, so it is stamped on secret ballots too.
	 *
	 * @param string $votingMethod The round's votingMethod
	 * @param string $ballotOwnerId The participant the ballot counts for
	 *
	 * @return int The ballot weight.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function resolveWeight(string $votingMethod, string $ballotOwnerId): int {
		if ($votingMethod !== self::WEIGHTED_METHOD) {
			return 1;
		}

		$weight = $this->membershipWeight(participantId: $ballotOwnerId);
		if ($weight === null) {
			$participant = $this->loadObject(id: $ballotOwnerId, schema: 'participant');
			$weight = self::wholeWeight(value: ($participant['votingWeight'] ?? null));
		}

		return ($weight ?? 1);
	}//end resolveWeight()

	/**
	 * The voting weight on the Membership behind a Participant, when set.
	 *
	 * Uses the same Participant-to-Membership crosswalk the recusal guard
	 * uses on the cast path; any failure falls back to the Participant.
	 *
	 * @param string $participantId The participant UUID
	 *
	 * @return int|null The membership weight, or null when none applies.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function membershipWeight(string $participantId): ?int {
		try {
			$crosswalk = $this->container->get(ParticipantToPersonMembershipResolver::class);
			$resolution = $crosswalk->resolve(participantId: $participantId);
		} catch (Throwable $e) {
			$this->logger->debug('Decidiq: membership lookup for vote weight failed', ['error' => $e->getMessage()]);
			return null;
		}

		$membershipId = (string)($resolution['membership'] ?? '');
		if ($membershipId === '') {
			return null;
		}

		$membership = $this->loadObject(id: $membershipId, schema: 'membership');
		return self::wholeWeight(value: ($membership['votingWeight'] ?? null));
	}//end membershipWeight()

	/**
	 * A stored voting weight as a whole non-negative number, or null when it
	 * is unset or is not one.
	 *
	 * @param mixed $value The stored votingWeight
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private static function wholeWeight(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		$number = (float)$value;
		if ($number < 0 || floor($number) !== $number) {
			return null;
		}

		return (int)$number;
	}//end wholeWeight()

	/**
	 * Load one decidiq object as an array, or null when it cannot be read.
	 *
	 * @param string $id The object UUID
	 * @param string $schema The schema slug
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function loadObject(string $id, string $schema): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entity = $objectService->find(id: $id, options: new ObjectFindOptions(register: 'decidiq', schema: $schema));
			if (is_array($entity) === true) {
				return $entity;
			}

			if ($entity !== null) {
				return $entity->jsonSerialize();
			}
		} catch (Throwable $e) {
			$this->logger->debug('Decidiq: vote weight lookup failed', ['schema' => $schema, 'error' => $e->getMessage()]);
		}

		return null;
	}//end loadObject()

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
				options: new ObjectFindOptions(register: 'decidiq', schema: 'participant')
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
