<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

/**
 * Decidesk Agenda Service
 *
 * Service for managing agenda lifecycle operations including publication,
 * BOB phase advancement, consent item (hamerstukken) processing, and reordering.
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

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Decidesk\Exception\NotFoundException;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing agenda lifecycle operations.
 *
 * Provides domain-specific business logic for:
 * - Publishing agendas and notifying participants
 * - Advancing BOB (Beeldvorming/Oordeelsvorming/Besluitvorming) phases
 * - Processing consent agenda items (hamerstukken)
 * - Atomically reordering agenda items
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
 */
class AgendaService
{

    /**
     * BOB phase transition map: current status → next status.
     *
     * @var array<string,string>
     */
    private const BOB_PHASE_TRANSITIONS = [
        'voorstel'        => 'beeldvorming',
        'beeldvorming'    => 'oordeelsvorming',
        'oordeelsvorming' => 'besluitvorming',
        'besluitvorming'  => 'afgerond',
    ];

    /**
     * Tag identifying consent agenda items.
     *
     * @var string
     */
    private const HAMERSTUK_TAG = 'hamerstuk';

    /**
     * Constructor for AgendaService.
     *
     * @param ObjectService        $objectService        OpenRegister object service
     * @param CalendarEventService $calendarEventService OpenRegister calendar event service
     * @param INotificationManager $notificationManager  Nextcloud notification manager
     * @param LoggerInterface      $logger               PSR-3 logger
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly CalendarEventService $calendarEventService,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Publish an agenda for a meeting.
     *
     * Validates that at least one AgendaItem exists for the meeting,
     * then sends Nextcloud notifications to all active participants
     * and updates the Meeting lifecycle to 'opened'.
     *
     * @param string $meetingId UUID of the Meeting to publish
     *
     * @return void
     *
     * @throws InvalidArgumentException When no agenda items exist for the meeting
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    public function publishAgenda(string $meetingId): void
    {
        // Validate at least one AgendaItem exists.
        $items = $this->objectService->findAll(
            [
                'filters' => [
                    'register'                => 'decidesk',
                    'schema'                  => 'agenda-item',
                    '@self.relations.meeting' => $meetingId,
                ],
            ]
        );

        if (empty($items) === true) {
            throw new InvalidArgumentException('Cannot publish agenda: no agenda items exist for this meeting.');
        }

        // Fetch participants for this specific meeting only.
        $participants = $this->objectService->findAll(
            [
                'filters' => [
                    'register'                => 'decidesk',
                    'schema'                  => 'participant',
                    '@self.relations.meeting' => $meetingId,
                ],
            ]
        );

        // Notify each active participant (leftAt is null = still active).
        foreach ($participants as $participant) {
            $participantData = $this->toArray(item: $participant);
            $leftAt          = $participantData['leftAt'] ?? null;
            if ($leftAt !== null) {
                continue;
            }

            $userId = $participantData['owner'] ?? null;
            if ($userId === null) {
                continue;
            }

            $this->sendAgendaPublishedNotification(
                userId: (string) $userId,
                meetingId: $meetingId
            );
        }

        // Update the meeting calendar entry to reflect the published agenda.
        $this->calendarEventService->updateMeetingEvent(meetingId: $meetingId);

        // Update meeting lifecycle to 'opened'.
        $this->objectService->saveObject(
            object: [
                'id'        => $meetingId,
                'lifecycle' => 'opened',
            ],
            register: 'decidesk',
            schema: 'meeting',
            uuid: $meetingId,
        );

        $this->logger->info('Agenda published for meeting {meetingId}', ['meetingId' => $meetingId]);

    }//end publishAgenda()

    /**
     * Send an agenda-published Nextcloud notification to a single user.
     *
     * @param string $userId    The Nextcloud user ID to notify
     * @param string $meetingId The meeting UUID for deep-link context
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    private function sendAgendaPublishedNotification(string $userId, string $meetingId): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('decidesk')
                ->setUser($userId)
                ->setDateTime(new DateTime())
                ->setObject('meeting', $meetingId)
                ->setSubject('agenda_published', ['meetingId' => $meetingId]);

            $this->notificationManager->notify($notification);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to send agenda notification to user {userId}: {error}',
                ['userId' => $userId, 'error' => $e->getMessage()]
            );
        }

    }//end sendAgendaPublishedNotification()

    /**
     * Normalise an OpenRegister object to a plain PHP array.
     *
     * Handles both raw arrays and ObjectEntity instances returned by ObjectService.
     *
     * @param mixed $item The raw item from ObjectService
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    private function toArray(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (method_exists($item, 'getObject') === true) {
            return $item->getObject();
        }

        return (array) $item;

    }//end toArray()

    /**
     * Advance the BOB phase of a single agenda item.
     *
     * Maps the current status to the next BOB phase using the transition table.
     * Informational items (itemType = 'informational') cannot be advanced.
     *
     * BOB phase order: voorstel → beeldvorming → oordeelsvorming → besluitvorming → afgerond
     *
     * @param string $agendaItemId UUID of the AgendaItem to advance
     *
     * @return void
     *
     * @throws NotFoundException        When the agenda item does not exist
     * @throws InvalidArgumentException When item is informational or already at final phase
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    public function advanceBobPhase(string $agendaItemId): void
    {
        $item = $this->objectService->find($agendaItemId);
        if ($item === null) {
            throw new NotFoundException(message: "AgendaItem {$agendaItemId} not found.");
        }

        $itemData = $this->toArray(item: $item);

        // Guard: informational items have no BOB phase.
        $itemType = $itemData['itemType'] ?? null;
        if ($itemType === 'informational') {
            throw new InvalidArgumentException('Informational agenda items do not have a BOB phase.');
        }

        $currentStatus = $itemData['status'] ?? 'beeldvorming';
        $nextStatus    = self::BOB_PHASE_TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            throw new InvalidArgumentException(
                "AgendaItem is already at final phase '{$currentStatus}' and cannot be advanced."
            );
        }

        $this->objectService->saveObject(
            object: [
                'id'     => $agendaItemId,
                'status' => $nextStatus,
            ],
            register: 'decidesk',
            schema: 'agenda-item',
            uuid: $agendaItemId,
        );

        $this->logger->info(
            'BOB phase advanced for agenda item {id}: {from} to {to}',
            ['id' => $agendaItemId, 'from' => $currentStatus, 'to' => $nextStatus]
        );

    }//end advanceBobPhase()

    /**
     * Process all consent agenda items (hamerstukken) for a meeting.
     *
     * Fetches all AgendaItems for the meeting that have the 'hamerstuk' tag
     * and bulk-updates their status to 'afgerond' via ObjectService.
     *
     * @param string $meetingId UUID of the Meeting
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    public function processHamerstukken(string $meetingId): void
    {
        $items = $this->objectService->findAll(
            [
                'filters' => [
                    'register'                => 'decidesk',
                    'schema'                  => 'agenda-item',
                    '@self.relations.meeting' => $meetingId,
                ],
            ]
        );

        $processedCount = 0;
        foreach ($items as $item) {
            $itemData = $this->toArray(item: $item);
            $tags     = $itemData['tags'] ?? ($itemData['@self']['tags'] ?? []);

            if (in_array(needle: self::HAMERSTUK_TAG, haystack: (array) $tags, strict: true) === false) {
                continue;
            }

            $itemId = $itemData['id'] ?? ($itemData['@self']['id'] ?? ($itemData['uuid'] ?? null));
            if ($itemId === null) {
                continue;
            }

            $this->objectService->saveObject(
                object: [
                    'id'     => $itemId,
                    'status' => 'afgerond',
                ],
                register: 'decidesk',
                schema: 'agenda-item',
                uuid: (string) $itemId,
            );

            $processedCount++;
        }//end foreach

        $this->logger->info(
            'Processed {count} hamerstukken for meeting {meetingId}',
            ['count' => $processedCount, 'meetingId' => $meetingId]
        );

    }//end processHamerstukken()

    /**
     * Revert a published agenda back to draft (scheduled) state.
     *
     * Updates the Meeting lifecycle to 'scheduled', allowing chair/secretary to
     * continue editing before a subsequent publish. Symmetric with publishAgenda().
     *
     * @param string $meetingId UUID of the Meeting to revert
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    public function reviseAgenda(string $meetingId): void
    {
        $this->objectService->saveObject(
            object: [
                'id'        => $meetingId,
                'lifecycle' => 'scheduled',
            ],
            register: 'decidesk',
            schema: 'meeting',
            uuid: $meetingId,
        );

        $this->logger->info('Agenda reverted to draft for meeting {meetingId}', ['meetingId' => $meetingId]);

    }//end reviseAgenda()

    /**
     * Atomically reorder agenda items for a meeting.
     *
     * Accepts an ordered array of AgendaItem UUIDs and assigns sequential
     * orderNumber values 1..n, preventing gaps and duplicates.
     *
     * @param string   $meetingId  UUID of the Meeting (used for validation)
     * @param string[] $orderedIds Ordered array of AgendaItem UUIDs
     *
     * @return void
     *
     * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
     */
    public function reorderItems(string $meetingId, array $orderedIds): void
    {
        // Build a set of valid UUIDs that belong to this meeting.
        $meetingItems = $this->objectService->findAll(
            [
                'filters' => [
                    'register'                => 'decidesk',
                    'schema'                  => 'agenda-item',
                    '@self.relations.meeting' => $meetingId,
                ],
            ]
        );

        $validIds = [];
        foreach ($meetingItems as $item) {
            $itemData = $this->toArray(item: $item);
            $itemId   = $itemData['id'] ?? ($itemData['@self']['id'] ?? ($itemData['uuid'] ?? null));
            if ($itemId !== null) {
                $validIds[(string) $itemId] = true;
            }
        }

        $orderNumber = 1;
        foreach ($orderedIds as $itemId) {
            if (isset($validIds[(string) $itemId]) === false) {
                $this->logger->warning(
                    'reorderItems: UUID {id} does not belong to meeting {meetingId} — skipped',
                    ['id' => $itemId, 'meetingId' => $meetingId]
                );
                continue;
            }

            $this->objectService->saveObject(
                object: [
                    'id'          => $itemId,
                    'orderNumber' => $orderNumber,
                ],
                register: 'decidesk',
                schema: 'agenda-item',
                uuid: (string) $itemId,
            );

            $orderNumber++;
        }//end foreach

        $this->logger->info(
            'Reordered {count} agenda items for meeting {meetingId}',
            ['count' => count($orderedIds), 'meetingId' => $meetingId]
        );

    }//end reorderItems()
}//end class
