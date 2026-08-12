<?php

/**
 * Stub for OCA\OpenRegister\Service\CalendarEventService.
 *
 * SIGNATURE PARITY CONTRACT (decidesk#399)
 * ----------------------------------------
 * This file used to also declare ObjectEntity and ObjectService. Both were
 * dead: tests/bootstrap-unit.php registers `OCA\OpenRegister\` as a PSR-4 root
 * over tests/Stubs/, so those two names always resolve to
 * tests/Stubs/Db/ObjectEntity.php and tests/Stubs/Service/ObjectService.php and
 * the class_exists() guards below them never fired. The copies here declared
 * LOOSER signatures than the ones that actually load (`saveObject(): mixed`
 * returning its own input array), so anyone reading this file for the contract
 * read a fiction. They are gone; the two PSR-4 stubs are the single source.
 *
 * CalendarEventService keeps a stub because PSR-4 would look for
 * tests/Stubs/Service/CalendarEventService.php, which does not exist, so
 * without this file the class is genuinely absent when OpenRegister is not
 * installed and AgendaService cannot be constructed.
 *
 * Matched against ConductionNL/openregister@origin/development,
 * lib/Service/CalendarEventService.php. Production declares:
 *
 *   getEventsForObject()      line 139
 *   getAllUserEvents()        line 259
 *   createEvent()             line 342
 *   linkEvent()               line 425
 *   unlinkEvent()             line 466
 *   unlinkEventsForObject()   line 508
 *
 * It does NOT declare updateMeetingEvent(). An earlier revision of this stub
 * did, which is why AgendaService::publishAgenda() guards that call with
 * method_exists() — the guard is load-bearing and the branch is never taken in
 * a real install. Declaring the method here would hide that.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

if (class_exists(CalendarEventService::class) === false) {
	/**
	 * Stub for OpenRegister CalendarEventService — used only in standalone unit tests.
	 */
	class CalendarEventService {

		/**
		 * Return the calendar events linked to an object.
		 *
		 * @param string $objectUuid UUID of the object
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function getEventsForObject(string $objectUuid): array {
			return [];
		}//end getEventsForObject()

		/**
		 * Remove every calendar link for an object.
		 *
		 * @param string $objectUuid UUID of the object
		 *
		 * @return void
		 */
		public function unlinkEventsForObject(string $objectUuid): void {

		}//end unlinkEventsForObject()

	}//end class
}//end if
