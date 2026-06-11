<?php
/**
 * Decidesk Resolution Service
 *
 * Phase 2 service governing the Resolution lifecycle: propose, amend, open
 * vote (guarded by quorum), conclude (computes adoption status from cast
 * votes vs. the configured threshold).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side resolution lifecycle owner.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
 */
class ResolutionService
{

    /**
     * Allowed `type` enum values.
     *
     * @var string[]
     */
    public const TYPES = [
        'approval',
        'appointment',
        'dismissal',
        'financial',
        'strategic',
        'policy',
        'delegation-of-authority',
        'acknowledgement',
        'written-resolution',
    ];

    /**
     * Allowed `voteThreshold` enum values.
     *
     * @var string[]
     */
    public const THRESHOLDS = [
        'simple-majority',
        'qualified-majority-two-thirds',
        'qualified-majority-three-quarters',
        'unanimous',
    ];


    /**
     * Constructor for ResolutionService.
     *
     * @param ContainerInterface       $container       The DI container
     * @param LoggerInterface          $logger          The logger
     * @param ResolutionLifecycleGuard $guard           Quorum + conflict guard
     * @param AuditLogService          $auditLogService Audit log dependency
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ResolutionLifecycleGuard $guard,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()


    /**
     * Propose a new resolution (status = `proposed`).
     *
     * @param string               $meetingId UUID of the parent meeting
     * @param array<string, mixed> $data      Resolution payload
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
     *
     * @return array{success: bool, resolution: array|null, message: string}
     */
    public function propose(string $meetingId, array $data): array
    {
        if (isset($data['title']) === false || trim((string) $data['title']) === '') {
            return [
                'success'    => false,
                'resolution' => null,
                'message'    => 'Resolution title is required.',
            ];
        }

        if (isset($data['type']) === true && in_array($data['type'], self::TYPES, true) === false) {
            return [
                'success'    => false,
                'resolution' => null,
                'message'    => 'Unknown resolution type: '.$data['type'],
            ];
        }

        if (isset($data['voteThreshold']) === true && in_array($data['voteThreshold'], self::THRESHOLDS, true) === false) {
            return [
                'success'    => false,
                'resolution' => null,
                'message'    => 'Unknown vote threshold: '.$data['voteThreshold'],
            ];
        }

        $row = array_merge(
            [
                'voteType'      => 'named',
                'voteThreshold' => 'simple-majority',
            ],
            $data,
            [
                'meetingKoppeling' => $meetingId,
                'status'           => 'proposed',
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
            $this->logger->error('Decidesk: ResolutionService::propose failed', ['exception' => $e->getMessage()]);
            return [
                'success'    => false,
                'resolution' => null,
                'message'    => 'Failed to propose resolution.',
            ];
        }

        return [
            'success'    => true,
            'resolution' => is_object($saved) === true ? (array) $saved->jsonSerialize() : $row,
            'message'    => 'Resolution proposed.',
        ];

    }//end propose()


    /**
     * Amend a resolution that is still in `proposed` or `under-discussion`.
     *
     * @param string               $resolutionId UUID of the resolution
     * @param array<string, mixed> $patch        Fields to update
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
     *
     * @return array{success: bool, resolution: array|null, message: string}
     */
    public function amend(string $resolutionId, array $patch): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $resolutionId, register: 'decidesk', schema: 'resolution');
            if ($entity === null) {
                return [
                    'success'    => false,
                    'resolution' => null,
                    'message'    => 'Resolution not found.',
                ];
            }

            $current = (method_exists($entity, 'getObject') === true)
                ? $entity->getObject()
                : (array) $entity->jsonSerialize();

            $status = (string) ($current['status'] ?? 'proposed');
            if (in_array($status, ['proposed', 'under-discussion'], true) === false) {
                return [
                    'success'    => false,
                    'resolution' => null,
                    'message'    => "Cannot amend a resolution in '".$status."' state.",
                ];
            }

            $merged = array_merge($current, $patch);
            $saved  = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'resolution',
                uuid: $resolutionId
            );
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: ResolutionService::amend failed', ['exception' => $e->getMessage()]);
            return [
                'success'    => false,
                'resolution' => null,
                'message'    => 'Failed to amend resolution.',
            ];
        }//end try

        return [
            'success'    => true,
            'resolution' => is_object($saved) === true ? (array) $saved->jsonSerialize() : $merged,
            'message'    => 'Resolution amended.',
        ];

    }//end amend()


    /**
     * Open voting on a resolution. The lifecycle guard validates quorum before
     * the transition is allowed.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
     *
     * @return array{success: bool, resolution: array|null, message: string}
     */
    public function openVote(string $resolutionId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $resolutionId, register: 'decidesk', schema: 'resolution');
            if ($entity === null) {
                return [
                    'success'    => false,
                    'resolution' => null,
                    'message'    => 'Resolution not found.',
                ];
            }

            $current   = (method_exists($entity, 'getObject') === true)
                ? $entity->getObject()
                : (array) $entity->jsonSerialize();
            $meetingId = (string) ($current['meetingKoppeling'] ?? '');

            $gate = $this->guard->canOpenVote($meetingId);
            if ($gate['allowed'] === false) {
                return [
                    'success'    => false,
                    'resolution' => null,
                    'message'    => $gate['reason'],
                ];
            }

            $merged = array_merge($current, ['status' => 'under-discussion']);
            $saved  = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'resolution',
                uuid: $resolutionId
            );
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: ResolutionService::openVote failed', ['exception' => $e->getMessage()]);
            return [
                'success'    => false,
                'resolution' => null,
                'message'    => 'Failed to open vote.',
            ];
        }//end try

        return [
            'success'    => true,
            'resolution' => is_object($saved) === true ? (array) $saved->jsonSerialize() : $merged,
            'message'    => 'Vote opened.',
        ];

    }//end openVote()


    /**
     * Conclude voting: read all BoardVote rows linked to the resolution,
     * compute adoption against the configured threshold, and persist the
     * resulting `adopted` or `rejected` status.
     *
     * @param string $resolutionId UUID of the resolution
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-resolution-service
     *
     * @return array{success: bool, resolution: array|null, tally: array<string, int>, message: string}
     */
    public function conclude(string $resolutionId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $resolutionId, register: 'decidesk', schema: 'resolution');
            if ($entity === null) {
                return [
                    'success'    => false,
                    'resolution' => null,
                    'tally'      => [],
                    'message'    => 'Resolution not found.',
                ];
            }

            $current = (method_exists($entity, 'getObject') === true)
                ? $entity->getObject()
                : (array) $entity->jsonSerialize();

            $votes = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board-vote',
                    'filters'  => ['resolutionKoppeling' => $resolutionId],
                    'limit'    => 1000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: ResolutionService::conclude failed', ['exception' => $e->getMessage()]);
            return [
                'success'    => false,
                'resolution' => null,
                'tally'      => [],
                'message'    => 'Failed to load resolution / votes.',
            ];
        }

        $tally = [
            'in-favor'                 => 0,
            'against'                  => 0,
            'abstain'                  => 0,
            'absent'                   => 0,
            'recused-due-to-conflict'  => 0,
        ];
        foreach ((array) $votes as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            if (($row['resolutionKoppeling'] ?? null) !== $resolutionId) {
                continue;
            }

            $vote = (string) ($row['vote'] ?? 'absent');
            if (isset($tally[$vote]) === true) {
                $tally[$vote]++;
            }
        }

        $cast = ($tally['in-favor'] + $tally['against'] + $tally['abstain']);
        $threshold = (string) ($current['voteThreshold'] ?? 'simple-majority');

        $adopted = $this->isAdopted($threshold, $tally, $cast);

        $merged = array_merge(
            $current,
            [
                'status'       => $adopted === true ? 'adopted' : 'rejected',
                'adoptionDate' => gmdate('Y-m-d'),
            ]
        );

        try {
            $saved = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'resolution',
                uuid: $resolutionId
            );
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: ResolutionService::conclude persist failed', ['exception' => $e->getMessage()]);
            return [
                'success'    => false,
                'resolution' => null,
                'tally'      => $tally,
                'message'    => 'Failed to persist resolution conclusion.',
            ];
        }

        return [
            'success'    => true,
            'resolution' => is_object($saved) === true ? (array) $saved->jsonSerialize() : $merged,
            'tally'      => $tally,
            'message'    => 'Resolution '.($adopted === true ? 'adopted' : 'rejected').'.',
        ];

    }//end conclude()


    /**
     * Decide whether the configured threshold is satisfied by the given tally.
     *
     * @param string             $threshold One of self::THRESHOLDS
     * @param array<string, int> $tally     Cast counts per vote enum
     * @param int                $cast      Total non-absent, non-recused votes
     *
     * @return bool
     */
    private function isAdopted(string $threshold, array $tally, int $cast): bool
    {
        if ($cast <= 0) {
            return false;
        }

        switch ($threshold) {
            case 'unanimous':
                return ($tally['against'] === 0 && $tally['abstain'] === 0 && $tally['in-favor'] > 0);
            case 'qualified-majority-three-quarters':
                return ($tally['in-favor'] >= (int) ceil(($cast * 3) / 4));
            case 'qualified-majority-two-thirds':
                return ($tally['in-favor'] >= (int) ceil(($cast * 2) / 3));
            case 'simple-majority':
            default:
                return ($tally['in-favor'] > ($cast / 2));
        }

    }//end isAdopted()


}//end class
