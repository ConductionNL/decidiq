<?php

/**
 * Decidesk Decision Lifecycle Service
 *
 * Orchestrates guarded decision lifecycle transitions: validates the action
 * against DecisionTransitionGuard's transition map and per-domain policy,
 * enforces chair-only transitions (fail closed) and the quorum gate before
 * `voting`, persists the new lifecycle via OpenRegister, and appends a
 * hash-chained `decision-transition` audit entry.
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
 * @spec openspec/specs/decision-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Event\DecisionConcludedEvent;
use OCA\Decidesk\Lifecycle\DecisionTransitionGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for guarded decision lifecycle state transitions.
 *
 * Decision CRUD stays on OpenRegister's object API (ADR-022); this service
 * owns only the state machine, which needs server-side validation that
 * cannot be expressed as a plain data write.
 *
 * ## Access control design (OWASP A01 / ADR-005)
 *
 * Per-object authorization is OpenRegister ObjectService RBAC — find()
 * returns null/throws for objects the caller may not read, saveObject()
 * throws without write access — the approved pattern documented on
 * MeetingController (chairs and clerks are NOT Nextcloud admins). The
 * chair-only gate layers role semantics on top and FAILS CLOSED when no
 * chair can be resolved.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
class DecisionLifecycleService {
	/**
	 * Terminal-outcome lifecycle states that conclude a delegated decision.
	 *
	 * `withdrawn` is not a state in the transition guard graph (which ends at
	 * `archived`) but IS a recognised `lifecycle` value getOutcomeEnvelope()
	 * maps to `status=withdrawn`; it is included so a withdrawal reaching
	 * transition() still notifies the consumer.
	 *
	 * @var list<string>
	 */
	private const TERMINAL_OUTCOME_STATES = ['decided', 'enacted', 'withdrawn'];

	/**
	 * Resolves the decision, its linked meeting, domain, governance body and chair.
	 *
	 * @var DecisionContextResolver
	 */
	private readonly DecisionContextResolver $contextResolver;

	/**
	 * Constructor for DecisionLifecycleService.
	 *
	 * @param ContainerInterface $container The DI container (lazy-loads OpenRegister's ObjectService)
	 * @param LoggerInterface $logger The logger
	 * @param DecisionTransitionGuard $transitionGuard Pure transition map + per-domain policy guard
	 * @param AuditLogService $auditLogService Hash-chained append-only audit log
	 * @param ProcessTemplateService $templateService Resolves a body's process-template policy override (process-configuration)
	 * @param DecisionIntegrationService $integrationService Builds the cross-app outcome envelope for concluded delegated decisions
	 * @param IEventDispatcher $eventDispatcher Dispatches the DecisionConcludedEvent cross-app contract
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly DecisionTransitionGuard $transitionGuard,
		private readonly AuditLogService $auditLogService,
		private readonly ProcessTemplateService $templateService,
		private readonly DecisionIntegrationService $integrationService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->contextResolver = new DecisionContextResolver(logger: $logger);

	}//end __construct()

	/**
	 * Return the current lifecycle state and the allowed next transitions
	 * for a decision (consumed by the detail-view Lifecycle tab).
	 *
	 * Object-level read ACL: ObjectService::find() resolves the session user
	 * and returns null when the caller lacks read access — indistinguishable
	 * from a missing object, so UUID probing is not possible.
	 *
	 * @param string $decisionId UUID of the decision
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return array{success: bool, lifecycle: ?string, domain: ?string, actions: array<int, array<string, mixed>>, states: string[], message: string}
	 */
	public function getAvailableTransitions(string $decisionId): array {
		try {
			$decision = $this->contextResolver->loadDecision(objectService: $this->objectService, decisionId: $decisionId);
			if ($decision === null) {
				return [
					'success' => false,
					'lifecycle' => null,
					'domain' => null,
					'actions' => [],
					'states' => DecisionTransitionGuard::STATES,
					'message' => "Decision '$decisionId' not found.",
				];
			}

			$lifecycle = (string)($decision['lifecycle'] ?? 'draft');
			$meeting = $this->contextResolver->resolveLinkedMeeting(objectService: $this->objectService, decision: $decision);
			$domain = $this->contextResolver->resolveDomain(decision: $decision, meeting: $meeting);

			// Process-configuration: when the decision's body has an assigned
			// process template, its policy drives the guard; null otherwise so
			// the built-in hardcoded domain policy applies unchanged.
			$policyOverride = $this->resolvePolicyOverride(decision: $decision, meeting: $meeting);

			$actions = [];

			$availableActions = $this->transitionGuard->getAvailableActions(
				currentLifecycle: $lifecycle,
				domain: $domain,
				policyOverride: $policyOverride
			);
			foreach ($availableActions as $action) {
				$transition = $this->transitionGuard->resolveTransition(action: $action);
				$actions[] = [
					'action' => $action,
					'to' => ($transition['to'] ?? ''),
					'chairOnly' => $this->transitionGuard->requiresChairAuthorization(
						domain: $domain,
						from: $lifecycle,
						to: ($transition['to'] ?? ''),
						policyOverride: $policyOverride
					),
				];
			}

			return [
				'success' => true,
				'lifecycle' => $lifecycle,
				'domain' => $domain,
				'actions' => $actions,
				'states' => DecisionTransitionGuard::STATES,
				'message' => 'OK',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: failed to resolve available decision transitions',
				['id' => $decisionId, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'lifecycle' => null,
				'domain' => null,
				'actions' => [],
				'states' => DecisionTransitionGuard::STATES,
				'message' => 'Failed to resolve available transitions.',
			];
		}//end try

	}//end getAvailableTransitions()

	/**
	 * Apply a lifecycle transition to a decision.
	 *
	 * Pipeline: OR find (per-object read ACL / 404) → transition-map
	 * validation → domain policy → chair-only gate (fail closed) → quorum
	 * gate before `voting` → outcome gate before `enact` → saveObject
	 * (per-object write ACL; sets enactedAt on enact) → hash-chained
	 * audit append.
	 *
	 * @param string $decisionId UUID of the decision to transition
	 * @param string $action Transition action: propose|deliberate|openVoting|decide|enact|archive
	 * @param string|null $currentUserId Nextcloud UID of the requesting user (chair gate + audit actor)
	 * @param string $comment Optional transition comment recorded in the audit entry
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return array{success: bool, decision: array|null, message: string}
	 */
	public function transition(string $decisionId, string $action, ?string $currentUserId = null, string $comment = ''): array {
		$transition = $this->transitionGuard->resolveTransition(action: $action);
		if ($transition === null) {
			return [
				'success' => false,
				'decision' => null,
				'message' => 'Unknown action. Valid actions: ' . implode(', ', $this->transitionGuard->getKnownActions()) . '.',
			];
		}

		try {
			$decision = $this->contextResolver->loadDecision(objectService: $this->objectService, decisionId: $decisionId);
			if ($decision === null) {
				return [
					'success' => false,
					'decision' => null,
					'message' => "Decision '$decisionId' not found.",
				];
			}

			$currentLifecycle = (string)($decision['lifecycle'] ?? 'draft');

			// Every guard (transition map, domain policy, chair-only fail-closed,
			// quorum before `voting`, outcome before `enact`) reports through one
			// rejection message; null means the transition may proceed.
			$rejection = $this->resolveRejection(
				objectService: $this->objectService,
				decision: $decision,
				transition: $transition,
				action: $action,
				currentLifecycle: $currentLifecycle,
				currentUserId: $currentUserId
			);
			if ($rejection !== null) {
				return [
					'success' => false,
					'decision' => null,
					'message' => $rejection,
				];
			}

			$patch = ['lifecycle' => $transition['to']];
			if ($transition['to'] === 'enacted') {
				$patch['enactedAt'] = date(DATE_ATOM);
			}

			// Object-level write ACL: saveObject() throws when the session user lacks
			// write access on this specific object (caught below, generic error).
			$updated = $this->objectService->saveObject(
				object: array_merge($decision, $patch),
				register: 'decidesk',
				schema: 'decision',
				uuid: $decisionId,
			);

			$this->applyPostTransitionEffects(
				objectService: $this->objectService,
				decision: array_merge($decision, $patch),
				decisionId: $decisionId,
				action: $action,
				currentLifecycle: $currentLifecycle,
				newState: $transition['to'],
				currentUserId: $currentUserId,
				comment: $comment
			);

			return [
				'success' => true,
				'decision' => $updated->jsonSerialize(),
				'message' => "Decision transitioned to '{$transition['to']}'.",
			];
		} catch (DoesNotExistException) {
			return [
				'success' => false,
				'decision' => null,
				'message' => "Decision '$decisionId' not found.",
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: decision lifecycle transition failed',
				['id' => $decisionId, 'action' => $action, 'exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'decision' => null,
				'message' => 'Transition failed. See server log for details.',
			];
		}//end try

	}//end transition()

	/**
	 * Resolve why a requested transition must be refused, or null when it may proceed.
	 *
	 * Evaluated in the documented order: transition-map from-state, per-domain
	 * policy, chair-only authorization (fail closed), then the state-entry
	 * gates (quorum before `voting`, outcome before `enact`).
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $decision Decision object array
	 * @param array<string, mixed> $transition Resolved transition descriptor (from/to)
	 * @param string $action Requested transition action
	 * @param string $currentLifecycle The decision's current lifecycle state
	 * @param string|null $currentUserId Nextcloud UID of the requesting user
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null Rejection message, or null when the transition is permitted
	 */
	private function resolveRejection(
		object $objectService,
		array $decision,
		array $transition,
		string $action,
		string $currentLifecycle,
		?string $currentUserId,
	): ?string {
		if (in_array(needle: $currentLifecycle, haystack: $transition['from'], strict: true) === false) {
			$allowed = $this->transitionGuard->getAvailableActions(currentLifecycle: $currentLifecycle);
			return "Cannot '$action' a decision in '$currentLifecycle' state. "
				. 'Allowed transitions from this state: ' . implode(', ', $allowed) . '.';
		}

		$meeting = $this->contextResolver->resolveLinkedMeeting(objectService: $objectService, decision: $decision);
		$domain = $this->contextResolver->resolveDomain(decision: $decision, meeting: $meeting);

		// Process-configuration: a body's assigned process template drives the
		// guard policy when present; null falls back to the hardcoded domain policy.
		$policyOverride = $this->resolvePolicyOverride(decision: $decision, meeting: $meeting);

		// Domain-level transition validation (default-deny for unknown domains).
		$isAllowed = $this->transitionGuard->isTransitionAllowed(
			domain: $domain,
			fromState: $currentLifecycle,
			toState: $transition['to'],
			policyOverride: $policyOverride
		);
		if ($isAllowed === false) {
			return "Transition '$action' is not permitted in the '$domain' domain.";
		}

		$chairRejection = $this->resolveChairRejection(
			objectService: $objectService,
			meeting: $meeting,
			domain: $domain,
			currentLifecycle: $currentLifecycle,
			newState: $transition['to'],
			policyOverride: $policyOverride,
			currentUserId: $currentUserId
		);
		if ($chairRejection !== null) {
			return $chairRejection;
		}

		return $this->resolveStateGateRejection(
			decision: $decision,
			meeting: $meeting,
			domain: $domain,
			newState: $transition['to'],
			policyOverride: $policyOverride
		);

	}//end resolveRejection()

	/**
	 * Enforce chair-only transitions (OWASP A01:2021 — broken access control).
	 *
	 * FAILS CLOSED: a chair-only transition with no resolvable chair is
	 * rejected, never skipped.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed>|null $meeting Linked meeting object array, when any
	 * @param string $domain Governance domain driving the policy
	 * @param string $currentLifecycle The decision's current lifecycle state
	 * @param string $newState The lifecycle state being entered
	 * @param array<string, mixed>|null $policyOverride Process-template policy override, when any
	 * @param string|null $currentUserId Nextcloud UID of the requesting user
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null Rejection message, or null when the caller is authorized
	 */
	private function resolveChairRejection(
		object $objectService,
		?array $meeting,
		string $domain,
		string $currentLifecycle,
		string $newState,
		?array $policyOverride,
		?string $currentUserId,
	): ?string {
		$requiresChair = $this->transitionGuard->requiresChairAuthorization(
			domain: $domain,
			from: $currentLifecycle,
			to: $newState,
			policyOverride: $policyOverride
		);
		if ($requiresChair !== true) {
			return null;
		}

		$chairNcUserId = $this->contextResolver->resolveChairUserId(
			objectService: $objectService,
			meeting: $meeting
		);
		if ($chairNcUserId === null || $currentUserId !== $chairNcUserId) {
			return 'Only the meeting chair may perform this transition.';
		}

		return null;
	}//end resolveChairRejection()

	/**
	 * Enforce the gates guarding entry into a specific lifecycle state:
	 * quorum before `voting` and the adopted-outcome gate before `enacted`.
	 *
	 * @param array<string, mixed> $decision Decision object array
	 * @param array<string, mixed>|null $meeting Linked meeting object array, when any
	 * @param string $domain Governance domain driving the policy
	 * @param string $newState The lifecycle state being entered
	 * @param array<string, mixed>|null $policyOverride Process-template policy override, when any
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null Rejection message, or null when the state may be entered
	 */
	private function resolveStateGateRejection(
		array $decision,
		?array $meeting,
		string $domain,
		string $newState,
		?array $policyOverride,
	): ?string {
		if ($newState === 'voting') {
			return $this->resolveQuorumRejection(
				meeting: $meeting,
				domain: $domain,
				policyOverride: $policyOverride
			);
		}

		// Outcome gate: only adopted decisions may be enacted.
		if ($newState === 'enacted' && $this->transitionGuard->isEnactAllowed(decision: $decision) === false) {
			return "Only decisions with outcome 'adopted' may be enacted.";
		}

		// Terminal-state completeness. This gate is the reason `outcome` and
		// `decisionDate` could safely leave the schema's unconditional
		// `required[]`: an in-flight motion (draft/proposed/deliberating/voting)
		// legitimately has neither and a `withdrawn` decision never acquires
		// them, but a decision that reaches `decided`/`enacted`/`archived`
		// without them is a real defect — and dropping the requirement outright
		// would have made that undetectable.
		//
		// It is enforced HERE, at the transition boundary, rather than as a
		// JSON-Schema `if`/`then` on the schema, because OpenRegister cannot
		// enforce a conditional `required`: Schema::getSchemaObject() rebuilds
		// the validated schema from a fixed key list, so the block is discarded
		// before the validator ever runs (measured — see the register's
		// `x-decidesk-terminal-completeness` note).
		if ($this->transitionGuard->isTerminalOutcomeState(lifecycle: $newState) === false) {
			return null;
		}

		$missing = $this->transitionGuard->getMissingTerminalFields(decision: $decision);
		if ($missing === []) {
			return null;
		}

		return "A decision cannot enter '$newState' without a recorded result. "
			. 'Missing or invalid: ' . implode(', ', $missing) . '.';

	}//end resolveStateGateRejection()

	/**
	 * Enforce the quorum gate before entering `voting` (OWASP A01:2021).
	 *
	 * Applies when the domain enforces quorum AND a meeting is linked;
	 * standalone decisions (written-resolution path, BW 2:40) carry quorum on
	 * their voting round instead.
	 *
	 * @param array<string, mixed>|null $meeting Linked meeting object array, when any
	 * @param string $domain Governance domain driving the policy
	 * @param array<string, mixed>|null $policyOverride Process-template policy override, when any
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return string|null Rejection message, or null when voting may open
	 */
	private function resolveQuorumRejection(?array $meeting, string $domain, ?array $policyOverride): ?string {
		if ($meeting === null) {
			return null;
		}

		$quorumRequired = $this->transitionGuard->isQuorumRequired(
			domain: $domain,
			policyOverride: $policyOverride
		);
		if ($quorumRequired !== true) {
			return null;
		}

		if ($this->transitionGuard->isVotingOpenAllowed(meeting: $meeting) === false) {
			return 'Quorum is not met for the linked meeting. Cannot open voting.';
		}

		return null;
	}//end resolveQuorumRejection()

	/**
	 * Run every side effect that follows a persisted lifecycle transition:
	 * the generated resolution record (on enact), the hash-chained audit
	 * append, the info log line and the cross-app conclusion event.
	 *
	 * All of them are fail-soft — the lifecycle write already persisted, so a
	 * failure here is logged loudly and never rolls the transition back.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $decision Decision object array (post-transition)
	 * @param string $decisionId UUID of the transitioned decision
	 * @param string $action The applied transition action
	 * @param string $currentLifecycle The pre-transition lifecycle state
	 * @param string $newState The post-transition lifecycle state
	 * @param string|null $currentUserId Nextcloud UID of the requesting user (audit actor)
	 * @param string $comment Transition comment recorded in the audit entry
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return void
	 */
	private function applyPostTransitionEffects(
		object $objectService,
		array $decision,
		string $decisionId,
		string $action,
		string $currentLifecycle,
		string $newState,
		?string $currentUserId,
		string $comment,
	): void {
		// Enacting generates the formal resolution record (resolution-minutes
		// spec): the lifecycle write persisted already, so a resolution failure
		// is logged loudly but does not roll the transition back.
		if ($newState === 'enacted') {
			$this->generateResolutionRecord(
				objectService: $objectService,
				decision: $decision,
				decisionId: $decisionId
			);
		}

		// Hash-chained immutable audit entry for the transition (WBTR).
		$audit = $this->auditLogService->append(
			actor: ($currentUserId ?? 'system'),
			action: 'decision-transition',
			objectUids: [$decisionId],
			payload: [
				'transition' => $action,
				'from' => $currentLifecycle,
				'to' => $newState,
				'comment' => $comment,
			]
		);
		if ($audit['success'] === false) {
			// The transition itself persisted (and OR's own object audit trail
			// recorded the change); surface the chain failure loudly.
			$this->logger->error(
				'Decidesk: decision transition persisted but audit chain append failed',
				['id' => $decisionId, 'action' => $action, 'message' => $audit['message']]
			);
		}

		$this->logger->info(
			'Decidesk: decision lifecycle transitioned',
			['id' => $decisionId, 'action' => $action, 'from' => $currentLifecycle, 'to' => $newState]
		);

		// Cross-app event contract (decidesk-decision-events): when a
		// delegated decision (one carrying provenance) reaches a terminal
		// outcome, emit DecisionConcludedEvent so the originating consumer
		// app can perform its own downstream side effects. Internal
		// (no-provenance) decisions emit nothing. Fail-soft — never roll back.
		$this->emitConclusionIfDelegated(
			decision: $decision,
			decisionId: $decisionId,
			newState: $newState
		);

	}//end applyPostTransitionEffects()

	/**
	 * Emit a DecisionConcludedEvent when a delegated decision concludes.
	 *
	 * Only fires for a decision that carries provenance (`sourceApp` set) and
	 * whose new lifecycle state is terminal for outcome purposes
	 * (decided/enacted/withdrawn). The outcome envelope is built by
	 * DecisionIntegrationService::getOutcomeEnvelope() (status derived, signing
	 * resolved — no duplication). Fail-soft: any error is logged and the
	 * already-persisted transition is never rolled back.
	 *
	 * @param array<string, mixed> $decision Decision object array (post-transition)
	 * @param string $decisionId UUID of the transitioned decision
	 * @param string $newState The post-transition lifecycle state
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 *
	 * @return void
	 */
	private function emitConclusionIfDelegated(array $decision, string $decisionId, string $newState): void {
		if (in_array($newState, self::TERMINAL_OUTCOME_STATES, true) === false) {
			return;
		}

		$sourceApp = (string)($decision['sourceApp'] ?? '');
		if ($sourceApp === '') {
			// Internal decision (no consumer is waiting) — emit nothing.
			return;
		}

		try {
			$envelope = $this->integrationService->getOutcomeEnvelope($decisionId);
			if ($envelope === null) {
				return;
			}

			$event = new DecisionConcludedEvent(
				decisionId: (string)($envelope['decisionId'] ?? ''),
				decisionType: (string)($envelope['decisionType'] ?? ''),
				status: (string)($envelope['status'] ?? 'pending'),
				outcome: (string)($decision['outcome'] ?? ''),
				signed: (bool)($envelope['signed'] ?? false),
				signingReference: ($envelope['signingReference'] ?? null),
				signers: (array)($envelope['signers'] ?? []),
				decidedAt: ($envelope['decidedAt'] ?? null),
				sourceApp: $sourceApp,
				subjectRegister: ($envelope['subjectRegister'] ?? null),
				subjectSchema: ($envelope['subjectSchema'] ?? null),
				subjectId: ($envelope['subjectId'] ?? null),
				externalReference: (string)($envelope['externalReference'] ?? ''),
				correlationId: (string)($decision['externalReference'] ?? ''),
			);

			$this->eventDispatcher->dispatchTyped($event);

			$this->logger->info(
				'Decidesk: dispatched DecisionConcludedEvent',
				['id' => $decisionId, 'sourceApp' => $sourceApp, 'state' => $newState]
			);
		} catch (\Throwable $e) {
			// The transition has already persisted; a dispatch failure must not
			// roll it back (matches generateResolutionRecord's fail-loud contract).
			$this->logger->error(
				'Decidesk: decision concluded but DecisionConcludedEvent dispatch failed',
				['id' => $decisionId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end emitConclusionIfDelegated()

	/**
	 * Generate the formal resolution record for an enacted decision
	 * (per the decision-management enact scenario / resolution-minutes spec).
	 *
	 * The resolution captures the enacted decision: adopted status, the
	 * decision text as full text, legal basis, adoption and effective dates,
	 * and the meeting link when present.
	 *
	 * @param object $objectService OpenRegister ObjectService instance
	 * @param array<string, mixed> $decision Decision object array (post-transition)
	 * @param string $decisionId UUID of the enacted decision
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 *
	 * @return void
	 */
	private function generateResolutionRecord(object $objectService, array $decision, string $decisionId): void {
		try {
			$meetingId = $this->contextResolver->resolveRelationId(
				value: $this->contextResolver->readMeetingReference(decision: $decision)
			);

			$resolution = [
				'title' => (string)($decision['title'] ?? ''),
				'fullText' => (string)($decision['text'] ?? ''),
				'legalBasis' => (string)($decision['legalBasis'] ?? ''),
				'status' => 'adopted',
				'adoptionDate' => (string)($decision['decisionDate'] ?? ''),
				'effectiveDate' => (string)($decision['enactedAt'] ?? ''),
				'background' => 'Generated from enacted decision ' . $decisionId . '.',
			];
			if ($meetingId !== null) {
				$resolution['meeting'] = $meetingId;
			}

			$this->objectService->saveObject(
				object: $resolution,
				register: 'decidesk',
				schema: 'decision'
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: decision enacted but resolution record generation failed',
				['id' => $decisionId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end generateResolutionRecord()

	/**
	 * Resolve the process-template policy override for a decision (process-configuration).
	 *
	 * Reads the governance body from the decision (or its linked meeting), looks
	 * up that body's assigned process template, and translates it to the guard
	 * policy shape. Returns null when no body is resolvable, no template is
	 * assigned, or the template is malformed — in every such case the caller
	 * falls back to the built-in hardcoded domain policy (fail-safe).
	 *
	 * @param array<string, mixed> $decision Decision object array
	 * @param array<string, mixed>|null $meeting Linked meeting object array, when any
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 *
	 * @return array<string, mixed>|null The guard policy override, or null to fall back
	 */
	private function resolvePolicyOverride(array $decision, ?array $meeting): ?array {
		$bodyId = $this->contextResolver->resolveGovernanceBodyId(decision: $decision, meeting: $meeting);
		if ($bodyId === null) {
			return null;
		}

		return $this->templateService->resolvePolicyForBody(governanceBodyId: $bodyId);
	}//end resolvePolicyOverride()
}//end class
