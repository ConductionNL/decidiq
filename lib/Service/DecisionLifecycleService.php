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
use OCA\Decidesk\Service\ProcessTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
class DecisionLifecycleService
{
    /**
     * Constructor for DecisionLifecycleService.
     *
     * @param ContainerInterface         $container          The DI container (lazy-loads OpenRegister's ObjectService)
     * @param LoggerInterface            $logger             The logger
     * @param DecisionTransitionGuard    $transitionGuard    Pure transition map + per-domain policy guard
     * @param AuditLogService            $auditLogService    Hash-chained append-only audit log
     * @param ProcessTemplateService     $templateService    Resolves a body's process-template policy override (process-configuration)
     * @param DecisionIntegrationService $integrationService Builds the cross-app outcome envelope for concluded delegated decisions
     * @param IEventDispatcher           $eventDispatcher    Dispatches the DecisionConcludedEvent cross-app contract
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly DecisionTransitionGuard $transitionGuard,
        private readonly AuditLogService $auditLogService,
        private readonly ProcessTemplateService $templateService,
        private readonly DecisionIntegrationService $integrationService,
        private readonly IEventDispatcher $eventDispatcher,
    ) {
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
    public function getAvailableTransitions(string $decisionId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $decision = $this->loadDecision(objectService: $objectService, decisionId: $decisionId);
            if ($decision === null) {
                return [
                    'success'   => false,
                    'lifecycle' => null,
                    'domain'    => null,
                    'actions'   => [],
                    'states'    => DecisionTransitionGuard::STATES,
                    'message'   => "Decision '$decisionId' not found.",
                ];
            }

            $lifecycle = (string) ($decision['lifecycle'] ?? 'draft');
            $meeting   = $this->resolveLinkedMeeting(objectService: $objectService, decision: $decision);
            $domain    = $this->resolveDomain(decision: $decision, meeting: $meeting);

            // process-configuration: when the decision's body has an assigned
            // process template, its policy drives the guard; null otherwise so
            // the built-in hardcoded domain policy applies unchanged.
            $policyOverride = $this->resolvePolicyOverride(decision: $decision, meeting: $meeting);

            $actions = [];
            foreach ($this->transitionGuard->getAvailableActions(currentLifecycle: $lifecycle, domain: $domain, policyOverride: $policyOverride) as $action) {
                $transition = $this->transitionGuard->resolveTransition(action: $action);
                $actions[]  = [
                    'action'    => $action,
                    'to'        => ($transition['to'] ?? ''),
                    'chairOnly' => $this->transitionGuard->requiresChairAuthorization(
                        domain: $domain,
                        from: $lifecycle,
                        to: ($transition['to'] ?? ''),
                        policyOverride: $policyOverride
                    ),
                ];
            }

            return [
                'success'   => true,
                'lifecycle' => $lifecycle,
                'domain'    => $domain,
                'actions'   => $actions,
                'states'    => DecisionTransitionGuard::STATES,
                'message'   => 'OK',
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to resolve available decision transitions',
                ['id' => $decisionId, 'exception' => $e->getMessage()]
            );
            return [
                'success'   => false,
                'lifecycle' => null,
                'domain'    => null,
                'actions'   => [],
                'states'    => DecisionTransitionGuard::STATES,
                'message'   => 'Failed to resolve available transitions.',
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
     * @param string      $decisionId    UUID of the decision to transition
     * @param string      $action        Transition action: propose|deliberate|openVoting|decide|enact|archive
     * @param string|null $currentUserId Nextcloud UID of the requesting user (chair gate + audit actor)
     * @param string      $comment       Optional transition comment recorded in the audit entry
     *
     * @spec openspec/specs/decision-management/spec.md
     * @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md
     *
     * @return array{success: bool, decision: array|null, message: string}
     */
    public function transition(string $decisionId, string $action, ?string $currentUserId=null, string $comment=''): array
    {
        $transition = $this->transitionGuard->resolveTransition(action: $action);
        if ($transition === null) {
            return [
                'success'  => false,
                'decision' => null,
                'message'  => 'Unknown action. Valid actions: '.implode(', ', $this->transitionGuard->getKnownActions()).'.',
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $decision = $this->loadDecision(objectService: $objectService, decisionId: $decisionId);
            if ($decision === null) {
                return [
                    'success'  => false,
                    'decision' => null,
                    'message'  => "Decision '$decisionId' not found.",
                ];
            }

            $currentLifecycle = (string) ($decision['lifecycle'] ?? 'draft');

            if (in_array(needle: $currentLifecycle, haystack: $transition['from'], strict: true) === false) {
                $allowed = $this->transitionGuard->getAvailableActions(currentLifecycle: $currentLifecycle);
                return [
                    'success'  => false,
                    'decision' => null,
                    'message'  => "Cannot '$action' a decision in '$currentLifecycle' state. "
                        .'Allowed transitions from this state: '.implode(', ', $allowed).'.',
                ];
            }

            $meeting = $this->resolveLinkedMeeting(objectService: $objectService, decision: $decision);
            $domain  = $this->resolveDomain(decision: $decision, meeting: $meeting);

            // process-configuration: a body's assigned process template drives the
            // guard policy when present; null falls back to the hardcoded domain policy.
            $policyOverride = $this->resolvePolicyOverride(decision: $decision, meeting: $meeting);

            // Domain-level transition validation (default-deny for unknown domains).
            if ($this->transitionGuard->isTransitionAllowed(domain: $domain, fromState: $currentLifecycle, toState: $transition['to'], policyOverride: $policyOverride) === false) {
                return [
                    'success'  => false,
                    'decision' => null,
                    'message'  => "Transition '$action' is not permitted in the '$domain' domain.",
                ];
            }

            // Chair-only enforcement (OWASP A01:2021 — broken access control). FAIL CLOSED:
            // a chair-only transition with no resolvable chair is rejected, never skipped.
            if ($this->transitionGuard->requiresChairAuthorization(domain: $domain, from: $currentLifecycle, to: $transition['to'], policyOverride: $policyOverride) === true) {
                $chairNcUserId = $this->resolveChairUserId(objectService: $objectService, meeting: $meeting);
                if ($chairNcUserId === null || $currentUserId === null || $currentUserId !== $chairNcUserId) {
                    return [
                        'success'  => false,
                        'decision' => null,
                        'message'  => 'Only the meeting chair may perform this transition.',
                    ];
                }
            }

            // Quorum gate before entering `voting` (OWASP A01:2021). Applies when the
            // domain enforces quorum AND a meeting is linked; standalone decisions
            // (written-resolution path, BW 2:40) carry quorum on their voting round.
            if ($transition['to'] === 'voting'
                && $this->transitionGuard->isQuorumRequired(domain: $domain, policyOverride: $policyOverride) === true
                && $meeting !== null
                && $this->transitionGuard->isVotingOpenAllowed(meeting: $meeting) === false
            ) {
                return [
                    'success'  => false,
                    'decision' => null,
                    'message'  => 'Quorum is not met for the linked meeting. Cannot open voting.',
                ];
            }

            // Outcome gate: only adopted decisions may be enacted.
            if ($transition['to'] === 'enacted' && $this->transitionGuard->isEnactAllowed(decision: $decision) === false) {
                return [
                    'success'  => false,
                    'decision' => null,
                    'message'  => "Only decisions with outcome 'adopted' may be enacted.",
                ];
            }

            $patch = ['lifecycle' => $transition['to']];
            if ($transition['to'] === 'enacted') {
                $patch['enactedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            }

            // Object-level write ACL: saveObject() throws when the session user lacks
            // write access on this specific object (caught below, generic error).
            $updated = $objectService->saveObject(
                object: array_merge($decision, $patch),
                register: 'decidesk',
                schema: 'decision',
                uuid: $decisionId,
            );

            // Enacting generates the formal resolution record (resolution-minutes
            // spec): the lifecycle write persisted above, so a resolution failure
            // is logged loudly but does not roll the transition back.
            if ($transition['to'] === 'enacted') {
                $this->generateResolutionRecord(
                    objectService: $objectService,
                    decision: array_merge($decision, $patch),
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
                    'from'       => $currentLifecycle,
                    'to'         => $transition['to'],
                    'comment'    => $comment,
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
                ['id' => $decisionId, 'action' => $action, 'from' => $currentLifecycle, 'to' => $transition['to']]
            );

            // Cross-app event contract (decidesk-decision-events): when a
            // delegated decision (one carrying provenance) reaches a terminal
            // outcome, emit DecisionConcludedEvent so the originating consumer
            // app can perform its own downstream side effects. Internal
            // (no-provenance) decisions emit nothing. Fail-soft — never roll back.
            $this->emitConclusionIfDelegated(
                decision: array_merge($decision, $patch),
                decisionId: $decisionId,
                newState: $transition['to']
            );

            return [
                'success'  => true,
                'decision' => $updated->jsonSerialize(),
                'message'  => "Decision transitioned to '{$transition['to']}'.",
            ];
        } catch (DoesNotExistException) {
            return [
                'success'  => false,
                'decision' => null,
                'message'  => "Decision '$decisionId' not found.",
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: decision lifecycle transition failed',
                ['id' => $decisionId, 'action' => $action, 'exception' => $e->getMessage()]
            );
            return [
                'success'  => false,
                'decision' => null,
                'message'  => 'Transition failed. See server log for details.',
            ];
        }//end try

    }//end transition()

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
     * Emit a DecisionConcludedEvent when a delegated decision concludes.
     *
     * Only fires for a decision that carries provenance (`sourceApp` set) and
     * whose new lifecycle state is terminal for outcome purposes
     * (decided/enacted/withdrawn). The outcome envelope is built by
     * DecisionIntegrationService::getOutcomeEnvelope() (status derived, signing
     * resolved — no duplication). Fail-soft: any error is logged and the
     * already-persisted transition is never rolled back.
     *
     * @param array<string, mixed> $decision   Decision object array (post-transition)
     * @param string               $decisionId UUID of the transitioned decision
     * @param string               $newState   The post-transition lifecycle state
     *
     * @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md
     *
     * @return void
     */
    private function emitConclusionIfDelegated(array $decision, string $decisionId, string $newState): void
    {
        if (in_array($newState, self::TERMINAL_OUTCOME_STATES, true) === false) {
            return;
        }

        $sourceApp = (string) ($decision['sourceApp'] ?? '');
        if ($sourceApp === '') {
            // Internal decision (no consumer is waiting) — emit nothing.
            return;
        }

        try {
            $envelope = $this->integrationService->getOutcomeEnvelope($decisionId);
            if ($envelope === null) {
                return;
            }

            $event = DecisionConcludedEvent::fromEnvelope(
                envelope: $envelope,
                outcome: (string) ($decision['outcome'] ?? ''),
                sourceApp: $sourceApp,
                correlationId: (string) ($decision['externalReference'] ?? '')
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
     * @param object               $objectService OpenRegister ObjectService instance
     * @param array<string, mixed> $decision      Decision object array (post-transition)
     * @param string               $decisionId    UUID of the enacted decision
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return void
     */
    private function generateResolutionRecord(object $objectService, array $decision, string $decisionId): void
    {
        try {
            $meetingId = $decision['meeting'] ?? ($decision['relations']['Meeting'][0] ?? null);
            if (is_array($meetingId) === true) {
                $meetingId = ($meetingId['id'] ?? null);
            }

            $resolution = [
                'title'         => (string) ($decision['title'] ?? ''),
                'fullText'      => (string) ($decision['text'] ?? ''),
                'legalBasis'    => (string) ($decision['legalBasis'] ?? ''),
                'status'        => 'adopted',
                'adoptionDate'  => (string) ($decision['decisionDate'] ?? ''),
                'effectiveDate' => (string) ($decision['enactedAt'] ?? ''),
                'background'    => 'Generated from enacted decision '.$decisionId.'.',
            ];
            if (is_string($meetingId) === true && $meetingId !== '') {
                $resolution['meeting'] = $meetingId;
            }

            $objectService->saveObject(
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
     * Load a decision object as a plain array, or null when missing /
     * unreadable for the session user (ObjectService RBAC).
     *
     * @param object $objectService OpenRegister ObjectService instance
     * @param string $decisionId    UUID of the decision
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return array<string, mixed>|null
     */
    private function loadDecision(object $objectService, string $decisionId): ?array
    {
        try {
            $entity = $objectService->find(id: $decisionId, register: 'decidesk', schema: 'decision');
        } catch (DoesNotExistException) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return (array) $entity->jsonSerialize();

    }//end loadDecision()

    /**
     * Resolve the meeting linked to a decision, if any.
     *
     * Decisions reference their meeting either through the `meeting` relation
     * property or the legacy `relations.Meeting` array written by
     * LiveDecisionService — both shapes are accepted.
     *
     * @param object               $objectService OpenRegister ObjectService instance
     * @param array<string, mixed> $decision      Decision object array
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return array<string, mixed>|null Meeting object array or null when not linked / not found
     */
    private function resolveLinkedMeeting(object $objectService, array $decision): ?array
    {
        $meetingId = $decision['meeting'] ?? ($decision['relations']['Meeting'][0] ?? null);
        if (is_array($meetingId) === true) {
            $meetingId = ($meetingId['id'] ?? null);
        }

        if (is_string($meetingId) === false || $meetingId === '') {
            return null;
        }

        try {
            $entity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
        } catch (DoesNotExistException) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return (array) $entity->jsonSerialize();

    }//end resolveLinkedMeeting()

    /**
     * Resolve the governance domain for policy lookup.
     *
     * Resolution chain: decision.domain → linked meeting.domain →
     * 'operations' — the same chain MeetingService uses. Unknown values are
     * mapped to the restricted default-deny policy inside the guard.
     *
     * @param array<string, mixed>      $decision Decision object array
     * @param array<string, mixed>|null $meeting  Linked meeting object array, when any
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return string
     */
    private function resolveDomain(array $decision, ?array $meeting): string
    {
        $domain = ($decision['domain'] ?? null);
        if (is_string($domain) === true && $domain !== '') {
            return $domain;
        }

        $meetingDomain = null;
        if ($meeting !== null) {
            $meetingDomain = ($meeting['domain'] ?? null);
        }

        if (is_string($meetingDomain) === true && $meetingDomain !== '') {
            return $meetingDomain;
        }

        return 'operations';

    }//end resolveDomain()

    /**
     * Resolve the process-template policy override for a decision (process-configuration).
     *
     * Reads the governance body from the decision (or its linked meeting), looks
     * up that body's assigned process template, and translates it to the guard
     * policy shape. Returns null when no body is resolvable, no template is
     * assigned, or the template is malformed — in every such case the caller
     * falls back to the built-in hardcoded domain policy (fail-safe).
     *
     * @param array<string, mixed>      $decision Decision object array
     * @param array<string, mixed>|null $meeting  Linked meeting object array, when any
     *
     * @spec openspec/specs/process-configuration/spec.md
     *
     * @return array<string, mixed>|null The guard policy override, or null to fall back
     */
    private function resolvePolicyOverride(array $decision, ?array $meeting): ?array
    {
        $bodyId = ($decision['governanceBody'] ?? null);
        if ((is_string($bodyId) === false || $bodyId === '') && $meeting !== null) {
            $bodyId = ($meeting['governanceBody'] ?? null);
        }

        if (is_string($bodyId) === false || $bodyId === '') {
            return null;
        }

        return $this->templateService->resolvePolicyForBody(governanceBodyId: $bodyId);

    }//end resolvePolicyOverride()

    /**
     * Resolve the Nextcloud UID of the chair of the linked meeting.
     *
     * `meeting.chair` holds a Participant UUID (not an NC UID); the
     * Participant object carries the `nextcloudUserId` link. Returns null
     * when no meeting is linked, the meeting has no chair, or the chair
     * participant cannot be resolved — callers MUST treat null as
     * "authorization unavailable" and reject (fail closed), never skip.
     *
     * @param object                    $objectService OpenRegister ObjectService instance
     * @param array<string, mixed>|null $meeting       Linked meeting object array, when any
     *
     * @spec openspec/specs/decision-management/spec.md
     *
     * @return string|null Nextcloud UID of the chair, or null when unresolvable
     */
    private function resolveChairUserId(object $objectService, ?array $meeting): ?string
    {
        if ($meeting === null) {
            return null;
        }

        $chairParticipantId = ($meeting['chair'] ?? null);
        if (is_array($chairParticipantId) === true) {
            $chairParticipantId = ($chairParticipantId['id'] ?? null);
        }

        if (is_string($chairParticipantId) === false || $chairParticipantId === '') {
            return null;
        }

        try {
            $chairParticipant = $objectService->find(
                id: $chairParticipantId,
                register: 'decidesk',
                schema: 'participant'
            );
        } catch (DoesNotExistException) {
            return null;
        }

        if ($chairParticipant === null) {
            $this->logger->warning(
                'Decidesk DecisionLifecycleService: chair participant not found',
                ['chairParticipantId' => $chairParticipantId]
            );
            return null;
        }

        $chairData = (array) $chairParticipant->jsonSerialize();
        $uid       = ($chairData['nextcloudUserId'] ?? ($chairData['owner'] ?? null));
        if (is_string($uid) === true && $uid !== '') {
            return $uid;
        }

        return null;

    }//end resolveChairUserId()
}//end class
