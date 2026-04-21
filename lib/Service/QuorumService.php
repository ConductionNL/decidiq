<?php

/**
 * Decidesk Quorum Service
 *
 * Service for managing quorum calculations and validation for meetings.
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
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing quorum calculations and validation.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.1
 */
class QuorumService
{
    /**
     * Constructor for QuorumService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Calculate quorum status for a meeting.
     *
     * Supports two quorum rule formats:
     * - "fixed:N" — requires exactly N participants
     * - "percentage:N" — requires N% of total members
     *
     * @param string $meetingId UUID of the meeting
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.2
     *
     * @return array{quorumRequired: int|null, presentCount: int, percentage: float, met: bool}
     */
    public function calculateQuorum(string $meetingId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $meeting = $objectService->find(id: $meetingId);
            if ($meeting === null) {
                return [
                    'quorumRequired' => null,
                    'presentCount'   => 0,
                    'percentage'     => 0.0,
                    'met'            => false,
                ];
            }

            $meetingObj = $meeting->jsonSerialize();
            $quorumRule = $meetingObj['quorumRequired'] ?? null;

            if ($quorumRule === null) {
                return [
                    'quorumRequired' => null,
                    'presentCount'   => 0,
                    'percentage'     => 0.0,
                    'met'            => true,
                ];
            }

            $governanceBodyId = $meetingObj['governanceBody'] ?? null;
            if ($governanceBodyId === null) {
                return [
                    'quorumRequired' => $quorumRule,
                    'presentCount'   => 0,
                    'percentage'     => 0.0,
                    'met'            => false,
                ];
            }

            $body = $objectService->find(id: $governanceBodyId);
            if ($body === null) {
                return [
                    'quorumRequired' => $quorumRule,
                    'presentCount'   => 0,
                    'percentage'     => 0.0,
                    'met'            => false,
                ];
            }

            $members = $objectService->findObjects(
                register: 'decidesk',
                schema: 'Participant',
                filters: [
                    'governanceBody' => $governanceBodyId,
                    '_limit'         => 1000,
                ]
            );

            $presentCount = 0;
            foreach ($members['results'] ?? [] as $member) {
                if (($member['attendanceStatus'] ?? null) === 'present') {
                    $presentCount++;
                }
            }

            $totalMembers = count($members['results'] ?? []);
            $percentage   = 0.0;
            if ($totalMembers > 0) {
                $percentage = ($presentCount / $totalMembers) * 100;
            }

            $met = ($presentCount >= $quorumRule);

            return [
                'quorumRequired' => $quorumRule,
                'presentCount'   => $presentCount,
                'percentage'     => round($percentage, 2),
                'met'            => $met,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: quorum calculation failed',
                ['id' => $meetingId, 'exception' => $e->getMessage()]
            );
            return [
                'quorumRequired' => null,
                'presentCount'   => 0,
                'percentage'     => 0.0,
                'met'            => false,
            ];
        }//end try
    }//end calculateQuorum()

    /**
     * Validate whether quorum is met for a meeting.
     *
     * @param string $meetingId UUID of the meeting
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.2
     *
     * @return bool True if quorum is met or not required, false otherwise
     */
    public function validateQuorum(string $meetingId): bool
    {
        $quorum = $this->calculateQuorum(meetingId: $meetingId);
        return $quorum['met'];
    }//end validateQuorum()
}//end class
