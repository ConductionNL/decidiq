<?php

/**
 * Decidesk CalDAV Service
 *
 * Service for CalDAV-first storage of meetings (VEVENT) and action items (VTODO).
 * Meetings and tasks are stored natively in Nextcloud Calendar/Tasks with
 * X-DECIDESK-* extended properties for governance metadata (ADR-002).
 *
 * @category  Service
 * @package   OCA\Decidesk\Service
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTime;
use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * CalDAV-first storage service for meetings and action items.
 *
 * Follows the pattern established by OpenRegister's CalendarEventService
 * but stores governance metadata as X-DECIDESK-* properties per ADR-002.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class CalDavService
{

    /**
     * Calendar name for DecideDesk meetings.
     *
     * @var string
     */
    private const CALENDAR_NAME = 'Decidesk';

    /**
     * Constructor.
     *
     * @param CalDavBackend   $calDavBackend CalDAV backend
     * @param IUserSession    $userSession   User session
     * @param LoggerInterface $logger        Logger
     *
     * @return void
     */
    public function __construct(
        private readonly CalDavBackend $calDavBackend,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Create a meeting as a CalDAV VEVENT.
     *
     * @param array $meetingData Meeting fields matching ADR-000 Meeting entity
     *
     * @return array The created meeting as a JSON-friendly array with CalDAV UID
     *
     * @throws Exception If no user or calendar found
     */
    public function createMeeting(array $meetingData): array
    {
        $calendarId = $this->findOrCreateDecideskCalendar();
        $uid        = strtoupper(bin2hex(random_bytes(16)));
        $dtstamp    = gmdate('Ymd\THis\Z');

        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//Decidesk//Meetings//EN';

        $vevent = $vcalendar->add('VEVENT', [
            'UID'     => $uid,
            'DTSTAMP' => $dtstamp,
            'SUMMARY' => $meetingData['title'] ?? 'Untitled meeting',
        ]);

        // Standard CalDAV fields.
        if (empty($meetingData['scheduledDate']) === false) {
            $dtstart = new DateTime($meetingData['scheduledDate']);
            $vevent->add('DTSTART', $dtstart);
        }

        if (empty($meetingData['endDate']) === false) {
            $dtend = new DateTime($meetingData['endDate']);
            $vevent->add('DTEND', $dtend);
        }

        if (empty($meetingData['location']) === false) {
            $vevent->add('LOCATION', $meetingData['location']);
        }

        if (empty($meetingData['description']) === false) {
            $vevent->add('DESCRIPTION', $meetingData['description']);
        }

        // X-DECIDESK-* governance metadata (ADR-002).
        $vevent->add('X-DECIDESK-LIFECYCLE', $meetingData['lifecycle'] ?? 'draft');
        $vevent->add('X-DECIDESK-MEETING-TYPE', $meetingData['meetingType'] ?? 'regular');
        $vevent->add('X-DECIDESK-MEETING-MODE', $meetingData['meetingMode'] ?? 'in-person');

        if (empty($meetingData['quorumRequired']) === false) {
            $vevent->add('X-DECIDESK-QUORUM-REQUIRED', (string) $meetingData['quorumRequired']);
        }

        if (empty($meetingData['series']) === false) {
            $vevent->add('X-DECIDESK-SERIES', $meetingData['series']);
        }

        if (empty($meetingData['bodyUid']) === false) {
            $vevent->add('X-DECIDESK-BODY-UID', $meetingData['bodyUid']);
        }

        $calendarData = $vcalendar->serialize();
        $uri          = $uid . '.ics';

        $this->calDavBackend->createCalendarObject($calendarId, $uri, $calendarData);

        $this->logger->info('Decidesk: created meeting VEVENT', ['uid' => $uid, 'title' => $meetingData['title'] ?? '']);

        return $this->veventToMeeting($calendarData, (string) $calendarId, $uri);
    }//end createMeeting()

    /**
     * Update a meeting VEVENT by its CalDAV UID.
     *
     * @param string $calendarUid The VEVENT UID
     * @param array  $updates     Fields to update (merged with existing values)
     *
     * @return array The updated meeting array
     *
     * @throws Exception If meeting not found
     */
    public function updateMeeting(string $calendarUid, array $updates): array
    {
        $found = $this->findVeventByUid($calendarUid);

        if ($found === null) {
            throw new Exception("Meeting VEVENT '$calendarUid' not found");
        }

        $vcalendar = Reader::read($found['calendardata']);
        $vevent    = $vcalendar->VEVENT;

        // Update standard fields.
        if (isset($updates['title']) === true) {
            $vevent->SUMMARY = $updates['title'];
        }

        if (isset($updates['scheduledDate']) === true) {
            $vevent->DTSTART = new DateTime($updates['scheduledDate']);
        }

        if (isset($updates['endDate']) === true) {
            $vevent->DTEND = new DateTime($updates['endDate']);
        }

        if (array_key_exists('location', $updates) === true) {
            $vevent->LOCATION = $updates['location'];
        }

        if (array_key_exists('description', $updates) === true) {
            $vevent->DESCRIPTION = $updates['description'];
        }

        // Update X-DECIDESK-* properties.
        $xProperties = [
            'lifecycle'      => 'X-DECIDESK-LIFECYCLE',
            'meetingType'    => 'X-DECIDESK-MEETING-TYPE',
            'meetingMode'    => 'X-DECIDESK-MEETING-MODE',
            'quorumRequired' => 'X-DECIDESK-QUORUM-REQUIRED',
            'series'         => 'X-DECIDESK-SERIES',
            'bodyUid'        => 'X-DECIDESK-BODY-UID',
        ];

        foreach ($xProperties as $field => $xProp) {
            if (isset($updates[$field]) === true) {
                unset($vevent->{$xProp});
                $vevent->add($xProp, (string) $updates[$field]);
            }
        }

        $calendarData = $vcalendar->serialize();
        $this->calDavBackend->updateCalendarObject(
            (int) $found['calendarId'],
            $found['uri'],
            $calendarData
        );

        $this->logger->info('Decidesk: updated meeting VEVENT', ['uid' => $calendarUid]);

        return $this->veventToMeeting($calendarData, $found['calendarId'], $found['uri']);
    }//end updateMeeting()

    /**
     * Get a meeting by its CalDAV UID.
     *
     * @param string $calendarUid The VEVENT UID
     *
     * @return array|null The meeting array or null if not found
     */
    public function getMeeting(string $calendarUid): ?array
    {
        $found = $this->findVeventByUid($calendarUid);

        if ($found === null) {
            return null;
        }

        return $this->veventToMeeting($found['calendardata'], $found['calendarId'], $found['uri']);
    }//end getMeeting()

    /**
     * Delete a meeting VEVENT by its CalDAV UID.
     *
     * @param string $calendarUid The VEVENT UID
     *
     * @return bool True if deleted
     *
     * @throws Exception If meeting not found
     */
    public function deleteMeeting(string $calendarUid): bool
    {
        $found = $this->findVeventByUid($calendarUid);

        if ($found === null) {
            throw new Exception("Meeting VEVENT '$calendarUid' not found");
        }

        $this->calDavBackend->deleteCalendarObject((int) $found['calendarId'], $found['uri']);

        $this->logger->info('Decidesk: deleted meeting VEVENT', ['uid' => $calendarUid]);

        return true;
    }//end deleteMeeting()

    /**
     * List all DecideDesk meetings from the user's DecideDesk calendar.
     *
     * @param string|null $bodyUid Optional filter by GovernanceBody UUID
     *
     * @return array Array of meeting arrays
     */
    public function listMeetings(?string $bodyUid = null): array
    {
        try {
            $calendarId = $this->findOrCreateDecideskCalendar();
        } catch (Exception $e) {
            $this->logger->warning('Decidesk: cannot list meetings — ' . $e->getMessage());
            return [];
        }

        $calendarObjects = $this->calDavBackend->getCalendarObjects($calendarId);
        $meetings        = [];

        foreach ($calendarObjects as $calendarObject) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $calendarObject['uri']);
            if ($fullObject === null || empty($fullObject['calendardata']) === true) {
                continue;
            }

            if (strpos($fullObject['calendardata'], 'VEVENT') === false) {
                continue;
            }

            if (strpos($fullObject['calendardata'], 'X-DECIDESK-') === false) {
                continue;
            }

            $meeting = $this->veventToMeeting($fullObject['calendardata'], (string) $calendarId, $calendarObject['uri']);

            if ($bodyUid !== null && ($meeting['bodyUid'] ?? '') !== $bodyUid) {
                continue;
            }

            $meetings[] = $meeting;
        }//end foreach

        return $meetings;
    }//end listMeetings()

    /**
     * Create an action item as a CalDAV VTODO.
     *
     * @param array $taskData ActionItem fields matching ADR-000
     *
     * @return array The created task as a JSON-friendly array
     *
     * @throws Exception If no user or calendar found
     */
    public function createActionItem(array $taskData): array
    {
        $calendarId = $this->findOrCreateDecideskCalendar();
        $uid        = strtoupper(bin2hex(random_bytes(16)));
        $dtstamp    = gmdate('Ymd\THis\Z');

        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//Decidesk//Tasks//EN';

        $vtodo = $vcalendar->add('VTODO', [
            'UID'     => $uid,
            'DTSTAMP' => $dtstamp,
            'SUMMARY' => $taskData['title'] ?? 'Untitled task',
            'STATUS'  => $this->mapTaskStatus($taskData['taskStatus'] ?? 'open'),
        ]);

        if (empty($taskData['description']) === false) {
            $vtodo->add('DESCRIPTION', $taskData['description']);
        }

        if (empty($taskData['dueDate']) === false) {
            $vtodo->add('DUE', new DateTime($taskData['dueDate']));
        }

        if (empty($taskData['assignee']) === false) {
            $vtodo->add('ATTENDEE', 'mailto:' . $taskData['assignee']);
        }

        // X-DECIDESK-* linking properties.
        if (empty($taskData['motionUid']) === false) {
            $vtodo->add('X-DECIDESK-MOTION-UID', $taskData['motionUid']);
        }

        if (empty($taskData['meetingUid']) === false) {
            $vtodo->add('X-DECIDESK-MEETING-UID', $taskData['meetingUid']);
        }

        $calendarData = $vcalendar->serialize();
        $uri          = $uid . '.ics';

        $this->calDavBackend->createCalendarObject($calendarId, $uri, $calendarData);

        $this->logger->info('Decidesk: created action item VTODO', ['uid' => $uid]);

        return $this->vtodoToActionItem($calendarData, (string) $calendarId, $uri);
    }//end createActionItem()

    /**
     * Update an action item VTODO by its CalDAV UID.
     *
     * @param string $calendarUid The VTODO UID
     * @param array  $updates     Fields to update
     *
     * @return array The updated action item array
     *
     * @throws Exception If task not found
     */
    public function updateActionItem(string $calendarUid, array $updates): array
    {
        $found = $this->findVtodoByUid($calendarUid);

        if ($found === null) {
            throw new Exception("ActionItem VTODO '$calendarUid' not found");
        }

        $vcalendar = Reader::read($found['calendardata']);
        $vtodo     = $vcalendar->VTODO;

        if (isset($updates['title']) === true) {
            $vtodo->SUMMARY = $updates['title'];
        }

        if (isset($updates['description']) === true) {
            $vtodo->DESCRIPTION = $updates['description'];
        }

        if (isset($updates['dueDate']) === true) {
            $vtodo->DUE = new DateTime($updates['dueDate']);
        }

        if (isset($updates['taskStatus']) === true) {
            $vtodo->STATUS = $this->mapTaskStatus($updates['taskStatus']);
            if ($updates['taskStatus'] === 'completed') {
                $vtodo->add('COMPLETED', new DateTime());
            }
        }

        $calendarData = $vcalendar->serialize();
        $this->calDavBackend->updateCalendarObject(
            (int) $found['calendarId'],
            $found['uri'],
            $calendarData
        );

        return $this->vtodoToActionItem($calendarData, $found['calendarId'], $found['uri']);
    }//end updateActionItem()

    /**
     * Find or create the DecideDesk calendar for the current user.
     *
     * @return int The calendar ID
     *
     * @throws Exception If no user is logged in
     */
    private function findOrCreateDecideskCalendar(): int
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $principal = 'principals/users/' . $user->getUID();
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);

        // Look for existing DecideDesk calendar.
        foreach ($calendars as $calendar) {
            if (($calendar['{DAV:}displayname'] ?? '') === self::CALENDAR_NAME) {
                return $calendar['id'];
            }
        }

        // Create the DecideDesk calendar.
        $calendarId = $this->calDavBackend->createCalendar($principal, 'decidesk', [
            '{DAV:}displayname'                                        => self::CALENDAR_NAME,
            '{urn:ietf:params:xml:ns:caldav}calendar-description'      => 'Decidesk governance meetings and tasks',
            '{http://apple.com/ns/ical/}calendar-color'                => '#0082c9',
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'
                => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
        ]);

        $this->logger->info('Decidesk: created DecideDesk calendar', ['calendarId' => $calendarId, 'user' => $user->getUID()]);

        return $calendarId;
    }//end findOrCreateDecideskCalendar()

    /**
     * Find a VEVENT by its UID across the user's DecideDesk calendar.
     *
     * @param string $uid The VEVENT UID to find
     *
     * @return array{calendardata: string, calendarId: string, uri: string}|null
     */
    private function findVeventByUid(string $uid): ?array
    {
        return $this->findCalendarObjectByUid($uid, 'VEVENT');
    }//end findVeventByUid()

    /**
     * Find a VTODO by its UID across the user's DecideDesk calendar.
     *
     * @param string $uid The VTODO UID to find
     *
     * @return array{calendardata: string, calendarId: string, uri: string}|null
     */
    private function findVtodoByUid(string $uid): ?array
    {
        return $this->findCalendarObjectByUid($uid, 'VTODO');
    }//end findVtodoByUid()

    /**
     * Find a calendar object by UID and component type.
     *
     * @param string $uid           The UID to search for
     * @param string $componentType VEVENT or VTODO
     *
     * @return array{calendardata: string, calendarId: string, uri: string}|null
     */
    private function findCalendarObjectByUid(string $uid, string $componentType): ?array
    {
        try {
            $calendarId = $this->findOrCreateDecideskCalendar();
        } catch (Exception $e) {
            return null;
        }

        // Try direct URI lookup first (most efficient).
        $uri        = $uid . '.ics';
        $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $uri);

        if ($fullObject !== null
            && empty($fullObject['calendardata']) === false
            && strpos($fullObject['calendardata'], $componentType) !== false
            && strpos($fullObject['calendardata'], $uid) !== false
        ) {
            return [
                'calendardata' => $fullObject['calendardata'],
                'calendarId'   => (string) $calendarId,
                'uri'          => $uri,
            ];
        }

        // Fallback: scan all objects (handles renamed URIs).
        $calendarObjects = $this->calDavBackend->getCalendarObjects($calendarId);

        foreach ($calendarObjects as $calendarObject) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $calendarObject['uri']);
            if ($fullObject === null || empty($fullObject['calendardata']) === true) {
                continue;
            }

            if (strpos($fullObject['calendardata'], $uid) !== false
                && strpos($fullObject['calendardata'], $componentType) !== false
            ) {
                return [
                    'calendardata' => $fullObject['calendardata'],
                    'calendarId'   => (string) $calendarId,
                    'uri'          => $calendarObject['uri'],
                ];
            }
        }//end foreach

        return null;
    }//end findCalendarObjectByUid()

    /**
     * Parse a VEVENT into a Meeting-shaped array.
     *
     * @param string $calendarData Raw iCalendar string
     * @param string $calendarId   Calendar ID
     * @param string $uri          Calendar object URI
     *
     * @return array Meeting array matching ADR-000 Meeting entity
     */
    private function veventToMeeting(string $calendarData, string $calendarId, string $uri): array
    {
        $vcalendar = Reader::read($calendarData);
        $vevent    = $vcalendar->VEVENT;

        $dtstart = null;
        if (isset($vevent->DTSTART) === true) {
            $dtstart = $vevent->DTSTART->getDateTime()->format('c');
        }

        $dtend = null;
        if (isset($vevent->DTEND) === true) {
            $dtend = $vevent->DTEND->getDateTime()->format('c');
        }

        $attendees = [];
        if (isset($vevent->ATTENDEE) === true) {
            foreach ($vevent->ATTENDEE as $attendee) {
                $attendees[] = str_replace('mailto:', '', (string) $attendee);
            }
        }

        return [
            'id'              => isset($vevent->UID) === true ? (string) $vevent->UID : null,
            'calendarId'      => $calendarId,
            'calendarUri'     => $uri,
            'title'           => isset($vevent->SUMMARY) === true ? (string) $vevent->SUMMARY : '',
            'scheduledDate'   => $dtstart,
            'endDate'         => $dtend,
            'location'        => isset($vevent->LOCATION) === true ? (string) $vevent->LOCATION : null,
            'description'     => isset($vevent->DESCRIPTION) === true ? (string) $vevent->DESCRIPTION : null,
            'attendees'       => $attendees,
            'lifecycle'       => $this->getXProperty($vevent, 'X-DECIDESK-LIFECYCLE') ?? 'draft',
            'meetingType'     => $this->getXProperty($vevent, 'X-DECIDESK-MEETING-TYPE') ?? 'regular',
            'meetingMode'     => $this->getXProperty($vevent, 'X-DECIDESK-MEETING-MODE') ?? 'in-person',
            'quorumRequired'  => $this->getXProperty($vevent, 'X-DECIDESK-QUORUM-REQUIRED'),
            'series'          => $this->getXProperty($vevent, 'X-DECIDESK-SERIES'),
            'bodyUid'         => $this->getXProperty($vevent, 'X-DECIDESK-BODY-UID'),
        ];
    }//end veventToMeeting()

    /**
     * Parse a VTODO into an ActionItem-shaped array.
     *
     * @param string $calendarData Raw iCalendar string
     * @param string $calendarId   Calendar ID
     * @param string $uri          Calendar object URI
     *
     * @return array ActionItem array matching ADR-000 ActionItem entity
     */
    private function vtodoToActionItem(string $calendarData, string $calendarId, string $uri): array
    {
        $vcalendar = Reader::read($calendarData);
        $vtodo     = $vcalendar->VTODO;

        $dueDate = null;
        if (isset($vtodo->DUE) === true) {
            $dueDate = $vtodo->DUE->getDateTime()->format('c');
        }

        $completedAt = null;
        if (isset($vtodo->COMPLETED) === true) {
            $completedAt = $vtodo->COMPLETED->getDateTime()->format('c');
        }

        $assignee = null;
        if (isset($vtodo->ATTENDEE) === true) {
            $assignee = str_replace('mailto:', '', (string) $vtodo->ATTENDEE);
        }

        return [
            'id'          => isset($vtodo->UID) === true ? (string) $vtodo->UID : null,
            'calendarId'  => $calendarId,
            'calendarUri' => $uri,
            'title'       => isset($vtodo->SUMMARY) === true ? (string) $vtodo->SUMMARY : '',
            'description' => isset($vtodo->DESCRIPTION) === true ? (string) $vtodo->DESCRIPTION : null,
            'assignee'    => $assignee,
            'dueDate'     => $dueDate,
            'taskStatus'  => $this->reverseMapTaskStatus(isset($vtodo->STATUS) === true ? (string) $vtodo->STATUS : 'NEEDS-ACTION'),
            'completedAt' => $completedAt,
            'motionUid'   => $this->getXProperty($vtodo, 'X-DECIDESK-MOTION-UID'),
            'meetingUid'  => $this->getXProperty($vtodo, 'X-DECIDESK-MEETING-UID'),
        ];
    }//end vtodoToActionItem()

    /**
     * Get an X-property value from a vobject component.
     *
     * @param mixed  $component The vobject component (VEVENT or VTODO)
     * @param string $name      The property name
     *
     * @return string|null The property value or null
     */
    private function getXProperty(mixed $component, string $name): ?string
    {
        if (isset($component->{$name}) === true) {
            return (string) $component->{$name};
        }

        return null;
    }//end getXProperty()

    /**
     * Map DecideDesk task status to CalDAV VTODO STATUS values.
     *
     * @param string $status DecideDesk status: open, in-progress, completed, overdue
     *
     * @return string CalDAV STATUS: NEEDS-ACTION, IN-PROCESS, COMPLETED
     */
    private function mapTaskStatus(string $status): string
    {
        return match ($status) {
            'in-progress' => 'IN-PROCESS',
            'completed'   => 'COMPLETED',
            default       => 'NEEDS-ACTION',
        };
    }//end mapTaskStatus()

    /**
     * Reverse map CalDAV VTODO STATUS to DecideDesk task status.
     *
     * @param string $caldavStatus CalDAV STATUS value
     *
     * @return string DecideDesk status
     */
    private function reverseMapTaskStatus(string $caldavStatus): string
    {
        return match (strtoupper($caldavStatus)) {
            'IN-PROCESS' => 'in-progress',
            'COMPLETED'  => 'completed',
            default      => 'open',
        };
    }//end reverseMapTaskStatus()
}//end class
