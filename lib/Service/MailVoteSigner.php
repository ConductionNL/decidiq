<?php

/**
 * Decidesk Mail Vote Signer
 *
 * Signs and verifies the `_mail` metadata entries that carry email vote
 * replies on a VotingRound object.
 *
 * The signature is the ONLY thing that distinguishes an entry written by the
 * trusted ingestion path from one injected directly into OpenRegister by a user
 * with write access to the VotingRound (OWASP A08:2021 / issue #299), so it
 * lives in one place with one implementation rather than being restated
 * wherever an entry is read.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Derives, applies and verifies the HMAC on VotingRound `_mail` entries.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 */
class MailVoteSigner {

	/**
	 * HMAC algorithm used for _mail entry signatures.
	 *
	 * @var string
	 */
	private const HMAC_ALGO = 'sha256';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config holding the base secret
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Compute the HMAC for a _mail entry.
	 *
	 * The signed payload is the canonical concatenation:
	 *   participantId + ':' + roundId + ':' + timestamp
	 * Covers the three fields that identify a unique, time-bound vote instruction.
	 * `replyBody` is intentionally excluded from the signed payload so that the
	 * ingestion path does not need to know the vote value at signing time (the vote
	 * body is trusted only after the signature is verified).
	 *
	 * @param string $participantId The participant UUID
	 * @param string $roundId The VotingRound UUID
	 * @param string $timestamp ISO 8601 timestamp written by the ingestion path
	 *
	 * @return string The hex HMAC
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function compute(string $participantId, string $roundId, string $timestamp): string {
		$payload = $participantId . ':' . $roundId . ':' . $timestamp;

		return hash_hmac(self::HMAC_ALGO, $payload, $this->secret(roundId: $roundId));
	}//end compute()

	/**
	 * Sign a _mail entry array and return it with the hmac field set.
	 *
	 * @param array<string,mixed> $entry The raw _mail entry (must contain participantId and timestamp)
	 * @param string $roundId The VotingRound UUID (used to derive the secret)
	 *
	 * @return array<string,mixed> The entry with hmac appended
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function sign(array $entry, string $roundId): array {
		$entry['hmac'] = $this->compute(
			participantId: (string)($entry['participantId'] ?? ''),
			roundId: $roundId,
			timestamp: (string)($entry['timestamp'] ?? '')
		);

		return $entry;
	}//end sign()

	/**
	 * Verify the HMAC on a _mail entry.
	 *
	 * Returns false for any entry that is missing the hmac field, has an
	 * empty participantId, or whose HMAC does not match the expected value.
	 *
	 * @param array<string,mixed> $entry The _mail entry to verify
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return bool True when the entry is authentically signed
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function verify(array $entry, string $roundId): bool {
		$providedHmac = (string)($entry['hmac'] ?? '');
		$participantId = (string)($entry['participantId'] ?? '');
		$timestamp = (string)($entry['timestamp'] ?? '');

		if ($providedHmac === '' || $participantId === '' || $timestamp === '') {
			return false;
		}

		$expectedHmac = $this->compute(
			participantId: $participantId,
			roundId: $roundId,
			timestamp: $timestamp
		);

		// Constant-time comparison prevents timing-oracle attacks.
		return hash_equals($expectedHmac, $providedHmac);
	}//end verify()

	/**
	 * Derive the per-round HMAC secret from the app's voter_token_secret.
	 *
	 * Uses the same underlying voter_token_secret as VotingService so that
	 * both services share one secret without tight coupling. A domain prefix
	 * differentiates this use-case from vote-token HMACs.
	 *
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return string The derived per-round secret (hex)
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function secret(string $roundId): string {
		$base = $this->appConfig->getValueString(Application::APP_ID, 'voter_token_secret', '');
		if ($base === '') {
			// Generate and persist a base secret if one does not yet exist.
			// sensitive: true — see InitializeSettings; this is the same HMAC key.
			$base = bin2hex(random_bytes(32));
			$this->appConfig->setValueString(Application::APP_ID, 'voter_token_secret', $base, sensitive: true);
		}

		// Derive a round-scoped key using HKDF-style domain separation.
		return hash_hmac(self::HMAC_ALGO, 'mail-reply:' . $roundId, $base);
	}//end secret()
}//end class
