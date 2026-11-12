<?php

/**
 * Decidiq DecisionStateRequestedListener
 *
 * Answers the READ half of the cross-app decision contract: a consumer holding
 * a decisionId asks what became of it, and this listener reports still open,
 * concluded with an outcome, or withdrawn.
 *
 * IT ADDS A DOOR, NOT A SECOND ANSWER. The state is derived by the SAME
 * `DecisionIntegrationService::getOutcomeEnvelope()` the HTTP endpoint and the
 * conclusion event already use, and authorized by the SAME
 * `DecisionIntegrationAuthorizationGuard::isAuthorizedToReadOutcome()` rule
 * `IntegrationController::getOutcome()` enforces. Nothing here derives a status
 * of its own — a second derivation is exactly how the announced outcome and
 * the read-back outcome would come to disagree about one Decision.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\DecisionStateRequestedEvent;
use OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Reports one Decision's outcome state to the consumer that asks for it.
 *
 * 🔴 THE BUS CARRIES NO SESSION, SO IT MUST CARRY AN ACTOR. An HTTP caller is
 * whoever Nextcloud authenticated; an in-process dispatch has no such fact —
 * the heartbeat that motivates this seam runs under the cron worker, where
 * `IUserSession` holds nobody. So the read is authorized AS the uid the event
 * names, and an event that names none is REFUSED rather than treated as a
 * system caller. There is deliberately no admin bypass here either: the one
 * `IntegrationController` has belongs to a real authenticated administrator,
 * and there is nobody on this path to check that against.
 *
 * The named actor is not a claim about who is calling — in-process there is no
 * boundary to make that meaningful, and any app that can dispatch this event
 * can already reach `ObjectService` directly. It is the identity the read is
 * SCOPED to, which is what stops a consumer from reading back decisions its own
 * runs never raised: `@self.owner` is stamped from the identity that raised the
 * Decision, so naming any other uid answers "not permitted".
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 */
class DecisionStateRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DecisionIntegrationService $integrationService The reused envelope derivation
	 * @param DecisionIntegrationAuthorizationGuard $authorizationGuard The reused outcome-read rule (REQ-DCDH-101)
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly DecisionIntegrationService $integrationService,
		private readonly DecisionIntegrationAuthorizationGuard $authorizationGuard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a DecisionStateRequestedEvent.
	 *
	 * Leaves the event UNHANDLED on any failure, and never throws into the
	 * dispatcher — the same posture `DecisionRequestedListener` takes. That
	 * distinction is load-bearing for the consumer: unhandled means "ask me
	 * again", while handled-and-not-found means "stop waiting". An unresolvable
	 * lookup is therefore left unhandled rather than reported as a refusal.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof DecisionStateRequestedEvent === false) {
			return;
		}

		$decisionId = trim($event->getDecisionId());
		$actorId = trim($event->getActorId());

		// A read with no subject or no identity is refused, not elevated. Both
		// are answerable facts, so the event IS handled: a consumer that asked
		// wrongly should be told so rather than left polling.
		if ($decisionId === '' || $actorId === '') {
			$this->logger->warning(
				'Decidiq: refusing a decision-state read with no decision id or no actor',
				['sourceApp' => $event->getSourceApp(), 'decisionId' => $decisionId, 'hasActor' => ($actorId !== '')]
			);
			$event->setHandled(true);

			return;
		}

		try {
			$this->answerRead(event: $event, decisionId: $decisionId);
		} catch (\Throwable $e) {
			// Left UNHANDLED on purpose. A consumer reads that as "could not
			// read", which buys it another attempt; reading a failed lookup as
			// "no such decision" would strand a run whose decision is sitting
			// there decided.
			$this->logger->error(
				'Decidiq: DecisionStateRequestedListener failed',
				[
					'sourceApp' => $event->getSourceApp(),
					'decisionId' => $decisionId,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end handle()

	/**
	 * Answer a complete read: refuse or leave it unanswered as the read rule
	 * says, else permit it and deliver the envelope.
	 *
	 * @param DecisionStateRequestedEvent $event      The event
	 * @param string                      $decisionId The trimmed decision id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	private function answerRead(DecisionStateRequestedEvent $event, string $decisionId): void {
		if ($this->isPermittedRead(event: $event, decisionId: $decisionId) === false) {
			return;
		}

		$event->setPermitted(true);
		$this->deliverEnvelope(event: $event, decisionId: $decisionId);
	}//end answerRead()

	/**
	 * Whether the caller may read the decision.
	 *
	 * REQ-DCDH-101, unchanged and not re-implemented: the owner that raised
	 * the Decision, or anyone when it is published. Asked in its three-way
	 * form, because a Decision that could not be RESOLVED is not a Decision
	 * the caller may not see — and this caller acts differently on the two:
	 * an unresolvable one is left unanswered, a refused one is handled.
	 *
	 * @param DecisionStateRequestedEvent $event      The event
	 * @param string                      $decisionId The trimmed decision id
	 *
	 * @return bool True when the read is allowed.
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	private function isPermittedRead(DecisionStateRequestedEvent $event, string $decisionId): bool {
		$access = $this->authorizationGuard->resolveOutcomeReadAccess(
			decisionId: $decisionId,
			callerUid: trim($event->getActorId())
		);

		if ($access === DecisionIntegrationAuthorizationGuard::READ_UNRESOLVED) {
			$this->logger->warning(
				'Decidiq: could not resolve a Decision for a state read; leaving it unanswered',
				$this->readContext(event: $event, decisionId: $decisionId)
			);
			return false;
		}

		if ($access !== DecisionIntegrationAuthorizationGuard::READ_ALLOWED) {
			$this->logger->warning('Decidiq: refused a decision-state read', $this->readContext(event: $event, decisionId: $decisionId));
			$event->setHandled(true);
			return false;
		}

		return true;
	}//end isPermittedRead()

	/**
	 * Put the decision's envelope on a permitted read; a genuine miss is
	 * handled and not found.
	 *
	 * @param DecisionStateRequestedEvent $event      The event
	 * @param string                      $decisionId The trimmed decision id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	private function deliverEnvelope(DecisionStateRequestedEvent $event, string $decisionId): void {
		$envelope = $this->integrationService->getOutcomeEnvelope(decisionId: $decisionId);
		if ($envelope === null) {
			// The guard lets a genuine miss through so the answer is 404
			// rather than 403, exactly as it does for the HTTP endpoint.
			$event->setHandled(true);

			return;
		}

		$event->setFound(true);
		$event->setEnvelope($envelope);
		$event->setHandled(true);

		$this->logger->debug(
			'Decidiq: answered a decision-state read',
			['sourceApp' => $event->getSourceApp(), 'decisionId' => $decisionId, 'status' => (string)($envelope['status'] ?? '')]
		);
	}//end deliverEnvelope()

	/**
	 * The log context of a read the rule refused or could not resolve.
	 *
	 * @param DecisionStateRequestedEvent $event      The event
	 * @param string                      $decisionId The trimmed decision id
	 *
	 * @return array<string, string> The context.
	 */
	private function readContext(DecisionStateRequestedEvent $event, string $decisionId): array {
		return ['sourceApp' => $event->getSourceApp(), 'decisionId' => $decisionId, 'actor' => trim($event->getActorId())];
	}//end readContext()
}//end class
