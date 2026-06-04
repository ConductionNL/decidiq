<?php
/**
 * Decidesk Written Resolution Service
 *
 * Implements the rondvraag-besluit workflow: a resolution adopted in writing
 * outside a meeting, collecting eIDAS QES agreement from every required member
 * and verifying unanimity within the response window.
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

/**
 * Written-resolution (rondvraag) workflow with unanimity verification.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
 */
class WrittenResolutionService
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Constructor.
     *
     * @param ContainerInterface    $container The DI container.
     * @param EidasSignatureService $eidas     The eIDAS signature service.
     * @param BoardAuditLogService  $auditLog  The audit log service.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly EidasSignatureService $eidas,
        private readonly BoardAuditLogService $auditLog,
    ) {
    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Initiate a written resolution and request signatures.
     *
     * @param array<string,mixed> $resolutionData      The resolution payload.
     * @param array<string>       $requiredSignatories Required signatory member UUIDs.
     * @param string              $actorUuid           Acting user UUID (audit).
     *
     * @return array<string,mixed> The created resolution with signing request id.
     *
     * @throws \RuntimeException When there are no required signatories.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     */
    public function initiate(array $resolutionData, array $requiredSignatories, string $actorUuid): array
    {
        if ($requiredSignatories === []) {
            throw new \RuntimeException('A written resolution requires at least one signatory');
        }

        $resolutionData['type']          = 'written-resolution';
        $resolutionData['voteType']      = 'unanimous-consent';
        $resolutionData['voteThreshold'] = 'unanimous';
        $resolutionData['status']        = 'proposed';
        $resolutionData['voteOpen']      = true;

        $saved      = $this->objectService()->saveObject(register: self::REGISTER, schema: 'resolution', object: $resolutionData);
        $resolution = $resolutionData;
        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            $resolution = $saved->jsonSerialize();
        }

        $resolutionRef = (string) ($resolution['id'] ?? ($resolution['resolutionNumber'] ?? ''));
        $this->auditLog->append(actorUuid: $actorUuid, action: 'notice-sent', objectUids: [$resolutionRef]);

        return $resolution;

    }//end initiate()

    /**
     * Record a collected signature as an in-favor ballot.
     *
     * @param string $resolutionId  Resolution UUID.
     * @param string $boardMemberId Signing member UUID.
     * @param string $signatureBlob The signature blob.
     * @param string $actorUuid     Acting user UUID (audit).
     *
     * @return array{verified:bool,certificateThumbprint:?string}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     */
    public function collectSignature(string $resolutionId, string $boardMemberId, string $signatureBlob, string $actorUuid): array
    {
        $verification = $this->eidas->verifySignature($resolutionId.':'.$boardMemberId, $signatureBlob);
        if ($verification['valid'] === false) {
            return ['verified' => false, 'certificateThumbprint' => null];
        }

        $ballot = [
            'vote'          => 'in-favor',
            'voteTimestamp' => $verification['timestamp'],
            'voteMethod'    => 'written-ballot',
            'anonymized'    => false,
            'relations'     => [
                ['schema' => 'resolution', 'id' => $resolutionId],
                ['schema' => 'board-member', 'id' => $boardMemberId],
            ],
        ];
        $this->objectService()->saveObject(register: self::REGISTER, schema: 'board-vote', object: $ballot);
        $this->auditLog->append($actorUuid, 'signature', [$resolutionId, $boardMemberId]);

        return ['verified' => true, 'certificateThumbprint' => $verification['certificateThumbprint']];

    }//end collectSignature()

    /**
     * Determine unanimity given signatories and collected in-favor ballots.
     *
     * @param array<string> $requiredSignatories Required member UUIDs.
     * @param array<string> $signedMemberIds     Member UUIDs that signed in-favor.
     *
     * @return bool True when every required signatory has signed.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     */
    public function isUnanimous(array $requiredSignatories, array $signedMemberIds): bool
    {
        if ($requiredSignatories === []) {
            return false;
        }

        foreach ($requiredSignatories as $required) {
            if (in_array($required, $signedMemberIds, true) === false) {
                return false;
            }
        }

        return true;

    }//end isUnanimous()

    /**
     * Finalize a written resolution, adopting it when unanimity is reached.
     *
     * @param string        $resolutionId        Resolution UUID.
     * @param array<string> $requiredSignatories Required signatory UUIDs.
     * @param string        $actorUuid           Acting user UUID (audit).
     *
     * @return array{adopted:bool,resolution:array<string,mixed>}
     *
     * @throws \RuntimeException When the resolution is missing.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     */
    public function finalize(string $resolutionId, array $requiredSignatories, string $actorUuid): array
    {
        $objectService = $this->objectService();
        $resolution    = $objectService->find(id: $resolutionId, register: self::REGISTER, schema: 'resolution');
        if ($resolution === null) {
            throw new \RuntimeException('Resolution not found');
        }

        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema('board-vote');
        $ballots = $objectService->findAll(['filters' => ['relations.resolution' => $resolutionId]]);

        $signed = [];
        foreach (($ballots['results'] ?? $ballots) as $item) {
            if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
                $data = $item->jsonSerialize();
            } else {
                $data = (array) $item;
            }

            if (($data['vote'] ?? '') !== 'in-favor') {
                continue;
            }

            foreach (($data['relations'] ?? []) as $relation) {
                if (($relation['schema'] ?? '') === 'board-member') {
                    $signed[] = (string) ($relation['id'] ?? '');
                }
            }
        }

        $adopted = $this->isUnanimous(requiredSignatories: $requiredSignatories, signedMemberIds: $signed);

        $data = $resolution->jsonSerialize();
        $data['voteOpen'] = false;
        if ($adopted === true) {
            $data['status']        = 'adopted';
            $data['adoptionDate']  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
            $data['effectiveDate'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        }

        $objectService->saveObject(register: self::REGISTER, schema: 'resolution', object: $data, uuid: $resolutionId);
        $this->auditLog->append($actorUuid, 'vote', [$resolutionId]);

        return ['adopted' => $adopted, 'resolution' => $data];

    }//end finalize()
}//end class
