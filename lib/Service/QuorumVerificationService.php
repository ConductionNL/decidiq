<?php
/**
 * Decidesk Quorum Verification Service
 *
 * Computes board-meeting quorum from in-person attendance, remote attendance and valid
 * proxies, and produces an attendance breakdown. Used to gate vote opening on a meeting.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Quorum computation for board meetings.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 */
class QuorumVerificationService
{
    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Attendance statuses that count towards quorum.
     *
     * @var string[]
     */
    private const PRESENT_STATUSES = ['present', 'remote', 'proxy'];

    /**
     * Constructor for QuorumVerificationService.
     *
     * @param ContainerInterface $container The DI container.
     * @param LoggerInterface    $logger    The logger.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
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
     * Load a BoardMeeting record.
     *
     * @param string $meetingId BoardMeeting UUID.
     *
     * @return array The serialized meeting.
     *
     * @throws \RuntimeException When the meeting does not exist.
     */
    private function meeting(string $meetingId): array
    {
        $entity = $this->objectService()->find(id: $meetingId, register: self::REGISTER, schema: 'board-meeting');
        if ($entity === null) {
            throw new RuntimeException('BoardMeeting '.$meetingId.' not found.');
        }

        return $entity->jsonSerialize();

    }//end meeting()

    /**
     * Compute quorum for a meeting.
     *
     * @param string $meetingId BoardMeeting UUID.
     *
     * @return array{total: int, threshold: int, met: bool} Quorum result.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function computeQuorum(string $meetingId): array
    {
        $meeting   = $this->meeting(meetingId: $meetingId);
        $threshold = (int) ($meeting['quorum-required'] ?? 0);
        $report    = $this->getAttendanceReport(meetingId: $meetingId);

        $total = (int) ($report['present'] + $report['remote'] + $report['proxy']);

        return [
            'total'     => $total,
            'threshold' => $threshold,
            'met'       => ($threshold === 0 || $total >= $threshold),
        ];

    }//end computeQuorum()

    /**
     * Validate that a participant of the given type counts towards attendance.
     *
     * @param string $meetingId       BoardMeeting UUID (validated to exist).
     * @param string $participantType One of present, remote, proxy.
     *
     * @return bool True when the participant type counts towards quorum.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function verifyAttendance(string $meetingId, string $participantType): bool
    {
        $this->meeting(meetingId: $meetingId);
        return in_array($participantType, self::PRESENT_STATUSES, true);

    }//end verifyAttendance()

    /**
     * Produce a per-status attendance breakdown for a meeting.
     *
     * Counts board members linked to the meeting's board by their attendanceStatus.
     *
     * @param string $meetingId BoardMeeting UUID.
     *
     * @return array{present: int, remote: int, proxy: int, absent: int} Attendance counts.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function getAttendanceReport(string $meetingId): array
    {
        $meeting = $this->meeting(meetingId: $meetingId);
        $boardId = (string) ($meeting['board-koppeling'] ?? '');

        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema('board-member');
        $members = $objectService->findAll(['filters' => ['board-koppeling' => $boardId]]);

        $counts = ['present' => 0, 'remote' => 0, 'proxy' => 0, 'absent' => 0];
        foreach ($members as $entity) {
            $data   = $entity->jsonSerialize();
            $status = (string) ($data['attendanceStatus'] ?? 'absent');
            if (isset($counts[$status]) === false) {
                $status = 'absent';
            }

            $counts[$status]++;
        }//end foreach

        return $counts;

    }//end getAttendanceReport()
}//end class
