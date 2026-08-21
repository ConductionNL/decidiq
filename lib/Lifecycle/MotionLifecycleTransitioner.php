<?php

/**
 * Decidesk Motion Lifecycle Transitioner
 *
 * The guarded lifecycle state machine for motion- and amendment-typed Decision
 * objects: the transition tables, the co-signer gate, the outcome axis, and the
 * terminal-completeness check that must be met before a decision may be
 * recorded as decided.
 *
 * @category Lifecycle
 * @package  OCA\Decidesk\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guarded lifecycle transitions for motion- and amendment-typed Decisions.
 *
 * WHY THIS IS ITS OWN CLASS. This logic lived inside MotionService, which also
 * owns co-signatures, budget-impact notes, amendment application and
 * forwarding. Adding the ADR-005 outcome axis to it pushed the class past its
 * complexity and coupling limits, and the metric was reporting something real:
 * a state machine, an authorization gate and a persistence path had accreted
 * inside a class whose subject is "a motion". They are extracted here whole
 * rather than trimmed, so the seam MotionService exposes is unchanged and every
 * existing caller and test keeps working.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class MotionLifecycleTransitioner {

	/**
	 * Allowed lifecycle transitions for motion-typed Decision objects.
	 *
	 * ADR-005 folded `Motion` into `Decision`, and a fold is not complete until
	 * the VOCABULARY moves with the schema. This table used to be written in
	 * the retired Motion vocabulary — `submitted | debating | adopted |
	 * rejected` — and every one of those four is outside the
	 * `Decision.lifecycle` enum (`draft | proposed | deliberating | voting |
	 * decided | enacted | archived | withdrawn`). The schema slug had migrated;
	 * the words had not, so every transition wrote a value OpenRegister
	 * rejects. Measured: the identical payload with `deliberating` validates
	 * where `debating` is refused.
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
	public const MOTION_TRANSITIONS = [
		'draft' => ['proposed', 'withdrawn'],
		'proposed' => ['deliberating', 'withdrawn'],
		'deliberating' => ['voting', 'withdrawn'],
		'voting' => ['decided', 'withdrawn'],
		'decided' => ['enacted', 'withdrawn'],
		'enacted' => ['archived'],
		'archived' => [],
		'withdrawn' => [],
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
	public const AMENDMENT_TRANSITIONS = [
		'draft' => ['proposed'],
		'proposed' => ['deliberating'],
		'deliberating' => ['voting'],
		'voting' => ['decided'],
		'decided' => [],
	];

	/**
	 * Construct the transitioner.
	 *
	 * @param ContainerInterface $container The DI container (lazy ObjectService / IAppConfig)
	 * @param LoggerInterface $logger Logger interface
	 * @param DecisionTransitionGuard $guard Shared terminal-completeness policy
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DecisionTransitionGuard $guard,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Transition a motion- or amendment-typed Decision to a new lifecycle state.
	 *
	 * @param string $objectId UUID of the motion- or amendment-typed Decision
	 * @param string $objectType ADR-005 decisionType discriminator: 'motion' or 'amendment'
	 * @param string $newState Target lifecycle state (Decision.lifecycle vocabulary)
	 * @param string $actorId Nextcloud user UID, or 'system' for internal calls
	 * @param string|null $outcome Vote result ('adopted'|'rejected'), terminal states only
	 *
	 * @throws InvalidArgumentException When the transition, actor, co-signer count or outcome is refused
	 * @throws RuntimeException When the object cannot be found or saved
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	public function transition(
		string $objectId,
		string $objectType,
		string $newState,
		string $actorId,
		?string $outcome = null,
	): void {
		// #317: Reject calls without an authenticated actor to prevent bare
		// DI-path abuse.
		if ($actorId === '') {
			throw new InvalidArgumentException('actorId must be a non-empty Nextcloud user UID or the sentinel "system"');
		}

		$transitions = $this->transitionTableFor(objectType: $objectType);

		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('decision');

		$objectArray = $this->loadDecision(
			objectService: $this->objectService,
			objectId: $objectId,
			objectType: $objectType
		);

		// The register declares `initial: "draft"` for Decision.lifecycle, so an
		// object that has never been transitioned is in `draft` — not in the
		// retired Motion vocabulary's `submitted`, which is not a state this
		// schema has ever accepted.
		$currentState = $objectArray['lifecycle'] ?? 'draft';

		if (in_array($newState, ($transitions[$currentState] ?? []), true) === false) {
			throw new InvalidArgumentException(
				"Transition from '$currentState' to '$newState' is not allowed for $objectType"
			);
		}

		$this->assertCoSignerThreshold(
			objectType: $objectType,
			currentState: (string)$currentState,
			newState: $newState,
			objectArray: $objectArray
		);

		$payload = $this->applyOutcome(
			objectArray: $objectArray,
			newState: $newState,
			outcome: $outcome
		);

		$this->objectService->saveObject(
			object: array_merge($payload, ['lifecycle' => $newState, 'status' => $newState]),
			register: 'decidesk',
			schema: 'decision',
			uuid: $objectId,
		);

		$this->logger->info(
			"Decidesk: $objectType $objectId transitioned from $currentState to $newState by $actorId"
		);

	}//end transition()

	/**
	 * Pick the transition table for a decisionType, refusing anything else.
	 *
	 * ADR-005: `$objectType` is a decisionType discriminator, not a schema slug
	 * — the motion/amendment schemas were retired into `decision`. The
	 * rejection happens BEFORE the register is touched: a value that reached a
	 * schema lookup used to raise DoesNotExistException, which is neither
	 * InvalidArgumentException nor RuntimeException and therefore escaped every
	 * controller catch clause as a 500.
	 *
	 * The `default` arm also closes a fail-open: the previous
	 * `$transitions = MOTION_TRANSITIONS; if ($objectType === 'amendment')`
	 * shape silently applied the motion table to any other value.
	 *
	 * @param string $objectType The decisionType discriminator
	 *
	 * @throws InvalidArgumentException When the type has no transition table
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return array<string, array<string>> The transition table
	 */
	private function transitionTableFor(string $objectType): array {
		return match ($objectType) {
			'motion' => self::MOTION_TRANSITIONS,
			'amendment' => self::AMENDMENT_TRANSITIONS,
			default => throw new InvalidArgumentException(
				"Unknown objectType '$objectType'; expected one of: motion, amendment"
			),
		};

	}//end transitionTableFor()

	/**
	 * Load a decision and confirm it is of the expected decisionType.
	 *
	 * A missing object and a decision of the wrong type are the same answer:
	 * this id is not one of these. An absent object has no discriminator, so
	 * the single check covers both.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $objectId The decision UUID
	 * @param string $objectType The expected decisionType discriminator
	 *
	 * @throws RuntimeException When the object is absent or of another type
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return array<string, mixed> The decision object array
	 */
	private function loadDecision(object $objectService, string $objectId, string $objectType): array {
		$object = $objectService->find($objectId);
		$objectArray = [];
		if ($object !== null) {
			$objectArray = $object->getObject();
		}

		if ($object === null || ($objectArray['decisionType'] ?? null) !== $objectType) {
			throw new RuntimeException("Object $objectType/$objectId not found");
		}

		return $objectArray;
	}//end loadDecision()

	/**
	 * Enforce the co-signer minimum before a motion may enter debate.
	 *
	 * Motion-amendment spec: a motion may only leave 'proposed' for
	 * 'deliberating' (the ADR-005 Decision-lifecycle spelling of the retired
	 * 'submitted' → 'debating' edge) when it carries at least the configured
	 * number of co-signers (app config motion_min_cosigners, default 0 =
	 * disabled). The rejection message names the requirement and the shortfall
	 * so the proposer knows how many more co-signers to gather before
	 * resubmitting. Amendments are exempt.
	 *
	 * @param string $objectType The decisionType discriminator
	 * @param string $currentState The current lifecycle state
	 * @param string $newState The target lifecycle state
	 * @param array<string, mixed> $objectArray The decision being transitioned
	 *
	 * @throws InvalidArgumentException When the co-signer minimum is not met
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 *
	 * @return void
	 */
	private function assertCoSignerThreshold(string $objectType, string $currentState, string $newState, array $objectArray): void {
		if ($objectType === 'amendment' || $currentState !== 'proposed' || $newState !== 'deliberating') {
			return;
		}

		$appConfig = $this->container->get(IAppConfig::class);
		$minCoSigners = (int)$appConfig->getValueString('decidesk', 'motion_min_cosigners', '0');
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
	 * This deliberately does NOT fail open: a decision recorded as `decided`
	 * with no result is indistinguishable from one that was never voted on.
	 *
	 * @param array<string, mixed> $objectArray The decision as stored
	 * @param string $newState The lifecycle state being entered
	 * @param string|null $outcome The supplied vote result, when any
	 *
	 * @throws InvalidArgumentException When the outcome is missing, misplaced, or out of vocabulary
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array<string, mixed> The payload to persist
	 */
	private function applyOutcome(array $objectArray, string $newState, ?string $outcome): array {
		if ($this->guard->isTerminalOutcomeState(lifecycle: $newState) === false) {
			if ($outcome !== null) {
				throw new InvalidArgumentException(
					'An outcome may only be recorded when entering a terminal state ('
					. implode(', ', DecisionTransitionGuard::TERMINAL_OUTCOME_STATES)
					. "); '$newState' is still in flight"
				);
			}

			return $objectArray;
		}

		$payload = $this->withOutcome(payload: $objectArray, outcome: $outcome);
		$payload = $this->withDecisionDate(payload: $payload);

		$missing = $this->guard->getMissingTerminalFields(decision: $payload);
		if ($missing !== []) {
			throw new InvalidArgumentException(
				"A $newState decision cannot be recorded without a result. "
				. 'Missing or invalid: ' . implode(', ', $missing) . '.'
			);
		}

		return $payload;
	}//end applyOutcome()

	/**
	 * Record the vote result, refusing anything outside the closed vocabulary.
	 *
	 * @param array<string, mixed> $payload The decision payload
	 * @param string|null $outcome The supplied vote result, when any
	 *
	 * @throws InvalidArgumentException When the outcome is out of vocabulary
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array<string, mixed> The payload, with the outcome recorded
	 */
	private function withOutcome(array $payload, ?string $outcome): array {
		if ($outcome === null) {
			return $payload;
		}

		if (in_array($outcome, DecisionTransitionGuard::OUTCOME_VALUES, true) === false) {
			throw new InvalidArgumentException(
				"Outcome '$outcome' is not a recorded result; expected one of: "
				. implode(', ', DecisionTransitionGuard::OUTCOME_VALUES)
			);
		}

		$payload['outcome'] = $outcome;

		return $payload;
	}//end withOutcome()

	/**
	 * Stamp `decisionDate` the first time a decision reaches a terminal state.
	 *
	 * It is stamped rather than demanded from the caller because the moment a
	 * decision becomes decided is this moment — asking a caller to supply it
	 * would invite a value that disagrees with the audit trail. An
	 * already-present date is never overwritten: re-entering a terminal state
	 * (decided → enacted → archived) must not restamp when the decision was
	 * made.
	 *
	 * @param array<string, mixed> $payload The decision payload
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array<string, mixed> The payload, with a decision date
	 */
	private function withDecisionDate(array $payload): array {
		$existing = '';
		if (array_key_exists('decisionDate', $payload) === true && is_string($payload['decisionDate']) === true) {
			$existing = trim($payload['decisionDate']);
		}

		if ($existing === '') {
			$payload['decisionDate'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		}

		return $payload;
	}//end withDecisionDate()
}//end class
