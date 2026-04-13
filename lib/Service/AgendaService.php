<?php

/**
 * Decidesk Agenda Service
 *
 * Service for agenda management: publication, BOB phase transitions,
 * hamerstukken processing, and agenda item reordering.
 *
 * @category  Service
 * @package   OCA\Decidesk\Service
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
        'voorstel'         => 'beeldvorming',
        'beeldvorming'     => 'oordeelsvorming',
        'oordeelsvorming'  => 'besluitvorming',
        'besluitvorming'   => 'afgerond',
    ];

    /**
     * Constructor for AgendaService.
     *
     * @param ContainerInterface $container The service container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Publish an agenda for a meeting.
     *
     * Validates that at least one agenda item exists, sends notifications
     * to all active Participants, and updates the meeting calendar event.
     *
     * @param string $meetingId The meeting ID
     *
     * @return array<string,mixed> Result with success flag and message
     *
     * @throws \RuntimeException When no agenda items exist for the meeting
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function publishAgenda(string $meetingId): array
    {
        $objectService = $this->getObjectService();

        // Fetch agenda items for this meeting.
        $agendaItems = $objectService->getObjects(
            schema: 'agenda-item',
            register: 'decidesk',
            filters: ['meeting' => $meetingId]
        );

        if (empty($agendaItems) === true) {
            throw new \RuntimeException('Een agenda moet minimaal één agendapunt bevatten');
        }

        // Fetch the meeting to get governance body and title.
        $meeting = $objectService->getObject(
            register: 'decidesk',
            schema: 'meeting',
            id: $meetingId
        );

        // Fetch active participants (leftAt is null) for the governance body.
        $participants = $this->getActiveParticipants(objectService: $objectService, meeting: $meeting);

        // Send notifications to each active participant.
        $notificationService = $this->getNotificationService();
        $meetingTitle         = ($meeting['title'] ?? 'Vergadering');
        $scheduledDate        = ($meeting['scheduledDate'] ?? '');

        foreach ($participants as $participant) {
            $notificationService->sendNotification(
                userId: ($participant['owner'] ?? ''),
                subject: $meetingTitle.' — Agenda gepubliceerd',
                message: 'De agenda voor '.$meetingTitle.' ('.$scheduledDate.') is gepubliceerd.',
            );
        }

        // Update calendar event via CalendarEventService.
        try {
            $calendarService = $this->getCalendarEventService();
            $calendarService->updateEvent(
                meetingId: $meetingId,
                data: ['agenda_published' => true]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: CalendarEventService not available',
                ['exception' => $e->getMessage()]
            );
        }

        $this->logger->info('Decidesk: Agenda published for meeting '.$meetingId);

        return [
            'success'       => true,
            'message'       => 'Agenda gepubliceerd',
            'notifications' => count($participants),
        ];

    }//end publishAgenda()

    /**
     * Advance the BOB phase of an agenda item.
     *
     * Transitions the status through: voorstel → beeldvorming →
     * oordeelsvorming → besluitvorming → afgerond.
     *
     * @param string $agendaItemId The agenda item ID
     *
     * @return array<string,mixed> Result with the new phase
     *
     * @throws \RuntimeException When the item is already at the final phase
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function advanceBobPhase(string $agendaItemId): array
    {
        $objectService = $this->getObjectService();

        $item         = $objectService->getObject(
            register: 'decidesk',
            schema: 'agenda-item',
            id: $agendaItemId
        );
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
            register: 'decidesk',
            schema: 'agenda-item',
            object: array_merge($item, ['status' => $nextPhase])
        );

        $this->logger->info(
            'Decidesk: BOB phase advanced from '.$currentPhase.' to '.$nextPhase
            .' for item '.$agendaItemId
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
     * Fetches all agenda items tagged 'hamerstuk' and bulk-updates
     * their status to 'afgerond'.
     *
     * @param string $meetingId The meeting ID
     *
     * @return array<string,mixed> Result with count of processed items
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function processHamerstukken(string $meetingId): array
    {
        $objectService = $this->getObjectService();

        $agendaItems = $objectService->getObjects(
            schema: 'agenda-item',
            register: 'decidesk',
            filters: [
                'meeting' => $meetingId,
                'tags'    => 'hamerstuk',
            ]
        );

        $count = 0;
        foreach ($agendaItems as $item) {
            $objectService->saveObject(
                register: 'decidesk',
                schema: 'agenda-item',
                object: array_merge($item, ['status' => 'afgerond'])
            );
            $count++;
        }

        $this->logger->info(
            'Decidesk: Processed '.$count.' hamerstukken for meeting '.$meetingId
        );

        return [
            'success' => true,
            'count'   => $count,
        ];

    }//end processHamerstukken()

    /**
     * Reorder agenda items for a meeting.
     *
     * Assigns sequential orderNumber values (1..n) to agenda items
     * based on the provided ordered array of IDs.
     *
     * @param string        $meetingId  The meeting ID
     * @param array<string> $orderedIds Ordered array of agenda item IDs
     *
     * @return array<string,mixed> Result with success flag
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
     */
    public function reorderItems(string $meetingId, array $orderedIds): array
    {
        $objectService = $this->getObjectService();

        $agendaItems = $objectService->getObjects(
            schema: 'agenda-item',
            register: 'decidesk',
            filters: ['meeting' => $meetingId]
        );

        // Index items by ID.
        $itemsById = [];
        foreach ($agendaItems as $item) {
            $id             = ($item['id'] ?? ($item['uuid'] ?? ''));
            $itemsById[$id] = $item;
        }

        // Assign sequential orderNumber based on provided order.
        $orderNumber = 1;
        foreach ($orderedIds as $id) {
            if (isset($itemsById[$id]) === true) {
                $objectService->saveObject(
                    register: 'decidesk',
                    schema: 'agenda-item',
                    object: array_merge($itemsById[$id], ['orderNumber' => $orderNumber])
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
     * Get active participants for a meeting's governance body.
     *
     * @param object               $objectService The object service
     * @param array<string,mixed>  $meeting       The meeting data
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
            schema: 'participant',
            register: 'decidesk',
            filters: ['governanceBody' => $governanceBodyId]
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
     * Get the ObjectService from the container.
     *
     * @return object The OpenRegister ObjectService
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Get the NotificationService from the container.
     *
     * @return object The OpenRegister NotificationService
     */
    private function getNotificationService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\NotificationService');

    }//end getNotificationService()

    /**
     * Get the CalendarEventService from the container.
     *
     * @return object The OpenRegister CalendarEventService
     */
    private function getCalendarEventService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\CalendarEventService');

    }//end getCalendarEventService()
}//end class
