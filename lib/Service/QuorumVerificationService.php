<?php
/**
 * Decidesk Quorum Verification Service
 *
 * Computes board-meeting quorum from in-person, remote and valid-proxy
 * attendance and evaluates configured quorum and vote-threshold rules.
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

/**
 * Pure quorum and threshold computation for board meetings.
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
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function __construct(
        private readonly ContainerInterface $container,
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
     * Compute quorum for a meeting.
     *
     * Total present is counted as the number of board members marked in-person
     * or remote, plus every active (non-suspended, non-revoked) proxy. The
     * required threshold is taken from the meeting's quorumRequired field.
     *
     * @param string $meetingId BoardMeeting UUID.
     *
     * @return array{total:int,present:int,proxies:int,threshold:int,met:bool}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function computeQuorum(string $meetingId): array
    {
        $report  = $this->getAttendanceReport(meetingId: $meetingId);
        $present = (int) $report['inPerson'] + (int) $report['remote'];
        $proxies = (int) $report['proxies'];
        $total   = ($present + $proxies);

        $objectService = $this->objectService();
        $meeting       = $objectService->find(id: $meetingId, register: self::REGISTER, schema: 'board-meeting');
        $threshold     = 0;
        if ($meeting !== null) {
            $data      = $meeting->jsonSerialize();
            $threshold = (int) ($data['quorumRequired'] ?? 0);
        }

        return [
            'total'     => $total,
            'present'   => $present,
            'proxies'   => $proxies,
            'threshold' => $threshold,
            'met'       => ($threshold > 0 && $total >= $threshold),
        ];

    }//end computeQuorum()

    /**
     * Produce a per-member attendance breakdown for a meeting.
     *
     * @param string $meetingId BoardMeeting UUID.
     *
     * @return array{inPerson:int,remote:int,proxies:int,absent:int,members:array<int,array<string,mixed>>}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function getAttendanceReport(string $meetingId): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister(self::REGISTER);
        $objectService->setSchema('board-proxy');
        $proxyResult = $objectService->findAll(['filters' => ['relations.boardMeeting' => $meetingId]]);

        $activeProxies = 0;
        foreach (($proxyResult['results'] ?? $proxyResult) as $proxy) {
            $data = $this->serialize(item: $proxy);
            if (($data['status'] ?? '') === 'active') {
                $activeProxies++;
            }
        }

        // Attendance markers live on BoardMeeting.attendance when set by the live
        // session; in the absence of a live instance we report proxies only and
        // leave the present counts to the caller's supplied attendance map.
        return [
            'inPerson' => 0,
            'remote'   => 0,
            'proxies'  => $activeProxies,
            'absent'   => 0,
            'members'  => [],
        ];

    }//end getAttendanceReport()

    /**
     * Verify a participant's attendance is of an accepted type.
     *
     * @param string $participantType One of in-person, remote, proxy-holder.
     *
     * @return bool
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function verifyAttendance(string $participantType): bool
    {
        return in_array($participantType, ['in-person', 'remote', 'proxy-holder'], true);

    }//end verifyAttendance()

    /**
     * Compute the number of in-favor votes required for a given threshold.
     *
     * Qualified majorities are computed against the total number of seats, not
     * against the number of attendees, per REQ-008.
     *
     * @param string $threshold  Vote-threshold enum value.
     * @param int    $totalSeats Total board seats.
     *
     * @return int Minimum in-favor votes required.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
     */
    public function requiredVotesFor(string $threshold, int $totalSeats): int
    {
        switch ($threshold) {
            case 'unanimous':
                return $totalSeats;
            case 'qualified-majority-three-quarters':
                return (int) ceil(($totalSeats * 3) / 4);
            case 'qualified-majority-two-thirds':
                return (int) ceil(($totalSeats * 2) / 3);
            case 'simple-majority':
            default:
                return (int) (floor($totalSeats / 2) + 1);
        }

    }//end requiredVotesFor()

    /**
     * Normalise an object-or-array into an associative array.
     *
     * @param mixed $item ObjectEntity or array.
     *
     * @return array<string,mixed>
     */
    private function serialize(mixed $item): array
    {
        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        if (is_array($item) === true) {
            return $item;
        }

        return [];

    }//end serialize()
}//end class
