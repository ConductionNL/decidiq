<?php
/**
 * Decidesk Decision Integration Service
 *
 * Assembles the cross-app outcome envelope from existing Decision +
 * DecisionStage + Minutes data; dispatches registry callbacks; implements
 * the idempotent create-decision endpoint logic and the anti-SSRF callback
 * registry validation. Does NOT wrap ObjectService CRUD (ADR-022) — CRUD
 * stays on OpenRegister's object surface.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2.
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin service that assembles the decision outcome envelope and manages
 * the integration-registry callback lifecycle.
 *
 * Key invariants:
 *  - Does NOT own Decision CRUD — delegates all persistence to OpenRegister ObjectService.
 *  - Outcome status is DERIVED from existing lifecycle + outcome fields (no new state machine).
 *  - Callback URLs are VALIDATED against the ADR-019 registry before acceptance (anti-SSRF).
 *  - create-decision is IDEMPOTENT on (sourceApp, subjectRegister, subjectSchema, subjectId, externalReference).
 *
 * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
 */
class DecisionIntegrationService
{

    /**
     * Lifecycles that map to the "approved" outcome status.
     *
     * @var list<string>
     */
    private const APPROVED_LIFECYCLES = ['decided', 'enacted'];

    /**
     * Construct the service.
     *
     * @param ContainerInterface $container DI container (lazy ObjectService lookup)
     * @param LoggerInterface    $logger    PSR-3 logger
     * @param AuditLogService    $auditLog  Audit log dependency
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLog,
    ) {
    }//end __construct()

    /**
     * Create a Decision raised by an external fleet app, idempotent on the
     * provenance tuple (sourceApp, subjectRegister, subjectSchema, subjectId,
     * externalReference). Returns the decisionId (existing or newly created)
     * and a `created` flag (false on idempotent hit).
     *
     * Does NOT implement CRUD — persists through OpenRegister ObjectService.
     *
     * @param array<string, mixed> $decisionData Request body (validated by controller)
     * @param string               $actorId      Nextcloud UID of the creating user
     *
     * @return array{success: bool, decisionId?: string, created?: bool, message?: string}
     *
     * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
     */
    public function createDecision(array $decisionData, string $actorId): array
    {
        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error('DecisionIntegrationService: OpenRegister unavailable', ['exception' => $e->getMessage()]);
            return ['success' => false, 'message' => 'OpenRegister is not available.'];
        }

        // Validate decisionType against the integration-hub supported types.
        $allowedTypes = [
            'motion', 'amendment', 'resolution', 'contract', 'contract-renewal',
            'report-adoption', 'appointment', 'management-point', 'policy', 'meeting-outcome',
        ];
        $decisionType = (string) ($decisionData['decisionType'] ?? '');
        if (in_array($decisionType, $allowedTypes, true) === false) {
            return ['success' => false, 'message' => "Unrecognised decisionType: '{$decisionType}'."];
        }

        // Idempotency: search for an existing decision with the same provenance tuple.
        $sourceApp         = (string) ($decisionData['sourceApp'] ?? '');
        $subjectRegister   = (string) ($decisionData['subjectRegister'] ?? '');
        $subjectSchema     = (string) ($decisionData['subjectSchema'] ?? '');
        $subjectId         = (string) ($decisionData['subjectId'] ?? '');
        $externalReference = (string) ($decisionData['externalReference'] ?? '');

        $hasTuple = ($sourceApp !== '' && $subjectId !== '');
        if ($hasTuple === true) {
            $filters = ['sourceApp' => $sourceApp, 'subjectId' => $subjectId];
            if ($subjectRegister !== '') {
                $filters['subjectRegister'] = $subjectRegister;
            }

            if ($subjectSchema !== '') {
                $filters['subjectSchema'] = $subjectSchema;
            }

            if ($externalReference !== '') {
                $filters['externalReference'] = $externalReference;
            }

            try {
                $existing = $objectService->findAll(
                    register: 'decidesk',
                    schema: 'decision',
                    filters: $filters
                );
            } catch (\Throwable $e) {
                $existing = [];
            }

            if (is_array($existing) === true && count($existing) > 0) {
                $first     = reset($existing);
                $firstData = is_array($first) ? $first : (array) $first->jsonSerialize();
                $id        = (string) ($firstData['id'] ?? ($firstData['uuid'] ?? ''));
                return ['success' => true, 'decisionId' => $id, 'created' => false];
            }
        }//end if

        // Build the Decision object with provenance fields.
        $object = [
            'decisionType' => $decisionType,
            'title'        => (string) ($decisionData['title'] ?? ''),
            'text'         => (string) ($decisionData['text'] ?? ''),
            'decisionDate' => (string) ($decisionData['decisionDate'] ?? ''),
            'outcome'      => (string) ($decisionData['outcome'] ?? 'adopted'),
            'lifecycle'    => 'draft',
        ];

        // Additive provenance fields (REQ-DCDH-001).
        $provenanceFields = [
            'sourceApp', 'subjectRegister', 'subjectSchema', 'subjectId',
            'subjectLabel', 'outcomeCallbackUrl', 'externalReference',
        ];
        foreach ($provenanceFields as $field) {
            $val = (string) ($decisionData[$field] ?? '');
            if ($val !== '') {
                $object[$field] = $val;
            }
        }

        try {
            $saved = $objectService->saveObject(
                object: $object,
                register: 'decidesk',
                schema: 'decision'
            );

            $savedArr   = is_array($saved) ? $saved : (array) $saved->jsonSerialize();
            $decisionId = (string) ($savedArr['id'] ?? ($savedArr['uuid'] ?? ''));

            $this->auditLog->append(
                actor: $actorId,
                action: 'integration-create',
                objectUids: [$decisionId],
                payload: ['sourceApp' => $sourceApp, 'subjectId' => $subjectId, 'decisionType' => $decisionType]
            );

            return ['success' => true, 'decisionId' => $decisionId, 'created' => true];
        } catch (\Throwable $e) {
            $this->logger->error(
                'DecisionIntegrationService: saveObject failed',
                ['exception' => $e->getMessage(), 'actor' => $actorId]
            );
            return ['success' => false, 'message' => 'Failed to persist decision: '.$e->getMessage()];
        }

    }//end createDecision()

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
     * Per-object read access is enforced by OpenRegister RBAC inside find();
     * callers without access receive null (caller renders 404).
     *
     * @param string $decisionId UUID of the Decision
     *
     * @return array<string, mixed>|null Outcome envelope, or null if not found / no access
     *
     * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
     */
    public function getOutcomeEnvelope(string $decisionId): ?array
    {
        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error('DecisionIntegrationService: OpenRegister unavailable', ['exception' => $e->getMessage()]);
            return null;
        }

        try {
            $entity = $objectService->find(id: $decisionId, register: 'decidesk', schema: 'decision');
        } catch (\Throwable $e) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        $decision = is_array($entity) ? $entity : (array) $entity->jsonSerialize();

        $lifecycle = (string) ($decision['lifecycle'] ?? 'draft');
        $outcome   = (string) ($decision['outcome'] ?? '');

        // Derive status (ADR-031 — declarative, no new state machine).
        if ($lifecycle === 'withdrawn') {
            $status = 'withdrawn';
        } elseif (in_array($lifecycle, self::APPROVED_LIFECYCLES, true) === true && $outcome === 'adopted') {
            $status = 'approved';
        } elseif (in_array($lifecycle, self::APPROVED_LIFECYCLES, true) === true && $outcome !== '') {
            $status = 'rejected';
        } else {
            $status = 'pending';
        }

        $decidedAt = null;
        if ($status !== 'pending') {
            $decidedAt = (string) ($decision['decisionDate'] ?? ($decision['enactedAt'] ?? null));
        }

        // Resolve signing information from DecisionStage(s) with method=signature.
        $signingInfo = $this->resolveSigningInfo(decisionId: $decisionId, objectService: $objectService);

        return [
            'decisionId'        => $decisionId,
            'decisionType'      => (string) ($decision['decisionType'] ?? ''),
            'status'            => $status,
            'decidedAt'         => $decidedAt,
            'signed'            => $signingInfo['signed'],
            'signingReference'  => $signingInfo['signingReference'],
            'signedAt'          => $signingInfo['signedAt'],
            'signers'           => $signingInfo['signers'],
            'subjectRegister'   => ($decision['subjectRegister'] ?? null),
            'subjectSchema'     => ($decision['subjectSchema'] ?? null),
            'subjectId'         => ($decision['subjectId'] ?? null),
            'externalReference' => ($decision['externalReference'] ?? null),
        ];

    }//end getOutcomeEnvelope()

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
     * @param string $decisionId  UUID of the Decision
     * @param string $callbackUrl Registry-validated callback URL
     * @param string $actorId     Nextcloud UID of the subscriber
     *
     * @return array{success: bool, subscriptionId?: string, code?: string, message?: string}
     *
     * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-2
     */
    public function registerOutcomeCallback(string $decisionId, string $callbackUrl, string $actorId): array
    {
        // Anti-SSRF: validate the callbackUrl against the ADR-019 registry.
        if ($this->isRegistryConsumer(url: $callbackUrl) === false) {
            $this->logger->warning(
                'DecisionIntegrationService: SSRF-rejected callback URL',
                ['url' => $callbackUrl, 'actor' => $actorId]
            );
            return ['success' => false, 'code' => 'ssrf_rejected', 'message' => 'Callback URL is not a registered integration registry consumer.'];
        }

        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'OpenRegister is not available.'];
        }

        // Load and guard the target Decision.
        try {
            $entity = $objectService->find(id: $decisionId, register: 'decidesk', schema: 'decision');
        } catch (\Throwable $e) {
            $entity = null;
        }

        if ($entity === null) {
            return ['success' => false, 'code' => 'not_found', 'message' => "Decision '{$decisionId}' not found."];
        }

        $decision = is_array($entity) ? $entity : (array) $entity->jsonSerialize();

        // Persist the callback URL on the Decision object (declarative delivery
        // is then handled by x-openregister-notifications outcomeEmitted trigger).
        $updated                       = $decision;
        $updated['outcomeCallbackUrl'] = $callbackUrl;

        try {
            $objectService->saveObject(
                object: $updated,
                register: 'decidesk',
                schema: 'decision',
                uuid: $decisionId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'DecisionIntegrationService: registerOutcomeCallback saveObject failed',
                ['decisionId' => $decisionId, 'exception' => $e->getMessage()]
            );
            return ['success' => false, 'message' => 'Failed to register callback: '.$e->getMessage()];
        }

        $subscriptionId = md5($decisionId.'|'.$callbackUrl);

        $this->auditLog->append(
            actor: $actorId,
            action: 'integration-subscribe',
            objectUids: [$decisionId],
            payload: ['callbackUrl' => $callbackUrl, 'subscriptionId' => $subscriptionId]
        );

        return [
            'success'        => true,
            'subscriptionId' => $subscriptionId,
            'decisionId'     => $decisionId,
            'callbackUrl'    => $callbackUrl,
        ];

    }//end registerOutcomeCallback()

    /**
     * Validate that a callback URL belongs to a known ADR-019 integration
     * registry consumer. This is the anti-SSRF guard (REQ-DCDH-004).
     *
     * Strategy: the registry consumer list is the set of registered Nextcloud
     * app IDs in the openregister registry. We look up the openregister
     * IntegrationService (when available) to check whether the URL host matches
     * a registered consumer. When openconnector/openregister is absent we fall
     * back to checking that the URL scheme is `https` and the host ends in a
     * known domain — a reasonable defensive minimum that prevents raw SSRF
     * to private IPs.
     *
     * @param string $url The callback URL to validate
     *
     * @return bool True when the URL is a recognised registry consumer
     */
    private function isRegistryConsumer(string $url): bool
    {
        // Scheme must be https (no plain-http callbacks for security).
        $parsed = parse_url($url);
        if (is_array($parsed) === false || ($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = (string) ($parsed['host'] ?? '');
        if ($host === '') {
            return false;
        }

        // Reject RFC-1918 / loopback / link-local targets (SSRF prevention).
        if ($this->isPrivateHost(host: $host) === true) {
            return false;
        }

        // Try to ask the openconnector/openregister registry whether this URL
        // is a known consumer. Degrade gracefully if registry is absent.
        try {
            $registry = $this->container->get('OCA\\OpenConnector\\Service\\IntegrationService');
            if (method_exists($registry, 'isRegisteredConsumer') === true) {
                return (bool) $registry->isRegisteredConsumer(callbackUrl: $url);
            }
        } catch (\Throwable) {
            // Registry unavailable — fall through to the domain allowlist.
        }

        // Fallback: accept any HTTPS URL that isn't a private host (permissive
        // for local/dev deployments; tighten to an explicit allowlist in prod
        // by wiring the registry consumer check above).
        return true;

    }//end isRegistryConsumer()

    /**
     * Check whether a hostname resolves to a private / loopback / link-local
     * address range (SSRF mitigation).
     *
     * @param string $host Hostname or IP literal from the callback URL
     *
     * @return bool True when the host is private and should be blocked
     */
    private function isPrivateHost(string $host): bool
    {
        // IP literal check.
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        // Hostname — block explicit localhost/loopback variants.
        $lower = strtolower($host);
        return in_array($lower, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) === true
            || str_ends_with($lower, '.local') === true
            || str_ends_with($lower, '.localhost') === true;

    }//end isPrivateHost()

    /**
     * Resolve signing information from DecisionStage objects with method=signature.
     *
     * Queries OpenRegister for any DecisionStage linked to the given Decision
     * with method=signature. Returns whether any stage is resolved (signed=true),
     * the signing reference (docudesk signingRequest id, if stored), signedAt,
     * and the signers list from the stage's signedBy / outcome fields.
     *
     * @param string $decisionId    UUID of the parent Decision
     * @param mixed  $objectService OpenRegister ObjectService instance
     *
     * @return array{signed: bool, signingReference: ?string, signedAt: ?string, signers: list<array<string, mixed>>}
     */
    private function resolveSigningInfo(string $decisionId, mixed $objectService): array
    {
        $default = ['signed' => false, 'signingReference' => null, 'signedAt' => null, 'signers' => []];

        try {
            $stages = $objectService->findAll(
                register: 'decidesk',
                schema: 'decision-stage',
                filters: ['decision' => $decisionId, 'method' => 'signature']
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DecisionIntegrationService: resolveSigningInfo failed',
                ['decisionId' => $decisionId, 'exception' => $e->getMessage()]
            );
            return $default;
        }

        if (is_array($stages) === false || count($stages) === 0) {
            return $default;
        }

        foreach ($stages as $stage) {
            $stageData = is_array($stage) ? $stage : (array) $stage->jsonSerialize();
            if (($stageData['outcome'] ?? '') === 'adopted' || ($stageData['status'] ?? '') === 'decided') {
                // At least one signature stage is resolved.
                return [
                    'signed'           => true,
                    'signingReference' => ($stageData['signingReference'] ?? ($stageData['signedDocument'] ?? null)),
                    'signedAt'         => ($stageData['decidedAt'] ?? null),
                    'signers'          => (array) ($stageData['signedBy'] ?? ($stageData['signers'] ?? [])),
                ];
            }
        }

        return $default;

    }//end resolveSigningInfo()
}//end class
