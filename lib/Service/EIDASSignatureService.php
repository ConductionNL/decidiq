<?php
/**
 * Decidesk eIDAS Signature Service
 *
 * Delegates QES (Qualified Electronic Signature) work to the openconnector
 * `e-sign` Source. Openconnector configures the QSP credentials, signing
 * profile and EU Trusted List access; this service merely composes calls
 * via openconnector's CallService.
 *
 * The service is constructed via the DI container with a lazy openconnector
 * lookup. If openconnector is absent or the e-sign Source is not configured,
 * the Application wires the dormant {@see LogEIDASSignatureService} fallback
 * instead — the controller / guard never see a hard 500.
 *
 * Retargeted onto the unified `minutes` / `decision` entities (ADR-006).
 * C5 (decision-methods) wires the "signature" decision method: when eIDAS
 * signing completes via {@see self::finalizeMinutes()}, the service locates
 * the related DecisionStage of method=signature and resolves it (sets
 * outcome=adopted + decidedAt + links the signedDocument). See
 * {@see self::resolveSignatureStage()} for the stage-resolution seam (C5 D5).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Concrete eIDAS QES service that delegates to openconnector's e-sign source.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 * @spec openspec/changes/decision-methods/tasks.md#4-eidas-signature-method-wiring-code
 */
class EIDASSignatureService implements IEIDASSignatureService
{

    /**
     * The openconnector Source slug that addresses the configured QSP.
     *
     * @var string
     */
    public const ESIGN_SOURCE_SLUG = 'eidas-qes';

    /**
     * Docudesk integration slug for the signing registry source (contract #2).
     * When a source with this slug is registered in the integration registry,
     * the docudesk e-signature path takes precedence over openconnector (REQ-DCDH-005).
     *
     * @var string
     */
    public const DOCUDESK_SOURCE_SLUG = 'docudesk-signing';

    /**
     * Construct the eIDAS service.
     *
     * @param ContainerInterface $container       DI container (lazy openconnector lookup)
     * @param LoggerInterface    $logger          Logger
     * @param AuditLogService    $auditLogService Audit log dependency
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @param string        $minutesId   UUID of the BoardMinutes record
     * @param array<string> $signatories Ordered list of member (Person) UUIDs
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{success: bool, requestId: ?string, signingUrl: ?string, message: string}
     */
    public function initializeSigningRequest(string $minutesId, array $signatories): array
    {
        if ($minutesId === '' || $signatories === []) {
            return [
                'success'    => false,
                'requestId'  => null,
                'signingUrl' => null,
                'message'    => 'minutesId and at least one signatory are required.',
            ];
        }

        // Contract #2: prefer docudesk for document e-signature when available (REQ-DCDH-005).
        $docudeskResult = $this->composeDocudeskSigningRequest(
            minutesId: $minutesId,
            signatories: $signatories
        );
        if ($docudeskResult['success'] === true) {
            return $docudeskResult;
        }

        // Fallback to openconnector e-sign Source (REQ-DCDH-005).
        $payload = [
            'minutesId'    => $minutesId,
            'signatories'  => array_values(array_map('strval', $signatories)),
            'profile'      => 'eIDAS-QES',
            'returnTarget' => 'decidesk/board-portal/minutes/'.$minutesId,
        ];

        try {
            $response = $this->invokeOpenconnector(
                action: 'initiate',
                payload: $payload
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: eIDAS initiate failed',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
            return [
                'success'    => false,
                'requestId'  => null,
                'signingUrl' => null,
                'message'    => 'Failed to initialize signing request: '.$e->getMessage(),
            ];
        }

        $requestId  = (string) ($response['requestId'] ?? '');
        $signingUrl = (string) ($response['signingUrl'] ?? '');

        $this->auditLogService->append(
            actor: 'system',
            action: 'signature',
            objectUids: [$minutesId, $requestId],
            payload: ['phase' => 'initiate', 'signatories' => array_values($signatories)]
        );

        $requestIdOut = null;
        if ($requestId !== '') {
            $requestIdOut = $requestId;
        }

        $signingUrlOut = null;
        if ($signingUrl !== '') {
            $signingUrlOut = $signingUrl;
        }

        return [
            'success'    => true,
            'requestId'  => $requestIdOut,
            'signingUrl' => $signingUrlOut,
            'message'    => 'Signing request initiated.',
        ];

    }//end initializeSigningRequest()

    /**
     * {@inheritDoc}
     *
     * @param string $requestId UUID of the signing request
     * @param string $signature Base-64 encoded signature blob
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{valid: bool, certificateThumbprint: ?string, timestamp: ?string, message: string}
     */
    public function verifySignature(string $requestId, string $signature): array
    {
        if ($requestId === '' || $signature === '') {
            return [
                'valid'                 => false,
                'certificateThumbprint' => null,
                'timestamp'             => null,
                'message'               => 'requestId and signature are required.',
            ];
        }

        try {
            $response = $this->invokeOpenconnector(
                action: 'verify',
                payload: [
                    'requestId' => $requestId,
                    'signature' => $signature,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: eIDAS verifySignature failed',
                ['requestId' => $requestId, 'exception' => $e->getMessage()]
            );
            return [
                'valid'                 => false,
                'certificateThumbprint' => null,
                'timestamp'             => null,
                'message'               => 'Failed to verify signature: '.$e->getMessage(),
            ];
        }

        $valid      = (bool) ($response['valid'] ?? false);
        $thumbprint = (string) ($response['certificateThumbprint'] ?? '');
        $timestamp  = (string) ($response['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z'));

        $thumbprintOut = null;
        if ($thumbprint !== '') {
            $thumbprintOut = $thumbprint;
        }

        $messageOut = 'Signature rejected.';
        if ($valid === true) {
            $messageOut = 'Signature verified.';
        }

        return [
            'valid'                 => $valid,
            'certificateThumbprint' => $thumbprintOut,
            'timestamp'             => $timestamp,
            'message'               => $messageOut,
        ];

    }//end verifySignature()

    /**
     * {@inheritDoc}
     *
     * @param string                    $minutesId     UUID of the BoardMinutes record
     * @param array<int, array<string>> $signatureList List of {signer, signature, timestamp} tuples
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{success: bool, pdfArchiveReference: ?string, hashSha256: ?string, message: string}
     */
    public function finalizeMinutes(string $minutesId, array $signatureList): array
    {
        if ($minutesId === '' || $signatureList === []) {
            return [
                'success'             => false,
                'pdfArchiveReference' => null,
                'hashSha256'          => null,
                'message'             => 'minutesId and at least one signature are required.',
            ];
        }

        try {
            $response = $this->invokeOpenconnector(
                action: 'finalize',
                payload: [
                    'minutesId'  => $minutesId,
                    'signatures' => array_values($signatureList),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: eIDAS finalize failed',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
            return [
                'success'             => false,
                'pdfArchiveReference' => null,
                'hashSha256'          => null,
                'message'             => 'Failed to finalize minutes: '.$e->getMessage(),
            ];
        }

        $archiveReference = (string) ($response['pdfArchiveReference'] ?? '');
        $hash = (string) ($response['hashSha256'] ?? '');

        // Persist the archive reference + hash + signed payload on the Minutes row.
        $this->updateMinutesRow(
            minutesId: $minutesId,
            patch: [
                'pdfArchiveReference'   => $archiveReference,
                'hashSha256'            => $hash,
                'signingCompletionDate' => gmdate('Y-m-d'),
                'eidasSignatureLevel'   => 'QES',
                'version'               => 'signed',
                'signedBy'              => array_values($signatureList),
            ]
        );

        // C5 (decision-methods D5): resolve the method=signature DecisionStage
        // that is linked to these minutes, if one exists.
        $this->resolveSignatureStage(minutesId: $minutesId);

        $this->auditLogService->append(
            actor: 'system',
            action: 'signature',
            objectUids: [$minutesId],
            payload: [
                'phase'               => 'finalize',
                'pdfArchiveReference' => $archiveReference,
                'hashSha256'          => $hash,
                'signatures'          => count($signatureList),
            ]
        );

        $archiveReferenceOut = null;
        if ($archiveReference !== '') {
            $archiveReferenceOut = $archiveReference;
        }

        $hashOut = null;
        if ($hash !== '') {
            $hashOut = $hash;
        }

        return [
            'success'             => true,
            'pdfArchiveReference' => $archiveReferenceOut,
            'hashSha256'          => $hashOut,
            'message'             => 'Minutes finalized.',
        ];

    }//end finalizeMinutes()

    /**
     * {@inheritDoc}
     *
     * @param string $certificateThumbprint SHA-256 thumbprint of the cert
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{valid: bool, issuer: ?string, trustListLevel: ?string, message: string}
     */
    public function validateCertificateChain(string $certificateThumbprint): array
    {
        if ($certificateThumbprint === '') {
            return [
                'valid'          => false,
                'issuer'         => null,
                'trustListLevel' => null,
                'message'        => 'certificateThumbprint is required.',
            ];
        }

        try {
            $response = $this->invokeOpenconnector(
                action: 'validate-cert',
                payload: ['certificateThumbprint' => $certificateThumbprint]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: eIDAS validateCertificateChain failed',
                ['certificateThumbprint' => $certificateThumbprint, 'exception' => $e->getMessage()]
            );
            return [
                'valid'          => false,
                'issuer'         => null,
                'trustListLevel' => null,
                'message'        => 'Failed to validate certificate: '.$e->getMessage(),
            ];
        }

        $valid  = (bool) ($response['valid'] ?? false);
        $issuer = (string) ($response['issuer'] ?? '');
        $level  = (string) ($response['trustListLevel'] ?? '');

        $issuerOut = null;
        if ($issuer !== '') {
            $issuerOut = $issuer;
        }

        $levelOut = null;
        if ($level !== '') {
            $levelOut = $level;
        }

        $validateMessage = 'Certificate not on EU Trusted List.';
        if ($valid === true) {
            $validateMessage = 'Certificate chain valid.';
        }

        return [
            'valid'          => $valid,
            'issuer'         => $issuerOut,
            'trustListLevel' => $levelOut,
            'message'        => $validateMessage,
        ];

    }//end validateCertificateChain()

    /**
     * Invoke the openconnector e-sign source via the CallService. The
     * openconnector Source slug is fixed (see ::ESIGN_SOURCE_SLUG); the action
     * is sent as a relative path the Source's mapper resolves into a concrete
     * API call.
     *
     * @param string               $action  One of initiate|verify|finalize|validate-cert
     * @param array<string, mixed> $payload Action-specific payload
     *
     * @return array<string, mixed>
     */
    private function invokeOpenconnector(string $action, array $payload): array
    {
        // Resolve openconnector's CallService lazily. If the app is absent
        // or the binding is missing, throw — the DI factory uses the
        // LogEIDASSignatureService fallback when openconnector is unwired.
        $callService  = $this->container->get('OCA\OpenConnector\Service\CallService');
        $sourceMapper = $this->container->get('OCA\OpenConnector\Db\SourceMapper');

        $source = $sourceMapper->findBySlug(slug: self::ESIGN_SOURCE_SLUG);
        if ($source === null) {
            throw new \RuntimeException("Openconnector source '".self::ESIGN_SOURCE_SLUG."' is not configured.");
        }

        $response = $callService->call(
            source: $source,
            endpoint: '/'.$action,
            method: 'POST',
            config: [
                'body'    => json_encode($payload),
                'headers' => ['Content-Type' => 'application/json'],
            ]
        );

        $body = '';
        if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
            $raw  = $response->getResponse();
            $body = (string) ($raw['body'] ?? '');
        }

        $decoded = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
        }

        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;

    }//end invokeOpenconnector()

    /**
     * Resolve the DecisionStage of method=signature that is linked (via the
     * Minutes → Meeting → Decision chain or directly via signedDocument) to the
     * finalised minutes. When found, links the DigitalDocument (signedDocument),
     * sets outcome=adopted, and stamps decidedAt (C5 D5 / design decision-methods
     * #4).
     *
     * The lookup strategy: search for a DecisionStage whose signedDocument UUID
     * matches the minutesId being finalised (treating the Minutes record itself as
     * the DigitalDocument proxy here, since signedDocument is the sealed artefact).
     * If openconnector is absent or the stage is not found, the method degrades
     * silently (warning log) — the signing artefact is still persisted.
     *
     * @param string      $minutesId        UUID of the finalised Minutes record
     * @param string|null $signingReference Optional signing reference (docudesk signingRequest id) to store on the stage
     *
     * @spec openspec/changes/decision-methods/tasks.md#4-eidas-signature-method-wiring-code
     * @spec openspec/changes/decidesk-contract-decision-hub/tasks.md#phase-3
     *
     * @return void
     */
    public function resolveSignatureStage(string $minutesId, ?string $signingReference = null): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Find any DecisionStage with method=signature whose signedDocument
            // points at this minutes record. Seeds and UI will wire the correct
            // DigitalDocument UUID; the service resolves the stage it finds.
            $results = $objectService->findAll(
                register: 'decidesk',
                schema: 'decision-stage',
                filters: [
                    'method'         => 'signature',
                    'signedDocument' => $minutesId,
                ]
            );

            if (empty($results) === true) {
                return;
            }

            $decidedAt = gmdate('Y-m-d\TH:i:s\Z');

            foreach ($results as $stage) {
                $current = [];
                if (method_exists($stage, 'getObject') === true) {
                    $current = $stage->getObject();
                } else {
                    $current = (array) $stage->jsonSerialize();
                }

                $stageId = (string) ($current['id'] ?? ($current['uuid'] ?? ''));
                if ($stageId === '') {
                    continue;
                }

                $patch = [
                    'outcome'   => 'adopted',
                    'decidedAt' => $decidedAt,
                    'status'    => 'decided',
                ];
                if ($signingReference !== null) {
                    $patch['signingReference'] = $signingReference;
                }

                $objectService->saveObject(
                    object: array_merge($current, $patch),
                    register: 'decidesk',
                    schema: 'decision-stage',
                    uuid: $stageId
                );

                $this->auditLogService->append(
                    actor: 'system',
                    action: 'signature',
                    objectUids: [$minutesId, $stageId],
                    payload: [
                        'phase'     => 'resolve-signature-stage',
                        'stageId'   => $stageId,
                        'decidedAt' => $decidedAt,
                    ]
                );
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: failed to resolve method=signature DecisionStage after finalizeMinutes',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
        }//end try

    }//end resolveSignatureStage()

    /**
     * Compose a docudesk signingRequest via the ADR-019 integration registry
     * (cross-app contract #2 / REQ-DCDH-005). Returns the same shape as
     * initializeSigningRequest. Returns success=false silently when docudesk
     * is absent (allows the openconnector fallback to proceed).
     *
     * The method is fail-closed: when docudesk is registered but returns an
     * error, we propagate the error and do NOT fall through to openconnector —
     * the document must not be silently "signed" by a lower-trust path.
     *
     * @param string        $minutesId   UUID of the Minutes / document record
     * @param array<string> $signatories Ordered list of Person UUIDs
     *
     * @return array{success: bool, requestId: ?string, signingUrl: ?string, message: string}
     */
    private function composeDocudeskSigningRequest(string $minutesId, array $signatories): array
    {
        try {
            $sourceMapper = $this->container->get('OCA\OpenConnector\Db\SourceMapper');
            $source       = $sourceMapper->findBySlug(slug: self::DOCUDESK_SOURCE_SLUG);
        } catch (\Throwable) {
            // openconnector absent or source not configured — docudesk unavailable.
            return ['success' => false, 'requestId' => null, 'signingUrl' => null, 'message' => 'Docudesk source not configured.'];
        }

        if ($source === null) {
            // Docudesk not registered — fall through to openconnector silently.
            return ['success' => false, 'requestId' => null, 'signingUrl' => null, 'message' => 'Docudesk source not registered.'];
        }

        // Docudesk IS registered — compose the signingRequest (fail-closed from here).
        $payload = [
            'documentId'   => $minutesId,
            'signatories'  => array_values(array_map('strval', $signatories)),
            'signingLevel' => 'QES',
            'returnTarget' => 'decidesk/minutes/'.$minutesId,
        ];

        try {
            $callService = $this->container->get('OCA\OpenConnector\Service\CallService');
            $response    = $callService->call(
                source: $source,
                endpoint: '/signing-requests',
                method: 'POST',
                config: [
                    'body'    => json_encode($payload),
                    'headers' => ['Content-Type' => 'application/json'],
                ]
            );

            $body = '';
            if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
                $raw  = $response->getResponse();
                $body = (string) ($raw['body'] ?? '');
            }

            $decoded = null;
            if ($body !== '') {
                $decoded = json_decode($body, true);
            }

            if (is_array($decoded) === false) {
                throw new \RuntimeException('Docudesk returned non-JSON response.');
            }

            $requestId  = (string) ($decoded['id'] ?? ($decoded['signingRequestId'] ?? ''));
            $signingUrl = (string) ($decoded['signingUrl'] ?? '');

            $this->auditLogService->append(
                actor: 'system',
                action: 'signature',
                objectUids: [$minutesId, $requestId],
                payload: ['phase' => 'docudesk-initiate', 'signatories' => array_values($signatories)]
            );

            return [
                'success'    => true,
                'requestId'  => $requestId !== '' ? $requestId : null,
                'signingUrl' => $signingUrl !== '' ? $signingUrl : null,
                'message'    => 'Signing request composed via docudesk.',
            ];
        } catch (\Throwable $e) {
            // Docudesk was registered but failed — fail CLOSED (REQ-DCDH-005).
            $this->logger->error(
                'Decidesk: docudesk signingRequest composition failed (fail-closed)',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
            return [
                'success'    => false,
                'requestId'  => null,
                'signingUrl' => null,
                'message'    => 'Docudesk signing failed (fail-closed): '.$e->getMessage(),
            ];
        }//end try

    }//end composeDocudeskSigningRequest()

    /**
     * Persist a partial update on a Minutes row. Wrapped in a try/catch so
     * a failed write degrades the response to a warning without leaking a 500.
     *
     * @param string               $minutesId Minutes UUID
     * @param array<string, mixed> $patch     Fields to merge
     *
     * @return void
     */
    private function updateMinutesRow(string $minutesId, array $patch): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(
                id: $minutesId,
                register: 'decidesk',
                schema: 'minutes'
            );
            if ($entity === null) {
                return;
            }

            $current = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $current = $entity->getObject();
            }

            $objectService->saveObject(
                object: array_merge($current, $patch),
                register: 'decidesk',
                schema: 'minutes',
                uuid: $minutesId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: failed to persist signed Minutes row',
                ['minutesId' => $minutesId, 'exception' => $e->getMessage()]
            );
        }//end try

    }//end updateMinutesRow()
}//end class
