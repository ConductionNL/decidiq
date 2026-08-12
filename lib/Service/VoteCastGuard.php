<?php

/**
 * Decidesk Vote Cast Guard
 *
 * Enforces everything a cast vote must satisfy before a ballot is written: the
 * round is open, the caster is a member of the owning meeting, the caster holds
 * a formal proxy grant from the claimed delegator, and no proxy has already
 * been registered for that delegator in the round.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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
 * @spec openspec/specs/user-settings/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Fail-closed eligibility rules for the vote-casting path.
 *
 * Extracted from VotingService::castVote(), where these checks were roughly a
 * hundred lines of nested branching inside a 229-line method. Every rejection
 * throws — this class never returns a "permitted" boolean the caller could
 * forget to check.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/user-settings/spec.md
 */
class VoteCastGuard {

	/**
	 * The rejection used for both the secret and the open one-proxy-per-round check.
	 *
	 * @var string
	 */
	private const ALREADY_REGISTERED = 'Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde';

	/**
	 * Constructor for the VoteCastGuard.
	 *
	 * @param ContainerInterface $container The DI container (for ObjectService)
	 * @param LoggerInterface $logger The logger
	 * @param ObjectRelationFilter $relationFilter Exact-id scoping for relation-filtered result sets
	 * @param VoterTokenSecret $tokens Derives the secret-ballot delegator token
	 * @param ParticipantResolver $participantResolver Resolves a meeting's participants
	 * @param AmendmentOrderService $amendmentOrder Resolves the meeting behind a round
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectRelationFilter $relationFilter,
		private readonly VoterTokenSecret $tokens,
		private readonly ParticipantResolver $participantResolver,
		private readonly AmendmentOrderService $amendmentOrder,
	) {
	}//end __construct()

	/**
	 * Load a voting round and assert it is currently accepting votes.
	 *
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<string,mixed> The open voting round.
	 *
	 * @throws RuntimeException When the round is missing, closed or not yet opened.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function loadOpenRound(string $votingRoundId): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
		$round = null;
		if ($roundEntity !== null) {
			$round = $roundEntity->jsonSerialize();
		}

		if ($round === null) {
			throw new RuntimeException("VotingRound {$votingRoundId} not found");
		}

		if (($round['closedAt'] ?? null) !== null && strtotime($round['closedAt']) < time()) {
			throw new RuntimeException('Stemronde is gesloten');
		}

		if (($round['openedAt'] ?? null) === null) {
			throw new RuntimeException('Stemronde is nog niet geopend');
		}

		return $round;
	}//end loadOpenRound()

	/**
	 * Verify the participant belongs to the meeting that owns the round (#300).
	 *
	 * The round is linked to a Motion (or an Amendment, which resolves through
	 * its parent motion); the Motion is linked to a Meeting via its relations.
	 * When no meeting can be resolved the membership check is skipped — there is
	 * nothing to check against.
	 *
	 * @param array<string,mixed> $round The voting round
	 * @param string $participantId The casting participant UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the participant is not a meeting member.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function assertMeetingMembership(array $round, string $participantId): void {
		$meetingId = $this->amendmentOrder->resolveMeetingIdForRound(round: $round);
		if ($meetingId === null) {
			return;
		}

		$meetingParticipants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
		$memberIds = array_column($meetingParticipants, 'id');
		if (in_array($participantId, $memberIds, true) === false) {
			throw new RuntimeException('Deelnemer is geen lid van de vergadering');
		}

	}//end assertMeetingMembership()

	/**
	 * Assert the caster holds a formal proxy from the claimed delegator.
	 *
	 * User-settings spec — "Delegate cannot vote without explicit proxy": an
	 * absence delegation (configured in personal settings) covers notifications
	 * and read access only. When the caster IS the configured absence delegate
	 * of the claimed delegator, the rejection names that fact and points at the
	 * formal proxy (volmacht) granting process. The formal grant check stays
	 * authoritative either way — both branches deny the vote.
	 *
	 * @param array<string, mixed> $round The voting round
	 * @param string $participantId The casting participant UUID
	 * @param string $delegatorId The claimed delegator UUID
	 * @param string|null $callerUid The casting user's Nextcloud UID, when known
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no formal proxy grant exists.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function assertGrant(array $round, string $participantId, string $delegatorId, ?string $callerUid): void {
		if ($this->grantExists(round: $round, participantId: $participantId, delegatorId: $delegatorId) === true) {
			return;
		}

		if ($this->hasAbsenceDelegation(delegatorId: $delegatorId, participantId: $participantId, callerUid: $callerUid) === true) {
			throw new RuntimeException(
				'Delegation does not include voting rights. A formal proxy (volmacht) is required for voting. '
				. 'Grant one via the voting round proxy process (POST /apps/decidesk/api/voting-rounds/{id}/proxy).'
			);
		}

		throw new RuntimeException(
			'Geen geldige volmacht gevonden: de deelnemer heeft geen volmacht ontvangen van deze volmachtgever'
		);

	}//end assertGrant()

	/**
	 * Assert no proxy vote is already registered for this delegator in this round.
	 *
	 * For secret rounds participant relations are suppressed for anonymity, so
	 * the check is keyed on the deterministic delegatorToken (HMAC) instead of
	 * the delegator's participant id.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $delegatorId The claimed delegator UUID
	 * @param bool $isSecret Whether the round is a secret ballot
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a proxy is already registered.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function assertNotAlreadyRegistered(string $votingRoundId, string $delegatorId, bool $isSecret): void {
		if ($isSecret === true) {
			$existing = $this->votesInRound(
				votingRoundId: $votingRoundId,
				extraFilters: [
					'delegatorToken' => $this->tokens->delegatorToken(
						delegatorId: $delegatorId,
						votingRoundId: $votingRoundId
					),
				]
			);

			if (empty($existing) === false) {
				throw new RuntimeException(self::ALREADY_REGISTERED);
			}

			return;
		}

		$existingProxies = $this->votesInRound(votingRoundId: $votingRoundId, extraFilters: ['isProxy' => true]);
		foreach ($existingProxies as $proxyVoteEntity) {
			$proxyVote = $proxyVoteEntity->jsonSerialize();
			foreach (($proxyVote['relations'] ?? []) as $rel) {
				if ($this->isDelegatorRelation(relation: $rel, delegatorId: $delegatorId) === true) {
					throw new RuntimeException(self::ALREADY_REGISTERED);
				}
			}
		}

	}//end assertNotAlreadyRegistered()

	/**
	 * Whether the round carries a Proxy note granting this delegation.
	 *
	 * @param array<string, mixed> $round The voting round
	 * @param string $participantId The casting participant UUID
	 * @param string $delegatorId The claimed delegator UUID
	 *
	 * @return bool True when a formal grant is present.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function grantExists(array $round, string $participantId, string $delegatorId): bool {
		foreach (($round['notes'] ?? []) as $note) {
			if (($note['title'] ?? '') !== 'Proxy') {
				continue;
			}

			$body = json_decode($note['body'] ?? '{}', true);
			if (($body['fromParticipantId'] ?? '') === $delegatorId
				&& ($body['toParticipantId'] ?? '') === $participantId
			) {
				return true;
			}
		}

		return false;
	}//end grantExists()

	/**
	 * Whether one relation entry marks the given participant as delegator.
	 *
	 * @param mixed $relation One entry of a vote's relations structure
	 * @param string $delegatorId The claimed delegator UUID
	 *
	 * @return bool True when the relation is this delegator's delegator link.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function isDelegatorRelation(mixed $relation, string $delegatorId): bool {
		if (is_array($relation) === false) {
			return false;
		}

		return ($relation['schema'] ?? '') === 'participant'
			&& ($relation['id'] ?? '') === $delegatorId
			&& ($relation['type'] ?? '') === 'delegator';

	}//end isDelegatorRelation()

	/**
	 * Whether the caster is the configured absence delegate of the delegator.
	 *
	 * Matches the stored delegate identifier against both the caster's
	 * participant UUID and their Nextcloud UID (the settings UI stores NC
	 * UIDs). Fail-closed for the gate's purpose: when the preference service
	 * is unavailable the method returns false and the caller falls back to
	 * the generic no-proxy rejection — the vote is denied either way.
	 *
	 * @param string $delegatorId The claimed delegator (participant UUID or NC UID)
	 * @param string $participantId The casting participant UUID
	 * @param string|null $callerUid The casting user's Nextcloud UID, when known
	 *
	 * @return bool True when an absence delegation is configured.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function hasAbsenceDelegation(string $delegatorId, string $participantId, ?string $callerUid): bool {
		try {
			$prefService = $this->container->get(NotificationPreferenceService::class);
			if ($prefService instanceof NotificationPreferenceService === false) {
				return false;
			}

			if ($prefService->hasActiveDelegationTo(delegatorId: $delegatorId, delegateId: $participantId) === true) {
				return true;
			}

			if ($callerUid !== null && $callerUid !== '') {
				return $prefService->hasActiveDelegationTo(delegatorId: $delegatorId, delegateId: $callerUid);
			}
		} catch (Throwable $e) {
			// Both outcomes deny the vote; this only selects the error text.
			$this->logger->debug('Decidesk: delegation consult failed', ['error' => $e->getMessage()]);
		}

		return false;
	}//end hasAbsenceDelegation()

	/**
	 * Fetch the votes of one round, scoped to that round exactly.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param array<string, mixed> $extraFilters Additional OpenRegister filters
	 *
	 * @return array<int, mixed> The matching vote entities.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function votesInRound(string $votingRoundId, array $extraFilters): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$objectService->setRegister('decidesk');
		$objectService->setSchema('vote');

		return $this->relationFilter->matching(
			entities: $objectService->findAll(
				[
					'filters' => array_merge(
						$this->relationFilter->filterFor(targetId: $votingRoundId),
						$extraFilters
					),
				]
			),
			schema: 'voting-round',
			targetId: $votingRoundId
		);

	}//end votesInRound()
}//end class
