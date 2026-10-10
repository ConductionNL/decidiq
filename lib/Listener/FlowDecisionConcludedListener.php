<?php

/**
 * Decidiq FlowDecisionConcludedListener
 *
 * Wakes the flow run that asked for a decision when decidiq concludes it.
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
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCA\Decidiq\Service\FlowDecisionService;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Signals the run suspended on a concluded flow decision.
 *
 * A WAKE, NOT AN ANSWER. The payload tells the node to look now instead of at
 * its next heartbeat; the node then READS the decision and routes on decidiq's
 * state. So a wrongly routed wake can at worst cost a re-suspension, never an
 * outcome.
 *
 * 🔑 MATCHED ON THE DECISION REF. A run is signalled only when one of its node
 * slots records this very decision, so a run waiting on a different decision
 * about the same object stays where it is.
 *
 * Never throws into the dispatcher: the decision is concluded whether or not a
 * run was listening, and a lost wake is recovered by the node's heartbeat.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
 */
class FlowDecisionConcludedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FlowDecisionService $decisions Reads the run reference back
	 * @param FlowRunMapper $runs Finds the run
	 * @param FlowRunSignalService $signals OpenRegister's guarded signal seam
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
	 */
	public function __construct(
		private readonly FlowDecisionService $decisions,
		private readonly FlowRunMapper $runs,
		private readonly FlowRunSignalService $signals,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a DecisionConcludedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
	 */
	public function handle(Event $event): void {
		if (($event instanceof DecisionConcludedEvent) === false) {
			return;
		}

		if ($event->getSourceApp() !== FlowDecisionService::SOURCE_APP) {
			return;
		}

		$decisionRef = trim($event->getDecisionId());
		if ($decisionRef === '') {
			return;
		}

		try {
			$candidates = $this->candidateRuns(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not look up the run waiting on a concluded flow decision; its heartbeat will recover it',
				['decisionRef' => $decisionRef, 'error' => $e->getMessage()]
			);

			return;
		}

		foreach ($candidates as $run) {
			$nodeId = $this->nodeAwaiting(run: $run, decisionRef: $decisionRef);
			if ($nodeId === null) {
				continue;
			}

			$this->wake(run: $run, nodeId: $nodeId, event: $event);

			return;
		}
	}//end handle()

	/**
	 * The runs that might be waiting: the one the reference names, or, for a
	 * decision raised without one, the suspended runs about its subject.
	 *
	 * @param DecisionConcludedEvent $event The conclusion
	 *
	 * @return array<int, FlowRun> The candidate runs
	 */
	private function candidateRuns(DecisionConcludedEvent $event): array {
		$reference = $this->decisions->parseRunReference(reference: $event->getExternalReference());
		if ($reference !== null) {
			return [$this->runs->findByUuid($reference['runUuid'])];
		}

		return array_values($this->runs->findSuspendedBySubject(subjectUuid: (string)$event->getSubjectId()));
	}//end candidateRuns()

	/**
	 * The node of this run whose slot records this decision, if any.
	 *
	 * @param FlowRun $run The candidate run
	 * @param string $decisionRef The concluded decision
	 *
	 * @return string|null The node id, or null when the run is not waiting on it
	 */
	private function nodeAwaiting(FlowRun $run, string $decisionRef): ?string {
		if ($run->getStatus() !== FlowRun::STATUS_SUSPENDED) {
			return null;
		}

		$slots = (($run->getContext() ?? [])[FlowResumeState::CONTEXT_KEY] ?? []);
		if (is_array($slots) === false) {
			return null;
		}

		foreach ($slots as $nodeId => $slot) {
			if (is_string($nodeId) === true
				&& is_array($slot) === true
				&& trim((string)($slot['decisionRef'] ?? '')) === $decisionRef
			) {
				return $nodeId;
			}
		}

		return null;
	}//end nodeAwaiting()

	/**
	 * Signal the run, addressed to the node that asked.
	 *
	 * Through the GUARDED seam, as OpenRegister asks every consumer to. The
	 * request-decision node records no assignee, so the guard admits the wake;
	 * using the unguarded primitive would silently re-open the gap the seam
	 * exists to close if that ever changed.
	 *
	 * @param FlowRun $run The waiting run
	 * @param string $nodeId The node that asked
	 * @param DecisionConcludedEvent $event The conclusion
	 *
	 * @return void
	 */
	private function wake(FlowRun $run, string $nodeId, DecisionConcludedEvent $event): void {
		try {
			$this->signals->signalRunAs(
				run: $run,
				payload: [
					'decision' => $event->getStatus(),
					'decisionRef' => $event->getDecisionId(),
					'subjectId' => (string)$event->getSubjectId(),
				],
				actorUid: null,
				nodeId: $nodeId
			);

			$this->logger->info(
				'Decidiq: woke flow run ' . (string)$run->getUuid() . ' on concluded decision ' . $event->getDecisionId(),
				['node' => $nodeId]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not wake the flow run waiting on a concluded decision; its heartbeat will recover it',
				['run' => $run->getUuid(), 'decisionRef' => $event->getDecisionId(), 'error' => $e->getMessage()]
			);
		}//end try
	}//end wake()
}//end class
