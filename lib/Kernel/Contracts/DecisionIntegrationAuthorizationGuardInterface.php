<?php

/**
 * Contract of DecisionIntegrationAuthorizationGuard
 *
 * The public surface of DecisionIntegrationAuthorizationGuard that other components call, so they depend
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
 * Contract of DecisionIntegrationAuthorizationGuard.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DecisionIntegrationAuthorizationGuardInterface {

	/**
	 * The caller may read this Decision's outcome envelope.
	 *
	 * @var string
	 */
	public const READ_ALLOWED = 'allowed';

	/**
	 * The question could not be answered — the Decision could not be resolved
	 * at all. Not the same fact as a refusal; see
	 * {@see self::resolveOutcomeReadAccess()}.
	 *
	 * @var string
	 */
	public const READ_UNRESOLVED = 'unresolved';

	/**
	 * Authorize a read of one Decision's outcome envelope (REQ-DCDH-101).
	 *
	 * A caller is authorized when:
	 *   (a) they are the Decision's OpenRegister owner (`@self.owner`) — the
	 *       identity that raised it through `POST /api/v1/decisions`, i.e. the
	 *       consumer REQ-DCDH-003 exists to serve. This is also the established
	 *       Decidiq per-object guard on this very `decision` schema; see
	 *       `MotionCoauthorService::checkMotionAccess()`; OR
	 *   (b) the Decision is published (`isPublished === 'public'`) — the app's
	 *       own citizen-visibility flag, set by `DecisionController::publish()`.
	 *       A published decision is a public governance record by definition.
	 *
	 * The admin bypass is the caller's: `IntegrationController` passes a null
	 * `$callerUid` for a Nextcloud administrator and skips this check entirely,
	 * mirroring the `ProxyVoteController` / `ConflictOfInterestController`
	 * convention.
	 *
	 * There is deliberately no `sourceApp`-based rule: the hub holds no
	 * caller-to-consumer identity mapping. `DecisionIntegrationService::createDecision()`'s
	 * `$actorId` is written to the audit log only and never persisted on the
	 * object, and `isRegistryConsumer()` validates a callback URL rather than a
	 * caller — so `@self.owner` is the only ownership fact that actually exists.
	 *
	 * Fail-closed (ADR-005 / gate-8 `unsafe-auth-resolver`): when OpenRegister
	 * is unavailable or the lookup throws, the caller is DENIED rather than
	 * waved through. A Decision that genuinely does not exist is allowed
	 * through so that `getOutcomeEnvelope()` still answers `404` — the guard
	 * must not turn a miss into a `403`.
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callerUid Nextcloud UID of the caller (never null here — a
	 *                          null caller is the admin bypass and never reaches this method)
	 *
	 * @return bool True when the caller may read the envelope
	 *
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	public function isAuthorizedToReadOutcome(string $decisionId, string $callerUid): bool;

	/**
	 * Authorize a WRITE of one Decision's outcome callback (REQ-DCDH-102).
	 *
	 * A caller is authorized when they are the Decision's OpenRegister owner
	 * (`@self.owner`) — the identity that raised it through
	 * `POST /api/v1/decisions`. As with the read guard, a Nextcloud
	 * administrator never reaches this method: `IntegrationController` resolves
	 * a null `$callerUid` for an admin and skips the check.
	 *
	 * WHY THIS IS NOT THE READ RULE. {@see self::isAuthorizedToReadOutcome()}
	 * additionally allows any caller when `isPublished === 'public'`. That arm
	 * deliberately does NOT apply here, for three reasons that come out of the
	 * code rather than out of caution:
	 *
	 *   1. `isPublished` is a READ-visibility enum. It is only ever moved from
	 *      `internal` to `public` by `DecisionController::publish()`, which is
	 *      `#[AuthorizedAdminSetting]` and re-checks `isAdmin()` in its body,
	 *      and it is consumed by `OriController` to gate the anonymous public
	 *      feed (`$filters['isPublished'] = 'public'`). Nothing reads it as a
	 *      write grant. Carrying it onto this path would mean an admin's act of
	 *      widening who may READ silently widens who may WRITE — publishing a
	 *      decision would open its delivery target to every authenticated user.
	 *
	 *   2. The write is destructive by overwrite. `registerOutcomeCallback()`
	 *      assigns a single scalar field, so a second caller silently REPLACES
	 *      the raising consumer's delivery target: the outcome envelope — the
	 *      subject coordinates, the consumer's `externalReference` and the
	 *      `signers` array — is then pushed to a URL of the second caller's
	 *      choosing, and the legitimate consumer never receives its callback.
	 *
	 *   3. The anti-SSRF check is not a caller check. `isRegistryConsumer()`
	 *      validates the URL against the app-wide ADR-019 registry; every
	 *      authenticated user may name any registered consumer's URL. It
	 *      constrains WHERE the data goes, never WHO may redirect it.
	 *
	 * No capability is lost (ADR-044): there is no frontend caller of
	 * `/api/v1/decisions/...` at all, and the raising consumer can already set
	 * `outcomeCallbackUrl` in the create-decision body (it is a
	 * PROVENANCE_FIELD), so this endpoint is the same owner's later update.
	 *
	 * Fail-closed (ADR-005 / gate-8 `unsafe-auth-resolver`): an unresolvable
	 * lookup DENIES. A Decision that genuinely does not exist is allowed
	 * through so `registerOutcomeCallback()` still answers `not_found` → 404,
	 * keeping a 403 from becoming an existence oracle.
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callerUid Nextcloud UID of the caller (never null here — a
	 *                          null caller is the admin bypass and never reaches this method)
	 *
	 * @return bool True when the caller may attach an outcome callback
	 *
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	public function isAuthorizedToSubscribe(string $decisionId, string $callerUid): bool;

	/**
	 * The outcome-read rule of {@see self::isAuthorizedToReadOutcome()}, with
	 * "I could not tell" reported instead of folded into "no".
	 *
	 * SAME RULE, ONE MORE ANSWER. The boolean above is this method with
	 * `unresolved` collapsed onto `denied`, which is right for an HTTP caller:
	 * a request either proceeds or gets a 403, and failing closed is the only
	 * safe collapse. It is NOT right for a caller that has to decide whether to
	 * come back. `DecisionStateRequestedListener` serves a consumer's recovery
	 * heartbeat, and there "OpenRegister was unreachable for a moment" and "you
	 * may not see this Decision" call for opposite actions: wait, versus stop
	 * waiting and say why. Collapsing them would make a transient outage fail a
	 * consumer's run on an authorization error it never had.
	 *
	 * The rule itself is not restated — the boolean now delegates here, so the
	 * HTTP path and the event path cannot come to disagree about who may read.
	 *
	 * @param string $decisionId UUID of the Decision
	 * @param string $callerUid Nextcloud UID of the caller
	 *
	 * @return string One of self::READ_ALLOWED, self::READ_DENIED, self::READ_UNRESOLVED
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function resolveOutcomeReadAccess(string $decisionId, string $callerUid): string;
}//end interface
