<?php

/**
 * Contract of DecisionIntegrationService
 *
 * The public surface of DecisionIntegrationService that other components call, so they depend
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
 * Contract of DecisionIntegrationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionIntegrationServiceInterface {

	/**
	 * Create a Decision raised by an external fleet app, idempotent on the
	 * provenance tuple (sourceApp, subjectRegister, subjectSchema, subjectId,
	 * externalReference). Returns the decisionId (existing or newly created)
	 * and a `created` flag (false on idempotent hit).
	 *
	 * Does NOT implement CRUD — persists through OpenRegister ObjectService.
	 *
	 * @param array<string, mixed> $decisionData Request body (validated by controller)
	 * @param string $actorId Nextcloud UID of the creating user
	 *
	 * @return array{success: bool, decisionId?: string, created?: bool, message?: string}
	 *
	 * @spec openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md
	 */
	public function createDecision(array $decisionData, string $actorId): array;

	/**
	 * Assemble and return the outcome envelope for a Decision (REQ-DCDH-003).
	 *
	 * The `status` field is DERIVED from existing lifecycle + outcome fields
	 * (no new state machine, ADR-031):
	 *   approved  = lifecycle in {decided, enacted} with outcome=adopted
	 *   rejected  = lifecycle in {decided, enacted} with outcome=rejected (or any rejecting outcome)
	 *   withdrawn = lifecycle=withdrawn
	 *   pending   = any other lifecycle
	 *
	 * This method does NOT authorize the caller. It used to claim that
	 * OpenRegister RBAC inside `find()` settled per-object read access; that was
	 * false — the `Decision` schema declares no `authorization` block, so the
	 * decidesk register baseline applies (`read`/`list`:
	 * `["authenticated", "public"]`) and OR authorizes the read for everyone.
	 * The caller-scoping rule lives in
	 * {@see DecisionIntegrationAuthorizationGuard::isAuthorizedToReadOutcome()}
	 * and MUST be consulted before this method is invoked
	 * (`IntegrationController::getOutcome()` does so).
	 *
	 * @param string $decisionId UUID of the Decision
	 *
	 * @return array<string, mixed>|null Outcome envelope, or null when the Decision does not exist
	 *
	 * @spec openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function getOutcomeEnvelope(string $decisionId): ?array;

	/**
	 * Register an outcome callback for a Decision.
	 *
	 * The callbackUrl MUST match a registered ADR-019 integration registry
	 * consumer entry — arbitrary URLs are rejected to prevent SSRF.
	 * The URL is stored on the Decision's `outcomeCallbackUrl` field (additive,
	 * per REQ-DCDH-001 provenance block). The registry dispatch itself is
	 * declared via x-openregister-notifications on the Decision schema
	 * (ADR-031) and wired by OpenRegister's notification engine on terminal
	 * lifecycle transitions.
	 *
	 * This method does NOT authorize the caller. `isRegistryConsumer()` below
	 * validates the callback URL against the app-wide ADR-019 registry — it
	 * constrains WHERE the outcome may be delivered, not WHO may redirect it —
	 * and the `Decision` schema declares no `authorization` block, so
	 * OpenRegister authorizes the update for every authenticated user. The
	 * caller-scoping rule lives in
	 * {@see DecisionIntegrationAuthorizationGuard::isAuthorizedToSubscribe()}
	 * and MUST be consulted before this method is invoked
	 * (`IntegrationController::subscribe()` does so).
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callbackUrl Registry-validated callback URL
	 * @param string $actorId Nextcloud UID of the subscriber
	 *
	 * @return array{success: bool, subscriptionId?: string, decisionId?: string, callbackUrl?: string, code?: string, message?: string}
	 *
	 * @spec openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function registerOutcomeCallback(string $decisionId, string $callbackUrl, string $actorId): array;
}//end interface
