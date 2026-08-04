<?php
/**
 * Decidesk Vote Eligibility Guard
 *
 * The fail-closed checks a ballot must survive before it is recorded: the round
 * must be open, the caster must be a member of the meeting that owns the round
 * (#300), and a proxy vote must be backed by a granted volmacht that has not
 * already been used in this round.
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Fail-closed eligibility rules for casting a vote.
 *
 * Every method either returns silently or throws — there is no "allowed" return
 * value a caller can forget to check.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/user-settings/spec.md
 */
class VoteEligibilityGuard
{

    /**
     * Resolves the meeting that owns a round through the motion/amendment chain.
     *
     * @var AmendmentOrderService
     */
    private readonly AmendmentOrderService $amendmentOrder;

    /**
     * Round-scoped Vote lookups for the one-proxy-per-round rule.
     *
     * @var VoteRepository
     */
    private readonly VoteRepository $votes;

    /**
     * Constructor for VoteEligibilityGuard.
     *
     * @param ContainerInterface  $container           The DI container
     * @param LoggerInterface     $logger              The logger
     * @param MotionService       $motionService       The motion service (subject chain resolution)
     * @param ParticipantResolver $participantResolver Meeting-membership resolver
     *
     * @return void
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        MotionService $motionService,
        private readonly ParticipantResolver $participantResolver,
    ) {
        $this->amendmentOrder = new AmendmentOrderService(
            container: $container,
            motionService: $motionService
        );

        $this->votes = new VoteRepository(container: $container);

    }//end __construct()

    /**
     * Refuse a ballot on a round that is closed or not yet opened.
     *
     * @param array<string, mixed> $round The serialised voting round
     *
     * @return void
     *
     * @throws RuntimeException When the round is closed or not yet open
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function assertRoundVotable(array $round): void
    {
        if (($round['closedAt'] ?? null) !== null && strtotime($round['closedAt']) < time()) {
            throw new RuntimeException('Stemronde is gesloten');
        }

        if (($round['openedAt'] ?? null) === null) {
            throw new RuntimeException('Stemronde is nog niet geopend');
        }

    }//end assertRoundVotable()

    /**
     * Refuse a ballot from someone who is not a member of the owning meeting (#300).
     *
     * The round is linked to a Motion (or an Amendment, which resolves through its
     * parent motion); the Motion is linked to a Meeting via its relations. When the
     * meeting cannot be resolved there is nothing to check against and the ballot
     * proceeds to the remaining guards.
     *
     * @param array<string, mixed> $round         The serialised voting round
     * @param string               $participantId The casting participant UUID
     *
     * @return void
     *
     * @throws RuntimeException When the participant is not a member of the meeting
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function assertMeetingMembership(array $round, string $participantId): void
    {
        $meetingId = $this->amendmentOrder->resolveMeetingIdForRound(round: $round);
        if ($meetingId === null) {
            return;
        }

        $meetingParticipants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
        $memberIds           = array_column($meetingParticipants, 'id');
        if (in_array($participantId, $memberIds, true) === false) {
            throw new RuntimeException('Deelnemer is geen lid van de vergadering');
        }

    }//end assertMeetingMembership()

    /**
     * Refuse a proxy ballot without a granted volmacht, or a second use of one.
     *
     * @param array<string, mixed> $round         The serialised voting round
     * @param string               $votingRoundId The voting round UUID
     * @param string               $participantId The casting participant UUID
     * @param string               $delegatorId   The claimed delegator UUID
     * @param string|null          $callerUid     The caster's Nextcloud UID, when known
     * @param bool                 $isSecret      Whether the round is a secret ballot
     *
     * @return void
     *
     * @throws RuntimeException When no volmacht backs the claim or one is already used
     *
     * @spec openspec/specs/voting-system/spec.md
     * @spec openspec/specs/user-settings/spec.md
     */
    public function assertProxyPermitted(
        array $round,
        string $votingRoundId,
        string $participantId,
        string $delegatorId,
        ?string $callerUid,
        bool $isSecret
    ): void {
        $this->assertProxyGranted(
            round: $round,
            participantId: $participantId,
            delegatorId: $delegatorId,
            callerUid: $callerUid
        );

        $this->assertProxyUnused(delegatorId: $delegatorId, votingRoundId: $votingRoundId, isSecret: $isSecret);

    }//end assertProxyPermitted()

    /**
     * Verify the caster holds a granted proxy from the claimed delegator.
     *
     * User-settings spec — "Delegate cannot vote without explicit proxy": an
     * absence delegation (configured in personal settings) covers notifications
     * and read access only. When the caster IS the configured absence delegate of
     * the claimed delegator, the refusal carries the spec-mandated message plus a
     * pointer to the formal proxy (volmacht) granting process. The proxy-grant
     * check stays authoritative; the delegation consult only selects the wording.
     *
     * @param array<string, mixed> $round         The serialised voting round
     * @param string               $participantId The casting participant UUID
     * @param string               $delegatorId   The claimed delegator UUID
     * @param string|null          $callerUid     The caster's Nextcloud UID, when known
     *
     * @return void
     *
     * @throws RuntimeException When no granted volmacht backs the claim
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    private function assertProxyGranted(array $round, string $participantId, string $delegatorId, ?string $callerUid): void
    {
        if ($this->hasProxyGrant(round: $round, participantId: $participantId, delegatorId: $delegatorId) === true) {
            return;
        }

        if ($this->hasAbsenceDelegation(delegatorId: $delegatorId, participantId: $participantId, callerUid: $callerUid) === true) {
            throw new RuntimeException(
                'Delegation does not include voting rights. A formal proxy (volmacht) is required for voting. '
                .'Grant one via the voting round proxy process (POST /apps/decidesk/api/voting-rounds/{id}/proxy).'
            );
        }

        throw new RuntimeException('Geen geldige volmacht gevonden: de deelnemer heeft geen volmacht ontvangen van deze volmachtgever');

    }//end assertProxyGranted()

    /**
     * Whether the round carries a Proxy note granting delegator -> caster.
     *
     * @param array<string, mixed> $round         The serialised voting round
     * @param string               $participantId The casting participant UUID
     * @param string               $delegatorId   The claimed delegator UUID
     *
     * @return bool True when a matching proxy grant is recorded on the round
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function hasProxyGrant(array $round, string $participantId, string $delegatorId): bool
    {
        foreach (($round['notes'] ?? []) as $note) {
            if (($note['title'] ?? '') !== 'Proxy') {
                continue;
            }

            $body = json_decode($note['body'] ?? '{}', true);
            if (($body['fromParticipantId'] ?? '') === $delegatorId && ($body['toParticipantId'] ?? '') === $participantId) {
                return true;
            }
        }

        return false;

    }//end hasProxyGrant()

    /**
     * Enforce one proxy ballot per delegator per round.
     *
     * For secret rounds participant relations are suppressed for anonymity, so the
     * probe is keyed on the deterministic delegator token instead of the relation.
     *
     * @param string $delegatorId   The delegating participant UUID
     * @param string $votingRoundId The voting round UUID
     * @param bool   $isSecret      Whether the round is a secret ballot
     *
     * @return void
     *
     * @throws RuntimeException When a proxy ballot is already registered
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function assertProxyUnused(string $delegatorId, string $votingRoundId, bool $isSecret): void
    {
        if ($isSecret === true) {
            $this->refuseRegisteredProxy(
                registered: $this->votes->secretProxyExists(delegatorId: $delegatorId, votingRoundId: $votingRoundId)
            );
            return;
        }

        $this->refuseRegisteredProxy(
            registered: $this->votes->openProxyExists(delegatorId: $delegatorId, votingRoundId: $votingRoundId)
        );

    }//end assertProxyUnused()

    /**
     * Raise the duplicate-proxy refusal when a proxy ballot already exists.
     *
     * @param bool $registered Whether a proxy ballot is already registered
     *
     * @return void
     *
     * @throws RuntimeException When $registered is true
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function refuseRegisteredProxy(bool $registered): void
    {
        if ($registered === true) {
            throw new RuntimeException('Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde');
        }

    }//end refuseRegisteredProxy()

    /**
     * Check whether the caster is the configured absence delegate of the delegator.
     *
     * Matches the stored delegate identifier against both the caster's participant
     * UUID and their Nextcloud UID (the settings UI stores NC UIDs). Fail-closed for
     * this gate's purpose: when the preference service is unavailable the method
     * returns false and the caller falls back to the generic no-proxy rejection —
     * the vote is denied either way.
     *
     * @param string      $delegatorId   The claimed delegator (participant UUID or NC UID)
     * @param string      $participantId The casting participant UUID
     * @param string|null $callerUid     The casting user's Nextcloud UID, when known
     *
     * @return bool True when an active absence delegation exists
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    private function hasAbsenceDelegation(string $delegatorId, string $participantId, ?string $callerUid): bool
    {
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
}//end class
