<?php

/**
 * Decidiq Integration Controller — contract-decision hub
 *
 * Exposes the three ADR-019 integration-surface endpoints that fleet apps
 * use to raise a Decision, query its outcome, and subscribe to a push callback.
 * Implements REQ-DCDH-002, REQ-DCDH-003, and REQ-DCDH-004.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard;
use OCA\Decidiq\Service\DecisionIntegrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Integration surface controller for the decidesk contract-decision hub.
 *
 * Exposes POST /api/v1/decisions (create-decision-with-subject),
 * GET /api/v1/decisions/{id}/outcome (query outcome envelope), and
 * POST /api/v1/decisions/{id}/subscriptions (register outcome callback)
 * per the ADR-019 integration registry contract.
 *
 * AUTH POSTURE. This class used to state that per-object access control was
 * delegated to OpenRegister ObjectService RBAC inside
 * DecisionIntegrationService. That was NOT true: the `Decision` schema in
 * lib/Settings/decidesk_register.json declares no `authorization` block, so the
 * decidesk register baseline applies (`read`/`list`:
 * `["authenticated", "public"]`) and OpenRegister authorizes the read for
 * everyone — an unconfigured cascade is OPEN, not closed. `getOutcome()` and
 * `subscribe()` therefore enforce their own per-object rules (REQ-DCDH-101 /
 * REQ-DCDH-102, see DecisionIntegrationAuthorizationGuard::isAuthorizedToReadOutcome()
 * and ::isAuthorizedToSubscribe()). #[NoAdminRequired] plus a session check is
 * authentication, not authorization.
 *
 * The two rules differ on purpose: the READ allows a published decision through
 * (`isPublished === 'public'`), the WRITE does not. Public readability is not a
 * write grant — see subscribe()'s docblock.
 *
 * @spec openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
 */
class IntegrationController extends Controller {

	/**
	 * The fields a decision cannot be created without.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_DECISION_FIELDS = ['decisionType', 'title', 'text', 'decisionDate'];

	/**
	 * Subscription refusal codes with a status of their own; any other refusal is 422.
	 *
	 * @var array<string, int>
	 */
	private const SUBSCRIPTION_REFUSAL_STATUS = [
		'not_found' => Http::STATUS_NOT_FOUND,
		'ssrf_rejected' => Http::STATUS_FORBIDDEN,
	];
	/**
	 * Construct the Integration controller.
	 *
	 * @param IRequest $request HTTP request
	 * @param IUserSession $userSession Nextcloud user session
	 * @param DecisionIntegrationService $integrationService Outcome assembler + callback dispatcher
	 * @param LoggerInterface $logger PSR-3 logger
	 * @param IGroupManager $groupManager Group manager (admin bypass on the outcome-read and subscribe guards)
	 * @param DecisionIntegrationAuthorizationGuard $authorizationGuard Per-object outcome-read / subscribe authorization (REQ-DCDH-101 / REQ-DCDH-102)
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly DecisionIntegrationService $integrationService,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
		private readonly DecisionIntegrationAuthorizationGuard $authorizationGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Resolve the caller's UID for the authorization guard: null when the caller
	 * is a Nextcloud admin (admin bypass, mirroring
	 * `ProxyVoteController::resolveCallerUid()` and
	 * `ConflictOfInterestController::resolveCallerUid()`), the UID otherwise.
	 *
	 * A null return also covers "no session", but callers check for an
	 * authenticated user first, so a null here always means "admin".
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	private function resolveCallerUid(): ?string {
		return (new GuardCaller(userSession: $this->userSession, groupManager: $this->groupManager))->uid();
	}//end resolveCallerUid()

	/**
	 * Whether the guard refuses the caller; an administrator (null UID) is never refused.
	 *
	 * @param string $id Decision UUID.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	private function isSubscribeForbidden(string $id): bool {
		$callerUid = $this->resolveCallerUid();

		return $callerUid !== null
			&& $this->authorizationGuard->isAuthorizedToSubscribe(decisionId: $id, callerUid: $callerUid) === false;
	}//end isSubscribeForbidden()

	/**
	 * Whether the guard refuses the caller; an administrator (null UID) is never refused.
	 *
	 * @param string $id Decision UUID.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	private function isOutcomeReadForbidden(string $id): bool {
		$callerUid = $this->resolveCallerUid();

		return $callerUid !== null
			&& $this->authorizationGuard->isAuthorizedToReadOutcome(decisionId: $id, callerUid: $callerUid) === false;
	}//end isOutcomeReadForbidden()

	/**
	 * Create a Decision raised by an external fleet app.
	 *
	 * POST /api/v1/decisions
	 *
	 * Idempotent on the tuple (sourceApp, subjectRegister, subjectSchema,
	 * subjectId, externalReference): a second call for the same tuple returns
	 * the existing Decision rather than creating a duplicate (REQ-DCDH-002).
	 *
	 * Expected JSON body:
	 *   decisionType      string  required — e.g. "contract", "contract-renewal", "report-adoption"
	 *   title             string  required — human title shown in Decidiq list
	 *   text              string  required — decision body / rationale
	 *   decisionDate      string  required — ISO-8601 datetime
	 *   outcome           string  optional — "adopted"|"rejected" (default "adopted" for draft)
	 *   sourceApp         string  optional — slug of the calling app (provenance)
	 *   subjectRegister   string  optional — OR register of the originating object
	 *   subjectSchema     string  optional — OR schema of the originating object
	 *   subjectId         string  optional — UUID of the originating object
	 *   subjectLabel      string  optional — human label for the originating object
	 *   outcomeCallbackUrl string optional — registry-validated push-delivery URL
	 *   externalReference string  optional — caller's own idempotency key
	 *
	 * Returns 201 with { decisionId, created: true|false (false = idempotent hit) }
	 * Returns 401 when unauthenticated.
	 * Returns 400 when required fields are missing.
	 * Returns 422 when the decisionType is not recognised.
	 * Returns 500 on unexpected failure.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @spec            openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createDecision(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->authenticationRequired();
		}

		$body    = $this->request->getParams();
		$missing = $this->missingDecisionFields(body: $body);
		if ($missing !== []) {
			return new JSONResponse(
				['message' => 'Missing required fields: ' . implode(', ', $missing)],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->createDecisionAs(body: $body, user: $user);
	}//end createDecision()

	/**
	 * The required creation fields the body leaves empty, in order.
	 *
	 * @param array<string, mixed> $body The request body.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function missingDecisionFields(array $body): array {
		$missing = [];
		foreach (self::REQUIRED_DECISION_FIELDS as $field) {
			if (empty($body[$field]) === true) {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end missingDecisionFields()

	/**
	 * Create the decision as the session user and answer 201, 422 or 500.
	 *
	 * @param array<string, mixed> $body The validated request body.
	 * @param IUser                $user The session user.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function createDecisionAs(array $body, IUser $user): JSONResponse {
		$result = $this->createdDecision(body: $body, user: $user);
		if ($result === null) {
			return $this->internalError();
		}

		if ($result['success'] === false) {
			return new JSONResponse(
				['message' => $result['message'] ?? 'Failed to create decision.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end createDecisionAs()

	/**
	 * The service's creation result, or null when it failed (logged).
	 *
	 * @param array<string, mixed> $body The validated request body.
	 * @param IUser                $user The session user.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function createdDecision(array $body, IUser $user): ?array {
		try {
			return $this->integrationService->createDecision(
				decisionData: $body,
				actorId: $user->getUID()
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IntegrationController: createDecision failed',
				['exception' => $e->getMessage(), 'actor' => $user->getUID()]
			);
			return null;
		}
	}//end createdDecision()

	/**
	 * Return the outcome envelope for a delegated Decision.
	 *
	 * GET /api/v1/decisions/{id}/outcome
	 *
	 * The envelope contains decisionId, decisionType, a derived status
	 * (approved|rejected|withdrawn|pending), decidedAt, signed, signingReference,
	 * signedAt, signers, subjectRegister, subjectSchema, subjectId, and
	 * externalReference (REQ-DCDH-003).
	 *
	 * Authorization (REQ-DCDH-101): the caller must be the Decision's
	 * OpenRegister owner — the identity that raised it through
	 * `POST /api/v1/decisions`, i.e. the consumer this endpoint exists to serve
	 * — or a Nextcloud admin, or the Decision must be published
	 * (`isPublished === 'public'`). Anything else is refused with `403`. Without
	 * this any authenticated user could read any Decision's cross-app subject
	 * coordinates, the consumer's `externalReference` and the `signers` array by
	 * enumerating Decision UUIDs; the register baseline grants OR-level read to
	 * everyone because the `Decision` schema declares no `authorization` block.
	 *
	 * A Decision that does not exist still yields `404` — the guard allows the
	 * miss through so a `403` cannot become an existence oracle for a UUID the
	 * app never issued.
	 *
	 * @param string $id UUID of the Decision object
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @spec            openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec            openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-101-only-the-raising-consumer-an-admin-or-any-caller-of-a-published-decision-may-read-an-outcome-envelope
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getOutcome(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->authenticationRequired();
		}

		if ($this->isOutcomeReadForbidden(id: $id) === true) {
			return $this->forbidden();
		}

		return $this->outcomeResponse(id: $id);
	}//end getOutcome()

	/**
	 * The outcome envelope response: 200, 404 or 500.
	 *
	 * @param string $id Decision UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function outcomeResponse(string $id): JSONResponse {
		try {
			$result = $this->integrationService->getOutcomeEnvelope(decisionId: $id);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IntegrationController: getOutcome failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return $this->internalError();
		}

		if ($result === null) {
			return new JSONResponse(['message' => 'Decision not found.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end outcomeResponse()

	/**
	 * Register an outcome callback for a Decision.
	 *
	 * POST /api/v1/decisions/{id}/subscriptions
	 *
	 * The callback URL must match a registered ADR-019 registry consumer entry —
	 * arbitrary URLs are rejected (anti-SSRF, REQ-DCDH-004). When the Decision
	 * reaches a terminal outcome (decided/enacted/withdrawn), the outcome envelope
	 * is dispatched to the registered callback.
	 *
	 * Expected JSON body:
	 *   callbackUrl  string  required — registry-validated push-delivery URL
	 *
	 * Returns 201 with the subscription id on success.
	 * Returns 401 when unauthenticated.
	 * Returns 403 when the caller may not attach a callback to this Decision.
	 * Returns 400 when callbackUrl is missing.
	 * Returns 403 when callbackUrl does not match a known registry consumer.
	 * Returns 404 when the Decision is not found.
	 *
	 * Authorization (REQ-DCDH-102): the caller must be the Decision's
	 * OpenRegister owner — the consumer that raised it through
	 * `POST /api/v1/decisions` — or a Nextcloud admin. Anything else is refused
	 * with `403`.
	 *
	 * This is a WRITE, so the read rule of REQ-DCDH-101 does NOT apply: the
	 * `isPublished === 'public'` arm that keeps a published decision READABLE by
	 * anyone is deliberately absent here. `isPublished` is an admin-set
	 * read-visibility enum (`DecisionController::publish()` is
	 * `#[AuthorizedAdminSetting]`); honouring it on this path would mean the act
	 * of publishing a decision also opens its delivery target to every
	 * authenticated user. See
	 * `DecisionIntegrationAuthorizationGuard::isAuthorizedToSubscribe()` for the
	 * full derivation.
	 *
	 * Without this guard any authenticated user could overwrite the raising
	 * consumer's `outcomeCallbackUrl` on any Decision UUID — redirecting the
	 * outcome envelope (subject coordinates, `externalReference`, `signers`) to
	 * another registered consumer and denying the legitimate one its callback.
	 * The pre-existing `Http::STATUS_FORBIDDEN` in this method is the anti-SSRF
	 * `ssrf_rejected` mapping, which validates the URL and never the caller.
	 *
	 * A Decision that does not exist still yields `404` — the guard allows the
	 * miss through so a `403` cannot become an existence oracle.
	 *
	 * @param string $id UUID of the Decision object
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @spec            openspec/changes/archive/2026-06-14-decidesk-contract-decision-hub/tasks.md#phase-2
	 * @spec            openspec/specs/signature-and-outcome-authorization/spec.md#requirement-req-dcdh-102-only-the-raising-consumer-or-an-admin-may-attach-an-outcome-callback-to-a-decision
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function subscribe(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->authenticationRequired();
		}

		if ($this->isSubscribeForbidden(id: $id) === true) {
			return $this->forbidden();
		}

		$request = $this->subscriptionRequest(id: $id, user: $user);
		if ($request === null) {
			return new JSONResponse(['message' => "Missing required parameter 'callbackUrl'."], Http::STATUS_BAD_REQUEST);
		}

		return $this->subscriptionResponse(request: $request);
	}//end subscribe()

	/**
	 * The subscription the request asks for, or null without a callbackUrl.
	 *
	 * @param string $id   Decision UUID.
	 * @param IUser  $user The session user.
	 *
	 * @return OutcomeSubscriptionRequest|null
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function subscriptionRequest(string $id, IUser $user): ?OutcomeSubscriptionRequest {
		$callbackUrl = (string)($this->request->getParam('callbackUrl', ''));
		if ($callbackUrl === '') {
			return null;
		}

		return new OutcomeSubscriptionRequest(decisionId: $id, callbackUrl: $callbackUrl, actorId: $user->getUID());
	}//end subscriptionRequest()

	/**
	 * Register the callback and answer 201, or the refusal's status, or 500.
	 *
	 * @param OutcomeSubscriptionRequest $request The validated subscription.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function subscriptionResponse(OutcomeSubscriptionRequest $request): JSONResponse {
		$result = $this->registeredCallback(request: $request);
		if ($result === null) {
			return $this->internalError();
		}

		if ($result['success'] === false) {
			$status = $this->subscriptionRefusalStatus(code: ($result['code'] ?? ''));

			return new JSONResponse(['message' => $result['message'] ?? 'Subscription failed.'], $status);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end subscriptionResponse()

	/**
	 * The service's subscription result, or null when it failed (logged).
	 *
	 * @param OutcomeSubscriptionRequest $request The validated subscription.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function registeredCallback(OutcomeSubscriptionRequest $request): ?array {
		try {
			return $this->integrationService->registerOutcomeCallback(
				decisionId: $request->decisionId,
				callbackUrl: $request->callbackUrl,
				actorId: $request->actorId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IntegrationController: subscribe failed',
				['id' => $request->decisionId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end registeredCallback()

	/**
	 * The status a refused subscription answers with, by its refusal code.
	 *
	 * @param mixed $code The service's refusal code.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function subscriptionRefusalStatus(mixed $code): int {
		if (is_string($code) === false || isset(self::SUBSCRIPTION_REFUSAL_STATUS[$code]) === false) {
			return Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		return self::SUBSCRIPTION_REFUSAL_STATUS[$code];
	}//end subscriptionRefusalStatus()

	/**
	 * The 401 answer for a request without a session.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function authenticationRequired(): JSONResponse {
		return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
	}//end authenticationRequired()

	/**
	 * The 403 answer for a caller the guard refuses.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(['message' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
	}//end forbidden()

	/**
	 * The 500 answer that never leaks the failure's detail.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
	 */
	private function internalError(): JSONResponse {
		return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end internalError()
}//end class
