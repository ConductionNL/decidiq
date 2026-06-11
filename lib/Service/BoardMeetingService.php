<?php
/**
 * Decidesk Board Meeting Service
 *
 * Phase 2 service for the BoardMeeting lifecycle: schedule, sendNotice,
 * runLifecycleTransition. The CalDAV wrapper integration is stubbed at the
 * notice-sent → materials-distributed transition; deeper iCal generation lives
 * in CalDavService (task 7).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns the BoardMeeting lifecycle state machine.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
 */
class BoardMeetingService
{

    /**
     * Allowed BoardMeeting lifecycle transitions. Mirrors the schema enum.
     *
     * @var array<string, array{from: string[], to: string}>
     */
    private const TRANSITIONS = [
        'send-notice'          => ['from' => ['scheduled'], 'to' => 'notice-sent'],
        'distribute-materials' => ['from' => ['notice-sent'], 'to' => 'materials-distributed'],
        'open'                 => ['from' => ['materials-distributed', 'scheduled'], 'to' => 'in-session'],
        'adjourn'              => ['from' => ['in-session'], 'to' => 'adjourned'],
        'close'                => ['from' => ['in-session', 'adjourned'], 'to' => 'closed'],
        'sign-minutes'         => ['from' => ['closed'], 'to' => 'minutes-signed'],
    ];

    /**
     * Allowed meeting-type enum values.
     *
     * @var string[]
     */
    public const MEETING_TYPES = [
        'regular',
        'extraordinary',
        'strategy-day',
        'closed-session',
        'executive-session',
    ];

    /**
     * Allowed format enum values.
     *
     * @var string[]
     */
    public const FORMATS = ['in-person', 'remote', 'hybrid'];

    /**
     * Constructor for BoardMeetingService.
     *
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
     * @param AuditLogService    $auditLogService Audit log dependency for notice + transition events
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLogService,
    ) {
    }//end __construct()

    /**
     * Schedule a new board meeting in the `scheduled` lifecycle state.
     *
     * @param string               $boardId UUID of the parent board
     * @param array<string, mixed> $data    Meeting payload (date, type, format, language, ...)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
     *
     * @return array{success: bool, meeting: array|null, message: string}
     */
    public function schedule(string $boardId, array $data): array
    {
        if (isset($data['meetingDate']) === false || trim((string) $data['meetingDate']) === '') {
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'meetingDate is required.',
            ];
        }

        if (isset($data['meetingType']) === true && in_array($data['meetingType'], self::MEETING_TYPES, true) === false) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Unknown meetingType: '.$data['meetingType'],
            ];
        }

        if (isset($data['format']) === true && in_array($data['format'], self::FORMATS, true) === false) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Unknown format: '.$data['format'],
            ];
        }

        $row = array_merge(
            [
                'meetingType' => 'regular',
                'format'      => 'in-person',
                'language'    => 'nl',
            ],
            $data,
            [
                'boardKoppeling' => $boardId,
                'status'         => 'scheduled',
            ]
        );

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $row,
                register: 'decidesk',
                schema: 'board-meeting'
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardMeetingService::schedule failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Failed to schedule meeting.',
            ];
        }

        $serialized = $row;
        if (is_object($saved) === true) {
            $serialized = (array) $saved->jsonSerialize();
        }

        $this->logger->info(
            'Decidesk: board meeting scheduled',
            ['boardId' => $boardId, 'meetingDate' => $row['meetingDate']]
        );

        return [
            'success' => true,
            'meeting' => $serialized,
            'message' => 'Board meeting scheduled.',
        ];

    }//end schedule()

    /**
     * Send the formal meeting notice. Transitions the meeting from scheduled
     * to notice-sent, stamps `noticeSentDate`, and writes a notice-sent entry
     * to the audit log.
     *
     * @param string $meetingId UUID of the board meeting
     * @param string $actor     Acting user UID (for the audit log)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
     *
     * @return array{success: bool, meeting: array|null, message: string}
     */
    public function sendNotice(string $meetingId, string $actor): array
    {
        $result = $this->runLifecycleTransition(meetingId: $meetingId, action: 'send-notice');
        if ($result['success'] === false) {
            return $result;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'board-meeting');
            if ($entity !== null) {
                $current = (array) $entity->jsonSerialize();
                if (method_exists($entity, 'getObject') === true) {
                    $current = $entity->getObject();
                }

                $patched = array_merge($current, ['noticeSentDate' => gmdate('Y-m-d\TH:i:s\Z')]);

                $saved = $objectService->saveObject(
                    object: $patched,
                    register: 'decidesk',
                    schema: 'board-meeting',
                    uuid: $meetingId
                );

                if (is_object($saved) === true) {
                    $result['meeting'] = (array) $saved->jsonSerialize();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: failed to stamp noticeSentDate; transition retained',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
        }//end try

        $this->auditLogService->append(
            actor: $actor,
            action: 'notice-sent',
            objectUids: [$meetingId]
        );

        return $result;

    }//end sendNotice()

    /**
     * Run a single BoardMeeting lifecycle transition.
     *
     * @param string $meetingId UUID of the meeting
     * @param string $action    One of the keys in self::TRANSITIONS
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
     *
     * @return array{success: bool, meeting: array|null, message: string}
     */
    public function runLifecycleTransition(string $meetingId, string $action): array
    {
        if (isset(self::TRANSITIONS[$action]) === false) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Unknown action: '.$action,
            ];
        }

        $transition = self::TRANSITIONS[$action];

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'board-meeting');
            if ($entity === null) {
                return [
                    'success' => false,
                    'meeting' => null,
                    'message' => 'Meeting not found.',
                ];
            }

            $current = (array) $entity->jsonSerialize();
            if (method_exists($entity, 'getObject') === true) {
                $current = $entity->getObject();
            }

            $currentStatus = (string) ($current['status'] ?? 'scheduled');
            if (in_array($currentStatus, $transition['from'], true) === false) {
                return [
                    'success' => false,
                    'meeting' => null,
                    'message' => "Cannot '".$action."' a meeting in '".$currentStatus."' state.",
                ];
            }

            $merged = array_merge($current, ['status' => $transition['to']]);
            $saved  = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'board-meeting',
                uuid: $meetingId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardMeetingService::runLifecycleTransition failed',
                ['meetingId' => $meetingId, 'action' => $action, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Failed to transition meeting.',
            ];
        }//end try

        $meetingPayload = $merged;
        if (is_object($saved) === true) {
            $meetingPayload = (array) $saved->jsonSerialize();
        }

        return [
            'success' => true,
            'meeting' => $meetingPayload,
            'message' => "Meeting transitioned to '".$transition['to']."'.",
        ];

    }//end runLifecycleTransition()

    /**
     * Get the list of valid actions for the given meeting status.
     *
     * @param string $currentStatus Current meeting status
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-meeting-service
     *
     * @return string[]
     */
    public function getAvailableActions(string $currentStatus): array
    {
        $available = [];
        foreach (self::TRANSITIONS as $action => $transition) {
            if (in_array($currentStatus, $transition['from'], true) === true) {
                $available[] = $action;
            }
        }

        return $available;

    }//end getAvailableActions()
}//end class
