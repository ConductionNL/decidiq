<?php

/**
 * Stubs for OCA\OpenRegister services used by AgendaService.
 *
 * These stubs allow unit tests to run in a standalone environment
 * (without a full Nextcloud installation) by providing the class
 * signatures that PHPUnit can create mocks from.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Stubs
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

if (class_exists(ObjectEntity::class) === false) {
    /**
     * Stub for OpenRegister ObjectEntity — used only in standalone unit tests.
     */
    class ObjectEntity implements \JsonSerializable
    {
        /**
         * Return the plain-array representation of the entity's data.
         *
         * @return array<string,mixed>
         */
        public function getObject(): array
        {
            return [];
        }//end getObject()

        /**
         * Return the JSON-serialisable representation of the entity.
         *
         * @return array<string,mixed>
         */
        public function jsonSerialize(): array
        {
            return [];
        }//end jsonSerialize()

    }//end class
}//end if

namespace OCA\OpenRegister\Service;

if (class_exists(ObjectService::class) === false) {
    /**
     * Stub for OpenRegister ObjectService — used only in standalone unit tests.
     */
    class ObjectService
    {
        /**
         * Find a single object by ID.
         *
         * @param string $id Object UUID
         *
         * @return \OCA\OpenRegister\Db\ObjectEntity|array<string,mixed>|null
         */
        public function find(string $id): mixed
        {
            return null;
        }//end find()

        /**
         * Find multiple objects matching filters.
         *
         * @param array<string,mixed> $config Filter configuration
         *
         * @return array<int,mixed>
         */
        public function findAll(array $config = []): array
        {
            return [];
        }//end findAll()

        /**
         * Save (create or update) an object.
         *
         * @param array<string,mixed> $object        The object data
         * @param string              $register      Register slug
         * @param string              $schema        Schema slug
         * @param string              $uuid          Object UUID
         *
         * @return array<string,mixed>
         */
        public function saveObject(array $object, string $register, string $schema, string $uuid): array
        {
            return $object;
        }//end saveObject()

        /**
         * Update an existing object from an array of changed fields.
         *
         * @param string               $id            Object UUID
         * @param array<string, mixed> $object        The fields to update
         * @param bool                 $updateVersion Whether to bump the version counter
         * @param bool                 $patch         Whether to apply a partial (patch) update
         *
         * @return \OCA\OpenRegister\Db\ObjectEntity
         */
        public function updateFromArray(string $id, array $object, bool $updateVersion = false, bool $patch = false): \OCA\OpenRegister\Db\ObjectEntity
        {
            return new \OCA\OpenRegister\Db\ObjectEntity();
        }//end updateFromArray()

    }//end class
}//end if

if (class_exists(CalendarEventService::class) === false) {
    /**
     * Stub for OpenRegister CalendarEventService — used only in standalone unit tests.
     */
    class CalendarEventService
    {
        /**
         * Update the calendar event for a meeting after agenda publication.
         *
         * @param string $meetingId UUID of the meeting
         *
         * @return void
         */
        public function updateMeetingEvent(string $meetingId): void
        {
        }//end updateMeetingEvent()

    }//end class
}//end if
