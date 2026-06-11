<?php
/**
 * Decidesk BoardMeetingCalDavBridge
 *
 * Phase 7 — wires OpenRegister BoardMeeting lifecycle events to the Nextcloud
 * CalDAV backend via {@see \OCA\Decidesk\Service\BoardCalDavSyncService}.
 *
 * The listener subscribes to the standard OR object-lifecycle events (created
 * + updated) and forwards only BoardMeeting rows; every other schema is
 * ignored, so the bridge stays cheap even on busy registers.
 *
 * @category Listener
 * @package  OCA\Decidesk\Listener
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

namespace OCA\Decidesk\Listener;

use OCA\Decidesk\Service\BoardCalDavSyncService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Forwards BoardMeeting OR lifecycle events to the CalDAV sync service.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
 */
class BoardMeetingCalDavBridge implements IEventListener
{

    /**
     * The OR schema slug the bridge cares about.
     *
     * @var string
     */
    public const SCHEMA_BOARD_MEETING = 'board-meeting';

    /**
     * Constructor.
     *
     * @param BoardCalDavSyncService $syncService CalDAV sync service
     * @param IUserSession           $userSession Session used to derive the calendar principal
     * @param LoggerInterface        $logger      Logger
     */
    public function __construct(
        private readonly BoardCalDavSyncService $syncService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a BoardMeeting OR lifecycle event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-7.1
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false
            && $event instanceof ObjectUpdatedEvent === false
        ) {
            return;
        }

        try {
            $entity = $event->getObject();
            if ($entity === null) {
                return;
            }

            $row = [];
            if (method_exists($entity, 'getObject') === true) {
                $row = (array) $entity->getObject();
            }

            if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {
                $row = (array) $entity->jsonSerialize();
            }

            // Resolve the schema slug from the canonical OR entity surface.
            $schema = $this->resolveSchemaSlug(entity: $entity, row: $row);
            if ($schema !== self::SCHEMA_BOARD_MEETING) {
                return;
            }

            // Inject the OR row id so the sync service can build a stable UID.
            if (isset($row['id']) === false && method_exists($entity, 'getUuid') === true) {
                $row['id'] = (string) $entity->getUuid();
            }

            $principal = $this->resolvePrincipal();
            $result    = $this->syncService->syncMeeting(meeting: $row, principalUid: $principal);

            if ($result['success'] === false) {
                $this->logger->warning(
                    'Decidesk: BoardMeeting CalDAV bridge sync failed',
                    ['meetingId' => ($row['id'] ?? null), 'message' => $result['message']]
                );
                return;
            }

            $this->logger->info(
                'Decidesk: BoardMeeting synced to CalDAV',
                [
                    'meetingId' => ($row['id'] ?? null),
                    'uid'       => $result['uid'],
                    'calendar'  => $result['calendar'],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardMeeting CalDAV bridge crashed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end handle()

    /**
     * Resolve the schema slug of the changed OR entity. Reads
     * `_schema` / `_schemaSlug` from the serialized row, or falls back to the
     * entity's typed accessor.
     *
     * @param object               $entity The OR object entity
     * @param array<string, mixed> $row    The serialized row
     *
     * @return string
     */
    private function resolveSchemaSlug(object $entity, array $row): string
    {
        $candidates = [
            $row['_schemaSlug'] ?? null,
            $row['_schema'] ?? null,
            $row['schema'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) === true && $candidate !== '') {
                return $candidate;
            }
        }

        if (method_exists($entity, 'getSchemaSlug') === true) {
            $slug = $entity->getSchemaSlug();
            if (is_string($slug) === true && $slug !== '') {
                return $slug;
            }
        }

        if (method_exists($entity, 'getSchema') === true) {
            $schema = $entity->getSchema();
            if (is_string($schema) === true && $schema !== '') {
                return $schema;
            }
        }

        return '';

    }//end resolveSchemaSlug()

    /**
     * Resolve the principal UID under which the CalDAV write happens.
     *
     * @return string
     */
    private function resolvePrincipal(): string
    {
        try {
            $user = $this->userSession->getUser();
            if ($user !== null) {
                return (string) $user->getUID();
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: CalDAV bridge could not resolve user, defaulting to admin',
                ['exception' => $e->getMessage()]
            );
        }

        return 'admin';

    }//end resolvePrincipal()
}//end class
