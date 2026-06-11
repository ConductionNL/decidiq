<?php
/**
 * Decidesk dormant eIDAS Signature Service fallback
 *
 * A no-external-call fallback that satisfies {@see IEIDASSignatureService}
 * for deployments that have not (yet) configured openconnector with an
 * eIDAS-QSP Source. Every method emits an audit log entry (so signing
 * intents stay forensically traceable) and returns a structured `success: false`
 * payload with the message `eIDAS QES integration is not configured.`
 *
 * The application layer wires this implementation when:
 *  - the openconnector app is absent, OR
 *  - the openconnector Source with slug `eidas-qes` does not resolve at boot.
 *
 * Controllers and the {@see \OCA\Decidesk\Lifecycle\QesGuard} treat the
 * `success: false` shape as a soft-block rather than a hard 500, so the
 * resolution lifecycle remains usable without a QES adapter in place.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Log\LoggerInterface;

/**
 * Dormant fallback for {@see IEIDASSignatureService}.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
class LogEIDASSignatureService implements IEIDASSignatureService
{

    /**
     * Constant message used in every fallback response.
     *
     * @var string
     */
    public const UNCONFIGURED_MESSAGE = 'eIDAS QES integration is not configured.';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger          Logger
     * @param AuditLogService $auditLogService Audit log dependency
     */
    public function __construct(
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
        $this->logger->warning(
            'Decidesk: dormant eIDAS adapter received initializeSigningRequest',
            ['minutesId' => $minutesId, 'signatories' => $signatories]
        );

        $this->auditLogService->append(
            actor: 'system',
            action: 'signature',
            objectUids: [$minutesId],
            payload: [
                'phase'       => 'initiate',
                'adapter'     => 'dormant',
                'signatories' => array_values(array_map('strval', $signatories)),
            ]
        );

        return [
            'success'    => false,
            'requestId'  => null,
            'signingUrl' => null,
            'message'    => self::UNCONFIGURED_MESSAGE,
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
        $this->logger->warning(
            'Decidesk: dormant eIDAS adapter received verifySignature',
            ['requestId' => $requestId]
        );

        return [
            'valid'                 => false,
            'certificateThumbprint' => null,
            'timestamp'             => null,
            'message'               => self::UNCONFIGURED_MESSAGE,
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
        $this->logger->warning(
            'Decidesk: dormant eIDAS adapter received finalizeMinutes',
            ['minutesId' => $minutesId, 'signatureCount' => count($signatureList)]
        );

        $this->auditLogService->append(
            actor: 'system',
            action: 'signature',
            objectUids: [$minutesId],
            payload: [
                'phase'      => 'finalize',
                'adapter'    => 'dormant',
                'signatures' => count($signatureList),
            ]
        );

        return [
            'success'             => false,
            'pdfArchiveReference' => null,
            'hashSha256'          => null,
            'message'             => self::UNCONFIGURED_MESSAGE,
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
        $this->logger->warning(
            'Decidesk: dormant eIDAS adapter received validateCertificateChain',
            ['certificateThumbprint' => $certificateThumbprint]
        );

        return [
            'valid'          => false,
            'issuer'         => null,
            'trustListLevel' => null,
            'message'        => self::UNCONFIGURED_MESSAGE,
        ];

    }//end validateCertificateChain()
}//end class
