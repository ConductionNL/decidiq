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

namespace OCA\OpenRegister\Service;

if (class_exists(ObjectService::class) === false) {
    /**
     * Stub for OpenRegister ObjectService — used only in standalone unit tests.
     */
    class ObjectService
    {
        /**
         * Find a single object by UUID.
         *
         * @param string $uuid Object UUID
         *
         * @return array<string,mixed>|null
         */
        public function find(string $uuid): mixed
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
         * @param array<string,mixed> $object   The object data
         * @param string              $register Register slug
         * @param string              $schema   Schema slug
         * @param string              $uuid     Object UUID
         *
         * @return array<string,mixed>
         */
        public function saveObject(array $object, string $register, string $schema, string $uuid): array
        {
            return $object;
        }//end saveObject()

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
