<?php

/**
 * Decidesk Voter Token Secret
 *
 * Owns the per-app HMAC secret behind secret-ballot voter and delegator tokens.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IAppConfig;

/**
 * Resolves the HMAC secret used to derive secret-ballot tokens.
 *
 * Extracted from VotingService so the vote-casting path and the proxy guard
 * derive their tokens from one implementation — two copies of this method
 * could drift and silently produce tokens that no longer match.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoterTokenSecret {
	/**
	 * Constructor for the VoterTokenSecret.
	 *
	 * @param IAppConfig $appConfig App config store holding the HMAC secret
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Return the per-app HMAC secret for secret-ballot voter token generation.
	 *
	 * The secret is generated once with random_bytes() and persisted in app config
	 * so that the HMAC is stable across requests while remaining server-side only.
	 * Using HMAC instead of a bare SHA-256 hash means the mapping from
	 * (participantId, votingRoundId) -> voterToken cannot be computed without
	 * knowledge of this secret, preventing store-admin-level ballot de-anonymisation.
	 *
	 * @return string 64-character hex secret
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function value(): string {
		$appConfig = $this->appConfig;
		$secret = $appConfig->getValueString('decidesk', 'voter_token_secret', '');
		if ($secret === '') {
			// The `sensitive: true` flag below is required — see InitializeSettings. This
			// lazy path exists for the first call before the repair step has run; it must
			// flag the key too, or a fresh instance writes it in cleartext.
			$secret = bin2hex(random_bytes(32));
			$appConfig->setValueString('decidesk', 'voter_token_secret', $secret, sensitive: true);
		}

		return $secret;
	}//end value()

	/**
	 * Derive the deterministic dedup token for one ballot in one round.
	 *
	 * @param string $participantId The voting participant UUID
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return string The opaque voter token.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function voterToken(string $participantId, string $votingRoundId): string {
		return hash_hmac('sha256', $participantId . ':' . $votingRoundId, $this->value());
	}//end voterToken()

	/**
	 * Derive the deterministic one-proxy-per-round token for a delegator.
	 *
	 * Lets a secret round enforce one proxy per delegator without storing the
	 * delegator's participant id on the ballot (anonymity preservation).
	 *
	 * @param string $delegatorId The delegating participant UUID
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return string The opaque delegator token.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function delegatorToken(string $delegatorId, string $votingRoundId): string {
		return hash_hmac('sha256', $delegatorId . ':proxy:' . $votingRoundId, $this->value());
	}//end delegatorToken()
}//end class
