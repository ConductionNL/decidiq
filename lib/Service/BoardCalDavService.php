<?php
/**
 * Decidesk Board CalDAV Service
 *
 * Builds and parses iCalendar VEVENT blobs carrying X-DECIDESK-* board-meeting
 * properties, and synchronises them with the BoardMeeting OpenRegister wrapper.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * ICS VEVENT construction/parsing for board meetings (ADR-002).
 *
 * The X-DECIDESK-* property registry encodes board-specific lifecycle metadata
 * on the calendar event so a CalDAV-only consumer still sees the governance
 * state. Writing the event into a live Nextcloud calendar requires a running
 * CalDAV backend (deferred); the blob build/parse below is fully deterministic.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */
class BoardCalDavService
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
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
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
     * Convert an ISO-8601 timestamp to an iCalendar UTC stamp.
     *
     * @param string $iso ISO-8601 timestamp.
     *
     * @return string e.g. 20250312T140000Z.
     */
    private function icsStamp(string $iso): string
    {
        try {
            $dt = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

    }//end icsStamp()

    /**
     * Build a VEVENT ICS blob for a board meeting.
     *
     * @param array<string,mixed> $meeting BoardMeeting data.
     *
     * @return string The VCALENDAR/VEVENT blob.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function createBoardMeetingVevent(array $meeting): string
    {
        $uid     = (string) ($meeting['caldavUid'] ?? ('decidesk-'.bin2hex(random_bytes(6)).'@decidesk'));
        $start   = $this->icsStamp(iso: (string) ($meeting['meetingStart'] ?? ($meeting['meetingDate'] ?? 'now')));
        $end     = $this->icsStamp(iso: (string) ($meeting['meetingEnd'] ?? ($meeting['meetingDate'] ?? 'now')));
        $summary = 'Board meeting ('.($meeting['meetingType'] ?? 'regular').')';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Conduction//Decidesk//EN',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$this->icsStamp(iso: 'now'),
            'DTSTART:'.$start,
            'DTEND:'.$end,
            'SUMMARY:'.$summary,
            'LOCATION:'.(string) ($meeting['location'] ?? ''),
            'X-DECIDESK-BOARD-UID:'.(string) ($meeting['boardId'] ?? ''),
            'X-DECIDESK-LIFECYCLE:'.(string) ($meeting['status'] ?? 'scheduled'),
            'X-DECIDESK-QUORUM-REQUIRED:'.(string) ((int) ($meeting['quorumRequired'] ?? 0)),
            'X-DECIDESK-NOTICE-DEADLINE-DAYS:'.(string) ((int) ($meeting['noticeDeadlineDays'] ?? 7)),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines);

    }//end createBoardMeetingVevent()

    /**
     * Parse X-DECIDESK-* properties out of a VEVENT ICS blob.
     *
     * @param string $ics The ICS blob.
     *
     * @return array<string,string>
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function readBoardMeetingData(string $ics): array
    {
        $properties = [];
        foreach (preg_split('/\r\n|\n|\r/', $ics) as $line) {
            if (str_starts_with($line, 'UID:') === true) {
                $properties['caldavUid'] = substr($line, 4);
            } else if (str_starts_with($line, 'X-DECIDESK-BOARD-UID:') === true) {
                $properties['boardId'] = substr($line, 21);
            } else if (str_starts_with($line, 'X-DECIDESK-LIFECYCLE:') === true) {
                $properties['status'] = substr($line, 21);
            } else if (str_starts_with($line, 'X-DECIDESK-QUORUM-REQUIRED:') === true) {
                $properties['quorumRequired'] = substr($line, 27);
            } else if (str_starts_with($line, 'X-DECIDESK-NOTICE-DEADLINE-DAYS:') === true) {
                $properties['noticeDeadlineDays'] = substr($line, 32);
            }
        }

        return $properties;

    }//end readBoardMeetingData()

    /**
     * Build the ICS blob for a meeting and persist it on the BoardMeeting record.
     *
     * @param string $meetingId BoardMeeting UUID.
     *
     * @return string The generated ICS blob.
     *
     * @throws \RuntimeException When the meeting is missing.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function syncMeetingVevent(string $meetingId): string
    {
        $objectService = $this->objectService();
        $entity        = $objectService->find(id: $meetingId, register: self::REGISTER, schema: 'board-meeting');
        if ($entity === null) {
            throw new \RuntimeException('Board meeting not found');
        }

        $data = $entity->jsonSerialize();
        $ics  = $this->createBoardMeetingVevent(meeting: $data);
        $data['caldavIcsBlob'] = $ics;
        $objectService->saveObject(register: self::REGISTER, schema: 'board-meeting', object: $data, uuid: $meetingId);

        return $ics;

    }//end syncMeetingVevent()
}//end class
