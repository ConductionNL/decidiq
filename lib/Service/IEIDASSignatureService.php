<?php
/**
 * Decidesk eIDAS Signature Service interface
 *
 * Contract for eIDAS Qualified Electronic Signature (QES) integrations used by
 * the board portal. Two implementations are provided:
 *
 *  - {@see EIDASSignatureService} — delegates to the openconnector e-sign source
 *    when a board signing flow is configured.
 *  - {@see LogEIDASSignatureService} — a dormant fallback that records signing
 *    intents in the audit log without invoking an external trust service. This
 *    keeps the surface usable on deployments that have not (yet) configured an
 *    eIDAS QSP, so the controller never fans out unconfigured 500s.
 *
 * Both implementations are wired into the DI container via
 * {@see \OCA\Decidesk\AppInfo\Application::registerEidasSignatureBindings()}.
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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

/**
 * Contract for the eIDAS QES integration used by the board portal.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
interface IEIDASSignatureService
{
    /**
     * Request initialization for a QES flow against the configured QSP.
     *
     * @param string        $minutesId   UUID of the Minutes record
     * @param array<string> $signatories Ordered list of member (Person) UUIDs
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{success: bool, requestId: ?string, signingUrl: ?string, message: string}
     */
    public function initializeSigningRequest(string $minutesId, array $signatories): array;

    /**
     * Verify a signature against the EU Trusted List.
     *
     * @param string $requestId UUID of the signing request
     * @param string $signature Base-64 encoded signature blob
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{valid: bool, certificateThumbprint: ?string, timestamp: ?string, message: string}
     */
    public function verifySignature(string $requestId, string $signature): array;

    /**
     * Finalize signed minutes: produce the archive reference, hash the body,
     * update the Minutes row and append an audit log entry.
     *
     * @param string                    $minutesId     UUID of the BoardMinutes record
     * @param array<int, array<string>> $signatureList List of {signer, signature, timestamp} tuples
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{success: bool, pdfArchiveReference: ?string, hashSha256: ?string, message: string}
     */
    public function finalizeMinutes(string $minutesId, array $signatureList): array;

    /**
     * Verify the certificate chain against the EU eIDAS Trusted List.
     *
     * @param string $certThumbprint SHA-256 thumbprint of the cert
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{valid: bool, issuer: ?string, trustListLevel: ?string, message: string}
     */
    public function validateCertificateChain(string $certThumbprint): array;
}//end interface
