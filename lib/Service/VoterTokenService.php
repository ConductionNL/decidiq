<?php
/**
 * Decidesk Voter Token Service
 *
 * Server-side HMAC tokens for secret-ballot voting: the per-app secret, the
 * per-(participant, round) voter token, the per-(delegator, round) proxy token,
 * and the deterministic idempotency slug that makes vote casting an upsert.
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Opaque, server-side-only identifiers for ballot anonymity and vote idempotency.
 *
 * Using an HMAC instead of a bare hash means the mapping from
 * (participantId, votingRoundId) to voterToken cannot be recomputed without the
 * server secret, which prevents store-admin-level ballot de-anonymisation.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VoterTokenService
{
    /**
     * Constructor for VoterTokenService.
     *
     * @param ContainerInterface $container The DI container (lazy IAppConfig resolution)
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {

    }//end __construct()

    /**
     * Build the opaque per-(participant, round) ballot token.
     *
     * @param string $participantId The voting participant UUID
     * @param string $votingRoundId The voting round UUID
     *
     * @return string A 64-character hex token
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function voterToken(string $participantId, string $votingRoundId): string
    {
        return hash_hmac('sha256', $participantId.':'.$votingRoundId, $this->secret());

    }//end voterToken()

    /**
     * Build the opaque per-(delegator, round) proxy token.
     *
     * Lets one-proxy-per-round be enforced on a secret round without storing the
     * delegator's participant identity next to the ballot.
     *
     * @param string $delegatorId   The delegating participant UUID
     * @param string $votingRoundId The voting round UUID
     *
     * @return string A 64-character hex token
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function delegatorToken(string $delegatorId, string $votingRoundId): string
    {
        return hash_hmac('sha256', $delegatorId.':proxy:'.$votingRoundId, $this->secret());

    }//end delegatorToken()

    /**
     * Build the deterministic @self.slug that makes vote casting idempotent.
     *
     * Concurrent casts for the same (participant, round) must upsert rather than
     * insert twice: OpenRegister's saveObject() performs an UPDATE when the slug
     * matches an existing object, so the second request safely overwrites the
     * first with the same value.
     *
     * - Secret rounds:     the voter token, already opaque.
     * - Non-secret rounds: "vote-{round}-{participant}", truncated because slugs
     *   must be URL-safe and at most 255 characters.
     *
     * @param string      $votingRoundId The voting round UUID
     * @param string      $participantId The voting participant UUID
     * @param bool        $isSecret      Whether the round is secret
     * @param bool        $isProxy       Whether the vote is cast by proxy
     * @param string|null $delegatorId   The delegator UUID for a proxy vote
     *
     * @return string The idempotency slug
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function idempotencySlug(
        string $votingRoundId,
        string $participantId,
        bool $isSecret,
        bool $isProxy,
        ?string $delegatorId
    ): string {
        if ($isSecret === true) {
            return $this->voterToken(participantId: $participantId, votingRoundId: $votingRoundId);
        }

        $slug = 'vote-'.substr($votingRoundId, 0, 8).'-'.substr($participantId, 0, 8);
        if ($isProxy === true && $delegatorId !== null) {
            $slug .= '-proxy-'.substr($delegatorId, 0, 8);
        }

        return $slug;

    }//end idempotencySlug()

    /**
     * Return the per-app HMAC secret for secret-ballot voter token generation.
     *
     * The secret is generated once with random_bytes() and persisted in app config
     * so that the HMAC is stable across requests while remaining server-side only.
     *
     * @return string 64-character hex secret
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function secret(): string
    {
        $appConfig = $this->container->get(\OCP\IAppConfig::class);
        $secret    = $appConfig->getValueString('decidesk', 'voter_token_secret', '');
        if ($secret === '') {
            // The `sensitive: true` flag below is required — see InitializeSettings. This
            // lazy path exists for the first call before the repair step has run; it must
            // flag the key too, or a fresh instance writes it in cleartext.
            $secret = bin2hex(random_bytes(32));
            $appConfig->setValueString('decidesk', 'voter_token_secret', $secret, sensitive: true);
        }

        return $secret;

    }//end secret()
}//end class
