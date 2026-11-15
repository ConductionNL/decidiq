<?php

/**
 * Contract of FlowDecisionService
 *
 * The public surface of FlowDecisionService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of FlowDecisionService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FlowDecisionServiceInterface {

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
	 * Read a run reference back into its run uuid and node id.
	 *
	 * A run uuid never contains a colon, so the first colon after the prefix
	 * separates it from the node id, which may.
	 *
	 * @param string $reference An external reference
	 *
	 * @return array{runUuid: string, nodeId: string}|null The parts, or null when this is not a run reference
	 *
	 * @spec openspec/specs/flow-request-decision/spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked
	 */
	public function parseRunReference(string $reference): ?array;

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
	 * @spec openspec/specs/flow-request-decision/spec.md#requirement-req-frd-003-the-step-fails-closed
	 */
	public function raise(
		string $decisionType,
		string $externalReference,
		array $subject,
		array $context,
		string $actorId,
	): string;

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
	 * @spec openspec/specs/flow-request-decision/spec.md#requirement-req-frd-004-the-step-advances-on-its-decision-not-on-a-signal
	 */
	public function readState(string $decisionId, string $actorId): array;

	/**
	 * The external reference naming one node of one run.
	 *
	 * @param string $runUuid The flow run uuid
	 * @param string $nodeId The node id within the flow graph
	 *
	 * @return string `flow-run:<runUuid>:<nodeId>`
	 *
	 * @spec openspec/specs/flow-request-decision/spec.md#requirement-req-frd-002-the-decision-is-about-the-object-the-flow-carries
	 */
	public function runReference(string $runUuid, string $nodeId): string;
}//end interface
