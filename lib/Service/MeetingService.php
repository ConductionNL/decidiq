<?php

/**
 * Decidesk Meeting Service
 *
 * Service for meeting lifecycle management: state transitions,
 * role-based authorization, and participant notifications.
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
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for meeting lifecycle management.
 *
 * Provides lifecycle state transitions with role-based authorization
 * and participant notification dispatch.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
 */
class MeetingService
{

    /**
     * Valid lifecycle transitions: current-state => [allowed-transitions => next-state].
     *
     * @var array<string,array<string,string>>
     */
    private const LIFECYCLE_TRANSITIONS = [
        'draft'     => ['schedule' => 'scheduled'],
        'scheduled' => ['open' => 'opened'],
        'opened'    => [
            'pause'   => 'paused',
            'adjourn' => 'adjourned',
            'close'   => 'closed',
        ],
        'paused'    => ['resume' => 'opened'],
        'adjourned' => ['resume' => 'opened'],
    ];

    /**
     * Role values that are treated as chair or secretary.
     *
     * @var array<string>
     */
    private const CHAIR_ROLES = ['chair', 'voorzitter', 'secretary', 'secretaris'];

    /**
     * Constructor for MeetingService.
     *
     * @param ContainerInterface $container   The service container
     * @param LoggerInterface    $logger      The logger
     * @param IL10N              $l10n        The localisation helper
     * @param IUserSession       $userSession The user session
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
    ) {
    }//end __construct()

    /**
     * Transition a meeting to a new lifecycle state.
     *
     * Validates the requested transition against the state machine, enforces
     * chair/secretary authorization, persists the new state, and sends
     * notifications to active participants.
     *
     * @param string $meetingId  The meeting ID
     * @param string $transition The requested transition name
     *
     * @return array<string,mixed> Result with previous and current lifecycle state
     *
     * @throws RuntimeException When transition is invalid (HTTP 400)
     * @throws RuntimeException When the caller is not chair or secretary (HTTP 403)
     * @throws RuntimeException When the meeting is not found (HTTP 404)
     * @throws RuntimeException When OpenRegister is unavailable (HTTP 503)
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
     */
    public function transitionLifecycle(string $meetingId, string $transition): array
    {
        $objectService = $this->getObjectService();

        if ($objectService === null) {
            throw new RuntimeException('Service unavailable: OpenRegister is not installed', 503);
        }

        try {
            $meeting = $objectService->getObject('decidesk', 'meeting', $meetingId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: Failed to retrieve meeting',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Meeting not found', 404);
        }

        // Enforce chair/secretary role before revealing meeting state (info-disclosure guard).
        $participants = $this->getActiveParticipants(objectService: $objectService, meeting: $meeting);
        $this->assertChairOrSecretary(participants: $participants);

        $currentState = ($meeting['lifecycle'] ?? 'draft');

        // Validate the transition against the state machine.
        if (isset(self::LIFECYCLE_TRANSITIONS[$currentState]) === false) {
            throw new RuntimeException(
                $this->l10n->t('Meeting is in a terminal state and cannot be transitioned'),
                400
            );
        }

        $allowedTransitions = self::LIFECYCLE_TRANSITIONS[$currentState];
        if (isset($allowedTransitions[$transition]) === false) {
            $allowed = implode(', ', array_keys($allowedTransitions));
            throw new RuntimeException(
                $this->l10n->t(
                    'Invalid transition "%1$s" from state "%2$s". Allowed: %3$s',
                    [$transition, $currentState, $allowed]
                ),
                400
            );
        }

        // For 'schedule' transition, require scheduledDate to be set.
        if ($transition === 'schedule') {
            $scheduledDate = ($meeting['scheduledDate'] ?? '');
            if ($scheduledDate === '') {
                throw new RuntimeException(
                    $this->l10n->t('A scheduled date is required before scheduling a meeting'),
                    400
                );
            }
        }

        $newState = $allowedTransitions[$transition];

        // Save the new lifecycle state.
        $objectService->saveObject(
            'decidesk',
            'meeting',
            array_merge($meeting, ['lifecycle' => $newState])
        );

        // Send notifications to active participants.
        $meetingTitle = ($meeting['title'] ?? $this->l10n->t('Meeting'));
        $this->notifyParticipants(
            participants: $participants,
            meetingTitle: $meetingTitle,
            transition: $transition,
            newState: $newState
        );

        $this->logger->info(
            'Decidesk: Meeting lifecycle transitioned',
            [
                'meetingId'     => $meetingId,
                'previousState' => $currentState,
                'transition'    => $transition,
                'currentState'  => $newState,
            ]
        );

        return [
            'success'       => true,
            'previousState' => $currentState,
            'currentState'  => $newState,
            'transition'    => $transition,
        ];

    }//end transitionLifecycle()

    /**
     * Get the calling user's role for a meeting.
     *
     * Returns 'none' when OpenRegister is unavailable or the user is not
     * an active participant of the meeting's governance body.
     *
     * @param string $meetingId The meeting ID
     *
     * @return array<string,string> Array with a 'role' key
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
     */
    public function getUserRole(string $meetingId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['role' => 'none'];
        }

        $userId = $this->userSession->getUser()?->getUID() ?? '';
        if ($userId === '') {
            return ['role' => 'none'];
        }

        try {
            $meeting      = $objectService->getObject('decidesk', 'meeting', $meetingId);
            $participants = $this->getActiveParticipants(objectService: $objectService, meeting: $meeting);
        } catch (\Throwable) {
            return ['role' => 'none'];
        }

        foreach ($participants as $participant) {
            if (($participant['owner'] ?? '') !== $userId) {
                continue;
            }

            $role = strtolower($participant['role'] ?? ($participant['function'] ?? 'member'));
            return ['role' => $role];
        }

        return ['role' => 'none'];

    }//end getUserRole()

    /**
     * Send notifications to active participants about a lifecycle transition.
     *
     * @param array<int,array<string,mixed>> $participants Active participants
     * @param string                         $meetingTitle The meeting title
     * @param string                         $transition   The transition name
     * @param string                         $newState     The new lifecycle state
     *
     * @return void
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
     */
    private function notifyParticipants(
        array $participants,
        string $meetingTitle,
        string $transition,
        string $newState,
    ): void {
        $notificationService = $this->getNotificationService();
        if ($notificationService === null) {
            return;
        }

        $stateLabel = ucfirst($newState);
        foreach ($participants as $participant) {
            $owner = ($participant['owner'] ?? '');
            if ($owner === '') {
                continue;
            }

            $notificationService->sendNotification(
                $owner,
                $meetingTitle.' — '.ucfirst($transition),
                $this->l10n->t(
                    'The meeting "%1$s" has been %2$s (now: %3$s).',
                    [$meetingTitle, $transition, $stateLabel]
                )
            );
        }

    }//end notifyParticipants()

    /**
     * Get active participants for a meeting's governance body.
     *
     * @param object              $objectService The object service
     * @param array<string,mixed> $meeting       The meeting data
     *
     * @return array<int,array<string,mixed>> Active participants
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
     */
    private function getActiveParticipants(object $objectService, array $meeting): array
    {
        $governanceBodyId = '';
        $relations        = ($meeting['relations'] ?? []);
        foreach ($relations as $relation) {
            if (($relation['schema'] ?? '') === 'governance-body') {
                $governanceBodyId = ($relation['id'] ?? ($relation['uuid'] ?? ''));
                break;
            }
        }

        if ($governanceBodyId === '') {
            return [];
        }

        $participants = $objectService->getObjects(
            'decidesk',
            'participant',
            ['governanceBody' => $governanceBodyId]
        );

        // Filter to active participants only (leftAt is null/empty).
        return array_values(
            array_filter(
                $participants,
                static function (array $participant): bool {
                    return empty($participant['leftAt']);
                }
            )
        );

    }//end getActiveParticipants()

    /**
     * Assert that the current user holds a chair or secretary role.
     *
     * Throws with HTTP code 403 when the user is not authenticated or does
     * not hold a qualifying role.
     *
     * @param array<int,array<string,mixed>> $participants Active participants for the meeting
     *
     * @return void
     *
     * @throws RuntimeException With code 403 when not authorised
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1
     */
    private function assertChairOrSecretary(array $participants): void
    {
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        if ($userId === '') {
            throw new RuntimeException('Forbidden: unauthenticated request', 403);
        }

        foreach ($participants as $participant) {
            if (($participant['owner'] ?? '') !== $userId) {
                continue;
            }

            $role = strtolower($participant['role'] ?? ($participant['function'] ?? ''));
            if (in_array($role, self::CHAIR_ROLES, true) === true) {
                return;
            }
        }

        throw new RuntimeException('Forbidden: chair or secretary role required', 403);

    }//end assertChairOrSecretary()

    /**
     * Get the ObjectService from the container, or null if unavailable.
     *
     * Returns null when OpenRegister is not installed so callers can
     * degrade gracefully instead of throwing a container exception.
     *
     * @return object|null The OpenRegister ObjectService, or null
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable) {
            return null;
        }

    }//end getObjectService()

    /**
     * Get the NotificationService from the container, or null if unavailable.
     *
     * @return object|null The OpenRegister NotificationService, or null
     */
    private function getNotificationService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\NotificationService');
        } catch (\Throwable) {
            return null;
        }

    }//end getNotificationService()
}//end class
