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
     * Default statutory notice period in days before the meeting date
     * (BW 2:225 BV / typical ALV statutes). Overridable per meeting via
     * the additive `noticePeriodDays` schema property.
     *
     * @var int
     */
    public const DEFAULT_NOTICE_PERIOD_DAYS = 15;

    /**
     * Warn when the convocation is sent within this many days of the deadline.
     *
     * @var int
     */
    public const DEADLINE_WARNING_DAYS = 3;

    /**
     * Constructor for BoardMeetingService.
     *
     * @param ContainerInterface $container          The DI container
     * @param LoggerInterface    $logger             The logger
     * @param AuditLogService    $auditLogService    Audit log dependency for notice + transition events
     * @param BoardMemberService $boardMemberService Resolves the board's members for per-recipient delivery tracking
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AuditLogService $auditLogService,
        private readonly BoardMemberService $boardMemberService,
    ) {
    }//end __construct()

    /**
     * Compute the statutory notice deadline for a meeting and the warnings
     * that apply when sending the convocation *now*.
     *
     * Pure (clock injectable) so PHPUnit can pin `$now`: the deadline is
     * `meetingDate - noticePeriodDays` (default 15 — BW 2:225 / BW 2:38);
     * sending after the deadline or within DEADLINE_WARNING_DAYS of it
     * produces a warning per the meeting-management convocation scenarios.
     *
     * @param array<string, mixed>    $meeting Board-meeting payload (meetingDate, noticePeriodDays)
     * @param \DateTimeImmutable|null $now     Send moment; defaults to the current UTC time
     *
     * @spec openspec/specs/meeting-management/spec.md
     *
     * @return array{deadline: string|null, daysUntilDeadline: int|null, warnings: string[]}
     */
    public function getNoticeDeadlineInfo(array $meeting, ?\DateTimeImmutable $now=null): array
    {
        $meetingDateRaw = (string) ($meeting['meetingDate'] ?? ($meeting['meetingStart'] ?? ''));
        if ($meetingDateRaw === '') {
            return [
                'deadline'          => null,
                'daysUntilDeadline' => null,
                'warnings'          => [],
            ];
        }

        try {
            $meetingDate = new \DateTimeImmutable(substr($meetingDateRaw, 0, 10).' 00:00:00', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return [
                'deadline'          => null,
                'daysUntilDeadline' => null,
                'warnings'          => [],
            ];
        }

        $periodDays = (int) ($meeting['noticePeriodDays'] ?? self::DEFAULT_NOTICE_PERIOD_DAYS);
        if ($periodDays < 0) {
            $periodDays = self::DEFAULT_NOTICE_PERIOD_DAYS;
        }

        $deadline = $meetingDate->sub(new \DateInterval('P'.$periodDays.'D'));
        $now      = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $today    = new \DateTimeImmutable($now->format('Y-m-d').' 00:00:00', new \DateTimeZone('UTC'));

        $daysUntilDeadline = (int) $today->diff($deadline)->format('%r%a');

        $warnings = [];
        if ($daysUntilDeadline < 0) {
            $warnings[] = sprintf(
                'The statutory notice deadline (%s, %d days before the meeting) has already passed.',
                $deadline->format('Y-m-d'),
                $periodDays
            );
        } else if ($daysUntilDeadline <= self::DEADLINE_WARNING_DAYS) {
            $warnings[] = sprintf(
                'The convocation is sent within %d day(s) of the statutory notice deadline (%s).',
                self::DEADLINE_WARNING_DAYS,
                $deadline->format('Y-m-d')
            );
        }

        return [
            'deadline'          => $deadline->format('Y-m-d'),
            'daysUntilDeadline' => $daysUntilDeadline,
            'warnings'          => $warnings,
        ];

    }//end getNoticeDeadlineInfo()

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
     * to notice-sent, stamps `noticeSentDate`, records one per-recipient
     * delivery entry per board member (`noticeDeliveries` — BW 2:225 / BW 2:38
     * proof of notice), computes statutory-deadline warnings, and writes a
     * notice-sent entry (with recipient count) to the audit log.
     *
     * @param string $meetingId UUID of the board meeting
     * @param string $actor     Acting user UID (for the audit log)
     *
     * @spec openspec/specs/meeting-management/spec.md
     *
     * @return array{success: bool, meeting: array|null, message: string, warnings?: string[], deliveries?: array<int, array<string, mixed>>}
     */
    public function sendNotice(string $meetingId, string $actor): array
    {
        $result = $this->runLifecycleTransition(meetingId: $meetingId, action: 'send-notice');
        if ($result['success'] === false) {
            return $result;
        }

        $warnings   = [];
        $deliveries = [];

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'board-meeting');
            if ($entity !== null) {
                $current = (array) $entity->jsonSerialize();
                if (method_exists($entity, 'getObject') === true) {
                    $current = $entity->getObject();
                }

                $deadlineInfo = $this->getNoticeDeadlineInfo(meeting: $current);
                $warnings     = $deadlineInfo['warnings'];

                $sentAt     = gmdate('Y-m-d\TH:i:s\Z');
                $deliveries = $this->buildNoticeDeliveries(meeting: $current, sentAt: $sentAt);

                $patched = array_merge(
                    $current,
                    [
                        'noticeSentDate'   => $sentAt,
                        'noticeDeliveries' => $deliveries,
                    ]
                );

                $saved = $objectService->saveObject(
                    object: $patched,
                    register: 'decidesk',
                    schema: 'board-meeting',
                    uuid: $meetingId
                );

                if (is_object($saved) === true) {
                    $result['meeting'] = (array) $saved->jsonSerialize();
                }
            }//end if
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: failed to stamp noticeSentDate; transition retained',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
        }//end try

        $this->auditLogService->append(
            actor: $actor,
            action: 'notice-sent',
            objectUids: [$meetingId],
            payload: ['recipients' => count($deliveries)]
        );

        $result['warnings']   = $warnings;
        $result['deliveries'] = $deliveries;

        return $result;

    }//end sendNotice()

    /**
     * Build the per-recipient delivery entries for a notice send.
     *
     * Resolves the board's members through BoardMemberService and records
     * one `{recipient, displayName, role, channel, status, sentAt}` entry
     * each. Members whose term has ended before the meeting date are still
     * included when their `termEndDate` is in the future or unset.
     *
     * @param array<string, mixed> $meeting Board-meeting payload (board / boardKoppeling)
     * @param string               $sentAt  Send timestamp (ISO-8601 UTC)
     *
     * @spec openspec/specs/meeting-management/spec.md
     *
     * @return array<int, array<string, mixed>> Delivery entries (empty when the board cannot be resolved)
     */
    private function buildNoticeDeliveries(array $meeting, string $sentAt): array
    {
        $boardId = (string) ($meeting['boardKoppeling'] ?? ($meeting['board'] ?? ''));
        if ($boardId === '') {
            return [];
        }

        $listing = $this->boardMemberService->listForBoard(boardId: $boardId);
        if ($listing['success'] === false) {
            return [];
        }

        $deliveries = [];
        foreach ($listing['members'] as $member) {
            $memberId = (string) ($member['id'] ?? ($member['@self']['id'] ?? ''));
            if ($memberId === '') {
                continue;
            }

            $deliveries[] = [
                'recipient'   => $memberId,
                'displayName' => (string) ($member['displayName'] ?? ($member['person'] ?? ($member['persoonKoppeling'] ?? ''))),
                'role'        => (string) ($member['role'] ?? ($member['rol'] ?? '')),
                'channel'     => 'portal',
                'status'      => 'sent',
                'sentAt'      => $sentAt,
            ];
        }

        return $deliveries;

    }//end buildNoticeDeliveries()

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

        // Activity feed (fail-soft): board meeting lifecycle transition.
        // @spec openspec/specs/nextcloud-integration/spec.md.
        try {
            $this->container->get(\OCA\Decidesk\Service\ActivityPublisherService::class)->publishGovernanceEvent(
                subject: \OCA\Decidesk\Activity\DecideskProvider::SUBJECT_MEETING_TRANSITION,
                title: (string) ($meetingPayload['title'] ?? $meetingId),
                status: (string) $transition['to'],
                objectType: 'board-meeting',
                objectUuid: $meetingId,
                segment: 'board-meetings'
            );
        } catch (\Throwable $activityError) {
            $this->logger->debug('Decidesk: activity publish skipped', ['error' => $activityError->getMessage()]);
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
