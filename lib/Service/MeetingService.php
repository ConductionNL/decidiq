<?php

/**
 * Decidesk Meeting Service
 *
 * Service for managing meeting lifecycle state transitions.
 * Meetings are stored as CalDAV VEVENTs (ADR-002). The lifecycle state
 * is persisted as X-DECIDESK-LIFECYCLE on the VEVENT.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for managing meeting lifecycle state transitions.
 *
 * Implements the state machine defined in design.md:
 *   draft → scheduled → opened ↔ paused → adjourned → (re-)opened → closed
 *
 * Storage: CalDAV VEVENT with X-DECIDESK-LIFECYCLE (ADR-002).
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
 */
class MeetingService
{

    /**
     * Valid lifecycle transitions keyed by action name.
     *
     * Each entry defines:
     * - `from`: the set of states from which this action is permitted
     * - `to`:   the resulting state after the transition
     *
     * @var array<string, array{from: string[], to: string}>
     */
    private const TRANSITIONS = [
        'schedule' => ['from' => ['draft'],                                        'to' => 'scheduled'],
        'open'     => ['from' => ['scheduled', 'adjourned'],                       'to' => 'opened'],
        'pause'    => ['from' => ['opened'],                                        'to' => 'paused'],
        'resume'   => ['from' => ['paused'],                                        'to' => 'opened'],
        'adjourn'  => ['from' => ['opened', 'paused'],                             'to' => 'adjourned'],
        'close'    => ['from' => ['scheduled', 'opened', 'paused', 'adjourned'],   'to' => 'closed'],
    ];

    /**
     * Constructor for MeetingService.
     *
     * @param CalDavService   $calDavService The CalDAV service for VEVENT operations
     * @param LoggerInterface $logger        The logger
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     */
    public function __construct(
        private readonly CalDavService $calDavService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the list of valid action names for a given lifecycle state.
     *
     * @param string $currentLifecycle The current lifecycle value of the meeting
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     *
     * @return string[] List of action names the caller may invoke
     */
    public function getAvailableActions(string $currentLifecycle): array
    {
        $available = [];
        foreach (self::TRANSITIONS as $action => $transition) {
            if (in_array($currentLifecycle, $transition['from'], true) === true) {
                $available[] = $action;
            }
        }

        return $available;

    }//end getAvailableActions()

    /**
     * Create a new meeting as a CalDAV VEVENT.
     *
     * @param array $meetingData Meeting fields per ADR-000
     *
     * @return array{success: bool, meeting: array|null, message: string}
     */
    public function create(array $meetingData): array
    {
        try {
            $meeting = $this->calDavService->createMeeting($meetingData);

            return [
                'success' => true,
                'meeting' => $meeting,
                'message' => 'Meeting created.',
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting creation failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Failed to create meeting. See server log for details.',
            ];
        }

    }//end create()

    /**
     * Get a meeting by CalDAV UID.
     *
     * @param string $meetingUid CalDAV UID of the meeting VEVENT
     *
     * @return array|null The meeting array or null
     */
    public function get(string $meetingUid): ?array
    {
        return $this->calDavService->getMeeting($meetingUid);

    }//end get()

    /**
     * List meetings, optionally filtered by governance body.
     *
     * @param string|null $bodyUid Optional GovernanceBody UUID filter
     *
     * @return array Array of meeting arrays
     */
    public function list(?string $bodyUid = null): array
    {
        return $this->calDavService->listMeetings($bodyUid);

    }//end list()

    /**
     * Apply a lifecycle transition to a meeting VEVENT.
     *
     * Validates that `$action` is a known transition and that the meeting's
     * current lifecycle state permits the transition, then updates the
     * X-DECIDESK-LIFECYCLE property on the VEVENT via CalDavService.
     *
     * @param string $meetingUid CalDAV UID of the meeting VEVENT
     * @param string $action     Transition action: schedule|open|pause|resume|adjourn|close
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     *
     * @return array{success: bool, meeting: array|null, message: string}
     */
    public function transition(string $meetingUid, string $action): array
    {
        if (isset(self::TRANSITIONS[$action]) === false) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Unknown action. Valid actions: ' . implode(', ', array_keys(self::TRANSITIONS)) . '.',
            ];
        }

        $transition = self::TRANSITIONS[$action];

        try {
            $meeting = $this->calDavService->getMeeting($meetingUid);

            if ($meeting === null) {
                return [
                    'success' => false,
                    'meeting' => null,
                    'message' => "Meeting '$meetingUid' not found.",
                ];
            }

            $currentLifecycle = $meeting['lifecycle'] ?? 'draft';

            if (in_array($currentLifecycle, $transition['from'], true) === false) {
                return [
                    'success' => false,
                    'meeting' => null,
                    'message' => "Cannot '$action' a meeting in '$currentLifecycle' state. "
                        . "Allowed from: " . implode(', ', $transition['from']) . ".",
                ];
            }

            $updated = $this->calDavService->updateMeeting($meetingUid, [
                'lifecycle' => $transition['to'],
            ]);

            $this->logger->info(
                'Decidesk: meeting lifecycle transitioned',
                ['uid' => $meetingUid, 'action' => $action, 'to' => $transition['to']]
            );

            return [
                'success' => true,
                'meeting' => $updated,
                'message' => "Meeting transitioned to '{$transition['to']}'.",
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting lifecycle transition failed',
                ['uid' => $meetingUid, 'action' => $action, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Transition failed. See server log for details.',
            ];
        }//end try

    }//end transition()
}//end class
