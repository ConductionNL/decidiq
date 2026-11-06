<?php

/**
 * Decidiq FlowDecisionService
 *
 * The flow engine's door into decidiq's decisions: raise one about an object a
 * flow is carrying, and read back what became of it. Used by
 * {@see \OCA\Decidiq\Flow\DecidiqRequestDecisionNode}.
 *
 * IT ADDS A CALLER, NOT A SECOND ANSWER. A consumer app reaches the same two
 * operations over the event bus (`DecisionRequestedEvent`,
 * `DecisionStateRequestedEvent`); inside decidiq there is no reason to dispatch
 * our own events to ourselves, so this calls what those listeners call:
 * `DecisionIntegrationService::createDecision()`, the outcome-read guard, and
 * `DecisionIntegrationService::getOutcomeEnvelope()`. Nothing here derives a
 * status or an access rule of its own.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises and reads back decisions on behalf of a flow run.
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
 */
class FlowDecisionService {

	/**
	 * The provenance a flow-raised decision carries as its `sourceApp`.
	 *
	 * FROZEN once decisions exist. `FlowDecisionConcludedListener` wakes a run
	 * only for a conclusion carrying this value, and decidiq's idempotency key
	 * includes it, so renaming it orphans every in-flight flow decision from
	 * its wake (the heartbeat would still recover them, a heartbeat later).
	 *
	 * Distinct from `procest`, the value dossiq's delegation stamps, so dossiq's
	 * conclusion listener never projects a flow decision into a ZGW besluit.
	 *
	 * @var string
	 */
	public const SOURCE_APP = 'decidiq-flow';

	/**
	 * The prefix of the external reference a flow decision carries:
	 * `flow-run:<runUuid>:<nodeId>`.
	 *
	 * @var string
	 */
	private const RUN_REFERENCE_PREFIX = 'flow-run:';

	/**
	 * The read could not be answered: OpenRegister unavailable, or the lookup
	 * failed. NEVER "there is no such decision".
	 *
	 * @var string
	 */
	public const STATE_UNREADABLE = 'unreadable';

	/**
	 * The decision exists and the identity named may not read it.
	 *
	 * @var string
	 */
	public const STATE_REFUSED = 'refused';

	/**
	 * The read was allowed and no decision carries that id.
	 *
	 * @var string
	 */
	public const STATE_GONE = 'gone';

	/**
	 * The decision exists and has not been concluded.
	 *
	 * @var string
	 */
	public const STATE_OPEN = 'open';

	/**
	 * The decision was concluded with an outcome: `approved` or `rejected`.
	 *
	 * @var string
	 */
	public const STATE_DECIDED = 'decided';

	/**
	 * The decision reached a terminal state carrying no answer.
	 *
	 * @var string
	 */
	public const STATE_WITHDRAWN = 'withdrawn';

	/**
	 * Constructor.
	 *
	 * @param DecisionIntegrationService $integrationService The create path and the outcome envelope
	 * @param DecisionIntegrationAuthorizationGuard $authorizationGuard The outcome-read rule (REQ-DCDH-101)
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md
	 */
	public function __construct(
		private readonly DecisionIntegrationService $integrationService,
		private readonly DecisionIntegrationAuthorizationGuard $authorizationGuard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise a decision about a flow's object, and return its id.
	 *
	 * FAILS CLOSED. Any answer other than a decision id throws: a flow step that
	 * carried on without one would move its object past a decision nobody
	 * made, which is the one outcome a decision step exists to prevent.
	 *
	 * Idempotent on decidiq's provenance tuple (sourceApp, subject,
	 * externalReference), so a raise retried after a crash between the save and
	 * the caller recording the id returns the decision it already made.
	 *
	 * @param string $decisionType The configured decision type slug
	 * @param string $externalReference The caller's own reference; part of the idempotency key
	 * @param array<string, string> $subject subjectRegister, subjectSchema, subjectId, subjectLabel
	 * @param array<string, mixed> $context The ask itself (question, advisor)
	 * @param string $actorId The uid the raise is recorded as in the audit log
	 *
	 * @return string The decision id
	 *
	 * @throws RuntimeException When the decision could not be raised
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-003-the-step-fails-closed
	 */
	public function raise(
		string $decisionType,
		string $externalReference,
		array $subject,
		array $context,
		string $actorId,
	): string {
		// The object's label titles the decision, as it did for dossiq's
		// delegation; an object with no label is titled by the question, which
		// reads better in a decision list than "Decision requested by
		// decidiq-flow".
		$title = trim((string)($subject['subjectLabel'] ?? ''));
		if ($title === '') {
			$title = trim((string)($context['question'] ?? ''));
		}

		$result = $this->integrationService->createDecision(
			[
				'decisionType' => $decisionType,
				'sourceApp' => self::SOURCE_APP,
				'subjectRegister' => (string)($subject['subjectRegister'] ?? ''),
				'subjectSchema' => (string)($subject['subjectSchema'] ?? ''),
				'subjectId' => (string)($subject['subjectId'] ?? ''),
				'subjectLabel' => (string)($subject['subjectLabel'] ?? ''),
				'externalReference' => $externalReference,
				'title' => $title,
				'context' => $context,
			],
			$actorId
		);

		$decisionId = trim((string)($result['decisionId'] ?? ''));
		if ($result['success'] !== true || $decisionId === '') {
			throw new RuntimeException(
				'Decidiq did not raise the decision: ' . (string)($result['message'] ?? 'no decision id was returned')
			);
		}

		return $decisionId;
	}//end raise()

	/**
	 * Read what became of a decision, as the identity it is scoped to.
	 *
	 * SIX ANSWERS, NOT A BOOLEAN. "Could not ask", "may not see it", "not
	 * there", "still open", "decided" and "withdrawn" call for six different
	 * actions from a waiting run. Folding "could not ask" into "not there"
	 * would fail a run whose decision is sitting there taken because
	 * OpenRegister was briefly unreachable.
	 *
	 * An identity is required, and an empty one is UNREADABLE rather than
	 * refused: the caller has nobody to ask as, which says nothing about the
	 * decision.
	 *
	 * @param string $decisionId The decision id
	 * @param string $actorId The uid the read is scoped to
	 *
	 * @return array{state: string, status: string, envelope: array<string, mixed>} The state, decidiq's status word and the outcome envelope
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-004-the-step-advances-on-its-decision-not-on-a-signal
	 */
	public function readState(string $decisionId, string $actorId): array {
		$decisionId = trim($decisionId);
		$actorId = trim($actorId);
		if ($decisionId === '' || $actorId === '') {
			return $this->answer(state: self::STATE_UNREADABLE);
		}

		try {
			$access = $this->authorizationGuard->resolveOutcomeReadAccess(
				decisionId: $decisionId,
				callerUid: $actorId
			);

			if ($access === DecisionIntegrationAuthorizationGuard::READ_UNRESOLVED) {
				return $this->answer(state: self::STATE_UNREADABLE);
			}

			if ($access !== DecisionIntegrationAuthorizationGuard::READ_ALLOWED) {
				return $this->answer(state: self::STATE_REFUSED);
			}

			$envelope = $this->integrationService->getOutcomeEnvelope(decisionId: $decisionId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: could not read a flow decision',
				['decisionId' => $decisionId, 'actor' => $actorId, 'exception' => $e->getMessage()]
			);

			return $this->answer(state: self::STATE_UNREADABLE);
		}//end try

		if ($envelope === null) {
			return $this->answer(state: self::STATE_GONE);
		}

		return $this->stateFromEnvelope(envelope: $envelope, decisionId: $decisionId);
	}//end readState()

	/**
	 * Map the envelope's derived status onto a state.
	 *
	 * An unknown word reads as OPEN: waiting through a vocabulary extension
	 * costs a heartbeat, while guessing it means "decided" would advance a run
	 * on an outcome nobody can name.
	 *
	 * @param array<string, mixed> $envelope The outcome envelope
	 * @param string $decisionId The decision id, for the log line
	 *
	 * @return array{state: string, status: string, envelope: array<string, mixed>} The answer
	 */
	private function stateFromEnvelope(array $envelope, string $decisionId): array {
		$status = strtolower(trim((string)($envelope['status'] ?? '')));

		$state = match ($status) {
			'approved', 'rejected' => self::STATE_DECIDED,
			'withdrawn' => self::STATE_WITHDRAWN,
			'pending' => self::STATE_OPEN,
			default => null,
		};

		if ($state === null) {
			$this->logger->warning(
				'Decidiq: a flow decision carries a status this reader does not know; treating it as still open',
				['decisionId' => $decisionId, 'status' => $status]
			);
			$state = self::STATE_OPEN;
		}

		return $this->answer(state: $state, status: $status, envelope: $envelope);
	}//end stateFromEnvelope()

	/**
	 * The external reference naming one node of one run.
	 *
	 * @param string $runUuid The flow run uuid
	 * @param string $nodeId The node id within the flow graph
	 *
	 * @return string `flow-run:<runUuid>:<nodeId>`
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-002-the-decision-is-about-the-object-the-flow-carries
	 */
	public function runReference(string $runUuid, string $nodeId): string {
		return self::RUN_REFERENCE_PREFIX . trim($runUuid) . ':' . trim($nodeId);
	}//end runReference()

	/**
	 * Read a run reference back into its run uuid and node id.
	 *
	 * A run uuid never contains a colon, so the first colon after the prefix
	 * separates it from the node id, which may.
	 *
	 * @param string $reference An external reference
	 *
	 * @return array{runUuid: string, nodeId: string}|null The parts, or null when this is not a run reference
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
	 */
	public function parseRunReference(string $reference): ?array {
		$reference = trim($reference);
		if (str_starts_with($reference, self::RUN_REFERENCE_PREFIX) === false) {
			return null;
		}

		$parts = explode(':', substr($reference, strlen(self::RUN_REFERENCE_PREFIX)), 2);
		$runUuid = trim($parts[0]);
		$nodeId = trim($parts[1] ?? '');
		if ($runUuid === '' || $nodeId === '') {
			return null;
		}

		return ['runUuid' => $runUuid, 'nodeId' => $nodeId];
	}//end parseRunReference()

	/**
	 * One answer shape, so no caller interprets an absent key.
	 *
	 * @param string $state One of the STATE_* constants
	 * @param string $status decidiq's status word, when there was one
	 * @param array<string, mixed> $envelope The outcome envelope, when there was one
	 *
	 * @return array{state: string, status: string, envelope: array<string, mixed>} The answer
	 */
	private function answer(string $state, string $status = '', array $envelope = []): array {
		return ['state' => $state, 'status' => $status, 'envelope' => $envelope];
	}//end answer()
}//end class
