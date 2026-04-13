<?php

/**
 * Decidesk Agenda Service
 *
 * Service for agenda management: publication, BOB phase transitions,
 * hamerstukken processing, and agenda item reordering.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for agenda management operations.
 *
 * Provides agenda publication, BOB phase advancement, hamerstukken
 * batch processing, and agenda item reordering.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
 */
class AgendaService
{

    /**
     * Valid BOB phase transitions in order.
     *
     * @var array<string,string>
     */
    private const BOB_PHASES = [
        'voorstel'        => 'beeldvorming',
        'beeldvorming'    => 'oordeelsvorming',
        'oordeelsvorming' => 'besluitvorming',
        'besluitvorming'  => 'afgerond',
    ];

    /**
     * Role values that are treated as chair or secretary.
     *
     * @var array<string>
     */
    private const CHAIR_ROLES = ['chair', 'voorzitter', 'secretary', 'secretaris'];

    /**
     * Constructor for AgendaService.
     *
     * @param ContainerInterface $container   The service container
     * @param LoggerInterface    $logger      The logger
     * @param IL10N              $l10n        The localisation helper
     * @param IUserSession       $userSession The user session
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
    ) {
    }//end __construct()

    /**
     * Publish an agenda for a meeting.
     *
     * Validates that at least one agenda item exists, verifies that the
     * calling user holds a chair or secretary role, then sends notifications
     * to all active participants.
     *
     * @param string $meetingId The meeting ID
     *
     * @return array<string,mixed> Result with success flag and notification count
     *
     * @throws \RuntimeException When the agenda has no items (HTTP 400)
     * @throws \RuntimeException When the caller is not chair or secretary (HTTP 403)
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function publishAgenda(string $meetingId): array
    {
        $objectService = $this->getObjectService();

        if ($objectService === null) {
            return [
                'success'       => true,
                'message'       => $this->l10n->t('Agenda published'),
                'notifications' => 0,
            ];
        }

        // Fetch meeting and participants first so auth runs before any data is revealed.
        $meeting      = $objectService->getObject('decidesk', 'meeting', $meetingId);
        $participants = $this->getActiveParticipants(objectService: $objectService, meeting: $meeting);

        // Enforce chair/secretary role before revealing meeting state (info-disclosure guard).
        $this->assertChairOrSecretary(participants: $participants);

        // Validate: at least one agenda item must exist (spec §1.1).
        $agendaItems = $objectService->getObjects(
            'decidesk',
            'agenda-item',
            ['meeting' => $meetingId]
        );

        if (empty($agendaItems) === true) {
            throw new \RuntimeException('Een agenda moet minimaal één agendapunt bevatten');
        }

        // Send notifications to each active participant.
        $meetingTitle  = ($meeting['title'] ?? 'Vergadering');
        $scheduledDate = ($meeting['scheduledDate'] ?? '');

        $notificationService = $this->getNotificationService();
        if ($notificationService !== null) {
            foreach ($participants as $participant) {
                $notificationService->sendNotification(
                    ($participant['owner'] ?? ''),
                    $meetingTitle.' — '.$this->l10n->t('Agenda published'),
                    $this->l10n->t(
                        'The agenda for %1$s (%2$s) has been published.',
                        [$meetingTitle, $scheduledDate]
                    )
                );
            }
        }

        // Update calendar event via CalendarEventService.
        try {
            $calendarService = $this->getCalendarEventService();
            if ($calendarService !== null) {
                $calendarService->updateEvent($meetingId, ['agenda_published' => true]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: CalendarEventService not available',
                ['exception' => $e->getMessage()]
            );
        }

        $this->logger->info('Decidesk: Agenda published for meeting', ['meetingId' => $meetingId]);

        return [
            'success'       => true,
            'message'       => $this->l10n->t('Agenda published'),
            'notifications' => count($participants),
        ];

    }//end publishAgenda()

    /**
     * Advance the BOB phase of an agenda item.
     *
     * Transitions the status through: voorstel → beeldvorming →
     * oordeelsvorming → besluitvorming → afgerond. Verifies that the
     * calling user is chair or secretary for the item's meeting when the
     * meeting ID can be resolved from the item.
     *
     * @param string $agendaItemId The agenda item ID
     *
     * @return array<string,mixed> Result with the new phase
     *
     * @throws \RuntimeException When the item is informational (HTTP 400)
     * @throws \RuntimeException When the item is already at the final phase (HTTP 400)
     * @throws \RuntimeException When the caller is not chair or secretary (HTTP 403)
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function advanceBobPhase(string $agendaItemId): array
    {
        $objectService = $this->getObjectService();

        if ($objectService === null) {
            return [
                'success'       => true,
                'previousPhase' => 'voorstel',
                'currentPhase'  => 'beeldvorming',
            ];
        }

        try {
            $item = $objectService->getObject('decidesk', 'agenda-item', $agendaItemId);
        } catch (\Throwable) {
            throw new \RuntimeException('Agenda item not found', 404);
        }

        // Enforce chair/secretary role — deny access when meeting cannot be resolved.
        $meetingId = $this->getMeetingIdFromItem(item: $item);
        if ($meetingId === '') {
            // Orphan items (no meeting link) are not manipulatable without explicit governance decision.
            throw new \RuntimeException('Forbidden: agenda item is not linked to a meeting', 403);
        }

        $meeting      = $objectService->getObject('decidesk', 'meeting', $meetingId);
        $participants = $this->getActiveParticipants(objectService: $objectService, meeting: $meeting);
        $this->assertChairOrSecretary(participants: $participants);

        $currentPhase = ($item['status'] ?? 'beeldvorming');

        // Informational items cannot advance BOB phases.
        if (($item['itemType'] ?? '') === 'informational') {
            throw new \RuntimeException('Informatieve agendapunten hebben geen BOB-fasering');
        }

        if (isset(self::BOB_PHASES[$currentPhase]) === false) {
            throw new \RuntimeException('Agendapunt is al in de laatste fase');
        }

        $nextPhase = self::BOB_PHASES[$currentPhase];

        $objectService->saveObject(
            'decidesk',
            'agenda-item',
            array_merge($item, ['status' => $nextPhase])
        );

        $this->logger->info(
            'Decidesk: BOB phase advanced',
            [
                'previousPhase' => $currentPhase,
                'currentPhase'  => $nextPhase,
                'agendaItemId'  => $agendaItemId,
            ]
        );

        return [
            'success'       => true,
            'previousPhase' => $currentPhase,
            'currentPhase'  => $nextPhase,
        ];

    }//end advanceBobPhase()

    /**
     * Process hamerstukken (consent items) for a meeting.
     *
     * Verifies that the calling user is chair or secretary, then
     * bulk-updates all items tagged 'hamerstuk' to status 'afgerond'.
     *
     * @param string $meetingId The meeting ID
     *
     * @return array<string,mixed> Result with count of processed items
     *
     * @throws \RuntimeException When the caller is not chair or secretary (HTTP 403)
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function processHamerstukken(string $meetingId): array
    {
        $objectService = $this->getObjectService();

        if ($objectService === null) {
            return ['success' => true, 'count' => 0];
        }

        $meeting      = $objectService->getObject('decidesk', 'meeting', $meetingId);
        $participants = $this->getActiveParticipants(objectService: $objectService, meeting: $meeting);
        $this->assertChairOrSecretary(participants: $participants);

        $agendaItems = $objectService->getObjects(
            'decidesk',
            'agenda-item',
            [
                'meeting' => $meetingId,
                'tags'    => 'hamerstuk',
            ]
        );

        $count = 0;
        foreach ($agendaItems as $item) {
            $objectService->saveObject(
                'decidesk',
                'agenda-item',
                array_merge($item, ['status' => 'afgerond'])
            );
            $count++;
        }

        $this->logger->info(
            'Decidesk: Hamerstukken processed',
            [
                'count'     => $count,
                'meetingId' => $meetingId,
            ]
        );

        return [
            'success' => true,
            'count'   => $count,
        ];

    }//end processHamerstukken()

    /**
     * Reorder agenda items for a meeting.
     *
     * Verifies that the calling user is chair or secretary, then assigns
     * sequential orderNumber values (1..n) to agenda items based on the
     * provided ordered array of IDs.
     *
     * @param string        $meetingId  The meeting ID
     * @param array<string> $orderedIds Ordered array of agenda item IDs
     *
     * @return array<string,mixed> Result with success flag
     *
     * @throws \RuntimeException When the caller is not chair or secretary (HTTP 403)
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function reorderItems(string $meetingId, array $orderedIds): array
    {
        $objectService = $this->getObjectService();

        if ($objectService === null) {
            return ['success' => true, 'count' => 0];
        }

        $meeting      = $objectService->getObject('decidesk', 'meeting', $meetingId);
        $participants = $this->getActiveParticipants(objectService: $objectService, meeting: $meeting);
        $this->assertChairOrSecretary(participants: $participants);

        $agendaItems = $objectService->getObjects(
            'decidesk',
            'agenda-item',
            ['meeting' => $meetingId]
        );

        // Index items by ID.
        $itemsById = [];
        foreach ($agendaItems as $item) {
            $id = ($item['id'] ?? ($item['uuid'] ?? ''));
            $itemsById[$id] = $item;
        }

        // Assign sequential orderNumber based on provided order.
        $orderNumber = 1;
        foreach ($orderedIds as $id) {
            if (isset($itemsById[$id]) === true) {
                $objectService->saveObject(
                    'decidesk',
                    'agenda-item',
                    array_merge($itemsById[$id], ['orderNumber' => $orderNumber])
                );
                $orderNumber++;
            }
        }

        return [
            'success' => true,
            'count'   => ($orderNumber - 1),
        ];

    }//end reorderItems()

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
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
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
     * Get active participants for a meeting's governance body.
     *
     * @param object              $objectService The object service
     * @param array<string,mixed> $meeting       The meeting data
     *
     * @return array<int,array<string,mixed>> Active participants
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
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
     * @throws \RuntimeException With code 403 when not authorised
     */
    private function assertChairOrSecretary(array $participants): void
    {
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        if ($userId === '') {
            throw new \RuntimeException('Forbidden: unauthenticated request', 403);
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

        throw new \RuntimeException('Forbidden: chair or secretary role required', 403);

    }//end assertChairOrSecretary()

    /**
     * Extract the meeting ID from an agenda item object.
     *
     * Checks the `meeting` string field first, then falls back to the
     * item's relations array looking for schema 'meeting'.
     *
     * @param array<string,mixed> $item The agenda item data
     *
     * @return string The meeting ID, or empty string if not resolvable
     */
    private function getMeetingIdFromItem(array $item): string
    {
        if (isset($item['meeting']) === true && is_string($item['meeting']) === true) {
            return $item['meeting'];
        }

        foreach (($item['relations'] ?? []) as $relation) {
            if (($relation['schema'] ?? '') === 'meeting') {
                return ($relation['id'] ?? ($relation['uuid'] ?? ''));
            }
        }

        return '';

    }//end getMeetingIdFromItem()

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

    /**
     * Get the CalendarEventService from the container, or null if unavailable.
     *
     * @return object|null The OpenRegister CalendarEventService, or null
     */
    private function getCalendarEventService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\CalendarEventService');
        } catch (\Throwable) {
            return null;
        }

    }//end getCalendarEventService()
}//end class
