<?php
/**
 * Decidesk Board CalDAV Sync Service
 *
 * Phase 7 — bridges OpenRegister BoardMeeting objects to the Nextcloud
 * CalDAV calendar (ADR-002 source of truth for calendar entries). The
 * service builds an RFC-5545 VEVENT for a BoardMeeting, stamps the
 * X-DECIDESK-* extension properties documented in the change design,
 * and writes the event through OCP\Calendar\ICreateFromString into the
 * organiser's personal calendar — or returns the raw ICS blob when no
 * CalDAV calendar is available (so the OR-side `caldavIcsBlob` field is
 * still populated for downstream consumers).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors BoardMeeting OR rows to the Nextcloud CalDAV backend.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */
class BoardCalDavSyncService
{

    /**
     * Default duration (in seconds) for a meeting without explicit end-time.
     *
     * @var int
     */
    public const DEFAULT_DURATION_SECONDS = 3600;

    /**
     * The X-prefix used for every Decidesk-specific iCalendar property.
     *
     * @var string
     */
    public const X_PREFIX = 'X-DECIDESK-';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (lazy ObjectService + IManager)
     * @param LoggerInterface    $logger    Logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build a deterministic CalDAV UID for a BoardMeeting record.
     *
     * @param array<string, mixed> $meeting OR row
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     *
     * @return string
     */
    public function buildUid(array $meeting): string
    {
        $explicit = trim((string) ($meeting['caldavUid'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $id = trim((string) ($meeting['id'] ?? ''));
        if ($id === '') {
            return 'meeting-'.bin2hex(random_bytes(8)).'@decidesk.local';
        }

        return $id.'@decidesk.local';

    }//end buildUid()

    /**
     * Build a VEVENT ICS blob from a BoardMeeting row.
     *
     * The blob includes the configured `X-DECIDESK-*` extension properties so
     * round-tripping the calendar entry preserves the lifecycle / governance
     * metadata; this is the documented contract from task 7.2.
     *
     * @param array<string, mixed> $meeting OR BoardMeeting row
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.2
     *
     * @return string ICS body
     */
    public function buildIcs(array $meeting): string
    {
        $uid         = $this->buildUid(meeting: $meeting);
        $summary     = $this->escape(text: (string) ($meeting['title'] ?? 'Board meeting'));
        $description = $this->escape(text: (string) ($meeting['description'] ?? ''));
        $location    = $this->escape(text: (string) ($meeting['location'] ?? ''));
        $dtstart     = $this->formatDt(value: (string) ($meeting['meetingStart'] ?? $meeting['meetingDate'] ?? ''));
        $dtend       = $this->resolveDtEnd(meeting: $meeting, dtstart: $dtstart);
        $now         = gmdate('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Conduction//Decidesk//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$now,
            'DTSTART:'.$dtstart,
            'DTEND:'.$dtend,
            'SUMMARY:'.$summary,
        ];

        if ($description !== '') {
            $lines[] = 'DESCRIPTION:'.$description;
        }

        if ($location !== '') {
            $lines[] = 'LOCATION:'.$location;
        }

        $lines[] = 'STATUS:CONFIRMED';

        // X-DECIDESK-* extension properties — see design.md, task 7.2.
        if (isset($meeting['boardKoppeling']) === true && $meeting['boardKoppeling'] !== '') {
            $lines[] = self::X_PREFIX.'BOARD-UID:'.$this->escape(text: (string) $meeting['boardKoppeling']);
        }

        if (isset($meeting['status']) === true && $meeting['status'] !== '') {
            $lines[] = self::X_PREFIX.'LIFECYCLE:'.$this->escape(text: (string) $meeting['status']);
        }

        if (isset($meeting['quorumRequired']) === true && $meeting['quorumRequired'] !== '') {
            $lines[] = self::X_PREFIX.'QUORUM-REQUIRED:'.(int) $meeting['quorumRequired'];
        }

        if (isset($meeting['noticeDeadlineDays']) === true && $meeting['noticeDeadlineDays'] !== '') {
            $lines[] = self::X_PREFIX.'NOTICE-DEADLINE-DAYS:'.(int) $meeting['noticeDeadlineDays'];
        }

        if (isset($meeting['meetingType']) === true && $meeting['meetingType'] !== '') {
            $lines[] = self::X_PREFIX.'MEETING-TYPE:'.$this->escape(text: (string) $meeting['meetingType']);
        }

        if (isset($meeting['format']) === true && $meeting['format'] !== '') {
            $lines[] = self::X_PREFIX.'FORMAT:'.$this->escape(text: (string) $meeting['format']);
        }

        if (isset($meeting['language']) === true && $meeting['language'] !== '') {
            $lines[] = self::X_PREFIX.'LANGUAGE:'.$this->escape(text: (string) $meeting['language']);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";

    }//end buildIcs()

    /**
     * Push the meeting into the principal's first writable CalDAV calendar.
     *
     * Returns the generated ICS blob + the UID under which the event was
     * persisted. When no writable calendar is available the ICS is still
     * returned (callers persist it on the OR row as `caldavIcsBlob` so the
     * dialect stays round-trippable when CalDAV is added later).
     *
     * @param array<string, mixed> $meeting      OR BoardMeeting row
     * @param string               $principalUid Acting principal UID (calendar owner)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     *
     * @return array{success: bool, uid: string, ics: string, calendar: string|null, message: string}
     */
    public function syncMeeting(array $meeting, string $principalUid): array
    {
        $uid = $this->buildUid(meeting: $meeting);
        $ics = $this->buildIcs(meeting: $meeting);

        try {
            $manager = $this->container->get(ICalendarManager::class);
        } catch (\Throwable $e) {
            $this->logger->info(
                'Decidesk CalDAV: ICalendarManager not available, returning ICS only',
                ['exception' => $e->getMessage()]
            );

            return [
                'success'  => true,
                'uid'      => $uid,
                'ics'      => $ics,
                'calendar' => null,
                'message'  => 'CalDAV unavailable; ICS blob captured only.',
            ];
        }

        $principalUri = 'principals/users/'.$principalUid;
        try {
            $calendars = $manager->getCalendarsForPrincipal(principalUri: $principalUri);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk CalDAV: failed to enumerate principal calendars',
                ['principal' => $principalUri, 'exception' => $e->getMessage()]
            );

            return [
                'success'  => false,
                'uid'      => $uid,
                'ics'      => $ics,
                'calendar' => null,
                'message'  => 'Unable to enumerate CalDAV calendars.',
            ];
        }

        $writable = null;
        foreach ($calendars as $calendar) {
            if ($calendar instanceof ICreateFromString === true) {
                $writable = $calendar;
                break;
            }
        }

        if ($writable === null) {
            $this->logger->info(
                'Decidesk CalDAV: no writable calendar found for principal',
                ['principal' => $principalUri]
            );

            return [
                'success'  => true,
                'uid'      => $uid,
                'ics'      => $ics,
                'calendar' => null,
                'message'  => 'No writable CalDAV calendar; ICS blob captured only.',
            ];
        }

        $filename = preg_replace('/[^A-Za-z0-9._@-]/', '_', $uid).'.ics';
        try {
            $writable->createFromString(name: (string) $filename, calendarData: $ics);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk CalDAV: createFromString failed',
                ['uid' => $uid, 'exception' => $e->getMessage()]
            );

            return [
                'success'  => false,
                'uid'      => $uid,
                'ics'      => $ics,
                'calendar' => $writable->getUri(),
                'message'  => 'CalDAV write failed: '.$e->getMessage(),
            ];
        }

        return [
            'success'  => true,
            'uid'      => $uid,
            'ics'      => $ics,
            'calendar' => $writable->getUri(),
            'message'  => 'Board meeting synced to CalDAV.',
        ];

    }//end syncMeeting()

    /**
     * Parse a stored VEVENT ICS blob back into the X-DECIDESK-* metadata map.
     *
     * Returns the canonical lifecycle / governance fields so callers can
     * round-trip the calendar entry without re-fetching the OR row.
     *
     * @param string $ics RFC-5545 VEVENT body
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.2
     *
     * @return array<string, string>
     */
    public function readMeetingData(string $ics): array
    {
        $out   = [];
        $lines = preg_split('/\r\n|\r|\n/', $ics);
        if ($lines === false) {
            $lines = [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([A-Z0-9\-]+)(?:;[^:]*)?:(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $name  = $match[1];
            $value = $match[2];
            switch ($name) {
                case 'UID':
                    $out['uid'] = $value;
                    break;
                case 'SUMMARY':
                    $out['title'] = $this->unescape(text: $value);
                    break;
                case 'DTSTART':
                    $out['meetingStart'] = $value;
                    break;
                case 'DTEND':
                    $out['meetingEnd'] = $value;
                    break;
                case 'LOCATION':
                    $out['location'] = $this->unescape(text: $value);
                    break;
                case 'DESCRIPTION':
                    $out['description'] = $this->unescape(text: $value);
                    break;
                default:
                    if (str_starts_with(haystack: $name, needle: self::X_PREFIX) === true) {
                        $key = self::mapXProperty(xName: $name);
                        if ($key !== null) {
                            $out[$key] = $this->unescape(text: $value);
                        }
                    }
            }//end switch
        }//end foreach

        return $out;

    }//end readMeetingData()

    /**
     * Get the catalog of supported X-DECIDESK-* properties.
     *
     * Exposed for documentation + test parity with design.md.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.2
     *
     * @return array<string, string>
     */
    public static function supportedXProperties(): array
    {
        return [
            self::X_PREFIX.'BOARD-UID'            => 'boardKoppeling',
            self::X_PREFIX.'LIFECYCLE'            => 'status',
            self::X_PREFIX.'QUORUM-REQUIRED'      => 'quorumRequired',
            self::X_PREFIX.'NOTICE-DEADLINE-DAYS' => 'noticeDeadlineDays',
            self::X_PREFIX.'MEETING-TYPE'         => 'meetingType',
            self::X_PREFIX.'FORMAT'               => 'format',
            self::X_PREFIX.'LANGUAGE'             => 'language',
        ];

    }//end supportedXProperties()

    /**
     * Map a known X-DECIDESK-* property name back to the OR field.
     *
     * @param string $xName Full property name (incl. prefix)
     *
     * @return string|null
     */
    private static function mapXProperty(string $xName): ?string
    {
        $catalog = self::supportedXProperties();
        return ($catalog[$xName] ?? null);

    }//end mapXProperty()

    /**
     * Escape characters that have special meaning in iCalendar text fields.
     *
     * @param string $text The raw value
     *
     * @return string
     */
    private function escape(string $text): string
    {
        $text = str_replace(
            search: ['\\', "\n", "\r", ',', ';'],
            replace: ['\\\\', '\\n', '', '\,', '\;'],
            subject: $text,
        );
        return $text;

    }//end escape()

    /**
     * Inverse of self::escape() — reverse the canonical RFC-5545 text escaping.
     *
     * @param string $text Escaped value
     *
     * @return string
     */
    private function unescape(string $text): string
    {
        return str_replace(
            search: ['\\n', '\,', '\;', '\\\\'],
            replace: ["\n", ',', ';', '\\'],
            subject: $text,
        );

    }//end unescape()

    /**
     * Normalise a stored timestamp (ISO-8601 or already-iCal-formatted) into
     * the iCalendar BASIC datetime form (`YYYYMMDDTHHMMSSZ`). Falls back to
     * "now" when parsing fails.
     *
     * @param string $value Raw timestamp
     *
     * @return string
     */
    private function formatDt(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return gmdate('Ymd\THis\Z');
        }

        if (preg_match('/^\d{8}T\d{6}Z?$/', $value) === 1) {
            if (str_ends_with(haystack: $value, needle: 'Z') === true) {
                return $value;
            }

            return $value.'Z';
        }

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk CalDAV: failed to parse datetime, fallback to now',
                ['value' => $value, 'exception' => $e->getMessage()]
            );
            return gmdate('Ymd\THis\Z');
        }

    }//end formatDt()

    /**
     * Resolve the end-time used in the VEVENT.
     *
     * Honours `meetingEnd` when set; otherwise adds the default duration to
     * `meetingStart` (or to `dtstart` when no end-time exists).
     *
     * @param array<string, mixed> $meeting OR row
     * @param string               $dtstart Already-iCal-formatted start
     *
     * @return string iCalendar end
     */
    private function resolveDtEnd(array $meeting, string $dtstart): string
    {
        $end = (string) ($meeting['meetingEnd'] ?? '');
        if ($end !== '') {
            return $this->formatDt(value: $end);
        }

        try {
            $start = \DateTimeImmutable::createFromFormat(
                'Ymd\THis\Z',
                $dtstart,
                new \DateTimeZone('UTC')
            );
            if ($start instanceof \DateTimeImmutable === true) {
                return $start->add(new \DateInterval('PT'.self::DEFAULT_DURATION_SECONDS.'S'))->format('Ymd\THis\Z');
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk CalDAV: fallback dtend computation',
                ['exception' => $e->getMessage()]
            );
        }

        return gmdate('Ymd\THis\Z', (time() + self::DEFAULT_DURATION_SECONDS));

    }//end resolveDtEnd()
}//end class
