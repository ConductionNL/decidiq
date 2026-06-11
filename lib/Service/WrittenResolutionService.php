<?php
/**
 * Decidesk Written Resolution Service
 *
 * Phase 5 asynchronous Resolution lifecycle: written resolutions are
 * adopted by collecting QES signatures from every required signatory
 * (unanimity by default) outside a board meeting. Status transitions:
 *
 *   proposed → under-signature → adopted | tabled | rejected
 *
 * The unanimity check is satisfied when every required signatory's QES
 * signature is valid; verification is delegated to
 * {@see IEIDASSignatureService} so the dormant adapter automatically blocks
 * adoption when no QSP is configured.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Written-resolution lifecycle service.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
 */
class WrittenResolutionService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface     $container        DI container
     * @param LoggerInterface        $logger           Logger
     * @param IEIDASSignatureService $signatureService eIDAS adapter
     * @param AuditLogService        $auditLogService  Audit log dependency
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IEIDASSignatureService $signatureService,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Initiate a written resolution. Creates the Resolution row with
     * `type=written-resolution`, status=`under-signature`, and asks the
     * eIDAS adapter to issue a signing request for the provided signatories.
     *
     * @param array<string, mixed> $resolutionData   Fields for the Resolution row (title required)
     * @param array<int, string>   $requiredSigners  Board-member UUIDs whose signature is required
     * @param string               $responseDeadline ISO-8601 deadline string
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     *
     * @return array{success: bool, resolution: array|null, signingRequestId: ?string, message: string}
     */
    public function initiate(array $resolutionData, array $requiredSigners, string $responseDeadline): array
    {
        $title = trim((string) ($resolutionData['title'] ?? ''));
        if ($title === '') {
            return [
                'success'          => false,
                'resolution'       => null,
                'signingRequestId' => null,
                'message'          => 'Resolution title is required.',
            ];
        }

        if ($requiredSigners === []) {
            return [
                'success'          => false,
                'resolution'       => null,
                'signingRequestId' => null,
                'message'          => 'At least one required signer is required.',
            ];
        }

        $row = array_merge(
            $resolutionData,
            [
                'type'             => 'written-resolution',
                'status'           => 'proposed',
                'voteType'         => 'unanimous-consent',
                'voteThreshold'    => 'unanimous',
                'requiredSigners'  => array_values(array_map('strval', $requiredSigners)),
                'responseDeadline' => $responseDeadline,
                'initiatedAt'      => gmdate('Y-m-d\TH:i:s\Z'),
            ]
        );

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $row,
                register: 'decidesk',
                schema: 'resolution'
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: WrittenResolutionService::initiate failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'          => false,
                'resolution'       => null,
                'signingRequestId' => null,
                'message'          => 'Failed to initiate written resolution.',
            ];
        }

        $payload      = $row;
        $resolutionId = '';
        if (is_object($saved) === true) {
            $payload      = (array) $saved->jsonSerialize();
            $resolutionId = (string) ($payload['id'] ?? $payload['uuid'] ?? '');
        }

        $signingResult    = $this->signatureService->initializeSigningRequest(
            $resolutionId,
            $requiredSigners
        );
        $signingRequestId = ($signingResult['requestId'] ?? null);

        // Transition to under-signature regardless of the signing-result; the
        // dormant adapter still returns success:false but the resolution
        // status accurately reflects "we asked".
        try {
            $payload['status']           = 'under-signature';
            $payload['signingRequestId'] = $signingRequestId;
            $objectService->saveObject(
                object: $payload,
                register: 'decidesk',
                schema: 'resolution',
                uuid: $resolutionId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: WrittenResolutionService::initiate failed to record signing request id',
                ['exception' => $e->getMessage()]
            );
        }

        $this->auditLogService->append(
            actor: 'system',
            action: 'signature',
            objectUids: [$resolutionId],
            payload: [
                'phase'           => 'initiate-written-resolution',
                'requiredSigners' => array_values($requiredSigners),
                'deadline'        => $responseDeadline,
            ]
        );

        return [
            'success'          => true,
            'resolution'       => $payload,
            'signingRequestId' => $signingRequestId,
            'message'          => 'Written resolution under signature.',
        ];

    }//end initiate()

    /**
     * Record a collected signature against the written resolution. The vote
     * is created as a BoardVote row with vote=in-favor, voteMethod=written-ballot.
     *
     * @param string $resolutionId  UUID of the resolution
     * @param string $signerUuid    UUID of the signing board member
     * @param string $signatureBlob Base-64 encoded signature
     * @param string $requestId     ID of the signing request (for verification)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     *
     * @return array{success: bool, vote: array|null, message: string}
     */
    public function collectSignature(string $resolutionId, string $signerUuid, string $signatureBlob, string $requestId): array
    {
        $verification = $this->signatureService->verifySignature($requestId, $signatureBlob);
        if (($verification['valid'] ?? false) !== true) {
            return [
                'success' => false,
                'vote'    => null,
                'message' => (string) ($verification['message'] ?? 'Signature rejected.'),
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $row           = [
                'resolutionKoppeling'  => $resolutionId,
                'boardMemberKoppeling' => $signerUuid,
                'vote'                 => 'in-favor',
                'voteTimestamp'        => (string) ($verification['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                'voteMethod'           => 'written-ballot',
                'anonymized'           => false,
            ];
            $saved         = $objectService->saveObject(
                object: $row,
                register: 'decidesk',
                schema: 'board-vote'
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: WrittenResolutionService::collectSignature failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'vote'    => null,
                'message' => 'Failed to record signature.',
            ];
        }//end try

        $payload = $row;
        if (is_object($saved) === true) {
            $payload = (array) $saved->jsonSerialize();
        }

        $this->auditLogService->append(
            actor: $signerUuid,
            action: 'signature',
            objectUids: [$resolutionId, (string) ($payload['id'] ?? '')],
            payload: ['phase' => 'collect-signature', 'requestId' => $requestId]
        );

        return [
            'success' => true,
            'vote'    => $payload,
            'message' => 'Signature recorded.',
        ];

    }//end collectSignature()

    /**
     * Finalize a written resolution. Counts unique in-favor votes against
     * the requiredSigners list; if all are present, transitions the
     * resolution to `adopted` and stamps the adoption date.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     *
     * @return array{success: bool, resolution: array|null, signaturesCollected: int, message: string}
     */
    public function finalize(string $resolutionId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(
                id: $resolutionId,
                register: 'decidesk',
                schema: 'resolution'
            );
            if ($entity === null) {
                return [
                    'success'             => false,
                    'resolution'          => null,
                    'signaturesCollected' => 0,
                    'message'             => 'Resolution not found.',
                ];
            }

            $current = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $current = $entity->getObject();
            }

            $required = (array) ($current['requiredSigners'] ?? []);
            $votes    = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board-vote',
                    'filters'  => ['resolutionKoppeling' => $resolutionId],
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: WrittenResolutionService::finalize failed',
                ['exception' => $e->getMessage(), 'resolutionId' => $resolutionId]
            );
            return [
                'success'             => false,
                'resolution'          => null,
                'signaturesCollected' => 0,
                'message'             => 'Failed to finalize resolution.',
            ];
        }//end try

        $signed = [];
        foreach ((array) $votes as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false
                || ($row['resolutionKoppeling'] ?? null) !== $resolutionId
                || ($row['vote'] ?? '') !== 'in-favor'
            ) {
                continue;
            }

            $signer = (string) ($row['boardMemberKoppeling'] ?? '');
            if ($signer !== '') {
                $signed[$signer] = true;
            }
        }

        $missing = [];
        foreach ($required as $member) {
            if (isset($signed[(string) $member]) === false) {
                $missing[] = (string) $member;
            }
        }

        if ($missing !== []) {
            return [
                'success'             => false,
                'resolution'          => $current,
                'signaturesCollected' => count($signed),
                'message'             => 'Unanimity not yet reached; missing: '.implode(', ', $missing).'.',
            ];
        }

        try {
            $merged = array_merge(
                $current,
                [
                    'status'       => 'adopted',
                    'adoptionDate' => gmdate('Y-m-d'),
                ]
            );
            $saved  = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'resolution',
                uuid: $resolutionId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: WrittenResolutionService::finalize persist failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'             => false,
                'resolution'          => $current,
                'signaturesCollected' => count($signed),
                'message'             => 'Failed to persist adoption.',
            ];
        }//end try

        $payload = $merged;
        if (is_object($saved) === true) {
            $payload = (array) $saved->jsonSerialize();
        }

        return [
            'success'             => true,
            'resolution'          => $payload,
            'signaturesCollected' => count($signed),
            'message'             => 'Written resolution adopted.',
        ];

    }//end finalize()
}//end class
