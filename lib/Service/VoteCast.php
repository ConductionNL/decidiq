<?php

/**
 * Decidiq Vote Cast
 *
 * One ballot as it is being cast: the round, the casting participant, the
 * choice, and whether it is a proxy vote for a delegator.
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

/**
 * An immutable ballot being cast.
 *
 * A proxy vote is marked with asProxy(); the checked value and the round's
 * secrecy are settled with forRound() once the round is loaded.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
final class VoteCast {

	/**
	 * Whether the participant votes as proxy for another.
	 *
	 * @var boolean
	 */
	private bool $isProxy = false;

	/**
	 * The participant the proxy vote is cast for, when any.
	 *
	 * @var string|null
	 */
	private ?string $delegatorId = null;

	/**
	 * Whether the round is a secret ballot.
	 *
	 * @var boolean
	 */
	private bool $isSecret = false;

	/**
	 * Constructor for a VoteCast.
	 *
	 * @param string                $votingRoundId The voting round UUID
	 * @param string                $participantId The casting participant UUID
	 * @param string                $value         for | against | abstain (ranked on a ranked-choice round)
	 * @param array<int, mixed>|null $ranking      The member's ranking, first preference first, or null
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		public readonly string $votingRoundId,
		public readonly string $participantId,
		private string $value,
		private ?array $ranking = null,
	) {
	}//end __construct()

	/**
	 * The same ballot, cast by proxy for the given delegator.
	 *
	 * @param string|null $delegatorId The participant UUID being delegated
	 *
	 * @return self The proxy ballot.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function asProxy(?string $delegatorId): self {
		$cast = clone $this;
		$cast->isProxy = true;
		$cast->delegatorId = $delegatorId;
		return $cast;
	}//end asProxy()

	/**
	 * The same ballot with the value the round accepted and the round's
	 * secrecy. A value other than a ranked one carries no ranking.
	 *
	 * @param string $value    The checked ballot value
	 * @param bool   $isSecret Whether the round is a secret ballot
	 *
	 * @return self The settled ballot.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function forRound(string $value, bool $isSecret): self {
		$cast = clone $this;
		$cast->value = $value;
		$cast->isSecret = $isSecret;
		if ($value !== RankedBallotRules::VALUE) {
			$cast->ranking = null;
		}

		return $cast;
	}//end forRound()

	/**
	 * The ballot value.
	 *
	 * @return string The value.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function value(): string {
		return $this->value;
	}//end value()

	/**
	 * The ranking of a ranked ballot.
	 *
	 * @return array<int, mixed>|null The ranking, or null.
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
	 */
	public function ranking(): ?array {
		return $this->ranking;
	}//end ranking()

	/**
	 * Whether the ballot is cast by proxy.
	 *
	 * @return bool True for a proxy vote.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function isProxy(): bool {
		return $this->isProxy;
	}//end isProxy()

	/**
	 * The delegator of a proxy vote.
	 *
	 * @return string|null The delegator UUID, or null.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function delegatorId(): ?string {
		return $this->delegatorId;
	}//end delegatorId()

	/**
	 * Whether the round is a secret ballot.
	 *
	 * @return bool True for a secret round.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function isSecret(): bool {
		return $this->isSecret;
	}//end isSecret()

	/**
	 * Whether the ballot is a proxy vote with a named delegator.
	 *
	 * @return bool True when the vote counts for a delegator.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function isDelegated(): bool {
		return $this->isProxy === true && $this->delegatorId !== null;
	}//end isDelegated()

	/**
	 * The participant the ballot counts for: the delegator on a proxy vote,
	 * the caster otherwise.
	 *
	 * @return string The participant UUID.
	 *
	 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-002-a-recused-member-cannot-vote-on-the-matter
	 */
	public function ballotOwner(): string {
		if ($this->isDelegated() === true) {
			return (string)$this->delegatorId;
		}

		return $this->participantId;
	}//end ballotOwner()
}//end class
