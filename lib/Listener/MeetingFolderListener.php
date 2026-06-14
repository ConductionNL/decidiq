<?php

/**
 * Decidesk Meeting Folder Listener
 *
 * Creates the structured Files folder tree when a meeting object is
 * created in OpenRegister (nextcloud-integration spec, Files requirement).
 *
 * @category Listener
 * @package  OCA\Decidesk\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Listener;

use OCA\Decidesk\Service\MeetingFolderService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Forwards meeting OR creation events to MeetingFolderService
 * (the fail-soft OR-event listener pattern).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class MeetingFolderListener implements IEventListener
{

    /**
     * The OR schema slug this listener cares about.
     *
     * @var string
     */
    public const SCHEMA_MEETING = 'meeting';

    /**
     * Constructor.
     *
     * @param MeetingFolderService $folderService Meeting folder service
     * @param LoggerInterface      $logger        Logger
     */
    public function __construct(
        private readonly MeetingFolderService $folderService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a meeting OR creation event.
     *
     * @param Event $event The event to handle
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false) {
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

            if ($this->resolveSchemaSlug(entity: $entity, row: $row) !== self::SCHEMA_MEETING) {
                return;
            }

            if (isset($row['id']) === false && method_exists($entity, 'getUuid') === true) {
                $row['id'] = (string) $entity->getUuid();
            }

            $this->folderService->ensureMeetingFolders(meeting: $row);
        } catch (\Throwable $e) {
            // Fail soft: folder creation must never break the object write path.
            $this->logger->warning(
                'Decidesk: meeting folder listener failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end handle()

    /**
     * Resolve the schema slug from the canonical OR entity surface
     * (the canonical meeting entity candidates).
     *
     * @param object               $entity OR object entity
     * @param array<string, mixed> $row    Serialized payload
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string Schema slug, or '' when unresolvable
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
}//end class
