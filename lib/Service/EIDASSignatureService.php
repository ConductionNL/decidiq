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
     * @param array<string> $signatories Ordered list of board-member UUIDs
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

        return [
            'success'    => true,
            'requestId'  => ($requestId !== '' ? $requestId : null),
            'signingUrl' => ($signingUrl !== '' ? $signingUrl : null),
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

        return [
            'valid'                 => $valid,
            'certificateThumbprint' => ($thumbprint !== '' ? $thumbprint : null),
            'timestamp'             => $timestamp,
            'message'               => ($valid === true ? 'Signature verified.' : 'Signature rejected.'),
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

        // Persist the archive reference + hash + signed payload on the BoardMinutes row.
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

        return [
            'success'             => true,
            'pdfArchiveReference' => ($archiveReference !== '' ? $archiveReference : null),
            'hashSha256'          => ($hash !== '' ? $hash : null),
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

        return [
            'valid'          => $valid,
            'issuer'         => ($issuer !== '' ? $issuer : null),
            'trustListLevel' => ($level !== '' ? $level : null),
            'message'        => ($valid === true ? 'Certificate chain valid.' : 'Certificate not on EU Trusted List.'),
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

        $decoded = ($body !== '' ? json_decode($body, true) : null);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;

    }//end invokeOpenconnector()

    /**
     * Persist a partial update on a BoardMinutes row. Wrapped in a try/catch so
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
                schema: 'board-minutes'
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
                schema: 'board-minutes',
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
