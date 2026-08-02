<?php
/**
 * Decidesk Integration Controller — contract-decision hub
 *
 * Exposes the three ADR-019 integration-surface endpoints that fleet apps
 * use to raise a Decision, query its outcome, and subscribe to a push callback.
 * Implements REQ-DCDH-002, REQ-DCDH-003, and REQ-DCDH-004.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2.
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\DecisionIntegrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
 * Per-object access control is delegated to OpenRegister ObjectService RBAC
 * inside DecisionIntegrationService — no object UUID is probed directly here.
 * The #[NoAdminRequired] attribute means any authenticated Nextcloud user
 * (or registry credential) may call these endpoints; the service layer then
 * enforces per-object read/write access (no-admin-IDOR gate pattern).
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */
class IntegrationController extends Controller
{
    /**
     * Construct the Integration controller.
     *
     * @param IRequest                   $request            HTTP request
     * @param IUserSession               $userSession        Nextcloud user session
     * @param DecisionIntegrationService $integrationService Outcome assembler + callback dispatcher
     * @param LoggerInterface            $logger             PSR-3 logger
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly DecisionIntegrationService $integrationService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

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
     *   title             string  required — human title shown in decidesk list
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
     * @spec            openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createDecision(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->request->getParams();

        // Validate required fields.
        $requiredFields = ['decisionType', 'title', 'text', 'decisionDate'];
        $missing        = [];
        foreach ($requiredFields as $field) {
            if (empty($body[$field]) === true) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return new JSONResponse(
                ['message' => 'Missing required fields: '.implode(', ', $missing)],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->integrationService->createDecision(
                decisionData: $body,
                actorId: $user->getUID()
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'IntegrationController: createDecision failed',
                ['exception' => $e->getMessage(), 'actor' => $user->getUID()]
            );
            return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($result['success'] === false) {
            return new JSONResponse(
                ['message' => $result['message'] ?? 'Failed to create decision.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse($result, Http::STATUS_CREATED);

    }//end createDecision()

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
     * Per-object read access is enforced by OpenRegister ObjectService RBAC
     * inside the service — callers without read access receive 404 (no UUID probing).
     *
     * @param string $id UUID of the Decision object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @spec            openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getOutcome(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->integrationService->getOutcomeEnvelope(decisionId: $id);
        } catch (\Throwable $e) {
            $this->logger->error(
                'IntegrationController: getOutcome failed',
                ['id' => $id, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($result === null) {
            return new JSONResponse(['message' => 'Decision not found.'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($result);

    }//end getOutcome()

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
     * Returns 400 when callbackUrl is missing.
     * Returns 403 when callbackUrl does not match a known registry consumer.
     * Returns 404 when the Decision is not found.
     *
     * @param string $id UUID of the Decision object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @spec            openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function subscribe(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        $callbackUrl = (string) ($this->request->getParam('callbackUrl', ''));
        if ($callbackUrl === '') {
            return new JSONResponse(['message' => "Missing required parameter 'callbackUrl'."], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->integrationService->registerOutcomeCallback(
                decisionId: $id,
                callbackUrl: $callbackUrl,
                actorId: $user->getUID()
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'IntegrationController: subscribe failed',
                ['id' => $id, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['message' => 'Internal server error.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($result['success'] === false) {
            $status = Http::STATUS_UNPROCESSABLE_ENTITY;
            if (($result['code'] ?? '') === 'not_found') {
                $status = Http::STATUS_NOT_FOUND;
            } else if (($result['code'] ?? '') === 'ssrf_rejected') {
                $status = Http::STATUS_FORBIDDEN;
            }

            return new JSONResponse(['message' => $result['message'] ?? 'Subscription failed.'], $status);
        }

        return new JSONResponse($result, Http::STATUS_CREATED);

    }//end subscribe()
}//end class
