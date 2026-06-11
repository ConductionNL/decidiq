<?php
/**
 * QES Guard
 *
 * Refuses to advance a Resolution to `adopted` unless every required
 * signatory has produced a verified Qualified Electronic Signature (QES).
 * The guard composes {@see IEIDASSignatureService::validateCertificateChain}
 * with the persisted signedBy list on the linked BoardMinutes row.
 *
 * @category Lifecycle
 * @package  OCA\Decidesk\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Lifecycle;

use OCA\Decidesk\Service\IEIDASSignatureService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guard QES presence + validity before Resolution.conclude promotes the
 * resolution to `adopted`.
 *
 * Verification semantics:
 *  - The guard reads the linked BoardMinutes row for the resolution's
 *    parent meeting.
 *  - The `signedBy` array on the row is iterated; every signer's
 *    certificate-thumbprint is validated via {@see IEIDASSignatureService::validateCertificateChain}.
 *  - If any required signer is missing or has an invalid cert chain, the
 *    guard refuses with a structured `{allowed:false, reason:string}` payload.
 *
 * Soft-block semantics: if the configured adapter is the dormant
 * LogEIDASSignatureService, every chain validation returns `valid:false` with
 * the well-known "eIDAS QES integration is not configured." reason. Callers
 * (ResolutionService::conclude) treat the soft-block as an
 * `HTTP 422 Unprocessable` rather than a 500.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
class QesGuard
{
    /**
     * Construct the guard.
     *
     * @param ContainerInterface     $container        DI container (lazy ObjectService lookup)
     * @param LoggerInterface        $logger           Logger
     * @param IEIDASSignatureService $signatureService eIDAS adapter
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IEIDASSignatureService $signatureService,
    ) {
    }//end __construct()

    /**
     * Decide whether the resolution may transition to `adopted` based on
     * eIDAS QES presence + validity. Returns a structured tuple so the caller
     * can map success/failure to HTTP status without throwing.
     *
     * @param string             $resolutionId    UUID of the resolution
     * @param array<int, string> $requiredSigners List of required board-member UUIDs
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     *
     * @return array{allowed: bool, reason: string, missing: array<int,string>, invalid: array<int,string>}
     */
    public function canConclude(string $resolutionId, array $requiredSigners): array
    {
        if ($resolutionId === '') {
            return [
                'allowed' => false,
                'reason'  => 'resolutionId is required.',
                'missing' => [],
                'invalid' => [],
            ];
        }

        try {
            $signed = $this->loadSignedBy(resolutionId: $resolutionId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: QesGuard could not load signed-by list',
                ['resolutionId' => $resolutionId, 'exception' => $e->getMessage()]
            );
            return [
                'allowed' => false,
                'reason'  => 'Failed to inspect minutes for QES signatures.',
                'missing' => $requiredSigners,
                'invalid' => [],
            ];
        }

        $missing = [];
        $invalid = [];
        $present = [];
        foreach ($signed as $entry) {
            $signer     = (string) ($entry['signerUuid'] ?? $entry['signer'] ?? '');
            $thumbprint = (string) ($entry['certificateThumbprint'] ?? $entry['thumbprint'] ?? '');
            if ($signer === '' || $thumbprint === '') {
                continue;
            }

            $check = $this->signatureService->validateCertificateChain($thumbprint);
            if (($check['valid'] ?? false) !== true) {
                $invalid[] = $signer;
                continue;
            }

            $present[$signer] = true;
        }

        foreach ($requiredSigners as $required) {
            if (isset($present[$required]) === false) {
                $missing[] = $required;
            }
        }

        if ($missing !== [] || $invalid !== []) {
            return [
                'allowed' => false,
                'reason'  => $this->buildReason(missing: $missing, invalid: $invalid),
                'missing' => $missing,
                'invalid' => $invalid,
            ];
        }

        return [
            'allowed' => true,
            'reason'  => 'QES present and verified.',
            'missing' => [],
            'invalid' => [],
        ];

    }//end canConclude()

    /**
     * Load the `signedBy` array from the BoardMinutes row linked to the
     * Resolution's parent meeting.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadSignedBy(string $resolutionId): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $resolution = $objectService->find(
            id: $resolutionId,
            register: 'decidesk',
            schema: 'resolution'
        );
        if ($resolution === null) {
            return [];
        }

        $resolutionRow = (array) $resolution->jsonSerialize();
        if (method_exists($resolution, 'getObject') === true) {
            $resolutionRow = $resolution->getObject();
        }

        $meetingId = (string) ($resolutionRow['meetingKoppeling'] ?? '');
        if ($meetingId === '') {
            return [];
        }

        $minutesRows = $objectService->findAll(
            [
                'register' => 'decidesk',
                'schema'   => 'board-minutes',
                'filters'  => ['meetingKoppeling' => $meetingId],
                'limit'    => 50,
            ]
        );

        foreach ((array) $minutesRows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false || ($row['meetingKoppeling'] ?? null) !== $meetingId) {
                continue;
            }

            if (($row['version'] ?? '') === 'signed') {
                return array_values((array) ($row['signedBy'] ?? []));
            }
        }

        return [];

    }//end loadSignedBy()

    /**
     * Build the soft-block reason string for the response tuple.
     *
     * @param array<int, string> $missing List of board-member UUIDs without a QES
     * @param array<int, string> $invalid List of board-member UUIDs whose chain failed
     *
     * @return string
     */
    private function buildReason(array $missing, array $invalid): string
    {
        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing QES signatures: '.implode(', ', $missing);
        }

        if ($invalid !== []) {
            $parts[] = 'invalid QES certificate chain: '.implode(', ', $invalid);
        }

        return 'Resolution cannot be adopted — '.implode('; ', $parts).'.';

    }//end buildReason()
}//end class
