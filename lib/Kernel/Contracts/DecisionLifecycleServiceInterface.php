<?php

/**
 * Contract of DecisionLifecycleService
 *
 * The public surface of DecisionLifecycleService that other components call, so they depend
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

/**
 * Contract of DecisionLifecycleService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionLifecycleServiceInterface {

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
	public function getAvailableTransitions(string $decisionId): array;

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
	public function transition(string $decisionId, string $action, ?string $currentUserId = null, string $comment = ''): array;
}//end interface
