<?php
/**
 * Decidesk Motion Service
 *
 * Service for managing motion lifecycle, co-signatories, amendments, and budget impact.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Decidesk\Lifecycle\DecisionTransitionGuard;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service handling motion lifecycle transitions, co-signatory management,
 * budget impact notes, amendment conflict detection, and amendment application.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
 */
class MotionService
{

    /**
     * Allowed lifecycle transitions for motion-typed Decision objects.
     *
     * Maps each state to the list of valid target states.
     *
     * ADR-005 folded `Motion` into `Decision`, and a fold is not complete until
     * the VOCABULARY moves with the schema. This table used to be written in
     * the retired Motion vocabulary — `submitted | debating | adopted |
     * rejected` — and every one of those four is outside the `Decision.lifecycle`
     * enum (`draft | proposed | deliberating | voting | decided | enacted |
     * archived | withdrawn`). The schema slug had migrated; the words had not,
     * so every transition wrote a value OpenRegister rejects. Measured: the
     * identical payload with `deliberating` validates where `debating` is
     * refused.
     *
     * The mapping applied, and why each is the only honest choice:
     *
     * | retired  | Decision lifecycle | note                                  |
     * |----------|--------------------|---------------------------------------|
     * | submitted| proposed           | a submitted motion has been proposed   |
     * | debating | deliberating       | same state, the schema's word for it   |
     * | voting   | voting             | unchanged                              |
     * | adopted  | decided + outcome  | ADR-005: an OUTCOME, never a state     |
     * | rejected | decided + outcome  | ADR-005: an OUTCOME, never a state     |
     * | withdrawn| withdrawn          | unchanged                              |
     *
     * `adopted` and `rejected` are deliberately ABSENT as target states. They
     * are values of `Decision.outcome`, which is orthogonal to `lifecycle` —
     * the schema says so in as many words ("Orthogonal to 'outcome' (the voting
     * result, set when reaching 'decided')"). Keeping them as pseudo-states
     * would have re-created the very conflation the fold exists to remove, and
     * would have made the two-dimensional truth (decided AND rejected)
     * inexpressible. A vote result now arrives as the `$outcome` argument
     * alongside `newState: 'decided'`.
     *
     * This table is a STRICT SUBSET of the register's own declarative
     * `x-openregister-lifecycle` transition map: it may forbid an edge the
     * register permits (motions never take the `deliberating → decided`
     * shortcut), but it may never permit one the register forbids. That
     * direction matters — the register is the authority, and a service that
     * allowed a wider set would be writing states the store would then refuse.
     *
     * @var array<string, array<string>>
     */
    private const MOTION_TRANSITIONS = [
        'draft'        => ['proposed', 'withdrawn'],
        'proposed'     => ['deliberating', 'withdrawn'],
        'deliberating' => ['voting', 'withdrawn'],
        'voting'       => ['decided', 'withdrawn'],
        'decided'      => ['enacted', 'withdrawn'],
        'enacted'      => ['archived'],
        'archived'     => [],
        'withdrawn'    => [],
    ];

    /**
     * Allowed lifecycle transitions for amendment-typed Decision objects.
     *
     * The same ADR-005 vocabulary migration as MOTION_TRANSITIONS above. The
     * amendment path stays narrower than the motion path, exactly as it was
     * before the fold: an amendment cannot be withdrawn (it is superseded by
     * the parent motion's own fate) and it stops at `decided` — an amendment is
     * never separately enacted or archived, because it is incorporated into its
     * parent motion's text on adoption.
     *
     * @var array<string, array<string>>
     */
    private const AMENDMENT_TRANSITIONS = [
        'draft'        => ['proposed'],
        'proposed'     => ['deliberating'],
        'deliberating' => ['voting'],
        'voting'       => ['decided'],
        'decided'      => [],
    ];

    /**
     * The amendment side of a motion.
     *
     * @var MotionAmendmentService
     */
    private readonly MotionAmendmentService $amendments;

    /**
     * The forward-to-another-body path.
     *
     * @var MotionForwardingService
     */
    private readonly MotionForwardingService $forwarding;

    /**
     * Construct the MotionService.
     *
     * The amendment collaborator is built in the constructor body rather than
     * injected, so the DI signature stays the three services below.
     *
     * @param ContainerInterface $container   The DI container for lazy-loading OR services
     * @param LoggerInterface    $logger      Logger interface
     * @param IUserManager       $userManager Nextcloud user manager for UID lookup
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IUserManager $userManager,
    ) {
        $this->amendments = new MotionAmendmentService(container: $container, logger: $logger);
        $this->forwarding = new MotionForwardingService(container: $container, userManager: $userManager);

    }//end __construct()

    /**
     * Get the ObjectService from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Get the MotionNotifier from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return MotionNotifier
     */
    private function getNotifier(): MotionNotifier
    {
        return $this->container->get(MotionNotifier::class);

    }//end getNotifier()

    /**
     * Get the MotionLinkResolver from the container.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return MotionLinkResolver
     */
    private function getLinkResolver(): MotionLinkResolver
    {
        return $this->container->get(MotionLinkResolver::class);

    }//end getLinkResolver()

    /**
     * Resolve the meeting UUID linked to a motion.
     *
     * Honours BOTH link shapes: the flat `meeting` property (what the UI and
     * the Newman fixtures write) and the structured `relations` entry.
     * Returns null when the motion, or any meeting link on it, cannot be
     * resolved — callers treat that as "no meeting context".
     *
     * @param string $motionId The motion UUID
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return string|null The meeting UUID or null if not found
     */
    public function resolveMeetingId(string $motionId): ?string
    {
        return $this->getLinkResolver()->resolveMeetingId(motionId: $motionId);

    }//end resolveMeetingId()

    /**
     * Transition a Motion or Amendment to a new lifecycle state.
     *
     * Validates that the transition is allowed for the object type, then
     * updates the `lifecycle` and `status` fields via ObjectService and logs
     * the event to ActivityService (via OpenRegister automatic audit trail).
     *
     * #317: The actorId must be a non-empty Nextcloud user UID or the reserved
     * sentinel 'system' (used only for internal service-to-service calls such as
     * VotingService closing a round). An empty actorId is rejected to prevent
     * unauthenticated DI-path callers from transitioning lifecycle without
     * first performing their own auth check.
     *
     * Controllers that expose this via HTTP must call their own requireChairOrSecretary()
     * guard BEFORE invoking this method and pass the authenticated UID as actorId.
     *
     * ADR-005: `$outcome` carries the vote result — `adopted` or `rejected` —
     * which is a value of `Decision.outcome`, NOT a lifecycle state. It is
     * required when, and only when, the transition enters a terminal outcome
     * state (`decided | enacted | archived`); passing it on an in-flight
     * transition is a caller error and is refused, because an in-flight motion
     * has no result yet and recording one would be a lie about what happened.
     *
     * @param string      $objectId   UUID of the motion- or amendment-typed Decision
     * @param string      $objectType ADR-005 decisionType discriminator: 'motion' or 'amendment'
     * @param string      $newState   Target lifecycle state (Decision.lifecycle vocabulary)
     * @param string      $actorId    Nextcloud user UID performing the transition, or 'system' for internal calls
     * @param string|null $outcome    Vote result ('adopted'|'rejected'), required entering a terminal outcome state and refused otherwise
     *
     * @throws InvalidArgumentException When the transition is not allowed, the co-signer minimum is
     *                                  not met, actorId is empty, or the outcome is missing,
     *                                  misplaced, or outside the recorded vocabulary
     * @throws RuntimeException         When the object cannot be found or saved
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function transitionLifecycle(
        string $objectId,
        string $objectType,
        string $newState,
        string $actorId,
        ?string $outcome=null,
    ): void {
        // #317: Reject calls without an authenticated actor to prevent bare DI-path abuse.
        if ($actorId === '') {
            throw new InvalidArgumentException('actorId must be a non-empty Nextcloud user UID or the sentinel "system"');
        }

        // ADR-005: `$objectType` is a decisionType discriminator, not a schema
        // slug — the motion/amendment schemas were retired into `decision`. Only
        // the two types this method has a transition table for are accepted, and
        // the rejection happens BEFORE the register is touched: a value that
        // reached a schema lookup used to raise DoesNotExistException, which is
        // neither InvalidArgumentException nor RuntimeException and therefore
        // escaped every controller catch clause as a 500.
        //
        // The `default` arm also closes a fail-open: the previous
        // `$transitions = MOTION_TRANSITIONS; if ($objectType === 'amendment')`
        // shape silently applied the motion table to any other value.
        $transitions = match ($objectType) {
            'motion'    => self::MOTION_TRANSITIONS,
            'amendment' => self::AMENDMENT_TRANSITIONS,
            default     => throw new InvalidArgumentException(
                "Unknown objectType '$objectType'; expected one of: motion, amendment"
            ),
        };

        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');

        $object      = $objectService->find($objectId);
        $objectArray = [];
        if ($object !== null) {
            $objectArray = $object->getObject();
        }

        if ($object === null
            || ($objectArray['decisionType'] ?? null) !== $objectType
        ) {
            throw new RuntimeException("Object $objectType/$objectId not found");
        }

        // The register declares `initial: "draft"` for Decision.lifecycle, so an
        // object that has never been transitioned is in `draft` — not in the
        // retired Motion vocabulary's `submitted`, which is not a state this
        // schema has ever accepted.
        $currentState = $objectArray['lifecycle'] ?? 'draft';

        $allowed = $transitions[$currentState] ?? [];
        if (in_array($newState, $allowed, true) === false) {
            throw new InvalidArgumentException(
                "Transition from '$currentState' to '$newState' is not allowed for $objectType"
            );
        }

        $this->assertCoSignerThreshold(
            objectType: $objectType,
            currentState: $currentState,
            newState: $newState,
            objectArray: $objectArray
        );

        $payload = $this->applyOutcome(
            objectArray: $objectArray,
            newState: $newState,
            outcome: $outcome
        );

        $objectService->saveObject(
            object: array_merge($payload, ['lifecycle' => $newState, 'status' => $newState]),
            register: 'decidesk',
            schema: 'decision',
            uuid: $objectId,
        );

        $this->logger->info(
            "Decidesk: $objectType $objectId transitioned from $currentState to $newState by $actorId"
        );

    }//end transitionLifecycle()

    /**
     * Fold the vote result onto the decision when it reaches a terminal state.
     *
     * ADR-005 separated the two things the retired Motion vocabulary conflated:
     * `lifecycle` says how far the decision has travelled, `outcome` says what
     * was decided. `adopted` and `rejected` live on the second axis, so they
     * arrive here rather than as transition targets.
     *
     * The terminal-completeness rule this enforces is the same one
     * DecisionLifecycleService applies on the generic decision path, and it is
     * enforced HERE for the same reason it is enforced there: OpenRegister does
     * not evaluate a conditional `required` — `Db\Schema` has no `if`/`then`
     * field and `Schema::getSchemaObject()` rebuilds the validated schema from a
     * fixed key list, so such a block is discarded before the validator runs.
     * A decorative schema constraint would therefore enforce NOTHING. The
     * transition boundary is where the state is actually entered, so it is
     * where the requirement can actually be met.
     *
     * This deliberately does NOT fail open. The method it replaces chose the
     * motion table for any objectType that was not literally 'amendment', and
     * that shape is the reason this file is being repaired; re-introducing a
     * "carry on if we cannot tell" branch here would recreate it on the outcome
     * axis — a decision recorded as `decided` with no result is indistinguishable
     * from one that was never voted on.
     *
     * @param array<string, mixed> $objectArray The decision as stored
     * @param string               $newState    The lifecycle state being entered
     * @param string|null          $outcome     The supplied vote result, when any
     *
     * @throws InvalidArgumentException When the outcome is missing, misplaced, or out of vocabulary
     *
     * @spec openspec/specs/motion-amendment/spec.md
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return array<string, mixed> The payload to persist
     */
    private function applyOutcome(array $objectArray, string $newState, ?string $outcome): array
    {
        $isTerminal = in_array($newState, DecisionTransitionGuard::TERMINAL_OUTCOME_STATES, true);

        if ($isTerminal === false) {
            if ($outcome !== null) {
                throw new InvalidArgumentException(
                    "An outcome may only be recorded when entering a terminal state ("
                    .implode(', ', DecisionTransitionGuard::TERMINAL_OUTCOME_STATES)
                    ."); '$newState' is still in flight"
                );
            }

            return $objectArray;
        }

        $payload = $objectArray;

        if ($outcome !== null) {
            if (in_array($outcome, DecisionTransitionGuard::OUTCOME_VALUES, true) === false) {
                throw new InvalidArgumentException(
                    "Outcome '$outcome' is not a recorded result; expected one of: "
                    .implode(', ', DecisionTransitionGuard::OUTCOME_VALUES)
                );
            }

            $payload['outcome'] = $outcome;
        }

        // `decisionDate` is the OTHER terminal-completeness field. It is stamped
        // rather than demanded from the caller because the moment a decision
        // becomes decided is this moment — asking a caller to supply it would
        // invite a value that disagrees with the audit trail. An already-present
        // date is never overwritten: re-entering a terminal state (decided →
        // enacted → archived) must not restamp when the decision was made.
        $existingDate = null;
        if (array_key_exists('decisionDate', $payload) === true && is_string($payload['decisionDate']) === true) {
            $existingDate = trim($payload['decisionDate']);
        }

        if ($existingDate === null || $existingDate === '') {
            $payload['decisionDate'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        }

        $missing = $this->getTransitionGuard()->getMissingTerminalFields(decision: $payload);
        if ($missing !== []) {
            throw new InvalidArgumentException(
                "A $newState decision cannot be recorded without a result. "
                .'Missing or invalid: '.implode(', ', $missing).'.'
            );
        }

        return $payload;

    }//end applyOutcome()

    /**
     * Get the shared decision transition guard.
     *
     * Resolved from the container so this service and the generic decision path
     * apply the SAME terminal-completeness rule rather than two copies that can
     * drift apart.
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return DecisionTransitionGuard
     */
    private function getTransitionGuard(): DecisionTransitionGuard
    {
        return $this->container->get(DecisionTransitionGuard::class);

    }//end getTransitionGuard()

    /**
     * Enforce the co-signer minimum before a motion may enter debate.
     *
     * Motion-amendment spec: a motion may only leave 'proposed' for
     * 'deliberating' (the ADR-005 Decision-lifecycle spelling of the retired
     * 'submitted' → 'debating' edge) when it carries at least the configured
     * number of co-signers (app config
     * motion_min_cosigners, default 0 = disabled). The rejection message names
     * the requirement and the shortfall so the proposer knows how many more
     * co-signers to gather before resubmitting. Amendments are exempt.
     *
     * @param string               $objectType   ADR-005 decisionType discriminator: 'motion' or 'amendment'
     * @param string               $currentState The current lifecycle state
     * @param string               $newState     The target lifecycle state
     * @param array<string, mixed> $objectArray  The serialized object being transitioned
     *
     * @throws InvalidArgumentException When the co-signer minimum is not met
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    private function assertCoSignerThreshold(string $objectType, string $currentState, string $newState, array $objectArray): void
    {
        if ($objectType === 'amendment' || $currentState !== 'proposed' || $newState !== 'deliberating') {
            return;
        }

        $appConfig     = $this->container->get(\OCP\IAppConfig::class);
        $minCoSigners  = (int) $appConfig->getValueString('decidesk', 'motion_min_cosigners', '0');
        $coSignerCount = count($objectArray['coSigners'] ?? []);

        if ($minCoSigners > 0 && $coSignerCount < $minCoSigners) {
            throw new InvalidArgumentException(
                sprintf(
                    'Motion requires at least %d co-signers before it can proceed to debate; it currently has %d (%d more needed)',
                    $minCoSigners,
                    $coSignerCount,
                    ($minCoSigners - $coSignerCount)
                )
            );
        }

    }//end assertCoSignerThreshold()

    /**
     * Send co-signature request notifications to listed Participants.
     *
     * For each participant ID, fetches the Participant object and sends a
     * Nextcloud notification with the motion title and a deep link.
     *
     * @param string        $motionId       UUID of the Motion
     * @param array<string> $participantIds Array of Participant UUIDs to notify
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function requestCoSignature(string $motionId, array $participantIds): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');

        $motionData = $this->findMotionData(objectService: $objectService, motionId: $motionId);
        $title      = $motionData['title'] ?? 'Motie';
        $pendingSignerUids = [];

        foreach ($participantIds as $participantId) {
            try {
                $nextcloudUserId = $this->coSignerUid(
                    objectService: $objectService,
                    participantId: $participantId
                );
                if ($nextcloudUserId === null) {
                    continue;
                }

                $pendingSignerUids[] = $nextcloudUserId;

                $this->getNotifier()->notify(
                    userId: $nextcloudUserId,
                    motionId: $motionId,
                    subject: 'co_sign_request',
                    parameters: ['motionTitle' => $title, 'motionId' => $motionId],
                    failureLog: "Decidesk: Could not send co-sign notification to $nextcloudUserId: "
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    "Decidesk: Could not send co-sign request to participant $participantId: {$e->getMessage()}"
                );
            }//end try
        }//end foreach

        // Persist the set of invited Nextcloud UIDs so coSignConfirm can verify authorization.
        if (empty($pendingSignerUids) === false) {
            $existing = array_unique(
                array_merge(
                    $motionData['pendingCoSignerUids'] ?? [],
                    $pendingSignerUids,
                )
            );
            $objectService->setRegister('decidesk');
            $objectService->setSchema('decision');
            $objectService->saveObject(
                object: array_merge($motionData, ['pendingCoSignerUids' => array_values($existing)]),
                register: 'decidesk',
                schema: 'decision',
                uuid: $motionId,
            );
        }

    }//end requestCoSignature()

    /**
     * Resolve the Nextcloud UID of a participant invited to co-sign.
     *
     * Prefers the stored nextcloudUserId and falls back to an email lookup,
     * which only resolves when it matches exactly one account.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $participantId UUID of the Participant
     *
     * @return string|null The Nextcloud UID, or null when it cannot be resolved.
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     */
    private function coSignerUid(object $objectService, string $participantId): ?string
    {
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $participant = $objectService->find($participantId);
        if ($participant === null) {
            return null;
        }

        $participantData = $participant->getObject();
        $nextcloudUserId = ($participantData['nextcloudUserId'] ?? null);
        if ($nextcloudUserId !== null) {
            return (string) $nextcloudUserId;
        }

        $email = ($participantData['email'] ?? null);
        if ($email === null) {
            return null;
        }

        $users = $this->userManager->getByEmail($email);
        if (count($users) !== 1) {
            return null;
        }

        return (string) $users[0]->getUID();

    }//end coSignerUid()

    /**
     * Append a co-signer display name to a Motion's coSigners array.
     *
     * Idempotent: if the name is already present, no duplicate is added.
     * Saves the updated Motion via ObjectService.
     *
     * @param string $motionId     UUID of the Motion
     * @param string $coSignerName Display name of the confirming co-signer
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function addCoSigner(string $motionId, string $coSignerName): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');

        $motionData = $this->findMotionData(objectService: $objectService, motionId: $motionId);
        $coSigners  = $motionData['coSigners'] ?? [];

        if (in_array($coSignerName, $coSigners, true) === false) {
            $coSigners[] = $coSignerName;
            $objectService->saveObject(
                object: array_merge($motionData, ['coSigners' => $coSigners]),
                register: 'decidesk',
                schema: 'decision',
                uuid: $motionId,
            );
        }

    }//end addCoSigner()

    /**
     * Check whether a Nextcloud user was invited to co-sign a Motion.
     *
     * Returns true when the user's UID appears in the motion's pendingCoSignerUids list.
     *
     * @param string $motionId     UUID of the Motion
     * @param string $nextcloudUid The Nextcloud user ID to verify
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return bool True when the user was invited
     */
    public function isPendingCoSigner(string $motionId, string $nextcloudUid): bool
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');

        $motionObject = $objectService->find($motionId);
        if ($motionObject === null) {
            return false;
        }

        $motionData = $motionObject->getObject();
        if (($motionData['decisionType'] ?? null) !== 'motion') {
            return false;
        }

        return in_array($nextcloudUid, $motionData['pendingCoSignerUids'] ?? [], true);

    }//end isPendingCoSigner()

    /**
     * Create or update a structured "Budget impact" note on a Motion.
     *
     * Stores budget line reference, amount delta, and rationale as a JSON
     * body in a note with title "Budget impact" using the OpenRegister
     * built-in notes mechanism.
     *
     * @param string $motionId    UUID of the Motion
     * @param string $budgetLine  Budget line reference (e.g. "Programma 4 - Jeugdzorg")
     * @param float  $amountDelta Amount change in EUR (positive = increase, negative = decrease)
     * @param string $rationale   Policy rationale for the budget change
     *
     * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.1
     *
     * @return void
     */
    public function saveBudgetImpact(string $motionId, string $budgetLine, float $amountDelta, string $rationale): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');

        $motionData = $this->findMotionData(objectService: $objectService, motionId: $motionId);
        $notes      = $motionData['notes'] ?? [];

        $budgetPayload = json_encode(
            [
                'budgetLine'  => $budgetLine,
                'amountDelta' => $amountDelta,
                'rationale'   => $rationale,
            ]
        );
        if ($budgetPayload === false) {
            throw new RuntimeException('JSON encoding of budget impact failed: '.json_last_error_msg());
        }

        $budgetNote = [
            'title' => 'Budget impact',
            'body'  => $budgetPayload,
        ];

        // Replace existing budget impact note or append.
        $updated = false;
        foreach ($notes as &$note) {
            if (($note['title'] ?? '') === 'Budget impact') {
                $note    = $budgetNote;
                $updated = true;
                break;
            }
        }

        unset($note);

        if ($updated === false) {
            $notes[] = $budgetNote;
        }

        $objectService->saveObject(
            object: array_merge($motionData, ['notes' => $notes]),
            register: 'decidesk',
            schema: 'decision',
            uuid: $motionId,
        );

    }//end saveBudgetImpact()

    /**
     * Load a motion decision by id, refusing anything that is not one.
     *
     * ADR-005 folded the `motion` schema into `decision`, so a lookup by id no
     * longer proves the object is a motion — the `decisionType` discriminator
     * does. A miss raises the same RuntimeException the schema-scoped lookup
     * used to raise, which the controllers map to 404.
     *
     * @param object $objectService The OpenRegister ObjectService, already scoped to the decision schema
     * @param string $motionId      UUID of the motion decision
     *
     * @throws RuntimeException When no motion decision carries that id
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return array<string, mixed> The serialized motion decision
     */
    private function findMotionData(object $objectService, string $motionId): array
    {
        $motionObject = $objectService->find($motionId);
        $motionData   = [];
        if ($motionObject !== null) {
            $motionData = $motionObject->getObject();
        }

        if ($motionObject === null
            || ($motionData['decisionType'] ?? null) !== 'motion'
        ) {
            throw new RuntimeException("Motion $motionId not found");
        }

        return $motionData;

    }//end findMotionData()

    /**
     * Resolve every Amendment that belongs to a Motion.
     *
     * Delegates to MotionAmendmentService, which honours BOTH link shapes (the
     * flat `amends` property and a structured `relations` entry) and
     * dedups by id.
     *
     * @param string $motionId UUID of the parent Motion
     *
     * @return array<int, array<string, mixed>> Serialized amendment objects
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function getAmendmentsForMotion(string $motionId): array
    {
        return $this->amendments->getAmendmentsForMotion(motionId: $motionId);

    }//end getAmendmentsForMotion()

    /**
     * Stamp the parliamentary voting order on a motion's amendments.
     *
     * @param string        $motionId            UUID of the parent Motion
     * @param array<string> $orderedAmendmentIds The amendment UUIDs in voting order
     * @param string        $actorId             The Nextcloud user ID of the chair
     *
     * @return array<int, array<string, mixed>> The reordered amendments
     *
     * @throws InvalidArgumentException When an id does not belong to the motion
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function setAmendmentVotingOrder(string $motionId, array $orderedAmendmentIds, string $actorId): array
    {
        return $this->amendments->setAmendmentVotingOrder(
            motionId: $motionId,
            orderedAmendmentIds: $orderedAmendmentIds,
            actorId: $actorId
        );

    }//end setAmendmentVotingOrder()

    /**
     * Flag amendments that overlap with a newly submitted one.
     *
     * @param string $motionId       UUID of the parent Motion
     * @param string $newAmendmentId UUID of the newly submitted Amendment
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function detectConflicts(string $motionId, string $newAmendmentId): void
    {
        $this->amendments->detectConflicts(motionId: $motionId, newAmendmentId: $newAmendmentId);

    }//end detectConflicts()

    /**
     * Merge an adopted amendment into its parent motion's text.
     *
     * @param string $motionId    UUID of the parent Motion
     * @param string $amendmentId UUID of the adopted Amendment
     *
     * @return void
     *
     * @spec openspec/specs/motion-amendment/spec.md
     */
    public function applyAmendment(string $motionId, string $amendmentId): void
    {
        $this->amendments->applyAmendment(motionId: $motionId, amendmentId: $amendmentId);

    }//end applyAmendment()

    /**
     * Forward a motion to a target governance body with optional approval workflow.
     *
     * Checks the actor's role against the motion_forwarding_roles config. Creates a new
     * Motion in the target body and stores a relation between the forwarded and source
     * motions. If approval is required, the forwarded Motion is created with lifecycle
     * 'proposed' (ADR-005 Decision vocabulary) and a notification is sent to the target chair.
     *
     * @param string $motionId      The motion UUID to forward
     * @param string $targetBodyId  The target governance body UUID
     * @param string $actorId       The Nextcloud user ID of the person forwarding
     * @param string $justification The reason for forwarding
     *
     * @return array<string,mixed> The created forwarded Motion object
     *
     * @throws RuntimeException When role is not authorized or motion is not found
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function forwardMotion(string $motionId, string $targetBodyId, string $actorId, string $justification): array
    {
        return $this->forwarding->forward(
            motionId: $motionId,
            targetBodyId: $targetBodyId,
            actorId: $actorId,
            justification: $justification
        );

    }//end forwardMotion()
}//end class
